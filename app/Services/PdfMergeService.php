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
     * @param  list<UploadedFile>  $files
     */
    public function merge(User $user, array $files, ?string $outputName = null): MergedPdf
    {
        if (count($files) < 2) {
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
            $pdf = new Fpdi;

            foreach ($files as $file) {
                $pageCount = $pdf->setSourceFile($file->getRealPath());

                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

                    $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }
            }

            $pdf->Output('F', $temporaryOutputPath);

            if (! is_file($temporaryOutputPath)) {
                throw new RuntimeException('The merged PDF could not be created.');
            }

            $sourceFileNames = array_map(
                static fn (UploadedFile $file): string => $file->getClientOriginalName(),
                $files,
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
