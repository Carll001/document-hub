<?php

declare(strict_types=1);

namespace App\Services\AfsFiling;

use App\Models\AfsFilingItem;
use App\Models\DocumentGeneratorTemplate;
use App\Services\DocxTemplateService;
use App\Services\PdfConversionService;
use App\Support\DocumentStorage;
use Illuminate\Support\Str;
use RuntimeException;

class AfsFilingItemGenerationService
{
    public function __construct(
        private readonly DocxTemplateService $docxTemplateService,
        private readonly PdfConversionService $pdfConversionService,
    ) {}

    public function generate(int $itemId): void
    {
        $item = AfsFilingItem::query()->find($itemId);
        if (! $item instanceof AfsFilingItem) {
            return;
        }

        if (in_array((string) $item->status, ['pdf_done', 'failed'], true)) {
            return;
        }

        $template = $this->resolveTemplateForRow($item->row_data ?? []);

        $templatePath = $this->copyStorageFileToTemporaryPath($template->template_path, '.docx');
        $docxPath = storage_path('app/tmp/afs-filing-'.Str::uuid().'.docx');
        $pdfPath = null;

        $baseDir = "afs_filing/{$item->user_id}/items/{$item->id}";
        $docxRelativePath = "{$baseDir}/row-{$item->row_number}.docx";
        $pdfRelativePath = "{$baseDir}/row-{$item->row_number}.pdf";

        $item->status = 'processing';
        $item->started_at = $item->started_at ?? now();
        $item->template_name = $template->template_name;
        $item->save();

        try {
            $validation = $this->docxTemplateService->validateRowData($templatePath, $item->row_data ?? [], $template->year);
            if (($validation['missing_data'] ?? []) !== [] || ($validation['errors'] ?? []) !== []) {
                $messages = [];
                if (($validation['missing_data'] ?? []) !== []) {
                    $messages[] = 'Missing data: '.implode(', ', $validation['missing_data']);
                }
                if (($validation['errors'] ?? []) !== []) {
                    $messages[] = implode(' ', $validation['errors']);
                }

                $item->status = 'failed';
                $item->error_message = mb_substr(trim(implode(' ', $messages)), 0, 2000);
                $item->error_details = $validation;
                $item->completed_at = now();
                $item->save();

                return;
            }

            $this->docxTemplateService->render($templatePath, $docxPath, $item->row_data ?? [], $template->year);
            $this->storeLocalFileToDocumentStorage($docxPath, $docxRelativePath);

            $pdfPath = $this->pdfConversionService->convertDocxToPdf($docxPath);
            $this->storeLocalFileToDocumentStorage($pdfPath, $pdfRelativePath);

            $item->status = 'pdf_done';
            $item->docx_path = $docxRelativePath;
            $item->pdf_path = $pdfRelativePath;
            $item->error_message = null;
            $item->error_details = null;
            $item->completed_at = now();
            $item->save();
        } catch (\Throwable $exception) {
            $item->status = 'failed';
            $item->error_message = mb_substr($exception->getMessage(), 0, 2000);
            $item->completed_at = now();
            $item->save();
        } finally {
            if (is_file($templatePath)) {
                @unlink($templatePath);
            }
            if (is_file($docxPath)) {
                @unlink($docxPath);
            }
            if (is_string($pdfPath) && is_file($pdfPath)) {
                @unlink($pdfPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $rowData
     */
    private function resolveTemplateForRow(array $rowData): DocumentGeneratorTemplate
    {
        $rowYear = $this->extractRegistrationYear($rowData);

        $templates = DocumentGeneratorTemplate::query()
            ->orderByRaw('CASE WHEN year IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('year')
            ->get();

        if ($templates->isEmpty()) {
            throw new RuntimeException('No global template configured for AFS filing.');
        }

        if ($rowYear !== null) {
            $matched = $templates->first(static function (DocumentGeneratorTemplate $template) use ($rowYear): bool {
                return $template->year !== null && (int) $template->year <= $rowYear;
            });

            if ($matched instanceof DocumentGeneratorTemplate) {
                return $matched;
            }
        }

        $default = $templates->first(static fn (DocumentGeneratorTemplate $template): bool => $template->year === null);

        if ($default instanceof DocumentGeneratorTemplate) {
            return $default;
        }

        /** @var DocumentGeneratorTemplate $first */
        $first = $templates->first();

        return $first;
    }

    /**
     * @param array<string, mixed> $rowData
     */
    private function extractRegistrationYear(array $rowData): ?int
    {
        foreach ($rowData as $header => $value) {
            $normalized = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim((string) $header))) ?? '';
            $normalized = trim($normalized, '_');
            if ($normalized !== 'sec_registration_date') {
                continue;
            }

            $raw = trim((string) $value);
            if ($raw === '') {
                return null;
            }

            $timestamp = strtotime($raw);
            if ($timestamp !== false) {
                return (int) date('Y', $timestamp);
            }

            if (preg_match('/\b(\d{4})\b/', $raw, $matches) === 1) {
                return (int) $matches[1];
            }

            return null;
        }

        return null;
    }

    private function copyStorageFileToTemporaryPath(string $storagePath, string $extension = ''): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'afs-template-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to allocate a temporary file path.');
        }

        $resolvedPath = $temporaryPath;
        if ($extension !== '') {
            $resolvedPath = $temporaryPath.(str_starts_with($extension, '.') ? $extension : '.'.$extension);
            if (! @rename($temporaryPath, $resolvedPath)) {
                @unlink($temporaryPath);
                throw new RuntimeException('Unable to prepare a temporary template file path.');
            }
        }

        $stream = DocumentStorage::disk()->readStream($storagePath);
        if (! is_resource($stream)) {
            if (is_file($resolvedPath)) {
                @unlink($resolvedPath);
            }
            throw new RuntimeException('Template file could not be read from storage.');
        }

        $target = @fopen($resolvedPath, 'wb');
        if (! is_resource($target)) {
            fclose($stream);
            if (is_file($resolvedPath)) {
                @unlink($resolvedPath);
            }
            throw new RuntimeException('Temporary template file could not be opened.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        return $resolvedPath;
    }

    private function storeLocalFileToDocumentStorage(string $localPath, string $storagePath): void
    {
        $stream = @fopen($localPath, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('Generated file could not be read for storage.');
        }

        try {
            DocumentStorage::disk()->writeStream($storagePath, $stream);
        } finally {
            fclose($stream);
        }
    }
}
