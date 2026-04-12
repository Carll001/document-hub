<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MergedPdf;
use App\Models\SyncedEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        SyncedEmail::query()->create([
            'claimed_by_user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '1001',
            'message_id' => '<dashboard-1@example.com>',
            'from_name' => 'Google',
            'from_email' => 'no-reply@accounts.google.com',
            'subject' => 'Security alert',
            'body_preview' => 'A new sign-in on Windows',
            'body_text' => 'A new sign-in on Windows',
            'received_at' => now()->subMinutes(10),
            'synced_at' => now()->subMinutes(5),
        ])->attachments()->create([
            'file_name' => 'alert.pdf',
            'storage_path' => 'email-sync/shared/1/alert.pdf',
            'content_type' => 'application/pdf',
            'file_size' => 1024,
        ]);

        SyncedEmail::query()->create([
            'claimed_by_user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '1002',
            'message_id' => '<dashboard-2@example.com>',
            'from_name' => 'Billing',
            'from_email' => 'billing@example.com',
            'subject' => 'Invoice available',
            'body_preview' => 'Your invoice is ready.',
            'body_text' => 'Your invoice is ready.',
            'received_at' => now()->subMinutes(30),
            'synced_at' => now()->subMinutes(20),
        ]);

        SyncedEmail::query()->create([
            'claimed_by_user_id' => $otherUser->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9999',
            'message_id' => '<foreign-dashboard@example.com>',
            'from_name' => 'Hidden',
            'from_email' => 'hidden@example.com',
            'subject' => 'Should not appear',
            'body_preview' => 'Nope',
            'body_text' => 'Nope',
            'received_at' => now(),
            'synced_at' => now(),
        ]);

        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'combined-report.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/combined-report.pdf',
            'file_size' => 4096,
            'source_count' => 3,
            'source_file_names' => ['one.pdf', 'two.pdf', 'three.pdf'],
        ]);

        MergedPdf::query()->create([
            'user_id' => $otherUser->id,
            'file_name' => 'hidden.pdf',
            'storage_path' => 'doc-merge/'.$otherUser->id.'/hidden.pdf',
            'file_size' => 9999,
            'source_count' => 2,
            'source_file_names' => ['x.pdf', 'y.pdf'],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('overview.totalSyncedEmails', 2)
                ->where('overview.emailsWithAttachments', 1)
                ->where('overview.totalMergedPdfs', 1)
                ->where('overview.totalMergedSize', 4096)
                ->has('recentEmails', 2)
                ->where('recentEmails.0.subject', 'Security alert')
                ->where('recentEmails.0.attachmentCount', 1)
                ->where('recentEmails.1.subject', 'Invoice available')
                ->has('recentMergedPdfs', 1)
                ->where('recentMergedPdfs.0.fileName', 'combined-report.pdf')
                ->where('recentMergedPdfs.0.downloadUrl', route('doc-merge.download', ['mergedPdf' => $mergedPdf])),
            );
    }

    public function test_superadmin_users_are_redirected_from_dashboard_to_users(): void
    {
        $superadmin = User::factory()->create([
            'role' => UserRole::Superadmin,
        ]);

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertRedirect(route('users.index'));
    }
}
