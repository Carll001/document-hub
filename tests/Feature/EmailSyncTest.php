<?php

namespace Tests\Feature;

use App\Models\SyncedEmail;
use App\Models\SyncedEmailAttachment;
use App\Models\User;
use App\Services\EmailSync\EmailSyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('email-sync.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_email_sync_page()
    {
        $this->withoutVite();

        config([
            'services.email_sync.username' => '',
            'services.email_sync.password' => '',
            'mail.default' => 'log',
            'mail.mailers.smtp.username' => '',
            'mail.mailers.smtp.password' => '',
        ]);

        $user = User::factory()->create();

        $email = SyncedEmail::query()->create([
            'user_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => '1001',
            'message_id' => '<message-1001@example.com>',
            'from_name' => 'Support Team',
            'from_email' => 'support@example.com',
            'subject' => 'Quarterly update',
            'body_preview' => 'Your quarterly account update is ready to review.',
            'body_text' => "Hello there,\n\nYour quarterly account update is ready to review.",
            'body_html' => '<p>Hello there,</p><p>Your quarterly account update is ready to review.</p>',
            'bir_receipt_file_name' => '1234567890000_RPT.pdf',
            'bir_receipt_date_received_by_bir' => 'Apr 10, 2026',
            'bir_receipt_time_received_by_bir' => '9:30 AM',
            'bir_receipt_tin' => '1234567890000',
            'bir_receipt_match_status' => 'unmatched',
            'received_at' => now()->subHour(),
            'synced_at' => now(),
        ]);

        $email->attachments()->create([
            'file_name' => 'quarterly-update.pdf',
            'storage_path' => 'email-sync/shared/'.$email->id.'/01-quarterly-update.pdf',
            'content_type' => 'application/pdf',
            'file_size' => 4096,
        ]);

        $this->actingAs($user)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailSync')
                ->where('connection.imapConfigured', false)
                ->where('connection.smtpConfigured', false)
                ->where('stats.totalStored', 1)
                ->where('pagination.currentPage', 1)
                ->where('pagination.lastPage', 1)
                ->where('pagination.total', 1)
                ->where('receiptCounts.unmatched', 1)
                ->where('receiptCounts.applied', 0)
                ->has('emails', 1)
                ->where('emails.0.matchedTin', '1234567890000')
                ->where('emails.0.matchStatus', 'unmatched')
                ->where('emails.0.parsedBirReceiptDetails.fileName', '1234567890000_RPT.pdf')
                ->where('emails.0.parsedBirReceiptDetails.dateReceived', 'Apr 10, 2026')
                ->where('emails.0.parsedBirReceiptDetails.timeReceived', '9:30 AM')
                ->has('appliedEmails', 0),
            );
    }

    public function test_email_sync_page_returns_only_the_first_twenty_five_saved_emails()
    {
        $this->withoutVite();

        $user = User::factory()->create();

        foreach (range(1, 26) as $offset) {
            SyncedEmail::query()->create([
                'user_id' => null,
                'mailbox' => 'INBOX',
                'imap_uid' => (string) (2000 + $offset),
                'message_id' => "<message-{$offset}@example.com>",
                'from_name' => 'Support Team',
                'from_email' => 'support@example.com',
                'subject' => "Message {$offset}",
                'body_preview' => "Preview {$offset}",
                'body_text' => "Body {$offset}",
                'bir_receipt_file_name' => "123456789{$offset}_RPT.pdf",
                'bir_receipt_date_received_by_bir' => 'Apr 10, 2026',
                'bir_receipt_time_received_by_bir' => '9:30 AM',
                'bir_receipt_tin' => '1234567890000',
                'bir_receipt_match_status' => 'unmatched',
                'received_at' => now()->subMinutes($offset),
                'synced_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailSync')
                ->has('emails', 25)
                ->where('pagination.currentPage', 1)
                ->where('pagination.lastPage', 2)
                ->where('pagination.total', 26)
                ->where('stats.totalStored', 26)
                ->where('emails.0.subject', 'Message 1')
                ->where('emails.24.subject', 'Message 25'),
            );
    }

    public function test_future_received_timestamps_do_not_sort_above_the_latest_synced_email()
    {
        $this->withoutVite();

        $user = User::factory()->create();

        SyncedEmail::query()->create([
            'user_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => '4001',
            'message_id' => '<message-4001@example.com>',
            'from_name' => 'Cj Carreon',
            'from_email' => 'cj@example.com',
            'subject' => 'Stored with future time',
            'body_preview' => 'Older sync with a bad future timestamp.',
            'body_text' => 'Older sync with a bad future timestamp.',
            'bir_receipt_file_name' => '1234567890000_RPT.pdf',
            'bir_receipt_date_received_by_bir' => 'Apr 10, 2026',
            'bir_receipt_time_received_by_bir' => '9:30 AM',
            'bir_receipt_tin' => '1234567890000',
            'bir_receipt_match_status' => 'unmatched',
            'received_at' => now()->addHours(6),
            'synced_at' => now()->subHour(),
        ]);

        SyncedEmail::query()->create([
            'user_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => '4002',
            'message_id' => '<message-4002@example.com>',
            'from_name' => 'Cj Carreon',
            'from_email' => 'cj@example.com',
            'subject' => 'Actual latest email',
            'body_preview' => 'Newest synced email should stay on top.',
            'body_text' => 'Newest synced email should stay on top.',
            'bir_receipt_file_name' => '1234567890001_RPT.pdf',
            'bir_receipt_date_received_by_bir' => 'Apr 11, 2026',
            'bir_receipt_time_received_by_bir' => '10:15 AM',
            'bir_receipt_tin' => '1234567890001',
            'bir_receipt_match_status' => 'unmatched',
            'received_at' => now()->subMinutes(2),
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailSync')
                ->where('emails.0.subject', 'Actual latest email')
                ->where('emails.1.subject', 'Stored with future time'),
            );
    }

    public function test_authenticated_users_can_load_more_saved_emails()
    {
        $user = User::factory()->create();

        foreach (range(1, 30) as $offset) {
            SyncedEmail::query()->create([
                'user_id' => null,
                'mailbox' => 'INBOX',
                'imap_uid' => (string) (3000 + $offset),
                'message_id' => "<message-{$offset}@example.com>",
                'from_name' => 'Support Team',
                'from_email' => 'support@example.com',
                'subject' => "Message {$offset}",
                'body_preview' => "Preview {$offset}",
                'body_text' => "Body {$offset}",
                'received_at' => now()->subMinutes($offset),
                'synced_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->getJson(route('email-sync.emails', ['cursor' => 2]))
            ->assertOk()
            ->assertJson([
                'hasMoreEmails' => false,
                'nextCursor' => null,
            ])
            ->assertJsonCount(5, 'emails')
            ->assertJsonPath('emails.0.subject', 'Message 26')
            ->assertJsonPath('emails.4.subject', 'Message 30');
    }

    public function test_authenticated_users_can_trigger_an_email_sync()
    {
        $user = User::factory()->create();

        $this->mock(EmailSyncRunner::class, function ($mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->andReturn([
                    'fetched' => 2,
                    'created' => 2,
                    'updated' => 0,
                    'mailbox' => 'INBOX',
                ]);
        });

        $this->actingAs($user)
            ->post(route('email-sync.sync'))
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHas('success', 'Inbox sync completed successfully.')
            ->assertSessionHas('syncResult', [
                'fetched' => 2,
                'created' => 2,
                'updated' => 0,
                'mailbox' => 'INBOX',
            ]);
    }

    public function test_sync_errors_are_sent_back_to_the_page()
    {
        $user = User::factory()->create();

        $this->mock(EmailSyncRunner::class, function ($mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->andThrow(new \RuntimeException('Email sync is not configured yet. Set your Gmail address in MAIL_USERNAME first.'));
        });

        $this->actingAs($user)
            ->post(route('email-sync.sync'))
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHas('error', 'Email sync is not configured yet. Set your Gmail address in MAIL_USERNAME first.');
    }

    public function test_authenticated_users_can_import_older_mail_from_a_selected_start_date()
    {
        $user = User::factory()->create();

        $this->mock(EmailSyncRunner::class, function ($mock): void {
            $mock->shouldReceive('backfill')
                ->once()
                ->with(
                    \Mockery::on(fn ($candidate): bool => $candidate instanceof \Carbon\CarbonImmutable && $candidate->format('Y-m-d') === '2026-01-01'),
                )
                ->andReturn([
                    'fetched' => 20,
                    'created' => 20,
                    'updated' => 0,
                    'mailbox' => 'INBOX',
                ]);
        });

        $this->actingAs($user)
            ->post(route('email-sync.backfill'), [
                'startDate' => '2026-01-01',
            ])
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHas('success', 'Older email import completed successfully.')
            ->assertSessionHas('syncResult', [
                'fetched' => 20,
                'created' => 20,
                'updated' => 0,
                'mailbox' => 'INBOX',
            ]);
    }

    public function test_start_date_is_required_for_backfill()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('email-sync.index'))
            ->post(route('email-sync.backfill'), [
                'startDate' => '',
            ])
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHasErrors('startDate');
    }

    public function test_backfill_requires_a_valid_start_date_format()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('email-sync.index'))
            ->post(route('email-sync.backfill'), [
                'startDate' => '01/01/2026',
            ])
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHasErrors('startDate');
    }

    public function test_authenticated_users_can_download_synced_email_attachments()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $email = SyncedEmail::query()->create([
            'user_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => '1002',
            'message_id' => '<message-1002@example.com>',
            'from_name' => 'Support Team',
            'from_email' => 'support@example.com',
            'subject' => 'Attachment included',
            'body_preview' => 'Please download the attached file.',
            'body_text' => 'Please download the attached file.',
            'received_at' => now()->subMinutes(30),
            'synced_at' => now(),
        ]);

        $attachment = SyncedEmailAttachment::query()->create([
            'synced_email_id' => $email->id,
            'file_name' => 'report.txt',
            'storage_path' => 'email-sync/shared/'.$email->id.'/01-report.txt',
            'content_type' => 'text/plain',
            'file_size' => 21,
        ]);

        Storage::disk('local')->put($attachment->storage_path, 'Attachment body text');

        $this->actingAs($user)
            ->get(route('email-sync.attachments.download', [
                'syncedEmail' => $email,
                'attachment' => $attachment,
            ]))
            ->assertOk()
            ->assertDownload('report.txt');
    }

    public function test_authenticated_users_can_view_rendered_email_html_with_inline_cid_images()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $email = SyncedEmail::query()->create([
            'user_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => '1200',
            'message_id' => '<message-1200@example.com>',
            'from_name' => 'Google',
            'from_email' => 'no-reply@accounts.google.com',
            'subject' => 'Security alert',
            'body_preview' => 'A new sign-in on Windows',
            'body_text' => "A new sign-in on Windows\nCheck activity",
            'body_html' => '<div><img src="cid:google-logo"><p>A new sign-in on Windows</p><a href="https://accounts.google.com">Check activity</a></div>',
            'received_at' => now()->subMinutes(10),
            'synced_at' => now(),
        ]);

        $attachment = SyncedEmailAttachment::query()->create([
            'synced_email_id' => $email->id,
            'file_name' => 'google.png',
            'storage_path' => 'email-sync/shared/'.$email->id.'/01-google.png',
            'content_type' => 'image/png',
            'content_id' => 'google-logo',
            'is_inline' => true,
            'file_size' => 16,
        ]);

        Storage::disk('local')->put($attachment->storage_path, 'fake-image-bytes');

        $this->actingAs($user)
            ->get(route('email-sync.rendered', ['syncedEmail' => $email]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('A new sign-in on Windows', false)
            ->assertSee(
                route('email-sync.attachments.inline', [
                    'syncedEmail' => $email,
                    'attachment' => $attachment,
                ]),
                false,
            )
            ->assertDontSee('cid:google-logo', false);
    }

    public function test_attachment_only_emails_with_blank_html_shells_do_not_render_as_html_messages()
    {
        $this->withoutVite();

        $user = User::factory()->create();

        $email = SyncedEmail::query()->create([
            'user_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => '1300',
            'message_id' => '<message-1300@example.com>',
            'from_name' => 'Cj Carreon',
            'from_email' => 'cj@example.com',
            'subject' => null,
            'body_preview' => null,
            'body_text' => null,
            'body_html' => '<div dir="ltr"><br></div>',
            'bir_receipt_file_name' => '1234567890000_RPT.pdf',
            'bir_receipt_date_received_by_bir' => 'Apr 10, 2026',
            'bir_receipt_time_received_by_bir' => '9:30 AM',
            'bir_receipt_tin' => '1234567890000',
            'bir_receipt_match_status' => 'unmatched',
            'received_at' => now()->subMinutes(5),
            'synced_at' => now(),
        ]);

        $email->attachments()->create([
            'file_name' => 'Payment_Confirmation_Receipt_Mockup.pdf',
            'storage_path' => 'email-sync/shared/'.$email->id.'/01-payment-confirmation.pdf',
            'content_type' => 'application/pdf',
            'file_size' => 32768,
            'is_inline' => false,
        ]);

        $this->actingAs($user)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailSync')
                ->where('emails.0.subject', null)
                ->where('emails.0.hasHtmlBody', false)
                ->where('emails.0.parsedBirReceiptDetails.fileName', '1234567890000_RPT.pdf')
                ->where('emails.0.attachments.0.fileName', 'Payment_Confirmation_Receipt_Mockup.pdf'),
            );
    }

    public function test_staff_users_share_the_same_email_sync_dataset()
    {
        $this->withoutVite();

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        SyncedEmail::query()->create([
            'user_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => '1400',
            'message_id' => '<message-1400@example.com>',
            'from_name' => 'Support Team',
            'from_email' => 'support@example.com',
            'subject' => 'Shared receipt',
            'body_preview' => 'Shared receipt body preview',
            'body_text' => 'Shared receipt body',
            'bir_receipt_file_name' => '1234567890000-1702EXv2018C.xml',
            'bir_receipt_date_received_by_bir' => 'Apr 10, 2026',
            'bir_receipt_time_received_by_bir' => '9:30 AM',
            'bir_receipt_tin' => '1234567890000',
            'bir_receipt_match_status' => 'unmatched',
            'received_at' => now()->subMinutes(5),
            'synced_at' => now(),
        ]);

        $assertSharedInbox = function (Assert $page): void {
            $page->component('EmailSync')
                ->where('stats.totalStored', 1)
                ->where('receiptCounts.unmatched', 1)
                ->has('emails', 1)
                ->where('emails.0.subject', 'Shared receipt');
        };

        $this->actingAs($firstUser)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia($assertSharedInbox);

        $this->actingAs($secondUser)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia($assertSharedInbox);
    }

    public function test_staff_users_can_download_shared_inbox_attachments()
    {
        Storage::fake('local');

        $viewer = User::factory()->create();

        $email = SyncedEmail::query()->create([
            'user_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => '1500',
            'message_id' => '<message-1500@example.com>',
            'from_name' => 'Support Team',
            'from_email' => 'support@example.com',
            'subject' => 'Shared attachment',
            'body_preview' => 'Please download the shared file.',
            'body_text' => 'Please download the shared file.',
            'received_at' => now()->subMinutes(30),
            'synced_at' => now(),
        ]);

        $attachment = SyncedEmailAttachment::query()->create([
            'synced_email_id' => $email->id,
            'file_name' => 'shared-report.txt',
            'storage_path' => 'email-sync/shared/'.$email->id.'/01-shared-report.txt',
            'content_type' => 'text/plain',
            'file_size' => 21,
        ]);

        Storage::disk('local')->put($attachment->storage_path, 'Attachment body text');

        $this->actingAs($viewer)
            ->get(route('email-sync.attachments.download', [
                'syncedEmail' => $email,
                'attachment' => $attachment,
            ]))
            ->assertOk()
            ->assertDownload('shared-report.txt');
    }
}
