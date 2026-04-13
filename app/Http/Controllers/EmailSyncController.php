<?php

namespace App\Http\Controllers;

use App\Models\EmailSyncAccount;
use App\Models\SyncedEmail;
use App\Models\SyncedEmailAttachment;
use App\Services\EmailSync\BirReceiptAutoMatchService;
use App\Services\EmailSync\EmailHtmlRenderer;
use App\Services\EmailSync\EmailSyncRunner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailSyncController extends Controller
{
    private const EMAILS_PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'appliedPage' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'formType' => ['nullable', 'string', 'max:64'],
            'accountIds' => ['nullable', 'array'],
            'accountIds.*' => ['integer', Rule::exists('email_sync_accounts', 'id')],
        ]);

        $user = $request->user();
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $formType = isset($validated['formType']) ? trim((string) $validated['formType']) : '';
        $accountIds = $this->normalizedAccountIds($validated['accountIds'] ?? []);

        $emailPage = $this->birReceiptPage(
            $user,
            (int) ($validated['page'] ?? 1),
            $search,
            $formType,
            $accountIds,
        );

        $appliedPage = $this->appliedBirReceiptPage(
            $user,
            (int) ($validated['appliedPage'] ?? 1),
            $formType,
            $accountIds,
        );

        $latestSyncedEmail = SyncedEmail::query()
            ->visibleTo($user)
            ->latest('synced_at')
            ->first();

        return Inertia::render('EmailSync', [
            'connection' => [
                'accountCount' => EmailSyncAccount::query()->where('is_active', true)->count(),
                'hasActiveAccounts' => EmailSyncAccount::query()->where('is_active', true)->exists(),
                'smtpConfigured' => $this->smtpConfigured(),
                'smtpHost' => config('mail.mailers.smtp.host'),
                'smtpPort' => config('mail.mailers.smtp.port'),
                'smtpScheme' => config('mail.mailers.smtp.scheme'),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'syncResult' => $request->session()->get('syncResult'),
                'syncResultDetails' => $request->session()->get('syncResultDetails'),
            ],
            'stats' => [
                'totalStored' => SyncedEmail::query()
                    ->visibleTo($user)
                    ->count(),
                'latestSyncedAt' => $latestSyncedEmail?->synced_at?->toIso8601String(),
            ],
            'emails' => $this->transformEmails(collect($emailPage->items())),
            'pagination' => $this->paginationPayload($emailPage),
            'appliedEmails' => $this->transformEmails(collect($appliedPage->items())),
            'appliedPagination' => $this->paginationPayload($appliedPage),
            'receiptCounts' => [
                'unmatched' => $this->birReceiptQuery($user, false, $search, $formType, $accountIds)->count(),
                'applied' => $this->birReceiptQuery($user, true, '', $formType, $accountIds)->count(),
            ],
            'filters' => [
                'search' => $search,
                'formType' => $formType,
                'formTypeOptions' => $this->formTypeOptions($user),
                'accountIds' => $accountIds,
                'accountOptions' => $this->filterAccountOptions($user),
            ],
            'syncAccounts' => [
                'options' => $this->syncAccountOptions(),
            ],
        ]);
    }

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

    public function allEmails(Request $request): Response
    {
        $validated = $request->validate([
            'accountIds' => ['nullable', 'array'],
            'accountIds.*' => ['integer', Rule::exists('email_sync_accounts', 'id')],
        ]);

        $user = $request->user();
        $accountIds = $this->normalizedAccountIds($validated['accountIds'] ?? []);
        $emailPage = $this->emailPage($user, 1, $accountIds);
        $latestSyncedEmail = SyncedEmail::query()
            ->visibleTo($user)
            ->latest('synced_at')
            ->first();

        return Inertia::render('AllEmailSync', [
            'connection' => [
                'accountCount' => EmailSyncAccount::query()->where('is_active', true)->count(),
                'hasActiveAccounts' => EmailSyncAccount::query()->where('is_active', true)->exists(),
                'smtpConfigured' => $this->smtpConfigured(),
                'smtpHost' => config('mail.mailers.smtp.host'),
                'smtpPort' => config('mail.mailers.smtp.port'),
                'smtpScheme' => config('mail.mailers.smtp.scheme'),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'syncResult' => $request->session()->get('syncResult'),
                'syncResultDetails' => $request->session()->get('syncResultDetails'),
            ],
            'stats' => [
                'totalStored' => SyncedEmail::query()
                    ->visibleTo($user)
                    ->count(),
                'latestSyncedAt' => $latestSyncedEmail?->synced_at?->toIso8601String(),
            ],
            'emails' => $this->transformEmails(collect($emailPage->items())),
            'hasMoreEmails' => $emailPage->hasMorePages(),
            'nextEmailsCursor' => $emailPage->hasMorePages()
                ? (string) ($emailPage->currentPage() + 1)
                : null,
            'filters' => [
                'accountIds' => $accountIds,
                'accountOptions' => $this->filterAccountOptions($user),
            ],
            'syncAccounts' => [
                'options' => $this->syncAccountOptions(),
            ],
        ]);
    }

    public function allEmailMessages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:1'],
            'accountIds' => ['nullable', 'array'],
            'accountIds.*' => ['integer', Rule::exists('email_sync_accounts', 'id')],
        ]);

        $page = (int) ($validated['cursor'] ?? 1);
        $accountIds = $this->normalizedAccountIds($validated['accountIds'] ?? []);

        return response()->json(
            $this->legacyEmailPagePayload(
                $this->emailPage($request->user(), $page, $accountIds),
            ),
        );
    }

    public function downloadAttachment(Request $request, SyncedEmail $syncedEmail, SyncedEmailAttachment $attachment): StreamedResponse
    {
        $this->abortUnlessOwnsAttachment($request, $syncedEmail, $attachment);

        return Storage::disk('local')->download(
            $attachment->storage_path,
            $attachment->file_name,
        );
    }

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

    public function renderedMessage(
        Request $request,
        SyncedEmail $syncedEmail,
        EmailHtmlRenderer $renderer,
    ): HttpResponse {
        abort_unless($syncedEmail->isVisibleTo($request->user()), 404);

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

    public function sync(Request $request, EmailSyncRunner $runner): RedirectResponse
    {
        $validated = $request->validate([
            'accountIds' => ['nullable', 'array'],
            'accountIds.*' => ['integer', Rule::exists('email_sync_accounts', 'id')],
        ]);

        try {
            $result = $runner->sync($this->normalizedAccountIds($validated['accountIds'] ?? []));

            if ($result['busyAccounts'] !== []) {
                return back()
                    ->with('error', $this->busyAccountMessage(
                        $result['busyAccounts'],
                        'syncing',
                        'Sync',
                        $result['results'],
                    ))
                    ->with('syncResult', $result['results'])
                    ->with('syncResultDetails', $this->manualSyncResultDetails('Sync', $result['results']));
            }

            return back()
                ->with('success', 'Inbox sync completed successfully.')
                ->with('syncResult', $result['results'])
                ->with('syncResultDetails', $this->manualSyncResultDetails('Sync', $result['results']));
        } catch (RuntimeException $exception) {
            report($exception);

            return back()
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->with('error', 'Email sync failed. Check the configured IMAP accounts, then try again.');
        }
    }

    public function backfill(Request $request, EmailSyncRunner $runner): RedirectResponse
    {
        $validated = $request->validate([
            'startDate' => ['required', 'date_format:Y-m-d'],
            'accountIds' => ['nullable', 'array'],
            'accountIds.*' => ['integer', Rule::exists('email_sync_accounts', 'id')],
        ]);

        try {
            $result = $runner->backfill(
                CarbonImmutable::createFromFormat('Y-m-d', $validated['startDate'])->startOfDay(),
                $this->normalizedAccountIds($validated['accountIds'] ?? []),
            );

            if ($result['busyAccounts'] !== []) {
                return back()
                    ->with('error', $this->busyAccountMessage(
                        $result['busyAccounts'],
                        'syncing',
                        'Import older',
                        $result['results'],
                    ))
                    ->with('syncResult', $result['results'])
                    ->with('syncResultDetails', $this->manualSyncResultDetails('Import older', $result['results']));
            }

            return back()
                ->with('success', 'Older email import completed successfully.')
                ->with('syncResult', $result['results'])
                ->with('syncResultDetails', $this->manualSyncResultDetails('Import older', $result['results']));
        } catch (RuntimeException $exception) {
            report($exception);

            return back()
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->with('error', 'Older email import failed. Check the configured IMAP accounts, then try again.');
        }
    }

    private function emailPage($user, int $page, array $accountIds = []): LengthAwarePaginator
    {
        $query = SyncedEmail::query()
            ->visibleTo($user)
            ->with(['attachments', 'emailSyncAccount'])
            ->orderByRaw(
                'CASE WHEN received_at IS NULL OR received_at > synced_at THEN synced_at ELSE received_at END DESC',
            )
            ->orderByDesc('id');

        if ($accountIds !== []) {
            $query->whereIn('email_sync_account_id', $accountIds);
        }

        return $query->paginate(self::EMAILS_PER_PAGE, ['*'], 'page', $page);
    }

    private function birReceiptPage($user, int $page, string $search, string $formType = '', array $accountIds = []): LengthAwarePaginator
    {
        return $this->birReceiptQuery($user, false, $search, $formType, $accountIds)
            ->orderByRaw(
                'CASE WHEN received_at IS NULL OR received_at > synced_at THEN synced_at ELSE received_at END DESC',
            )
            ->orderByDesc('id')
            ->paginate(self::EMAILS_PER_PAGE, ['*'], 'page', $page)
            ->withQueryString();
    }

    private function appliedBirReceiptPage($user, int $page, string $formType = '', array $accountIds = []): LengthAwarePaginator
    {
        return $this->birReceiptQuery($user, true, '', $formType, $accountIds)
            ->orderByDesc('bir_receipt_applied_at')
            ->orderByDesc('id')
            ->paginate(self::EMAILS_PER_PAGE, ['*'], 'appliedPage', $page)
            ->withQueryString();
    }

    private function birReceiptQuery($user, bool $applied, string $search = '', string $formType = '', array $accountIds = [])
    {
        $query = SyncedEmail::query()
            ->visibleTo($user)
            ->with(['attachments', 'emailSyncAccount']);

        if ($applied) {
            $query->where('bir_receipt_match_status', BirReceiptAutoMatchService::MATCH_STATUS_APPLIED);
        } else {
            $query
                ->whereNotNull('bir_receipt_match_status')
                ->where('bir_receipt_match_status', '!=', BirReceiptAutoMatchService::MATCH_STATUS_APPLIED)
                ->where('bir_receipt_match_status', '!=', BirReceiptAutoMatchService::MATCH_STATUS_NO_DETAILS);
        }

        $search = trim($search);
        $formType = strtoupper(trim($formType));

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($searchQuery) use ($like): void {
                $searchQuery
                    ->where('bir_receipt_tin', 'like', $like)
                    ->orWhere('bir_receipt_file_name', 'like', $like)
                    ->orWhere('bir_receipt_form_type', 'like', $like)
                    ->orWhere('bir_receipt_date_received_by_bir', 'like', $like)
                    ->orWhere('bir_receipt_time_received_by_bir', 'like', $like)
                    ->orWhere('bir_receipt_match_status', 'like', $like)
                    ->orWhere('bir_receipt_match_error', 'like', $like);
            });
        }

        if ($formType !== '') {
            $query->where('bir_receipt_form_type', $formType);
        }

        if ($accountIds !== []) {
            $query->whereIn('email_sync_account_id', $accountIds);
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function formTypeOptions($user): array
    {
        return SyncedEmail::query()
            ->visibleTo($user)
            ->whereNotNull('bir_receipt_form_type')
            ->where('bir_receipt_form_type', '!=', '')
            ->distinct()
            ->orderBy('bir_receipt_form_type')
            ->pluck('bir_receipt_form_type')
            ->map(fn (mixed $formType): string => (string) $formType)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: number, label: string, username: string|null, isActive: bool}>
     */
    private function filterAccountOptions($user): array
    {
        return EmailSyncAccount::withTrashed()
            ->where(function ($query) use ($user): void {
                $query
                    ->whereExists(function ($subquery) use ($user): void {
                        $subquery->selectRaw('1')
                            ->from('synced_emails')
                            ->whereColumn('synced_emails.email_sync_account_id', 'email_sync_accounts.id')
                            ->where(function ($visibility) use ($user): void {
                                $visibility
                                    ->whereNull('claimed_by_user_id')
                                    ->orWhere('claimed_by_user_id', $user->getKey());
                            });
                    })
                    ->orWhere('is_active', true);
            })
            ->orderBy('display_name')
            ->orderBy('username')
            ->get()
            ->map(fn (EmailSyncAccount $account): array => [
                'id' => $account->id,
                'label' => $account->label(),
                'username' => $account->username,
                'isActive' => (bool) $account->is_active && $account->deleted_at === null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: number, label: string, username: string}>
     */
    private function syncAccountOptions(): array
    {
        return EmailSyncAccount::query()
            ->where('is_active', true)
            ->orderBy('display_name')
            ->orderBy('username')
            ->get()
            ->map(fn (EmailSyncAccount $account): array => [
                'id' => $account->id,
                'label' => $account->label(),
                'username' => $account->username,
            ])
            ->values()
            ->all();
    }

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

    private function transformEmails(Collection $emails): array
    {
        return $emails->map(function (SyncedEmail $email): array {
            $hasHtmlBody = $this->hasMeaningfulHtmlBody($email->body_html);
            $account = $email->emailSyncAccount;

            return [
                'id' => $email->id,
                'mailbox' => $email->mailbox,
                'accountId' => $email->email_sync_account_id,
                'accountLabel' => $account?->label() ?? 'Removed account',
                'accountEmail' => $account?->username,
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
                    'formType' => $email->bir_receipt_form_type,
                    'dateReceived' => $email->bir_receipt_date_received_by_bir,
                    'timeReceived' => $email->bir_receipt_time_received_by_bir,
                ],
            ];
        })->all();
    }

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
     * @param  array<int, mixed>  $accountIds
     * @return list<int>
     */
    private function normalizedAccountIds(array $accountIds): array
    {
        return collect($accountIds)
            ->map(fn (mixed $accountId): int => (int) $accountId)
            ->filter(fn (int $accountId): bool => $accountId > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, mailbox: string, skipped: bool, emailIds: list<int>}>  $results
     * @return array{
     *     actionLabel: string,
     *     accountResults: list<array{
     *         accountId: int,
     *         accountLabel: string,
     *         fetched: int,
     *         created: int,
     *         updated: int,
     *         mailbox: string
     *     }>
     * }
     */
    private function manualSyncResultDetails(string $actionLabel, array $results): array
    {
        return [
            'actionLabel' => $actionLabel,
            'accountResults' => collect($results)
                ->map(function (array $result): array {
                    return [
                        'accountId' => $result['accountId'],
                        'accountLabel' => $result['accountLabel'],
                        'fetched' => $result['fetched'],
                        'created' => $result['created'],
                        'updated' => $result['updated'],
                        'mailbox' => $result['mailbox'],
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @param  list<string>  $busyAccounts
     * @param  list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, mailbox: string, skipped: bool, emailIds: list<int>}>  $results
     */
    private function busyAccountMessage(
        array $busyAccounts,
        string $statusLabel,
        string $actionLabel,
        array $results = [],
    ): string {
        $busyLabel = count($busyAccounts) === 1
            ? $busyAccounts[0]." is currently {$statusLabel}. Please wait for the current queue to finish, then try again."
            : implode(', ', $busyAccounts)." are currently {$statusLabel}. Please wait for the current queue to finish, then try again.";

        if ($results === []) {
            return $busyLabel;
        }

        $completedSummary = collect($results)
            ->map(fn (array $result): string => sprintf(
                '%s: %d fetched, %d created, %d updated',
                $result['accountLabel'],
                $result['fetched'],
                $result['created'],
                $result['updated'],
            ))
            ->implode(' | ');

        return "{$actionLabel} ran for: {$completedSummary}. {$busyLabel}";
    }

    private function abortUnlessOwnsAttachment(
        Request $request,
        SyncedEmail $syncedEmail,
        SyncedEmailAttachment $attachment,
    ): void {
        abort_unless(
            $syncedEmail->isVisibleTo($request->user())
                && $attachment->syncedEmail->is($syncedEmail),
            404,
        );
    }
}
