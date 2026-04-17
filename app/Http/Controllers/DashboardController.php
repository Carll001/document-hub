<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MergedPdf;
use App\Models\SyncedEmail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the application dashboard.
     */
    public function __invoke(): Response|RedirectResponse
    {
        /** @var User $user */
        $user = request()->user();

        if ($user->canAccessUserManagement()) {
            return to_route('users.index');
        }

        if ($user->isClient()) {
            return to_route('client.files');
        }

        $syncedEmailQuery = SyncedEmail::query()
            ->visibleTo($user);

        $mergedPdfQuery = MergedPdf::query()
            ->whereBelongsTo($user);

        $totalSyncedEmails = (clone $syncedEmailQuery)->count();
        $emailsWithAttachments = (clone $syncedEmailQuery)
            ->whereHas('attachments')
            ->count();
        $totalMergedPdfs = (clone $mergedPdfQuery)->count();

        return Inertia::render('Dashboard', [
            'signatureEnabled' => (bool) config('services.document_generator.signature_enabled', true),
            'overview' => [
                'totalSyncedEmails' => $totalSyncedEmails,
                'emailsWithAttachments' => $emailsWithAttachments,
                'totalMergedPdfs' => $totalMergedPdfs,
                'totalMergedSize' => (int) ((clone $mergedPdfQuery)->sum('file_size') ?: 0),
                'lastInboxSyncAt' => (clone $syncedEmailQuery)->max('synced_at'),
                'lastMergeAt' => (clone $mergedPdfQuery)->max('created_at'),
            ],
            'recentEmails' => $this->transformRecentEmails(
                (clone $syncedEmailQuery)
                    ->withCount('attachments')
                    ->orderByRaw(
                        'CASE WHEN received_at IS NULL OR received_at > synced_at THEN synced_at ELSE received_at END DESC',
                    )
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get(),
            ),
            'recentMergedPdfs' => $this->transformRecentMergedPdfs(
                (clone $mergedPdfQuery)
                    ->latest()
                    ->limit(5)
                    ->get(),
            ),
        ]);
    }

    /**
     * Transform recent email records for the dashboard.
     *
     * @param  Collection<int, SyncedEmail>  $emails
     * @return array<int, array<string, mixed>>
     */
    private function transformRecentEmails(Collection $emails): array
    {
        return $emails->map(fn (SyncedEmail $email): array => [
            'id' => $email->id,
            'fromName' => $email->from_name,
            'fromEmail' => $email->from_email,
            'subject' => $email->subject,
            'bodyPreview' => $email->body_preview,
            'mailbox' => $email->mailbox,
            'attachmentCount' => (int) ($email->attachments_count ?? 0),
            'receivedAt' => $email->received_at?->toIso8601String(),
            'syncedAt' => $email->synced_at?->toIso8601String(),
        ])->all();
    }

    /**
     * Transform recent merged PDFs for the dashboard.
     *
     * @param  Collection<int, MergedPdf>  $mergedPdfs
     * @return array<int, array<string, mixed>>
     */
    private function transformRecentMergedPdfs(Collection $mergedPdfs): array
    {
        return $mergedPdfs->map(fn (MergedPdf $mergedPdf): array => [
            'id' => $mergedPdf->id,
            'fileName' => $mergedPdf->file_name,
            'fileSize' => $mergedPdf->file_size,
            'sourceCount' => $mergedPdf->source_count,
            'createdAt' => $mergedPdf->created_at?->toIso8601String(),
            'downloadUrl' => route('doc-merge.download', ['mergedPdf' => $mergedPdf]),
        ])->all();
    }
}
