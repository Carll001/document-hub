<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\Form1702ExCompletedRowEmail;
use App\Models\Form1702ExBatchRow;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class Form1702ExCompletedEmailService
{
    public function isCompleted(Form1702ExBatchRow $row): bool
    {
        return $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_GENERATED
            && filled($row->generated_pdf_storage_path)
            && filled($row->receipt_storage_path)
            && filled($row->receipt_file_name);
    }

    public function recipientEmail(Form1702ExBatchRow $row): ?string
    {
        $payload = is_array($row->payload) ? $row->payload : [];
        $email = $this->normalizeRecipientEmail((string) ($payload['email_address'] ?? ''));

        return $email !== '' ? $email : null;
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

    public function defaultSubject(Form1702ExBatchRow $row): string
    {
        return sprintf(
            'Completed 1702-EX File - %s',
            (string) ($row->generated_pdf_file_name ?? '1702-ex.pdf'),
        );
    }

    public function defaultMessage(Form1702ExBatchRow $row): string
    {
        $payload = is_array($row->payload) ? $row->payload : [];
        $name = (string) ($payload['taxpayer_name'] ?? $payload['registered_name'] ?? 'Taxpayer');

        return "Attached is the completed 1702-EX PDF for {$name}.";
    }

    private function generatedPdfExists(Form1702ExBatchRow $row): bool
    {
        $generatedPdfPath = (string) ($row->generated_pdf_storage_path ?? '');

        return $generatedPdfPath !== '' && Storage::disk('local')->exists($generatedPdfPath);
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

    private function normalizeRecipientEmail(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
