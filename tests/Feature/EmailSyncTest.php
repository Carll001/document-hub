<?php

namespace Tests\Feature;

use App\Jobs\ProcessEmailSyncAccounts;
use App\Models\EmailSyncAccount;
use App\Models\SyncedEmail;
use App\Models\SyncedEmailAttachment;
use App\Models\User;
use App\Services\EmailSync\EmailSyncRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
            'claimed_by_user_id' => $user->id,
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
            'bir_receipt_form_type' => '1702EXV2018C',
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
                ->where('connection.accountCount', 0)
                ->where('connection.hasActiveAccounts', false)
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
                ->where('emails.0.parsedBirReceiptDetails.formType', '1702EXV2018C')
                ->where('emails.0.parsedBirReceiptDetails.dateReceived', 'Apr 10, 2026')
                ->where('emails.0.parsedBirReceiptDetails.timeReceived', '9:30 AM')
                ->where('filters.formType', '')
                ->where('filters.formTypeOptions.0', '1702EXV2018C')
                ->where('syncState.status', null)
                ->has('appliedEmails', 0),
            );
    }

    public function test_email_sync_page_returns_only_the_first_twenty_five_saved_emails()
    {
        $this->withoutVite();

        $user = User::factory()->create();

        foreach (range(1, 26) as $offset) {
            SyncedEmail::query()->create([
                'claimed_by_user_id' => $user->id,
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
            'claimed_by_user_id' => $user->id,
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
            'claimed_by_user_id' => $user->id,
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
                'claimed_by_user_id' => $user->id,
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

    public function test_unclaimed_emails_are_visible_to_multiple_staff_users(): void
    {
        $this->withoutVite();

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        SyncedEmail::query()->create([
            'mailbox' => 'INBOX',
            'imap_uid' => '3500',
            'message_id' => '<message-3500@example.com>',
            'subject' => 'Shared unclaimed receipt',
            'body_text' => 'Shared unclaimed receipt',
            'bir_receipt_tin' => '1234567890000',
            'bir_receipt_match_status' => 'unmatched',
            'synced_at' => now(),
        ]);

        $this->actingAs($firstUser)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.totalStored', 1)
                ->where('emails.0.subject', 'Shared unclaimed receipt'),
            );

        $this->actingAs($secondUser)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.totalStored', 1)
                ->where('emails.0.subject', 'Shared unclaimed receipt'),
            );
    }

    public function test_claimed_emails_are_hidden_from_other_staff_users(): void
    {
        $this->withoutVite();

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $owner->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '3600',
            'message_id' => '<message-3600@example.com>',
            'subject' => 'Claimed receipt',
            'body_text' => 'Claimed receipt',
            'bir_receipt_tin' => '1234567890000',
            'bir_receipt_match_status' => 'applied',
            'bir_receipt_applied_at' => now(),
            'synced_at' => now(),
        ]);

        $this->actingAs($otherUser)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.totalStored', 0)
                ->has('emails', 0)
                ->has('appliedEmails', 0),
            );

        $this->actingAs($otherUser)
            ->get(route('email-sync.rendered', ['syncedEmail' => $email]))
            ->assertNotFound();
    }

    public function test_authenticated_users_can_trigger_an_email_sync()
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = $this->createActiveSyncAccount();

        $this->actingAs($user)
            ->from(route('email-sync.index'))
            ->post(route('email-sync.sync'))
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHas('success', 'Email sync queued. Results will refresh automatically.');

        Queue::assertPushed(ProcessEmailSyncAccounts::class, function (ProcessEmailSyncAccounts $job) use ($account): bool {
            return $job->accountIds === [$account->id]
                && $job->actionLabel === 'Sync'
                && $job->startDate === null;
        });

        $account->refresh();

        $this->assertSame(EmailSyncAccount::PROCESSING_STATUS_QUEUED, $account->processing_status);
        $this->assertSame('Sync', $account->processing_action);
        $this->assertNotNull($account->processing_run_uuid);
        $this->assertNotNull($account->processing_started_at);
    }

    public function test_sync_requests_are_rejected_when_selected_accounts_are_already_busy()
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = $this->createActiveSyncAccount([
            'display_name' => 'Accounting Inbox',
            'processing_status' => EmailSyncAccount::PROCESSING_STATUS_PROCESSING,
            'processing_action' => 'Sync',
            'processing_started_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('email-sync.index'))
            ->post(route('email-sync.sync'), [
                'accountIds' => [$account->id],
            ])
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHas('error', 'Accounting Inbox is currently syncing. Please wait for the current queue to finish, then try again.');

        Queue::assertNothingPushed();
    }

    public function test_authenticated_users_can_import_older_mail_from_a_selected_start_date()
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = $this->createActiveSyncAccount();

        $this->actingAs($user)
            ->from(route('email-sync.index'))
            ->post(route('email-sync.backfill'), [
                'startDate' => '2026-01-01',
            ])
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHas('success', 'Older email import queued. Results will refresh automatically.');

        Queue::assertPushed(ProcessEmailSyncAccounts::class, function (ProcessEmailSyncAccounts $job) use ($account): bool {
            return $job->accountIds === [$account->id]
                && $job->actionLabel === 'Import older'
                && $job->startDate === '2026-01-01';
        });
    }

    public function test_email_sync_job_updates_account_status_and_result_counts_after_a_successful_run(): void
    {
        $account = $this->createActiveSyncAccount([
            'processing_status' => EmailSyncAccount::PROCESSING_STATUS_QUEUED,
            'processing_action' => 'Sync',
            'processing_run_uuid' => 'run-123',
            'processing_started_at' => now(),
        ]);

        $runner = \Mockery::mock(EmailSyncRunner::class);
        $runner->shouldReceive('syncSingleBatch')
            ->once()
            ->with([$account->id], \Mockery::type('callable'), null)
            ->andReturn([
                'results' => [[
                    'accountId' => $account->id,
                    'accountLabel' => $account->label(),
                    'fetched' => 5,
                    'created' => 3,
                    'updated' => 2,
                    'filtered' => 0,
                    'mailbox' => $account->mailbox,
                    'skipped' => false,
                    'emailIds' => [],
                ]],
                'busyAccounts' => [],
                'remainingUidsByAccount' => [],
                'hasMore' => false,
            ]);

        $job = new ProcessEmailSyncAccounts([$account->id], 'Sync', 'run-123');
        $job->handle($runner);

        $account->refresh();

        $this->assertNull($account->processing_status);
        $this->assertNull($account->processing_action);
        $this->assertNull($account->processing_run_uuid);
        $this->assertNull($account->processing_error);
        $this->assertNull($account->processing_started_at);
        $this->assertSame('run-123', $account->last_sync_run_uuid);
        $this->assertSame('Sync', $account->last_sync_action);
        $this->assertSame(5, $account->last_sync_fetched_count);
        $this->assertSame(3, $account->last_sync_created_count);
        $this->assertSame(2, $account->last_sync_updated_count);
        $this->assertNotNull($account->last_sync_completed_at);
    }

    public function test_email_sync_job_uses_the_selected_backfill_start_date(): void
    {
        $account = $this->createActiveSyncAccount([
            'processing_status' => EmailSyncAccount::PROCESSING_STATUS_QUEUED,
            'processing_action' => 'Import older',
            'processing_run_uuid' => 'run-backfill',
            'processing_started_at' => now(),
        ]);

        $runner = \Mockery::mock(EmailSyncRunner::class);
        $runner->shouldReceive('backfillSingleBatch')
            ->once()
            ->with(
                \Mockery::on(fn (mixed $candidate): bool => $candidate instanceof CarbonImmutable && $candidate->format('Y-m-d') === '2026-01-01'),
                [$account->id],
                \Mockery::type('callable'),
                null,
            )
            ->andReturn([
                'results' => [[
                    'accountId' => $account->id,
                    'accountLabel' => $account->label(),
                    'fetched' => 8,
                    'created' => 8,
                    'updated' => 0,
                    'filtered' => 0,
                    'mailbox' => $account->mailbox,
                    'skipped' => false,
                    'emailIds' => [],
                ]],
                'busyAccounts' => [],
                'remainingUidsByAccount' => [],
                'hasMore' => false,
            ]);

        $job = new ProcessEmailSyncAccounts([$account->id], 'Import older', 'run-backfill', '2026-01-01');
        $job->handle($runner);

        $account->refresh();

        $this->assertNull($account->processing_status);
        $this->assertSame('Import older', $account->last_sync_action);
        $this->assertSame(8, $account->last_sync_fetched_count);
    }

    public function test_email_sync_job_marks_accounts_as_failed_when_the_runner_throws(): void
    {
        $account = $this->createActiveSyncAccount([
            'processing_status' => EmailSyncAccount::PROCESSING_STATUS_QUEUED,
            'processing_action' => 'Sync',
            'processing_run_uuid' => 'run-failed',
            'processing_started_at' => now(),
        ]);

        $runner = \Mockery::mock(EmailSyncRunner::class);
        $runner->shouldReceive('syncSingleBatch')
            ->once()
            ->with([$account->id], \Mockery::type('callable'), null)
            ->andThrow(new \RuntimeException('Mailbox connection timed out.'));

        $job = new ProcessEmailSyncAccounts([$account->id], 'Sync', 'run-failed');

        try {
            $job->handle($runner);
            $this->fail('The queued sync job should rethrow runner exceptions.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Mailbox connection timed out.', $exception->getMessage());
        }

        $account->refresh();

        $this->assertSame(EmailSyncAccount::PROCESSING_STATUS_FAILED, $account->processing_status);
        $this->assertSame('Mailbox connection timed out.', $account->processing_error);
    }

    public function test_authenticated_users_can_cancel_a_running_email_sync(): void
    {
        $user = User::factory()->create();
        $account = $this->createActiveSyncAccount([
            'processing_status' => EmailSyncAccount::PROCESSING_STATUS_PROCESSING,
            'processing_action' => 'Import older',
            'processing_run_uuid' => 'run-cancel',
            'processing_error' => null,
            'processing_started_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('email-sync.index'))
            ->post(route('email-sync.cancel'))
            ->assertRedirect(route('email-sync.index'))
            ->assertSessionHas('success', 'Email sync cancelled.');

        $account->refresh();

        $this->assertNull($account->processing_status);
        $this->assertNull($account->processing_action);
        $this->assertNull($account->processing_run_uuid);
        $this->assertNull($account->processing_error);
        $this->assertNull($account->processing_started_at);
    }

    public function test_email_sync_page_exposes_queued_sync_state(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $account = $this->createActiveSyncAccount([
            'display_name' => 'Main Inbox',
            'processing_status' => EmailSyncAccount::PROCESSING_STATUS_QUEUED,
            'processing_action' => 'Sync',
            'processing_run_uuid' => 'run-queued',
            'processing_started_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailSync')
                ->where('syncState.status', EmailSyncAccount::PROCESSING_STATUS_QUEUED)
                ->where('syncState.actionLabel', 'Sync')
                ->where('syncState.accountLabels.0', $account->label()),
            );
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
            'claimed_by_user_id' => $user->id,
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

        Storage::disk('s3')->put($attachment->storage_path, 'Attachment body text');

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
            'claimed_by_user_id' => $user->id,
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

        Storage::disk('s3')->put($attachment->storage_path, 'fake-image-bytes');

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
            'claimed_by_user_id' => $user->id,
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
            'bir_receipt_form_type' => '1702EXV2018C',
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
                ->where('emails.0.parsedBirReceiptDetails.formType', '1702EXV2018C')
                ->where('emails.0.attachments.0.fileName', 'Payment_Confirmation_Receipt_Mockup.pdf'),
            );
    }

    public function test_scanned_email_search_can_filter_by_form_type()
    {
        $this->withoutVite();

        $user = User::factory()->create();

        SyncedEmail::query()->create([
            'claimed_by_user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '1600',
            'message_id' => '<message-1600@example.com>',
            'subject' => '1702-EX receipt',
            'body_text' => '1702-EX receipt',
            'bir_receipt_file_name' => '010860961000-1702EXv2018C-122025.xml',
            'bir_receipt_form_type' => '1702EXV2018C',
            'bir_receipt_tin' => '010860961000',
            'bir_receipt_match_status' => 'unmatched',
            'synced_at' => now()->subMinute(),
        ]);

        SyncedEmail::query()->create([
            'claimed_by_user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '1601',
            'message_id' => '<message-1601@example.com>',
            'subject' => '1701A receipt',
            'body_text' => '1701A receipt',
            'bir_receipt_file_name' => '445926028000-1701A-122025.xml',
            'bir_receipt_form_type' => '1701A',
            'bir_receipt_tin' => '445926028000',
            'bir_receipt_match_status' => 'unmatched',
            'bir_receipt_match_error' => 'The receipt file type does not apply to 1702-EX.',
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('email-sync.index', ['search' => '1701A']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailSync')
                ->has('emails', 1)
                ->where('emails.0.parsedBirReceiptDetails.fileName', '445926028000-1701A-122025.xml')
                ->where('emails.0.parsedBirReceiptDetails.formType', '1701A')
                ->where('emails.0.matchError', 'The receipt file type does not apply to 1702-EX.'),
            );
    }

    public function test_scanned_email_form_type_filter_returns_only_matching_form_types()
    {
        $this->withoutVite();

        $user = User::factory()->create();

        SyncedEmail::query()->create([
            'claimed_by_user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '1700',
            'message_id' => '<message-1700@example.com>',
            'subject' => '1702-EX receipt',
            'body_text' => '1702-EX receipt',
            'bir_receipt_file_name' => '010860961000-1702EXv2018C-122025.xml',
            'bir_receipt_form_type' => '1702EXV2018C',
            'bir_receipt_tin' => '010860961000',
            'bir_receipt_match_status' => 'unmatched',
            'synced_at' => now()->subMinute(),
        ]);

        SyncedEmail::query()->create([
            'claimed_by_user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '1701',
            'message_id' => '<message-1701@example.com>',
            'subject' => '1701A receipt',
            'body_text' => '1701A receipt',
            'bir_receipt_file_name' => '445926028000-1701A-122025.xml',
            'bir_receipt_form_type' => '1701A',
            'bir_receipt_tin' => '445926028000',
            'bir_receipt_match_status' => 'unmatched',
            'bir_receipt_match_error' => 'The receipt file type does not apply to 1702-EX.',
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('email-sync.index', ['formType' => '1701A']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailSync')
                ->where('filters.formType', '1701A')
                ->has('filters.formTypeOptions', 2)
                ->has('emails', 1)
                ->where('emails.0.parsedBirReceiptDetails.formType', '1701A')
                ->where('emails.0.parsedBirReceiptDetails.fileName', '445926028000-1701A-122025.xml'),
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

        Storage::disk('s3')->put($attachment->storage_path, 'Attachment body text');

        $this->actingAs($viewer)
            ->get(route('email-sync.attachments.download', [
                'syncedEmail' => $email,
                'attachment' => $attachment,
            ]))
            ->assertOk()
            ->assertDownload('shared-report.txt');
    }

    private function createActiveSyncAccount(array $overrides = []): EmailSyncAccount
    {
        return EmailSyncAccount::query()->create(array_merge([
            'display_name' => 'Shared Inbox',
            'username' => 'shared@example.com',
            'password' => 'secret',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'mailbox' => 'INBOX',
            'validate_certificate' => true,
            'is_active' => true,
        ], $overrides));
    }
}
