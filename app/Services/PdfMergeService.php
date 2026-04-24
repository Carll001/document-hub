<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Form1702ExBatchRow;
use App\Models\MergedPdf;
use App\Models\DocMergeBatch;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use setasign\Fpdi\Fpdi;

class PdfMergeService
{
    private const FOOTER_REPLACEMENT_HEIGHT = 14.0;

    public function __construct(
        private readonly PdfTinExtractorService $pdfTinExtractorService,
    ) {
    }

    /**
     * Merge the uploaded PDFs in order and persist the merged file.
     *
     * @param  list<UploadedFile|array{path: string, displayName: string}>  $sources
     */
    public function merge(
        User $user,
        array $sources,
        ?string $outputName = null,
        ?string $footerText = null,
        ?DocMergeBatch $batch = null,
    ): MergedPdf
    {
        $normalizedSources = $this->normalizeSources($sources);

        if (count($normalizedSources) < 2) {
            throw new RuntimeException('Select at least two PDF files to merge.');
        }

        $disk = \App\Support\DocumentStorage::disk();
        $normalizedOutputName = $this->normalizedOutputName($outputName);
        $normalizedFooterText = $this->normalizeFooterText($footerText);
        $tinNumber = $this->pdfTinExtractorService->extractTinNumber($normalizedSources);
        $temporaryBaseOutputPath = storage_path('app/tmp/doc-merge-base-'.Str::uuid().'.pdf');
        $temporaryVisibleOutputPath = $normalizedFooterText !== null
            ? storage_path('app/tmp/doc-merge-visible-'.Str::uuid().'.pdf')
            : null;
        $storagePath = sprintf(
            'doc-merge/%d/%s-%s',
            $user->id,
            Str::uuid(),
            $this->safeOutputFilename($normalizedOutputName),
        );
        $mergedPdf = null;

        if (! is_dir(dirname($temporaryBaseOutputPath))) {
            mkdir(dirname($temporaryBaseOutputPath), 0777, true);
        }

        try {
            $this->writeMergedPdf($normalizedSources, $temporaryBaseOutputPath);

            if (! is_file($temporaryBaseOutputPath)) {
                throw new RuntimeException('The merged PDF could not be created.');
            }

            $visibleOutputPath = $temporaryBaseOutputPath;

            if ($temporaryVisibleOutputPath !== null) {
                $this->writeMergedPdf([
                    [
                        'path' => $temporaryBaseOutputPath,
                        'displayName' => $normalizedOutputName,
                    ],
                ], $temporaryVisibleOutputPath, $normalizedFooterText);

                if (! is_file($temporaryVisibleOutputPath)) {
                    throw new RuntimeException('The merged PDF footer could not be created.');
                }

                $visibleOutputPath = $temporaryVisibleOutputPath;
            }

            $sourceFileNames = array_map(
                static fn (array $source): string => $source['displayName'],
                $normalizedSources,
            );

            $this->storePdfFromPath(
                $disk,
                $storagePath,
                $visibleOutputPath,
                'The merged PDF could not be stored.',
            );

            $mergedPdf = MergedPdf::query()->create([
                'user_id' => $user->id,
                'doc_merge_batch_id' => $batch?->id,
                'file_name' => $normalizedOutputName,
                'storage_path' => $storagePath,
                'file_size' => $disk->size($storagePath),
                'source_count' => count($sourceFileNames),
                'source_file_names' => $sourceFileNames,
                'tin_number' => $tinNumber,
                'footer_text' => $normalizedFooterText,
            ]);

            $this->storePdfFromPath(
                $disk,
                $this->receiptBaseStoragePath($mergedPdf),
                $temporaryBaseOutputPath,
                'The merged PDF base copy could not be stored.',
            );

            return $mergedPdf;
        } catch (\Throwable $exception) {
            if ($mergedPdf instanceof MergedPdf) {
                $mergedPdf->delete();
            } elseif ($disk->exists($storagePath)) {
                $disk->delete($storagePath);
            }

            throw new RuntimeException(
                'One or more PDFs could not be merged. Unsupported, encrypted, or malformed PDF files are not supported by the current merge engine.',
                previous: $exception,
            );
        } finally {
            if (is_file($temporaryBaseOutputPath)) {
                @unlink($temporaryBaseOutputPath);
            }

            if ($temporaryVisibleOutputPath !== null && is_file($temporaryVisibleOutputPath)) {
                @unlink($temporaryVisibleOutputPath);
            }
        }
    }

    /**
     * Append or replace the receipt pages on an existing merged PDF.
     */
    public function attachReceipt(MergedPdf $mergedPdf, string $receiptPath): void
    {
        $disk = \App\Support\DocumentStorage::disk();
        $normalizedFooterText = $this->normalizeFooterText($mergedPdf->footer_text);

        if (! $disk->exists($mergedPdf->storage_path)) {
            throw new RuntimeException("The saved PDF {$mergedPdf->file_name} is no longer available.");
        }

        $baseStoragePath = $this->ensureReceiptBaseExists(
            $disk,
            $mergedPdf,
            $normalizedFooterText,
        );
        $temporaryMergedOutputPath = storage_path('app/tmp/doc-merge-receipt-'.Str::uuid().'.pdf');
        $temporaryVisibleOutputPath = $normalizedFooterText !== null
            ? storage_path('app/tmp/doc-merge-receipt-visible-'.Str::uuid().'.pdf')
            : null;
        $temporaryReceiptPdfPath = null;

        try {
            $temporaryReceiptPdfPath = $this->normalizeReceiptSource($receiptPath);

            $this->writeMergedPdf([
                [
                    'path' => $disk->path($baseStoragePath),
                    'displayName' => $mergedPdf->file_name,
                ],
                [
                    'path' => $temporaryReceiptPdfPath,
                    'displayName' => basename($receiptPath),
                ],
            ], $temporaryMergedOutputPath);

            $visibleOutputPath = $temporaryMergedOutputPath;

            if ($temporaryVisibleOutputPath !== null) {
                $this->writeMergedPdf([
                    [
                        'path' => $temporaryMergedOutputPath,
                        'displayName' => $mergedPdf->file_name,
                    ],
                ], $temporaryVisibleOutputPath, $normalizedFooterText);

                $visibleOutputPath = $temporaryVisibleOutputPath;
            }

            $this->storePdfFromPath(
                $disk,
                $mergedPdf->storage_path,
                $visibleOutputPath,
                'The updated merged PDF could not be stored.',
            );
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'The receipt could not be appended to the merged PDF.',
                previous: $exception,
            );
        } finally {
            if ($temporaryReceiptPdfPath !== null && $temporaryReceiptPdfPath !== $receiptPath && is_file($temporaryReceiptPdfPath)) {
                @unlink($temporaryReceiptPdfPath);
            }

            if (is_file($temporaryMergedOutputPath)) {
                @unlink($temporaryMergedOutputPath);
            }

            if ($temporaryVisibleOutputPath !== null && is_file($temporaryVisibleOutputPath)) {
                @unlink($temporaryVisibleOutputPath);
            }
        }
    }

    /**
     * Append or replace the receipt pages on an existing 1702-EX row PDF.
     */
    public function attachForm1702ExReceipt(Form1702ExBatchRow $row, string $receiptPath): void
    {
        $disk = \App\Support\DocumentStorage::disk();
        $generatedPdfPath = (string) ($row->generated_pdf_storage_path ?? '');

        if ($generatedPdfPath === '' || ! $disk->exists($generatedPdfPath)) {
            throw new RuntimeException('The saved 1702-EX PDF is no longer available.');
        }

        $baseStoragePath = $this->ensureForm1702ExReceiptBaseExists($disk, $row);
        $temporaryMergedOutputPath = storage_path('app/tmp/form-1702-ex-receipt-'.Str::uuid().'.pdf');
        $temporaryReceiptPdfPath = null;

        try {
            $temporaryReceiptPdfPath = $this->normalizeReceiptSource($receiptPath);

            $this->writeMergedPdf([
                [
                    'path' => $disk->path($baseStoragePath),
                    'displayName' => (string) ($row->generated_pdf_file_name ?? '1702-ex.pdf'),
                ],
                [
                    'path' => $temporaryReceiptPdfPath,
                    'displayName' => basename($receiptPath),
                ],
            ], $temporaryMergedOutputPath);

            $this->storePdfFromPath(
                $disk,
                $generatedPdfPath,
                $temporaryMergedOutputPath,
                'The updated 1702-EX PDF could not be stored.',
            );
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'The receipt could not be appended to the 1702-EX PDF.',
                previous: $exception,
            );
        } finally {
            if ($temporaryReceiptPdfPath !== null && $temporaryReceiptPdfPath !== $receiptPath && is_file($temporaryReceiptPdfPath)) {
                @unlink($temporaryReceiptPdfPath);
            }

            if (is_file($temporaryMergedOutputPath)) {
                @unlink($temporaryMergedOutputPath);
            }
        }
    }

    /**
     * Remove the receipt pages from an existing merged PDF and restore its base copy.
     */
    public function removeReceipt(MergedPdf $mergedPdf): void
    {
        $disk = \App\Support\DocumentStorage::disk();
        $baseStoragePath = $this->receiptBaseStoragePath($mergedPdf);
        $normalizedFooterText = $this->normalizeFooterText($mergedPdf->footer_text);

        if (! $disk->exists($baseStoragePath)) {
            throw new RuntimeException('The original merged PDF could not be restored.');
        }

        if ($normalizedFooterText === null) {
            $this->storePdfFromPath(
                $disk,
                $mergedPdf->storage_path,
                $disk->path($baseStoragePath),
                'The original merged PDF could not be restored.',
            );

            return;
        }

        $temporaryVisibleOutputPath = storage_path('app/tmp/doc-merge-restore-visible-'.Str::uuid().'.pdf');

        try {
            $this->writeMergedPdf([
                [
                    'path' => $disk->path($baseStoragePath),
                    'displayName' => $mergedPdf->file_name,
                ],
            ], $temporaryVisibleOutputPath, $normalizedFooterText);

            $this->storePdfFromPath(
                $disk,
                $mergedPdf->storage_path,
                $temporaryVisibleOutputPath,
                'The original merged PDF could not be restored.',
            );
        } finally {
            if (is_file($temporaryVisibleOutputPath)) {
                @unlink($temporaryVisibleOutputPath);
            }
        }
    }

    /**
     * Remove the receipt pages from an existing 1702-EX row PDF and restore its base copy.
     */
    public function removeForm1702ExReceipt(Form1702ExBatchRow $row): void
    {
        $disk = \App\Support\DocumentStorage::disk();
        $generatedPdfPath = (string) ($row->generated_pdf_storage_path ?? '');
        $baseStoragePath = $row->receiptBaseStoragePath();

        if ($generatedPdfPath === '' || ! $disk->exists($generatedPdfPath)) {
            throw new RuntimeException('The saved 1702-EX PDF is no longer available.');
        }

        if (! $disk->exists($baseStoragePath)) {
            throw new RuntimeException('The original 1702-EX PDF could not be restored.');
        }

        $this->storePdfFromPath(
            $disk,
            $generatedPdfPath,
            $disk->path($baseStoragePath),
            'The original 1702-EX PDF could not be restored.',
        );
    }

    /**
     * Normalize upload and stored PDF sources into filesystem paths.
     *
     * @param  list<UploadedFile|array{path: string, displayName: string}>  $sources
     * @return list<array{path: string, displayName: string}>
     */
    private function normalizeSources(array $sources): array
    {
        return array_map(function (UploadedFile|array $source): array {
            if ($source instanceof UploadedFile) {
                $path = $source->getRealPath();

                if ($path === false || ! is_file($path)) {
                    throw new RuntimeException('One of the uploaded PDFs is no longer available.');
                }

                return [
                    'path' => $path,
                    'displayName' => $source->getClientOriginalName(),
                ];
            }

            if (
                ! isset($source['path'], $source['displayName'])
                || ! is_string($source['path'])
                || ! is_string($source['displayName'])
                || ! is_file($source['path'])
            ) {
                throw new RuntimeException('One of the selected PDF sources is no longer available.');
            }

            return $source;
        }, $sources);
    }

    /**
     * Merge PDF files into a single output path.
     *
     * @param  list<array{path: string, displayName: string}>  $normalizedSources
     */
    private function writeMergedPdf(
        array $normalizedSources,
        string $outputPath,
        ?string $footerText = null,
    ): void
    {
        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0777, true);
        }

        $pdf = new Fpdi;
        $normalizedFooterText = $this->normalizeFooterText($footerText);

        foreach ($normalizedSources as $source) {
            $pageCount = $pdf->setSourceFile($source['path']);

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($template);

                if ($normalizedFooterText !== null) {
                    $this->drawFooter(
                        $pdf,
                        $normalizedFooterText,
                        (float) $size['width'],
                        (float) $size['height'],
                    );
                }
            }
        }

        $pdf->Output('F', $outputPath);
    }

    private function drawFooter(
        Fpdi $pdf,
        string $footerText,
        float $pageWidth,
        float $pageHeight,
    ): void {
        $encodedFooterText = $this->encodedPdfText($footerText);

        if ($encodedFooterText === '') {
            return;
        }

        $replacementHeight = min(self::FOOTER_REPLACEMENT_HEIGHT, max(0.0, $pageHeight));

        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(
            0,
            max(0.0, $pageHeight - $replacementHeight),
            max(0.0, $pageWidth),
            $replacementHeight,
            'F',
        );

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(110, 110, 110);
        $pdf->SetXY(8, max(0.0, $pageHeight - 10));
        $pdf->Cell(max(0.0, $pageWidth - 16), 4, $encodedFooterText, 0, 0, 'C');
    }

    /**
     * Normalize a receipt into a PDF file path for appending.
     */
    private function normalizeReceiptSource(string $receiptPath): string
    {
        $extension = Str::of(pathinfo($receiptPath, PATHINFO_EXTENSION))
            ->lower()
            ->value();

        if ($extension === 'pdf') {
            return $receiptPath;
        }

        return $this->convertReceiptImageToPdf($receiptPath);
    }

    /**
     * Convert an uploaded image receipt into a single-page PDF.
     */
    private function convertReceiptImageToPdf(string $imagePath): string
    {
        if (! class_exists(\Imagick::class)) {
            throw new RuntimeException('Image receipts are not supported on this server right now.');
        }

        $temporaryPngPath = storage_path('app/tmp/doc-merge-receipt-image-'.Str::uuid().'.png');
        $temporaryPdfPath = storage_path('app/tmp/doc-merge-receipt-image-'.Str::uuid().'.pdf');

        if (! is_dir(dirname($temporaryPngPath))) {
            mkdir(dirname($temporaryPngPath), 0777, true);
        }

        try {
            $imagick = new \Imagick;
            $imagick->readImage($imagePath.'[0]');
            $imagick->setImageBackgroundColor('white');
            $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $imagick->setImageFormat('png');
            $imagick->writeImage($temporaryPngPath);
            $imagick->clear();
            $imagick->destroy();

            $dimensions = @getimagesize($temporaryPngPath);

            if (
                $dimensions === false
                || ! isset($dimensions[0], $dimensions[1])
                || $dimensions[0] <= 0
                || $dimensions[1] <= 0
            ) {
                throw new RuntimeException('The receipt image dimensions could not be read.');
            }

            $width = (float) $dimensions[0];
            $height = (float) $dimensions[1];
            $orientation = $width > $height ? 'L' : 'P';
            $pdf = new \FPDF($orientation, 'pt', [$width, $height]);

            $pdf->AddPage($orientation, [$width, $height]);
            $pdf->Image($temporaryPngPath, 0, 0, $width, $height, 'PNG');
            $pdf->Output('F', $temporaryPdfPath);

            if (! is_file($temporaryPdfPath)) {
                throw new RuntimeException('The receipt PDF could not be created.');
            }

            return $temporaryPdfPath;
        } finally {
            if (is_file($temporaryPngPath)) {
                @unlink($temporaryPngPath);
            }
        }
    }

    /**
     * Determine the hidden base PDF path used for receipt replacements.
     */
    private function receiptBaseStoragePath(MergedPdf $mergedPdf): string
    {
        return sprintf(
            'doc-merge/%d/receipt-bases/%d/base-%s',
            $mergedPdf->user_id,
            $mergedPdf->id,
            $this->safeOutputFilename($mergedPdf->file_name),
        );
    }

    /**
     * Normalize the output filename and ensure it ends in .pdf.
     */
    private function normalizedOutputName(?string $outputName): string
    {
        $outputName = trim((string) $outputName);

        if ($outputName === '') {
            $outputName = 'merged-document-'.now()->format('Ymd-His');
        }

        if (! Str::of($outputName)->lower()->endsWith('.pdf')) {
            $outputName .= '.pdf';
        }

        return $outputName;
    }

    /**
     * Build a storage-safe filename for the merged PDF.
     */
    private function safeOutputFilename(string $fileName): string
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
            $baseName = 'merged-document';
        }

        return $extension !== ''
            ? "{$baseName}.{$extension}"
            : "{$baseName}.pdf";
    }

    private function normalizeFooterText(?string $footerText): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $footerText)) ?? '';

        return $normalized !== '' ? $normalized : null;
    }

    private function encodedPdfText(string $value): string
    {
        $encoded = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);

        if ($encoded === false) {
            return preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        }

        return $encoded;
    }

    private function storePdfFromPath(
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

    private function ensureReceiptBaseExists(
        FilesystemAdapter $disk,
        MergedPdf $mergedPdf,
        ?string $normalizedFooterText,
    ): string {
        $baseStoragePath = $this->receiptBaseStoragePath($mergedPdf);

        if ($disk->exists($baseStoragePath)) {
            return $baseStoragePath;
        }

        if ($normalizedFooterText !== null) {
            throw new RuntimeException('The saved PDF base copy is no longer available.');
        }

        $disk->makeDirectory(dirname($baseStoragePath));

        if (
            ! $disk->copy($mergedPdf->storage_path, $baseStoragePath)
            || ! $disk->exists($baseStoragePath)
        ) {
            throw new RuntimeException('The saved PDF could not be prepared for receipt updates.');
        }

        return $baseStoragePath;
    }

    private function ensureForm1702ExReceiptBaseExists(
        FilesystemAdapter $disk,
        Form1702ExBatchRow $row,
    ): string {
        $generatedPdfPath = (string) ($row->generated_pdf_storage_path ?? '');
        $baseStoragePath = $row->receiptBaseStoragePath();

        if ($generatedPdfPath === '' || ! $disk->exists($generatedPdfPath)) {
            throw new RuntimeException('The saved 1702-EX PDF is no longer available.');
        }

        if ($disk->exists($baseStoragePath)) {
            return $baseStoragePath;
        }

        $disk->makeDirectory(dirname($baseStoragePath));

        if (
            ! $disk->copy($generatedPdfPath, $baseStoragePath)
            || ! $disk->exists($baseStoragePath)
        ) {
            throw new RuntimeException('The 1702-EX PDF could not be prepared for receipt updates.');
        }

        return $baseStoragePath;
    }

}
