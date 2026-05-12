<?php

declare(strict_types=1);

namespace App\Services\EmailSync;

use App\Jobs\ProcessForm1702ExBatchRows;
use App\Jobs\GenerateForm1702ExRowReceipt;
use App\Models\Form1702ExBatchRow;
use App\Models\SyncedEmail;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BirReceiptAutoMatchService
{
    public const MATCH_STATUS_NO_DETAILS = 'no_details';

    public const MATCH_STATUS_NO_TIN = 'no_tin';

    public const MATCH_STATUS_UNMATCHED = 'unmatched';

    public const MATCH_STATUS_PENDING_PDF = 'pending_pdf';

    public const MATCH_STATUS_QUEUED = 'queued';

    public const MATCH_STATUS_APPLIED = 'applied';

    public const MATCH_STATUS_FAILED = 'failed';

    public function __construct(
        private readonly BirReceiptEmailParser $parser,
    ) {
    }

    public function syncEmail(SyncedEmail $email): void
    {
        $parsed = $this->parser->parse($email->body_text);

        if ($parsed === null) {
            $email->forceFill($this->emailState(null, self::MATCH_STATUS_NO_DETAILS))->save();

            return;
        }

        $matchedRow = $this->matchRowForTin(
            $parsed['tin'],
            $parsed['date_received_by_bir'] ?? null,
        );
        if ($parsed['tin'] === null) {
            $email->forceFill($this->emailState($parsed, self::MATCH_STATUS_NO_TIN))->save();

            return;
        }

        if (! $this->supportsFormType($parsed['form_type'] ?? null)) {
            $email->forceFill($this->emailState(
                $parsed,
                self::MATCH_STATUS_UNMATCHED,
                null,
                'The receipt file type does not apply to 1702-EX.',
            ))->save();

            return;
        }

        $matchedRow = $this->matchRowForTin(
            $parsed['tin'],
            $parsed['date_received_by_bir'] ?? null,
        );

        if ($matchedRow === null) {
            $email->forceFill($this->emailState($parsed, self::MATCH_STATUS_UNMATCHED))->save();

            return;
        }

        if (in_array($email->bir_receipt_match_status, [self::MATCH_STATUS_QUEUED, self::MATCH_STATUS_APPLIED], true)
            && (int) $email->matched_form_1702_ex_batch_row_id === (int) $matchedRow->getKey()) {
            $email->forceFill($this->emailState(
                $parsed,
                (string) $email->bir_receipt_match_status,
                $matchedRow,
                $email->bir_receipt_match_error,
            ))->save();

            return;
        }

        $claimedEmail = $this->claimEmailForUser($email, (int) $matchedRow->batch->user_id);

        if (! $claimedEmail instanceof SyncedEmail) {
            return;
        }

        if (! $this->rowCanQueueReceipt($matchedRow)) {
            $this->markPending($claimedEmail, $matchedRow, $parsed);
            $this->ensureRowGenerationQueued($matchedRow);

            return;
        }

        $this->queueReceipt($claimedEmail, $matchedRow, $parsed);
    }

    public function applyPendingForRow(Form1702ExBatchRow $row): void
    {
        if (! $this->rowCanQueueReceipt($row)) {
            return;
        }

        $email = SyncedEmail::query()
            ->whereKey($row->auto_receipt_synced_email_id)
            ->where('matched_form_1702_ex_batch_row_id', $row->getKey())
            ->where('bir_receipt_match_status', self::MATCH_STATUS_PENDING_PDF)
            ->first();

        if (! $email instanceof SyncedEmail) {
            return;
        }

        $parsed = [
            'file_name' => $email->bir_receipt_file_name,
            'date_received_by_bir' => $email->bir_receipt_date_received_by_bir,
            'time_received_by_bir' => $email->bir_receipt_time_received_by_bir,
            'tin' => $email->bir_receipt_tin,
            'form_type' => $email->bir_receipt_form_type,
        ];

        if (
            ! $this->supportsFormType($parsed['form_type'] ?? null)
            || ! $this->receiptPassesAcceptanceStartDate($row, $parsed['date_received_by_bir'])
        ) {
            return;
        }

        $this->queueReceipt($email, $row, $parsed);
    }

    /**
     * Re-scan stored emails for the provided TINs so newly uploaded rows can
     * reconcile against emails that existed first.
     *
     * @param  list<string|null>  $tins
     */
    public function syncStoredEmailsForTins(array $tins): void
    {
        $normalizedTins = collect($tins)
            ->map(fn (mixed $tin): ?string => $this->normalizeTin($tin))
            ->filter()
            ->unique()
            ->values();

        if ($normalizedTins->isEmpty()) {
            return;
        }

        $emails = SyncedEmail::query()
            ->where(function ($query): void {
                $query
                    ->whereNotNull('bir_receipt_tin')
                    ->orWhereNotNull('body_text');
            })
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();

        foreach ($emails as $email) {
            $candidateTin = $this->normalizeTin($email->bir_receipt_tin);

            if ($candidateTin === null) {
                $parsed = $this->parser->parse($email->body_text);
                $candidateTin = $this->normalizeTin($parsed['tin'] ?? null);
            }

            if ($candidateTin === null || ! $normalizedTins->contains($candidateTin)) {
                continue;
            }

            $this->syncEmail($email);
        }
    }

    public function markReceiptApplied(Form1702ExBatchRow $row, SyncedEmail $email): void
    {
        $row->loadMissing('batch');

        $email->forceFill([
            'matched_form_1702_ex_batch_row_id' => $row->getKey(),
            'bir_receipt_match_status' => self::MATCH_STATUS_APPLIED,
            'bir_receipt_applied_at' => now(),
            'bir_receipt_match_error' => null,
            'claimed_by_user_id' => (int) $row->batch->user_id,
            'claimed_at' => $email->claimed_at ?? now(),
        ])->save();

        $row->forceFill([
            'auto_receipt_synced_email_id' => $email->getKey(),
            'auto_receipt_status' => self::MATCH_STATUS_APPLIED,
            'auto_receipt_error' => null,
        ])->save();

        $this->skipLaterDuplicateRows($row);
    }

    public function markReceiptFailed(Form1702ExBatchRow $row, SyncedEmail $email, string $message): void
    {
        $row->loadMissing('batch');

        $email->forceFill([
            'matched_form_1702_ex_batch_row_id' => $row->getKey(),
            'bir_receipt_match_status' => self::MATCH_STATUS_FAILED,
            'bir_receipt_match_error' => $message,
            'claimed_by_user_id' => (int) $row->batch->user_id,
            'claimed_at' => $email->claimed_at ?? now(),
        ])->save();

        $row->forceFill([
            'auto_receipt_synced_email_id' => $email->getKey(),
            'auto_receipt_status' => self::MATCH_STATUS_FAILED,
            'auto_receipt_error' => $message,
        ])->save();
    }

    public function resetMatchedReceiptEmail(SyncedEmail $email): void
    {
        $email->forceFill([
            'matched_form_1702_ex_batch_row_id' => null,
            'bir_receipt_match_status' => self::MATCH_STATUS_UNMATCHED,
            'bir_receipt_queued_at' => null,
            'bir_receipt_applied_at' => null,
            'bir_receipt_match_error' => null,
            'claimed_by_user_id' => null,
            'claimed_at' => null,
        ])->save();
    }

    /**
     * @param  array{
     *     file_name: string|null,
     *     date_received_by_bir: string|null,
     *     time_received_by_bir: string|null,
     *     tin: string|null,
     *     form_type: string|null
     * }|null  $parsed
     * @return array<string, mixed>
     */
    private function emailState(
        ?array $parsed,
        string $status,
        ?Form1702ExBatchRow $matchedRow = null,
        ?string $error = null,
    ): array {
        return [
            'bir_receipt_file_name' => $parsed['file_name'] ?? null,
            'bir_receipt_date_received_by_bir' => $parsed['date_received_by_bir'] ?? null,
            'bir_receipt_time_received_by_bir' => $parsed['time_received_by_bir'] ?? null,
            'bir_receipt_tin' => $parsed['tin'] ?? null,
            'bir_receipt_form_type' => $parsed['form_type'] ?? null,
            'matched_form_1702_ex_batch_row_id' => $matchedRow?->getKey(),
            'bir_receipt_match_status' => $status,
            'bir_receipt_match_error' => $error,
            'bir_receipt_queued_at' => $status === self::MATCH_STATUS_QUEUED ? now() : null,
            'bir_receipt_applied_at' => $status === self::MATCH_STATUS_APPLIED ? now() : null,
        ];
    }

    private function matchRowForTin(
        ?string $tin,
        ?string $dateReceivedByBir = null,
    ): ?Form1702ExBatchRow
    {
        $normalizedTin = $this->normalizeTin($tin);

        if ($normalizedTin === null) {
            return null;
        }

        /** @var Collection<int, Form1702ExBatchRow> $rows */
        $rows = Form1702ExBatchRow::query()
            ->with('batch')
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->get();

        return $rows->first(function (Form1702ExBatchRow $row) use ($normalizedTin, $dateReceivedByBir): bool {
            $payload = is_array($row->payload) ? $row->payload : [];

            return $this->normalizeTin($payload['tin'] ?? null) === $normalizedTin
                && $this->receiptPassesAcceptanceStartDate($row, $dateReceivedByBir)
                && $this->rowIsEligibleForMatch($row);
        });
    }

    /**
     * @param  array{
     *     file_name: string|null,
     *     date_received_by_bir: string|null,
     *     time_received_by_bir: string|null,
     *     tin: string|null
     * }  $parsed
     */
    private function queueReceipt(SyncedEmail $email, Form1702ExBatchRow $row, array $parsed): void
    {
        $row->loadMissing('batch');

        $values = [
            'mailbox_email' => $this->mailboxEmail($email),
            'file_name' => (string) ($parsed['file_name'] ?? ''),
            'date_received_by_bir' => (string) ($parsed['date_received_by_bir'] ?? ''),
            'time_received_by_bir' => (string) ($parsed['time_received_by_bir'] ?? ''),
        ];

        $row->forceFill([
            'receipt_job_status' => Form1702ExBatchRow::RECEIPT_JOB_STATUS_QUEUED,
            'receipt_job_error' => null,
            'auto_receipt_synced_email_id' => $email->getKey(),
            'auto_receipt_status' => self::MATCH_STATUS_QUEUED,
            'auto_receipt_error' => null,
        ])->save();

        $email->forceFill($this->emailState($parsed, self::MATCH_STATUS_QUEUED, $row))->save();
        $this->touchClaim($email, (int) $row->batch->user_id);

        GenerateForm1702ExRowReceipt::dispatch(
            (int) $row->getKey(),
            $values,
            (int) $email->getKey(),
        )->afterCommit();
    }

    private function mailboxEmail(SyncedEmail $email): string
    {
        $email->loadMissing('emailSyncAccount');

        return trim((string) ($email->emailSyncAccount?->username ?? ''));
    }

    /**
     * @param  array{
     *     file_name: string|null,
     *     date_received_by_bir: string|null,
     *     time_received_by_bir: string|null,
     *     tin: string|null
     * }  $parsed
     */
    private function markPending(SyncedEmail $email, Form1702ExBatchRow $row, array $parsed): void
    {
        $row->loadMissing('batch');

        $email->forceFill($this->emailState($parsed, self::MATCH_STATUS_PENDING_PDF, $row))->save();
        $this->touchClaim($email, (int) $row->batch->user_id);
        $row->forceFill([
            'auto_receipt_synced_email_id' => $email->getKey(),
            'auto_receipt_status' => self::MATCH_STATUS_PENDING_PDF,
            'auto_receipt_error' => null,
        ])->save();
    }

    private function rowCanQueueReceipt(Form1702ExBatchRow $row): bool
    {
        return $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_GENERATED
            && filled($row->generated_pdf_storage_path)
            && ! $row->isSkippedDuplicate()
            && ! $row->receiptJobIsBusy();
    }

    private function ensureRowGenerationQueued(Form1702ExBatchRow $row): void
    {
        if ($row->pdf_status === Form1702ExBatchRow::PDF_STATUS_GENERATED
            && filled($row->generated_pdf_storage_path)) {
            return;
        }

        if ($row->isProcessing()) {
            return;
        }

        $row->forceFill([
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_QUEUED,
            'pdf_error' => null,
        ])->save();

        ProcessForm1702ExBatchRows::dispatch([(int) $row->getKey()])->afterCommit();
    }

    private function normalizeTin(mixed $tin): ?string
    {
        $digits = preg_replace('/\D+/', '', trim((string) $tin));
        $digits = is_string($digits) ? $digits : '';

        return $digits !== '' ? $digits : null;
    }

    private function supportsFormType(?string $formType): bool
    {
        $normalized = strtoupper(trim((string) $formType));

        return in_array($normalized, ['1702EX', '1702EXV2018C'], true);
    }

    private function rowIsEligibleForMatch(Form1702ExBatchRow $row): bool
    {
        if ($row->isSkippedDuplicate()) {
            return false;
        }

        if (
            ! $row->receipt_is_temporary
            && (filled($row->receipt_storage_path) || filled($row->receipt_file_name))
        ) {
            return false;
        }

        if ($row->receiptJobIsBusy()) {
            return false;
        }

        return ! in_array($row->auto_receipt_status, [
            self::MATCH_STATUS_PENDING_PDF,
            self::MATCH_STATUS_QUEUED,
            self::MATCH_STATUS_APPLIED,
        ], true);
    }

    private function skipLaterDuplicateRows(Form1702ExBatchRow $row): void
    {
        $row->loadMissing('batch');

        $payload = is_array($row->payload) ? $row->payload : [];
        $normalizedTin = $this->normalizeTin($payload['tin'] ?? null);

        if ($normalizedTin === null) {
            return;
        }

        $completedAt = $row->generated_at ?? now();

        Form1702ExBatchRow::query()
            ->with('batch')
            ->whereKeyNot($row->getKey())
            ->whereHas('batch', fn ($query) => $query->where('user_id', $row->batch->user_id))
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->get()
            ->filter(function (Form1702ExBatchRow $candidate) use ($normalizedTin, $row): bool {
                if ($candidate->isSkippedDuplicate()) {
                    return false;
                }

                $candidatePayload = is_array($candidate->payload) ? $candidate->payload : [];

                if ($this->normalizeTin($candidatePayload['tin'] ?? null) !== $normalizedTin) {
                    return false;
                }

                if (! $this->rowWasUploadedAfter($candidate, $row)) {
                    return false;
                }

                return ! filled($candidate->receipt_storage_path)
                    && ! filled($candidate->receipt_file_name);
            })
            ->each(function (Form1702ExBatchRow $candidate) use ($completedAt, $row): void {
                $candidate->forceFill([
                    'duplicate_resolution_status' => Form1702ExBatchRow::DUPLICATE_RESOLUTION_SKIPPED,
                    'duplicate_of_form_1702_ex_batch_row_id' => $row->getKey(),
                    'duplicate_resolved_at' => $completedAt,
                    'auto_receipt_synced_email_id' => null,
                    'auto_receipt_status' => null,
                    'auto_receipt_error' => null,
                ])->save();
            });
    }

    private function rowWasUploadedAfter(Form1702ExBatchRow $candidate, Form1702ExBatchRow $anchor): bool
    {
        $candidateUploadedAt = $candidate->uploaded_at;
        $anchorUploadedAt = $anchor->uploaded_at;

        if ($candidateUploadedAt instanceof Carbon && $anchorUploadedAt instanceof Carbon) {
            if ($candidateUploadedAt->gt($anchorUploadedAt)) {
                return true;
            }

            if ($candidateUploadedAt->lt($anchorUploadedAt)) {
                return false;
            }
        }

        return (int) $candidate->getKey() > (int) $anchor->getKey();
    }

    private function receiptPassesAcceptanceStartDate(
        Form1702ExBatchRow $row,
        ?string $dateReceivedByBir = null,
    ): bool {
        $startDate = $this->acceptanceStartDate($row);

        if (! $startDate instanceof Carbon) {
            return true;
        }

        $receiptDate = $this->receiptDate($dateReceivedByBir);

        if (! $receiptDate instanceof Carbon) {
            return false;
        }

        return $receiptDate->greaterThanOrEqualTo($startDate);
    }

    private function acceptanceStartDate(Form1702ExBatchRow $row): ?Carbon
    {
        $row->loadMissing('batch');
        $payload = is_array($row->payload) ? $row->payload : [];
        $candidate = $payload['receipt_acceptance_start_date'] ?? $row->batch?->receipt_acceptance_start_date;

        if ($candidate instanceof Carbon) {
            return $candidate->copy()->startOfDay();
        }

        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            return null;
        }

        try {
            return Carbon::parse($candidate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function receiptDate(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['d F Y', 'j F Y', 'Y-m-d', 'm/d/Y', 'n/j/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->startOfDay();
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function claimEmailForUser(SyncedEmail $email, int $userId): ?SyncedEmail
    {
        return DB::transaction(function () use ($email, $userId): ?SyncedEmail {
            $lockedEmail = SyncedEmail::query()
                ->with('claimedByUser')
                ->whereKey($email->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedEmail instanceof SyncedEmail) {
                return null;
            }

            if ($lockedEmail->claimed_by_user_id !== null && (int) $lockedEmail->claimed_by_user_id !== $userId) {
                return null;
            }

            if ($lockedEmail->claimed_by_user_id === null) {
                $lockedEmail->forceFill([
                    'claimed_by_user_id' => $userId,
                    'claimed_at' => now(),
                ])->save();
            }

            return $lockedEmail;
        });
    }

    private function touchClaim(SyncedEmail $email, int $userId): void
    {
        $email->forceFill([
            'claimed_by_user_id' => $userId,
            'claimed_at' => $email->claimed_at ?? now(),
        ])->save();
    }
}
