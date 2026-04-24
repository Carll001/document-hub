<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\Form1702ExCompletedRowsEmail;
use App\Models\Client;
use App\Mail\Form1702ExCompletedRowEmail;
use App\Models\Form1702ExBatchRow;
use App\Support\Form1702ExRecipientEmailNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class Form1702ExCompletedEmailService
{
    private const DEFAULT_FORM_TYPE = '1702-EX';

    private const DEFAULT_EMAIL_FOOTER =
        'Please do not reply to this message. If you have any concerns, please contact:';

    public function __construct(
        private readonly Form1702ExRecipientEmailNormalizer $recipientEmailNormalizer,
    ) {
    }

    public function isCompleted(Form1702ExBatchRow $row): bool
    {
        return $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_GENERATED
            && filled($row->generated_pdf_storage_path)
            && filled($row->receipt_storage_path)
            && filled($row->receipt_file_name)
            && ! $row->receipt_is_temporary;
    }

    public function recipientEmail(Form1702ExBatchRow $row): ?string
    {
        return $this->recipientEmailNormalizer->normalize($row->completed_email_recipient);
    }

    public function queueManual(
        Form1702ExBatchRow $row,
        ?string $subject = null,
        ?string $message = null,
        ?string $extraAttachmentStoragePath = null,
        ?string $extraAttachmentFileName = null,
        ?string $extraAttachmentMimeType = null,
    ): ?string {
        $recipientEmail = $this->recipientEmail($row);

        if ($recipientEmail === null || ! $this->generatedPdfExists($row) || $row->isSkippedDuplicate()) {
            return null;
        }

        Mail::to($recipientEmail)->queue(
            (new Form1702ExCompletedRowEmail(
                $row,
                $subject ?? $this->defaultSubject($row),
                $message ?? $this->defaultMessage($row),
                $extraAttachmentStoragePath,
                $extraAttachmentFileName,
                $extraAttachmentMimeType,
            ))->afterCommit(),
        );

        return $recipientEmail;
    }

    public function queueAutomaticIfNeeded(Form1702ExBatchRow $row): bool
    {
        $row->loadMissing('batch');
        $recipientEmail = $this->recipientEmail($row);

        if (
            $recipientEmail === null
            || ! $this->isCompleted($row)
            || ! $this->generatedPdfExists($row)
            || $row->isSkippedDuplicate()
        ) {
            return false;
        }

        $signature = $this->completionSignature($row, $recipientEmail);

        if ($signature === null) {
            return false;
        }

        if (
            hash_equals((string) ($row->completed_email_auto_hash ?? ''), $signature)
            && $row->completed_email_auto_queued_at !== null
        ) {
            return false;
        }

        Mail::to($recipientEmail)->queue(
            (new Form1702ExCompletedRowEmail(
                $row,
                $this->defaultSubject($row),
                $this->defaultMessage($row),
            ))->afterCommit(),
        );

        $row->forceFill([
            'completed_email_auto_hash' => $signature,
            'completed_email_auto_recipient' => $recipientEmail,
            'completed_email_auto_queued_at' => now(),
        ])->save();

        return true;
    }

    /**
     * @param  Collection<int, Form1702ExBatchRow>  $rows
     */
    public function queueClientBulk(Client $client, Collection $rows): ?string
    {
        $eligibleRows = $rows
            ->filter(fn (Form1702ExBatchRow $row): bool => $this->isCompleted($row))
            ->filter(fn (Form1702ExBatchRow $row): bool => ! $row->isSkippedDuplicate())
            ->filter(fn (Form1702ExBatchRow $row): bool => $this->generatedPdfExists($row))
            ->values();

        if ($eligibleRows->isEmpty()) {
            return null;
        }

        $recipientEmails = $eligibleRows
            ->map(fn (Form1702ExBatchRow $row): ?string => $this->recipientEmail($row))
            ->filter()
            ->unique()
            ->values();

        if ($recipientEmails->count() !== 1) {
            return null;
        }

        $attachments = $eligibleRows
            ->map(fn (Form1702ExBatchRow $row): array => [
                'storagePath' => (string) $row->generated_pdf_storage_path,
                'fileName' => (string) $row->generated_pdf_file_name,
            ])
            ->all();

        $recipientEmail = (string) $recipientEmails->first();

        Mail::to($recipientEmail)->queue(
            (new Form1702ExCompletedRowsEmail(
                $attachments,
                $this->defaultClientSubject($client),
                $this->defaultClientMessage($client),
            ))->afterCommit(),
        );

        return $recipientEmail;
    }

    public function defaultSubject(Form1702ExBatchRow $row): string
    {
        return sprintf('1702EX - %s', $this->companyName($row));
    }

    public function defaultMessage(Form1702ExBatchRow $row): string
    {
        return implode("\n", [
            sprintf(
                'Good day! Attached is the %s with the confirmation for %s. Thank you!',
                self::DEFAULT_FORM_TYPE,
                $this->companyName($row),
            ),
            '',
            self::DEFAULT_EMAIL_FOOTER,
        ]);
    }

    public function defaultClientSubject(Client $client): string
    {
        return sprintf('1702EX - %s', $client->name);
    }

    public function defaultClientMessage(Client $client): string
    {
        return implode("\n", [
            sprintf(
                'Good day! Attached are the completed %s files for %s. Thank you!',
                self::DEFAULT_FORM_TYPE,
                $client->name,
            ),
            '',
            self::DEFAULT_EMAIL_FOOTER,
        ]);
    }

    private function generatedPdfExists(Form1702ExBatchRow $row): bool
    {
        $generatedPdfPath = (string) ($row->generated_pdf_storage_path ?? '');

        return $generatedPdfPath !== '' && \App\Support\DocumentStorage::disk()->exists($generatedPdfPath);
    }

    private function completionSignature(Form1702ExBatchRow $row, string $recipientEmail): ?string
    {
        $generatedPdfPath = (string) ($row->generated_pdf_storage_path ?? '');
        $receiptPath = (string) ($row->receipt_storage_path ?? '');

        if ($generatedPdfPath === '' || $receiptPath === '') {
            return null;
        }

        return hash('sha256', json_encode([
            'recipient' => $recipientEmail,
            'generated_pdf_path' => $generatedPdfPath,
            'generated_pdf_file_name' => (string) ($row->generated_pdf_file_name ?? ''),
            'generated_pdf_file_size' => (int) ($row->generated_pdf_file_size ?? 0),
            'generated_at' => $row->generated_at?->toIso8601String(),
            'receipt_path' => $receiptPath,
            'receipt_file_name' => (string) ($row->receipt_file_name ?? ''),
            'receipt_file_size' => (int) ($row->receipt_file_size ?? 0),
        ], JSON_THROW_ON_ERROR));
    }

    private function companyName(Form1702ExBatchRow $row): string
    {
        $payload = is_array($row->payload) ? $row->payload : [];
        $companyName = trim((string) ($payload['taxpayer_name'] ?? $payload['registered_name'] ?? ''));

        return $companyName !== '' ? $companyName : 'Taxpayer';
    }
}
