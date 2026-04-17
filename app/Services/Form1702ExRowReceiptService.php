<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Form1702ExBatchRow;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class Form1702ExRowReceiptService
{
    public function __construct(
        private readonly Form1702ExService $form1702ExService,
        private readonly PdfMergeService $pdfMergeService,
    ) {
    }

    /**
     * @param  array<string, scalar|null>  $submittedValues
     */
    public function generateAndAttachReceipt(
        Form1702ExBatchRow $row,
        array $submittedValues,
    ): void {
        $row->loadMissing('batch');
        $disk = Storage::disk('local');
        $generatedPdfPath = (string) ($row->generated_pdf_storage_path ?? '');

        if ($generatedPdfPath === '' || ! $disk->exists($generatedPdfPath)) {
            throw new RuntimeException('Generate the 1702-EX PDF before adding a receipt.');
        }

        $outputName = $this->normalizedReceiptOutputName($row);
        $storagePath = $this->receiptStoragePath(
            $row,
            $this->safePdfFilename($outputName, 'receipt'),
        );
        $previousReceiptPath = $row->receipt_storage_path;
        $temporaryReceiptPdfPath = storage_path(
            'app/tmp/form-1702-ex-generated-receipt-'.Str::uuid().'.pdf',
        );

        try {
            $this->form1702ExService->renderReceiptPdf(
                $temporaryReceiptPdfPath,
                $this->resolveReceiptValues($submittedValues),
            );

            $this->storeFileFromPath(
                $disk,
                $storagePath,
                $temporaryReceiptPdfPath,
                'The generated 1702-EX receipt PDF could not be stored.',
            );

            $this->pdfMergeService->attachForm1702ExReceipt($row, $disk->path($storagePath));

            $row->forceFill([
                'receipt_file_name' => $outputName,
                'receipt_storage_path' => $storagePath,
                'receipt_file_size' => $disk->size($storagePath),
                'receipt_is_temporary' => false,
            ])->save();

            if (
                filled($previousReceiptPath)
                && $previousReceiptPath !== $storagePath
                && $disk->exists($previousReceiptPath)
            ) {
                $disk->delete($previousReceiptPath);
            }
        } catch (RuntimeException $exception) {
            if (
                $storagePath !== $previousReceiptPath
                && $disk->exists($storagePath)
            ) {
                $disk->delete($storagePath);
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if (
                $storagePath !== $previousReceiptPath
                && $disk->exists($storagePath)
            ) {
                $disk->delete($storagePath);
            }

            throw new RuntimeException(
                'The 1702-EX receipt could not be generated right now. Please try again.',
                previous: $exception,
            );
        } finally {
            if (is_file($temporaryReceiptPdfPath)) {
                @unlink($temporaryReceiptPdfPath);
            }
        }
    }

    public function attachTemporaryReceipt(
        Form1702ExBatchRow $row,
        UploadedFile $uploadedReceipt,
    ): void {
        $row->loadMissing('batch');
        $disk = Storage::disk('local');
        $generatedPdfPath = (string) ($row->generated_pdf_storage_path ?? '');

        if ($generatedPdfPath === '' || ! $disk->exists($generatedPdfPath)) {
            throw new RuntimeException('Generate the 1702-EX PDF before adding a temporary receipt.');
        }

        $outputName = $this->normalizedTemporaryReceiptOutputName($uploadedReceipt);
        $storagePath = $this->receiptStoragePath(
            $row,
            $this->safeUploadedReceiptFilename(
                $outputName,
                'temporary-receipt',
            ),
        );
        $previousReceiptPath = $row->receipt_storage_path;

        try {
            $stored = $disk->putFileAs(
                dirname($storagePath),
                $uploadedReceipt,
                basename($storagePath),
            );

            if (! is_string($stored) || $stored === '' || ! $disk->exists($storagePath)) {
                throw new RuntimeException('The temporary receipt file could not be stored.');
            }

            $this->pdfMergeService->attachForm1702ExReceipt($row, $disk->path($storagePath));

            $row->forceFill([
                'receipt_file_name' => $outputName,
                'receipt_storage_path' => $storagePath,
                'receipt_file_size' => $disk->size($storagePath),
                'receipt_is_temporary' => true,
                'receipt_job_status' => null,
                'receipt_job_error' => null,
            ])->save();

            if (
                filled($previousReceiptPath)
                && $previousReceiptPath !== $storagePath
                && $disk->exists($previousReceiptPath)
            ) {
                $disk->delete($previousReceiptPath);
            }
        } catch (\Throwable $exception) {
            if (
                $storagePath !== $previousReceiptPath
                && $disk->exists($storagePath)
            ) {
                $disk->delete($storagePath);
            }

            throw new RuntimeException(
                'The temporary receipt could not be attached right now. Please try again.',
                previous: $exception,
            );
        }
    }

    public function removeReceipt(Form1702ExBatchRow $row): void
    {
        $disk = Storage::disk('local');
        $previousReceiptPath = $row->receipt_storage_path;

        $this->pdfMergeService->removeForm1702ExReceipt($row);
        $this->form1702ExService->clearReceipt($row);

        if (filled($previousReceiptPath) && $disk->exists($previousReceiptPath)) {
            $disk->delete($previousReceiptPath);
        }
    }

    /**
     * @param  array<string, scalar|null>  $submittedValues
     * @return array<string, string>
     */
    private function resolveReceiptValues(array $submittedValues): array
    {
        $resolvedValues = [];

        foreach ($this->form1702ExService->receiptInputFields() as $field) {
            $key = $field['key'];
            $resolvedValues[$key] = (string) ($submittedValues[$key] ?? '');
        }

        return $resolvedValues;
    }

    private function normalizedReceiptOutputName(Form1702ExBatchRow $row): string
    {
        $baseName = (string) Str::of((string) ($row->generated_pdf_file_name ?? '1702-ex.pdf'))
            ->beforeLast('.')
            ->value();

        return "{$baseName}-receipt.pdf";
    }

    private function receiptStoragePath(Form1702ExBatchRow $row, string $safeFileName): string
    {
        $row->loadMissing('batch');

        return sprintf(
            'forms/%d/%s/receipts/%d/%s-%s',
            $row->batch->user_id,
            Form1702ExService::FORM_KEY,
            $row->form_1702_ex_batch_id,
            Str::uuid(),
            $safeFileName,
        );
    }

    private function safePdfFilename(string $fileName, string $fallbackBaseName): string
    {
        $extension = Str::of(pathinfo($fileName, PATHINFO_EXTENSION))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();

        $baseName = Str::of(pathinfo($fileName, PATHINFO_FILENAME))
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-._')
            ->value();

        if ($baseName === '') {
            $baseName = $fallbackBaseName;
        }

        if ($extension !== 'pdf') {
            $extension = 'pdf';
        }

        return "{$baseName}.{$extension}";
    }

    private function normalizedTemporaryReceiptOutputName(UploadedFile $uploadedReceipt): string
    {
        $originalName = trim((string) $uploadedReceipt->getClientOriginalName());

        if ($originalName !== '') {
            return $originalName;
        }

        $extension = trim((string) $uploadedReceipt->getClientOriginalExtension());

        return $extension !== ''
            ? "temporary-receipt.{$extension}"
            : 'temporary-receipt.pdf';
    }

    private function safeUploadedReceiptFilename(string $fileName, string $fallbackBaseName): string
    {
        $extension = Str::of(pathinfo($fileName, PATHINFO_EXTENSION))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();
        $baseName = Str::of(pathinfo($fileName, PATHINFO_FILENAME))
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-._')
            ->value();

        if ($baseName === '') {
            $baseName = $fallbackBaseName;
        }

        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'pdf';
        }

        return "{$baseName}.{$extension}";
    }

    private function storeFileFromPath(
        FilesystemAdapter $disk,
        string $storagePath,
        string $sourcePath,
        string $errorMessage,
    ): void {
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException($errorMessage);
        }

        try {
            $stored = $disk->put($storagePath, $stream);
        } finally {
            fclose($stream);
        }

        if ($stored !== true || ! $disk->exists($storagePath)) {
            throw new RuntimeException($errorMessage);
        }
    }
}
