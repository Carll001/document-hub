<?php

namespace Tests\Feature;

use App\Models\SyncedEmail;
use App\Models\SyncedEmailAttachment;
use App\Models\User;
use App\Services\EmailSync\EmailSyncService;
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
            'user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '1001',
            'message_id' => '<message-1001@example.com>',
            'from_name' => 'Support Team',
            'from_email' => 'support@example.com',
            'subject' => 'Quarterly update',
            'body_preview' => 'Your quarterly account update is ready to review.',
            'body_text' => "Hello there,\n\nYour quarterly account update is ready to review.",
            'body_html' => '<p>Hello there,</p><p>Your quarterly account update is ready to review.</p>',
            'received_at' => now()->subHour(),
            'synced_at' => now(),
        ]);

        $email->attachments()->create([
            'file_name' => 'quarterly-update.pdf',
            'storage_path' => 'email-sync/1/1/01-quarterly-update.pdf',
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
                ->where('hasMoreEmails', false)
                ->where('nextCursor', null)
                ->where('backfill.presets', EmailSyncService::BACKFILL_PRESET_LIMITS)
                ->where('backfill.customMax', EmailSyncService::BACKFILL_CUSTOM_MAX)
                ->has('emails', 1)
                ->where('emails.0.subject', 'Quarterly update')
                ->where('emails.0.fromEmail', 'support@example.com')
                ->where('emails.0.bodyPreview', 'Your quarterly account update is ready to review.')
                ->where('emails.0.bodyText', "Hello there,\n\nYour quarterly account update is ready to review.")
                ->where('emails.0.hasHtmlBody', true)
                ->where('emails.0.htmlUrl', route('email-sync.rendered', ['syncedEmail' => $email]))
                ->where('emails.0.attachments.0.fileName', 'quarterly-update.pdf')
                ->where('emails.0.attachments.0.fileSize', 4096),
            );
    }

    public function test_email_sync_page_returns_only_the_first_twenty_five_saved_emails()
    {
        $this->withoutVite();

        $user = User::factory()->create();

        foreach (range(1, 26) as $offset) {
            SyncedEmail::query()->create([
                'user_id' => $user->id,
                'mailbox' => 'INBOX',
                'imap_uid' => (string) (2000 + $offset),
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
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailSync')
                ->has('emails', 25)
                ->where('hasMoreEmails', true)
                ->where('nextCursor', '2')
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
            'user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '4001',
            'message_id' => '<message-4001@example.com>',
            'from_name' => 'Cj Carreon',
            'from_email' => 'cj@example.com',
            'subject' => 'Stored with future time',
            'body_preview' => 'Older sync with a bad future timestamp.',
            'body_text' => 'Older sync with a bad future timestamp.',
            'received_at' => now()->addHours(6),
            'synced_at' => now()->subHour(),
        ]);

        SyncedEmail::query()->create([
            'user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '4002',
            'message_id' => '<message-4002@example.com>',
            'from_name' => 'Cj Carreon',
            'from_email' => 'cj@example.com',
            'subject' => 'Actual latest email',
            'body_preview' => 'Newest synced email should stay on top.',
            'body_text' => 'Newest synced email should stay on top.',
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
                'user_id' => $user->id,
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

        $this->mock(EmailSyncService::class, function ($mock) use ($user): void {
            $mock->shouldReceive('sync')
                ->once()
                ->with(\Mockery::on(fn (User $candidate): bool => $candidate->is($user)))
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

        $this->mock(EmailSyncService::class, function ($mock): void {
            $mock->shouldReceive('sync')
                ->once()
                ->andThrow(new \RuntimeException('Email sync is not configured yet. Set your Gmail address in MAIL_USERNAME first.'));
        });

        $this->actingAs($user)
            ->post(route('email-sync.sync'))
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHas('error', 'Email sync is not configured yet. Set your Gmail address in MAIL_USERNAME first.');
    }

    public function test_authenticated_users_can_import_older_mail_with_a_preset_limit()
    {
        $user = User::factory()->create();

        $this->mock(EmailSyncService::class, function ($mock) use ($user): void {
            $mock->shouldReceive('backfill')
                ->once()
                ->with(
                    \Mockery::on(fn (User $candidate): bool => $candidate->is($user)),
                    20,
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
                'mode' => '20',
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

    public function test_all_backfill_mode_passes_a_null_limit_to_the_service()
    {
        $user = User::factory()->create();

        $this->mock(EmailSyncService::class, function ($mock) use ($user): void {
            $mock->shouldReceive('backfill')
                ->once()
                ->with(
                    \Mockery::on(fn (User $candidate): bool => $candidate->is($user)),
                    null,
                )
                ->andReturn([
                    'fetched' => 120,
                    'created' => 120,
                    'updated' => 0,
                    'mailbox' => 'INBOX',
                ]);
        });

        $this->actingAs($user)
            ->post(route('email-sync.backfill'), [
                'mode' => 'all',
            ])
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHas('success', 'Older email import completed successfully.');
    }

    public function test_custom_backfill_requires_a_valid_limit()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('email-sync.index'))
            ->post(route('email-sync.backfill'), [
                'mode' => 'custom',
                'customLimit' => 0,
            ])
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHasErrors('customLimit');
    }

    public function test_authenticated_users_can_download_synced_email_attachments()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $email = SyncedEmail::query()->create([
            'user_id' => $user->id,
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
            'storage_path' => 'email-sync/'.$user->id.'/'.$email->id.'/01-report.txt',
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
            'user_id' => $user->id,
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
            'storage_path' => 'email-sync/'.$user->id.'/'.$email->id.'/01-google.png',
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
}
