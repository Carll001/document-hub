<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MergedPdf;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;
use RuntimeException;

class MergedPdfReceiptService
{
    public function __construct(
        private readonly ConfirmationDocxService $confirmationDocxService,
        private readonly PdfMergeService $pdfMergeService,
    ) {
    }

    /**
     * Generate a receipt PDF from the shared template and attach it to a saved merged PDF.
     *
     * @param  list<string>  $placeholders
     * @param  array<string, scalar|null>  $submittedValues
     */
    public function generateAndAttachReceipt(
        MergedPdf $mergedPdf,
        string $templateStoragePath,
        array $placeholders,
        array $submittedValues,
    ): void {
        $disk = \App\Support\DocumentStorage::disk();

        if (! $disk->exists($mergedPdf->storage_path)) {
            throw new RuntimeException("The saved PDF {$mergedPdf->file_name} is no longer available.");
        }

        if (! $disk->exists($templateStoragePath)) {
            throw new RuntimeException('The shared receipt template is no longer available.');
        }

        $outputName = $this->normalizedReceiptOutputName($mergedPdf);
        $storagePath = $this->receiptStoragePath(
            $mergedPdf,
            $this->safePdfFilename($outputName, 'receipt'),
        );
        $previousReceiptPath = $mergedPdf->receipt_storage_path;
        $sourceFileNames = $this->sourceFileNamesWithReceipt(
            $mergedPdf->source_file_names,
            $mergedPdf->receipt_file_name,
            $outputName,
        );
        $temporaryReceiptPdfPath = storage_path(
            'app/tmp/doc-merge-generated-receipt-'.Str::uuid().'.pdf',
        );

        try {
            $this->confirmationDocxService->renderPdf(
                $disk->path($templateStoragePath),
                $temporaryReceiptPdfPath,
                $this->resolveTemplatePlaceholderValues(
                    $submittedValues,
                    $placeholders,
                ),
            );

            $this->storeFileFromPath(
                $disk,
                $storagePath,
                $temporaryReceiptPdfPath,
                'The generated receipt PDF could not be stored.',
            );

            $this->pdfMergeService->attachReceipt($mergedPdf, $disk->path($storagePath));

            $mergedPdf->forceFill([
                'file_size' => $disk->size($mergedPdf->storage_path),
                'source_count' => count($sourceFileNames),
                'source_file_names' => $sourceFileNames,
                'receipt_file_name' => $outputName,
                'receipt_storage_path' => $storagePath,
                'receipt_file_size' => $disk->size($storagePath),
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
                'The receipt could not be generated right now. Please try again.',
                previous: $exception,
            );
        } finally {
            if (is_file($temporaryReceiptPdfPath)) {
                @unlink($temporaryReceiptPdfPath);
            }
        }
    }

    /**
     * @param  array<string, scalar|null>  $submittedValues
     * @param  list<string>  $placeholders
     * @return array<string, string>
     */
    private function resolveTemplatePlaceholderValues(
        array $submittedValues,
        array $placeholders,
    ): array {
        $resolvedValues = [];

        foreach ($placeholders as $placeholder) {
            $resolvedValues[$placeholder] = (string) ($submittedValues[$placeholder] ?? '');
        }

        return $resolvedValues;
    }

    /**
     * Build the generated receipt filename shown in merge history.
     */
    private function normalizedReceiptOutputName(MergedPdf $mergedPdf): string
    {
        return Str::of($mergedPdf->file_name)
            ->beforeLast('.')
            ->append('-receipt.pdf')
            ->value();
    }

    /**
     * Build the stored generated receipt PDF path for a merged PDF.
     */
    private function receiptStoragePath(MergedPdf $mergedPdf, string $safeFileName): string
    {
        return sprintf(
            'doc-merge/%d/receipts/%d/%s-%s',
            $mergedPdf->user_id,
            $mergedPdf->id,
            Str::uuid(),
            $safeFileName,
        );
    }

    /**
     * Build a storage-safe PDF filename.
     */
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

    /**
     * Update the visible source list so the receipt appears as the final entry.
     *
     * @return list<string>
     */
    private function sourceFileNamesWithReceipt(
        mixed $sourceFileNames,
        ?string $previousReceiptFileName,
        string $nextReceiptFileName,
    ): array {
        $names = array_values(
            array_filter(
                is_array($sourceFileNames) ? $sourceFileNames : [],
                fn ($name): bool => is_string($name) && trim($name) !== '',
            ),
        );

        if ($previousReceiptFileName !== null) {
            $previousReceiptLabel = $this->receiptSourceLabel($previousReceiptFileName);

            $names = array_values(
                array_filter(
                    $names,
                    fn (string $name): bool => $name !== $previousReceiptLabel,
                ),
            );
        }

        $names[] = $this->receiptSourceLabel($nextReceiptFileName);

        return $names;
    }

    /**
     * Format a receipt file name for the merge-source summary.
     */
    private function receiptSourceLabel(string $fileName): string
    {
        return "Receipt: {$fileName}";
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
