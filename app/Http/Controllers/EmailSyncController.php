<?php

namespace App\Http\Controllers;

use App\Models\SyncedEmail;
use App\Models\SyncedEmailAttachment;
use App\Services\EmailSync\BirReceiptAutoMatchService;
use App\Services\EmailSync\EmailHtmlRenderer;
use App\Services\EmailSync\EmailSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailSyncController extends Controller
{
    private const EMAILS_PER_PAGE = 25;

    /**
     * Show the email sync page.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'appliedPage' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $emailPage = $this->birReceiptPage(
            $user,
            (int) ($validated['page'] ?? 1),
            $search,
        );
        $appliedPage = $this->appliedBirReceiptPage(
            $user,
            (int) ($validated['appliedPage'] ?? 1),
        );
        $latestSyncedEmail = SyncedEmail::query()
            ->whereBelongsTo($user)
            ->latest('synced_at')
            ->first();

        return Inertia::render('EmailSync', [
            'connection' => [
                'gmailAddressMasked' => $this->maskEmail((string) config('services.email_sync.username')),
                'imapConfigured' => $this->imapConfigured(),
                'imapHost' => config('services.email_sync.host'),
                'imapPort' => config('services.email_sync.port'),
                'imapEncryption' => config('services.email_sync.encryption'),
                'mailbox' => config('services.email_sync.mailbox'),
                'smtpConfigured' => $this->smtpConfigured(),
                'smtpHost' => config('mail.mailers.smtp.host'),
                'smtpPort' => config('mail.mailers.smtp.port'),
                'smtpScheme' => config('mail.mailers.smtp.scheme'),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'syncResult' => $request->session()->get('syncResult'),
            ],
            'stats' => [
                'totalStored' => SyncedEmail::query()
                    ->whereBelongsTo($user)
                    ->count(),
                'latestSyncedAt' => $latestSyncedEmail?->synced_at?->toIso8601String(),
            ],
            'emails' => $this->transformEmails(collect($emailPage->items())),
            'pagination' => $this->paginationPayload($emailPage),
            'appliedEmails' => $this->transformEmails(collect($appliedPage->items())),
            'appliedPagination' => $this->paginationPayload($appliedPage),
            'receiptCounts' => [
                'unmatched' => $this->birReceiptQuery($user, false, $search)->count(),
                'applied' => $this->birReceiptQuery($user, true)->count(),
            ],
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    /**
     * Return the next page of stored emails for the legacy inbox endpoint.
     */
    public function emails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = (int) ($validated['cursor'] ?? 1);

        return response()->json(
            $this->legacyEmailPagePayload(
                $this->emailPage($request->user(), $page),
            ),
        );
    }

    /**
     * Download an attachment extracted from a synced email.
     */
    public function downloadAttachment(Request $request, SyncedEmail $syncedEmail, SyncedEmailAttachment $attachment): StreamedResponse
    {
        $this->abortUnlessOwnsAttachment($request, $syncedEmail, $attachment);

        return Storage::disk('local')->download(
            $attachment->storage_path,
            $attachment->file_name,
        );
    }

    /**
     * Stream an attachment inline so rendered email HTML can reference it.
     */
    public function inlineAttachment(Request $request, SyncedEmail $syncedEmail, SyncedEmailAttachment $attachment): StreamedResponse
    {
        $this->abortUnlessOwnsAttachment($request, $syncedEmail, $attachment);

        return Storage::disk('local')->response(
            $attachment->storage_path,
            $attachment->file_name,
            [
                'Content-Type' => $attachment->content_type ?: 'application/octet-stream',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Return a rendered HTML document for a stored email.
     */
    public function renderedMessage(
        Request $request,
        SyncedEmail $syncedEmail,
        EmailHtmlRenderer $renderer,
    ): HttpResponse {
        abort_unless($syncedEmail->user->is($request->user()), 404);

        $syncedEmail->loadMissing('attachments');

        $inlineAttachmentUrls = $syncedEmail->attachments
            ->filter(fn (SyncedEmailAttachment $attachment): bool => $attachment->is_inline && filled($attachment->content_id))
            ->mapWithKeys(fn (SyncedEmailAttachment $attachment): array => [
                $attachment->content_id => route('email-sync.attachments.inline', [
                    'syncedEmail' => $syncedEmail,
                    'attachment' => $attachment,
                ]),
            ])
            ->all();

        return response(
            $renderer->renderDocument(
                $syncedEmail->body_html,
                $syncedEmail->body_text,
                $inlineAttachmentUrls,
            ),
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self' data: http: https:; media-src 'self' data: http: https:; style-src 'unsafe-inline' http: https:; font-src data: http: https:; connect-src 'none'; script-src 'none'; object-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none';",
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Trigger an incremental Gmail inbox sync.
     */
    public function sync(Request $request, EmailSyncService $service): RedirectResponse
    {
        try {
            $result = $service->sync($request->user());

            return to_route('email-sync.index')
                ->with('success', 'Inbox sync completed successfully.')
                ->with('syncResult', $result);
        } catch (RuntimeException $exception) {
            report($exception);

            return to_route('email-sync.index')
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('email-sync.index')
                ->with('error', 'Email sync failed. Check your Gmail address, app password, and IMAP access, then try again.');
        }
    }

    /**
     * Import older Gmail inbox history.
     */
    public function backfill(Request $request, EmailSyncService $service): RedirectResponse
    {
        $validated = $request->validate([
            'startDate' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $result = $service->backfill(
                $request->user(),
                CarbonImmutable::createFromFormat('Y-m-d', $validated['startDate'])->startOfDay(),
            );

            return to_route('email-sync.index')
                ->with('success', 'Older email import completed successfully.')
                ->with('syncResult', $result);
        } catch (RuntimeException $exception) {
            report($exception);

            return to_route('email-sync.index')
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('email-sync.index')
                ->with('error', 'Older email import failed. Check your Gmail IMAP access, then try again.');
        }
    }

    /**
     * Build the paginated stored-email query for the legacy inbox view.
     */
    private function emailPage($user, int $page): LengthAwarePaginator
    {
        return SyncedEmail::query()
            ->whereBelongsTo($user)
            ->with('attachments')
            ->orderByRaw(
                'CASE WHEN received_at IS NULL OR received_at > synced_at THEN synced_at ELSE received_at END DESC',
            )
            ->orderByDesc('id')
            ->paginate(self::EMAILS_PER_PAGE, ['*'], 'page', $page);
    }

    private function birReceiptPage($user, int $page, string $search): LengthAwarePaginator
    {
        return $this->birReceiptQuery($user, false, $search)
            ->orderByRaw(
                'CASE WHEN received_at IS NULL OR received_at > synced_at THEN synced_at ELSE received_at END DESC',
            )
            ->orderByDesc('id')
            ->paginate(self::EMAILS_PER_PAGE, ['*'], 'page', $page)
            ->withQueryString();
    }

    private function appliedBirReceiptPage($user, int $page): LengthAwarePaginator
    {
        return $this->birReceiptQuery($user, true)
            ->orderByDesc('bir_receipt_applied_at')
            ->orderByDesc('id')
            ->paginate(self::EMAILS_PER_PAGE, ['*'], 'appliedPage', $page)
            ->withQueryString();
    }

    private function birReceiptQuery($user, bool $applied, string $search = '')
    {
        $query = SyncedEmail::query()
            ->whereBelongsTo($user)
            ->with('attachments');

        if ($applied) {
            $query->where('bir_receipt_match_status', BirReceiptAutoMatchService::MATCH_STATUS_APPLIED);
        } else {
            $query
                ->whereNotNull('bir_receipt_match_status')
                ->where('bir_receipt_match_status', '!=', BirReceiptAutoMatchService::MATCH_STATUS_APPLIED)
                ->where('bir_receipt_match_status', '!=', BirReceiptAutoMatchService::MATCH_STATUS_NO_DETAILS);
        }

        $search = trim($search);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($searchQuery) use ($like): void {
                $searchQuery
                    ->where('bir_receipt_tin', 'like', $like)
                    ->orWhere('bir_receipt_file_name', 'like', $like)
                    ->orWhere('bir_receipt_date_received_by_bir', 'like', $like)
                    ->orWhere('bir_receipt_time_received_by_bir', 'like', $like)
                    ->orWhere('bir_receipt_match_status', 'like', $like)
                    ->orWhere('bir_receipt_match_error', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param  LengthAwarePaginator<int, SyncedEmail>  $emailPage
     * @return array{
     *     emails: array<int, array<string, mixed>>,
     *     hasMoreEmails: bool,
     *     nextCursor: string|null
     * }
     */
    private function legacyEmailPagePayload(LengthAwarePaginator $emailPage): array
    {
        return [
            'emails' => $this->transformEmails(collect($emailPage->items())),
            'hasMoreEmails' => $emailPage->hasMorePages(),
            'nextCursor' => $emailPage->hasMorePages()
                ? (string) ($emailPage->currentPage() + 1)
                : null,
        ];
    }

    /**
     * @param  Collection<int, SyncedEmail>  $emails
     * @return array<int, array<string, mixed>>
     */
    private function transformEmails(Collection $emails): array
    {
        return $emails->map(function (SyncedEmail $email): array {
            $hasHtmlBody = $this->hasMeaningfulHtmlBody($email->body_html);

            return [
                'id' => $email->id,
                'mailbox' => $email->mailbox,
                'fromName' => $email->from_name,
                'fromEmail' => $email->from_email,
                'subject' => $email->subject,
                'bodyPreview' => $email->body_preview,
                'bodyText' => $email->body_text,
                'hasHtmlBody' => $hasHtmlBody,
                'htmlUrl' => $hasHtmlBody
                    ? route('email-sync.rendered', ['syncedEmail' => $email])
                    : null,
                'attachments' => $email->attachments->map(fn (SyncedEmailAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'fileName' => $attachment->file_name,
                    'fileSize' => $attachment->file_size,
                    'contentType' => $attachment->content_type,
                    'isInline' => $attachment->is_inline,
                    'downloadUrl' => route('email-sync.attachments.download', [
                        'syncedEmail' => $email,
                        'attachment' => $attachment,
                    ]),
                ])->all(),
                'receivedAt' => $email->received_at?->toIso8601String(),
                'syncedAt' => $email->synced_at?->toIso8601String(),
                'matchedTin' => $email->bir_receipt_tin,
                'matchStatus' => $email->bir_receipt_match_status,
                'matchError' => $email->bir_receipt_match_error,
                'parsedBirReceiptDetails' => [
                    'fileName' => $email->bir_receipt_file_name,
                    'dateReceived' => $email->bir_receipt_date_received_by_bir,
                    'timeReceived' => $email->bir_receipt_time_received_by_bir,
                ],
            ];
        })->all();
    }

    /**
     * @param  LengthAwarePaginator<int, SyncedEmail>  $page
     * @return array{
     *     currentPage: int,
     *     lastPage: int,
     *     perPage: int,
     *     total: int,
     *     from: int|null,
     *     to: int|null
     * }
     */
    private function paginationPayload(LengthAwarePaginator $page): array
    {
        return [
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }

    /**
     * Treat blank HTML shells like "<div><br></div>" as no visible body.
     */
    private function hasMeaningfulHtmlBody(?string $bodyHtml): bool
    {
        $bodyHtml = trim((string) $bodyHtml);

        if ($bodyHtml === '') {
            return false;
        }

        $textContent = html_entity_decode(
            strip_tags($bodyHtml),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
        $textContent = str_replace("\u{00A0}", ' ', $textContent);
        $textContent = preg_replace('/\s+/u', '', $textContent) ?? '';

        if ($textContent !== '') {
            return true;
        }

        return preg_match('/<(img|svg|video|audio|table|hr|canvas)\b/i', $bodyHtml) === 1;
    }

    /**
     * Determine whether the Gmail IMAP connection details are present.
     */
    private function imapConfigured(): bool
    {
        $username = trim((string) config('services.email_sync.username'));

        return filled(config('services.email_sync.host'))
            && filled(config('services.email_sync.port'))
            && filled(config('services.email_sync.password'))
            && $username !== ''
            && $username !== 'your-google-account@gmail.com';
    }

    /**
     * Determine whether SMTP is configured to send through Gmail.
     */
    private function smtpConfigured(): bool
    {
        $username = trim((string) config('mail.mailers.smtp.username'));

        return config('mail.default') === 'smtp'
            && filled(config('mail.mailers.smtp.host'))
            && filled(config('mail.mailers.smtp.port'))
            && filled(config('mail.mailers.smtp.password'))
            && $username !== ''
            && $username !== 'your-google-account@gmail.com';
    }

    /**
     * Mask the configured Gmail address before exposing it to the UI.
     */
    private function maskEmail(string $email): ?string
    {
        $email = trim($email);

        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        if ($local === '') {
            return null;
        }

        return sprintf(
            '%s%s@%s',
            $local[0],
            str_repeat('*', max(strlen($local) - 1, 1)),
            $domain,
        );
    }

    /**
     * Ensure the current user owns both the email and attachment.
     */
    private function abortUnlessOwnsAttachment(
        Request $request,
        SyncedEmail $syncedEmail,
        SyncedEmailAttachment $attachment,
    ): void {
        abort_unless(
            $syncedEmail->user->is($request->user())
                && $attachment->syncedEmail->is($syncedEmail),
            404,
        );
    }
}
