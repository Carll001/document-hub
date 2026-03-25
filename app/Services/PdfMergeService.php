<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MergedPdf;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use setasign\Fpdi\Fpdi;

class PdfMergeService
{
    /**
     * Merge the uploaded PDFs in order and persist the merged file.
     *
     * @param  list<UploadedFile|array{path: string, displayName: string}>  $sources
     */
    public function merge(User $user, array $sources, ?string $outputName = null): MergedPdf
    {
        $normalizedSources = $this->normalizeSources($sources);

        if (count($normalizedSources) < 2) {
            throw new RuntimeException('Select at least two PDF files to merge.');
        }

        $disk = Storage::disk('local');
        $normalizedOutputName = $this->normalizedOutputName($outputName);
        $temporaryOutputPath = storage_path('app/tmp/doc-merge-'.Str::uuid().'.pdf');
        $storagePath = sprintf(
            'doc-merge/%d/%s-%s',
            $user->id,
            Str::uuid(),
            $this->safeOutputFilename($normalizedOutputName),
        );

        if (! is_dir(dirname($temporaryOutputPath))) {
            mkdir(dirname($temporaryOutputPath), 0777, true);
        }

        try {
            $this->writeMergedPdf($normalizedSources, $temporaryOutputPath);

            if (! is_file($temporaryOutputPath)) {
                throw new RuntimeException('The merged PDF could not be created.');
            }

            $sourceFileNames = array_map(
                static fn (array $source): string => $source['displayName'],
                $normalizedSources,
            );

            $stream = fopen($temporaryOutputPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('The merged PDF could not be stored.');
            }

            try {
                $stored = $disk->put($storagePath, $stream);
            } finally {
                fclose($stream);
            }

            if ($stored !== true || ! $disk->exists($storagePath)) {
                throw new RuntimeException('The merged PDF could not be stored.');
            }

            return MergedPdf::query()->create([
                'user_id' => $user->id,
                'file_name' => $normalizedOutputName,
                'storage_path' => $storagePath,
                'file_size' => $disk->size($storagePath),
                'source_count' => count($sourceFileNames),
                'source_file_names' => $sourceFileNames,
            ]);
        } catch (\Throwable $exception) {
            if ($disk->exists($storagePath)) {
                $disk->delete($storagePath);
            }

            throw new RuntimeException(
                'One or more PDFs could not be merged. Unsupported, encrypted, or malformed PDF files are not supported by the current merge engine.',
                previous: $exception,
            );
        } finally {
            if (is_file($temporaryOutputPath)) {
                @unlink($temporaryOutputPath);
            }
        }
    }

    /**
     * Append or replace the receipt pages on an existing merged PDF.
     */
    public function attachReceipt(MergedPdf $mergedPdf, string $receiptPath): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($mergedPdf->storage_path)) {
            throw new RuntimeException("The saved PDF {$mergedPdf->file_name} is no longer available.");
        }

        $baseStoragePath = $this->receiptBaseStoragePath($mergedPdf);

        if (! $disk->exists($baseStoragePath)) {
            $disk->makeDirectory(dirname($baseStoragePath));

            if (
                ! $disk->copy($mergedPdf->storage_path, $baseStoragePath)
                || ! $disk->exists($baseStoragePath)
            ) {
                throw new RuntimeException('The saved PDF could not be prepared for receipt updates.');
            }
        }

        $temporaryOutputPath = storage_path('app/tmp/doc-merge-receipt-'.Str::uuid().'.pdf');
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
            ], $temporaryOutputPath);

            $stream = fopen($temporaryOutputPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('The updated merged PDF could not be stored.');
            }

            try {
                $stored = $disk->put($mergedPdf->storage_path, $stream);
            } finally {
                fclose($stream);
            }

            if ($stored !== true || ! $disk->exists($mergedPdf->storage_path)) {
                throw new RuntimeException('The updated merged PDF could not be stored.');
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'The receipt could not be appended to the merged PDF.',
                previous: $exception,
            );
        } finally {
            if ($temporaryReceiptPdfPath !== null && $temporaryReceiptPdfPath !== $receiptPath && is_file($temporaryReceiptPdfPath)) {
                @unlink($temporaryReceiptPdfPath);
            }

            if (is_file($temporaryOutputPath)) {
                @unlink($temporaryOutputPath);
            }
        }
    }

    /**
     * Remove the receipt pages from an existing merged PDF and restore its base copy.
     */
    public function removeReceipt(MergedPdf $mergedPdf): void
    {
        $disk = Storage::disk('local');
        $baseStoragePath = $this->receiptBaseStoragePath($mergedPdf);

        if (! $disk->exists($baseStoragePath)) {
            throw new RuntimeException('The original merged PDF could not be restored.');
        }

        $stream = fopen($disk->path($baseStoragePath), 'rb');

        if ($stream === false) {
            throw new RuntimeException('The original merged PDF could not be restored.');
        }

        try {
            $stored = $disk->put($mergedPdf->storage_path, $stream);
        } finally {
            fclose($stream);
        }

        if ($stored !== true || ! $disk->exists($mergedPdf->storage_path)) {
            throw new RuntimeException('The original merged PDF could not be restored.');
        }
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
    private function writeMergedPdf(array $normalizedSources, string $outputPath): void
    {
        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0777, true);
        }

        $pdf = new Fpdi;

        foreach ($normalizedSources as $source) {
            $pageCount = $pdf->setSourceFile($source['path']);

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }

        $pdf->Output('F', $outputPath);
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
}
