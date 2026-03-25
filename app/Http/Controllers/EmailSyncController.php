<?php

namespace App\Http\Controllers;

use App\Models\SyncedEmail;
use App\Models\SyncedEmailAttachment;
use App\Services\EmailSync\EmailHtmlRenderer;
use App\Services\EmailSync\EmailSyncService;
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

    /**
     * Show the email sync page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $emailPage = $this->emailPage($user, 1);
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
                'totalStored' => $emailPage->total(),
                'latestSyncedAt' => $latestSyncedEmail?->synced_at?->toIso8601String(),
            ],
            'backfill' => [
                'presets' => EmailSyncService::BACKFILL_PRESET_LIMITS,
                'customMax' => EmailSyncService::BACKFILL_CUSTOM_MAX,
            ],
            ...$this->emailPagePayload($emailPage),
        ]);
    }

    /**
     * Return the next page of stored emails for the inbox view.
     */
    public function emails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = (int) ($validated['cursor'] ?? 1);

        return response()->json(
            $this->emailPagePayload(
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
            'mode' => ['required', Rule::in([
                'all',
                ...array_map(static fn (int $limit): string => (string) $limit, EmailSyncService::BACKFILL_PRESET_LIMITS),
                'custom',
            ])],
            'customLimit' => [
                Rule::requiredIf($request->input('mode') === 'custom'),
                'nullable',
                'integer',
                'min:1',
                'max:'.EmailSyncService::BACKFILL_CUSTOM_MAX,
            ],
        ]);

        $limit = match ($validated['mode']) {
            'all' => null,
            'custom' => (int) $validated['customLimit'],
            default => (int) $validated['mode'],
        };

        try {
            $result = $service->backfill($request->user(), $limit);

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
     * Build the paginated stored-email query for the inbox view.
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

    /**
     * Convert the paginated email page into an Inertia/JSON payload.
     *
     * @param  LengthAwarePaginator<int, SyncedEmail>  $emailPage
     * @return array{
     *     emails: array<int, array<string, mixed>>,
     *     hasMoreEmails: bool,
     *     nextCursor: string|null
     * }
     */
    private function emailPagePayload(LengthAwarePaginator $emailPage): array
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
     * Convert synced email models into frontend payloads.
     *
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
            ];
        })->all();
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
