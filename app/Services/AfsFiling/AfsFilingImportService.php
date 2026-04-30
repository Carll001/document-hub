<?php

declare(strict_types=1);

namespace App\Services\AfsFiling;

use App\Jobs\AfsFiling\GenerateAfsFilingItemJob;
use App\Models\AfsFilingItem;
use App\Models\DocumentGeneratorTemplate;
use App\Services\DocxTemplateService;
use App\Services\ExcelExtractionService;
use App\Support\DocumentStorage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AfsFilingImportService
{
    public function __construct(
        private readonly ExcelExtractionService $excelExtractionService,
        private readonly DocxTemplateService $docxTemplateService,
    ) {}

    public function importStoredUpload(int $userId, string $excelPath, string $excelOriginalName): void
    {
        try {
            $extracted = $this->excelExtractionService->extractFromDocumentStorage($excelPath, 0);
            $rows = $extracted['rows'] ?? [];

            if (! is_array($rows) || $rows === []) {
                return;
            }

            $this->syncAfsFilingItemSequence();
            $createdIds = [];

            try {
                $createdIds = $this->createItemsFromRows($rows, $userId, $excelOriginalName);
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isAfsFilingPrimaryKeyCollision($exception)) {
                    throw $exception;
                }

                $this->syncAfsFilingItemSequence();
                $createdIds = $this->createItemsFromRows($rows, $userId, $excelOriginalName);
            }

            foreach ($createdIds as $position => $id) {
                GenerateAfsFilingItemJob::dispatch($id)->delay(now()->addSeconds($position));
            }
        } finally {
            if (DocumentStorage::isValidPath($excelPath)) {
                DocumentStorage::disk()->delete($excelPath);
            }
        }
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, int>
     */
    private function createItemsFromRows(array $rows, int $userId, string $excelOriginalName): array
    {
        $createdIds = [];

        DB::transaction(function () use ($rows, $userId, $excelOriginalName, &$createdIds): void {
            foreach ($rows as $index => $rowData) {
                if (! is_array($rowData)) {
                    continue;
                }

                $rowValidation = $this->validateRowAgainstTemplate($rowData, $index + 2);

                $isFailed = (($rowValidation['missing_data'] ?? []) !== []) || (($rowValidation['errors'] ?? []) !== []);
                $errorMessage = null;
                $errorDetails = null;

                if ($isFailed) {
                    $messages = [];

                    if (($rowValidation['missing_data'] ?? []) !== []) {
                        $messages[] = 'Missing data: '.implode(', ', $rowValidation['missing_data']);
                    }

                    if (($rowValidation['errors'] ?? []) !== []) {
                        $messages[] = implode(' ', $rowValidation['errors']);
                    }

                    $errorMessage = mb_substr(trim(implode(' ', $messages)), 0, 2000);
                    $errorDetails = [
                        'validation_stage' => 'upload',
                        'missing_data' => array_values($rowValidation['missing_data'] ?? []),
                        'errors' => array_values($rowValidation['errors'] ?? []),
                    ];
                }

                $item = AfsFilingItem::query()->create([
                    'user_id' => $userId,
                    'row_number' => $index + 2,
                    'row_data' => $rowData,
                    'status' => $isFailed ? 'failed' : 'queued',
                    'error_message' => $errorMessage,
                    'error_details' => $errorDetails,
                    'completed_at' => $isFailed ? now() : null,
                    'source_excel_name' => $excelOriginalName,
                ]);

                if (! $isFailed) {
                    $createdIds[] = (int) $item->id;
                }
            }
        });

        return $createdIds;
    }

    /**
     * @param array<string, mixed> $rowData
     * @return array{missing_data: list<string>, errors: list<string>}
     */
    private function validateRowAgainstTemplate(array $rowData, int $rowNumber): array
    {
        $template = $this->resolveTemplateForRow($rowData);
        $templatePath = null;

        try {
            $templatePath = $this->copyStorageFileToTemporaryPath($template->template_path, '.docx');

            return $this->docxTemplateService->validateRowData($templatePath, $rowData, $template->year);
        } finally {
            if (is_string($templatePath) && is_file($templatePath)) {
                @unlink($templatePath);
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

    private function syncAfsFilingItemSequence(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "SELECT setval(pg_get_serial_sequence('afs_filing_items', 'id'), COALESCE((SELECT MAX(id) FROM afs_filing_items), 1), true)"
        );
    }

    private function isAfsFilingPrimaryKeyCollision(UniqueConstraintViolationException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'afs_filing_items_pkey') && str_contains($message, 'duplicate key');
    }
}

