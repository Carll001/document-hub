<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Services\DocumentBatchItemGenerationService as DocumentBatchItemGenerationServiceContract;
use App\Exceptions\RetryableStorageConsistencyException;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentBatchTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DocumentBatchItemGenerationService implements DocumentBatchItemGenerationServiceContract
{
    public function __construct(
        private readonly DocumentBatchActivityLogger $activityLogger,
        private readonly DocxTemplateService $docxTemplateService,
        private readonly PdfConversionService $pdfConversionService,
        private readonly ExcelExtractionService $excelExtractionService,
    ) {}

    public function generate(int $documentBatchItemId): void
    {
        $item = DocumentBatchItem::with('batch.templates')->find($documentBatchItemId);
        if (! $item instanceof DocumentBatchItem) {
            return;
        }

        if (in_array($item->status, ['pdf_done', 'failed'], true)) {
            return;
        }

        try {
            $batch = $item->batch;
            if (! $batch instanceof DocumentBatch) {
                throw new \RuntimeException('Document batch not found.');
            }

            $baseDir = "document-generator/{$batch->user_id}/batch-{$batch->id}";
            $docxRelativePath = "{$baseDir}/row-{$item->row_number}.docx";
            $pdfRelativePath = "{$baseDir}/row-{$item->row_number}.pdf";

            \App\Support\DocumentStorage::disk()->makeDirectory($baseDir);

            /** @var array<string, string> $rowData */
            $rowData = $item->row_data ?? [];
            $template = $this->resolveTemplateForRow($batch, $rowData);
            $templatePath = $this->copyStorageFileToTemporaryPath($template->template_path, '.docx');
            $this->markItemProcessing($item->id);
            $docxPath = storage_path('app/tmp/document-generator-'.Str::uuid().'.docx');
            $pdfPath = null;

            try {
                $templateRowData = $this->buildTemplateRowData(
                    $batch,
                    $rowData,
                    $templatePath,
                    $template->year,
                    $this->docxTemplateService,
                    $this->excelExtractionService
                );

                $validation = $this->docxTemplateService->validateRowData($templatePath, $templateRowData, $template->year);
                $validationErrors = [];
                if (($validation['missing_data'] ?? []) !== []) {
                    $validationErrors[] = 'Missing data: '.implode(', ', $validation['missing_data']);
                }
                if (($validation['errors'] ?? []) !== []) {
                    array_push($validationErrors, ...$validation['errors']);
                }

                if ($validationErrors !== []) {
                    $errorMessage = implode(' ', $validationErrors);
                    $this->markItemFinal($item->id, false, null, null, $errorMessage, $validation);
                    $failedItem = DocumentBatchItem::query()->find($item->id);

                    if ($failedItem instanceof DocumentBatchItem) {
                        $this->activityLogger->log(
                            $batch,
                            $failedItem,
                            null,
                            'generation_failed_validation',
                            "Row {$item->row_number} failed placeholder validation.",
                            $validation
                        );
                    }

                    return;
                }

                $this->docxTemplateService->render($templatePath, $docxPath, $templateRowData, $template->year);
                $this->storeLocalFileToDocumentStorage($docxPath, $docxRelativePath);

                $this->markDocxDone($item->id, $docxRelativePath);

                $pdfPath = $this->pdfConversionService->convertDocxToPdf($docxPath);
                $this->storeLocalFileToDocumentStorage($pdfPath, $pdfRelativePath);

                $this->markItemFinal($item->id, true, $docxRelativePath, $pdfRelativePath);
                $completedItem = DocumentBatchItem::query()->find($item->id);
                if ($completedItem instanceof DocumentBatchItem) {
                    $this->activityLogger->log(
                        $batch,
                        $completedItem,
                        null,
                        'generation_completed',
                        "Row {$item->row_number} generated successfully.",
                        [
                            'docx_path' => $docxRelativePath,
                            'pdf_path' => $pdfRelativePath,
                        ]
                    );
                }
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
        } catch (Throwable $exception) {
            if ($exception instanceof RetryableStorageConsistencyException) {
                throw $exception;
            }

            $this->markItemFinal(
                $item->id,
                false,
                $item->docx_path,
                null,
                mb_substr($exception->getMessage(), 0, 2000),
                null
            );

            $batch = $item->batch;
            if ($batch instanceof DocumentBatch) {
                $failedItem = DocumentBatchItem::query()->find($item->id);
                if ($failedItem instanceof DocumentBatchItem) {
                    $this->activityLogger->log(
                        $batch,
                        $failedItem,
                        null,
                        'generation_failed',
                        "Row {$item->row_number} generation failed.",
                        [
                            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                        ]
                    );
                }
            }
        }
    }

    private function copyStorageFileToTemporaryPath(string $storagePath, string $extension = ''): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-gen-template-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Unable to allocate a temporary file path.');
        }

        $resolvedPath = $temporaryPath;
        $normalizedExtension = trim($extension);
        if ($normalizedExtension !== '') {
            $resolvedPath = $temporaryPath.(str_starts_with($normalizedExtension, '.') ? $normalizedExtension : '.'.$normalizedExtension);
            if (! @rename($temporaryPath, $resolvedPath)) {
                @unlink($temporaryPath);
                throw new \RuntimeException('Unable to prepare a temporary file path.');
            }
        }

        $stream = $this->openStorageReadStreamWithRetry($storagePath);
        if (! is_resource($stream)) {
            if (is_file($resolvedPath)) {
                @unlink($resolvedPath);
            }
            throw new RetryableStorageConsistencyException(
                "The template file could not be read from storage. Path: {$storagePath}."
            );
        }

        $target = @fopen($resolvedPath, 'wb');
        if (! is_resource($target)) {
            fclose($stream);
            if (is_file($resolvedPath)) {
                @unlink($resolvedPath);
            }
            throw new \RuntimeException('A temporary template file could not be opened.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        return $resolvedPath;
    }

    /**
     * @return resource|false
     */
    private function openStorageReadStreamWithRetry(string $storagePath, int $attempts = 20, int $delayMilliseconds = 500)
    {
        $disk = \App\Support\DocumentStorage::disk();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $stream = $disk->readStream($storagePath);
            if (is_resource($stream)) {
                return $stream;
            }

            if ($attempt < $attempts) {
                usleep($delayMilliseconds * 1000);
            }
        }

        return false;
    }

    private function storeLocalFileToDocumentStorage(string $localPath, string $storagePath): void
    {
        $stream = @fopen($localPath, 'rb');
        if (! is_resource($stream)) {
            throw new \RuntimeException('A generated file could not be read for storage.');
        }

        try {
            \App\Support\DocumentStorage::disk()->writeStream($storagePath, $stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, string>  $rowData
     * @return array<string, string>
     */
    private function buildTemplateRowData(
        DocumentBatch $batch,
        array $rowData,
        string $templatePath,
        ?int $selectedTemplateYear,
        DocxTemplateService $docxTemplateService,
        ExcelExtractionService $excelExtractionService
    ): array {
        if ($selectedTemplateYear !== 2025) {
            return $rowData;
        }

        $templateRowData = $rowData;
        $previousRowData = $this->findPreviousWorkbookRow($batch, $rowData, $excelExtractionService);

        foreach ($docxTemplateService->placeholderKeys($templatePath) as $placeholder) {
            $placeholder = trim($placeholder);
            if ($placeholder === '') {
                continue;
            }

            $subtractionOperands = $this->parseSubtractionPlaceholder($placeholder);
            if ($subtractionOperands !== null) {
                $hasDirectCurrentYearHeader = $this->hasNormalizedHeader($rowData, $subtractionOperands['left_operand']);
                $currentValue = $this->findCurrentYearValue($rowData, $subtractionOperands['left_operand']);
                if ($currentValue !== null && $this->isNumericValue($currentValue)) {
                    $templateRowData[$subtractionOperands['left_operand']] = $currentValue;
                }

                $previousValue = $this->findPreviousValue($previousRowData, $subtractionOperands['right_operand']);
                if ($previousValue === null && $hasDirectCurrentYearHeader) {
                    $previousValue = $this->findFirstNormalizedValue($rowData, $subtractionOperands['right_operand']);
                }

                if ($previousValue !== null) {
                    $templateRowData[$subtractionOperands['right_operand']] = $previousValue;
                } elseif ($currentValue !== null && $this->isNumericValue($currentValue)) {
                    $templateRowData[$subtractionOperands['right_operand']] = '';
                }

                continue;
            }

            $currentYearOperand = "{$placeholder} 2025";
            $hasDirectCurrentYearHeader = $this->hasNormalizedHeader($rowData, $currentYearOperand);
            $currentValue = $this->findCurrentYearValue($rowData, $currentYearOperand);
            if ($currentValue === null || ! $this->isNumericValue($currentValue)) {
                continue;
            }

            $templateRowData[$currentYearOperand] = $currentValue;

            $previousValue = $this->findPreviousValue($previousRowData, $placeholder);
            if ($previousValue === null && $hasDirectCurrentYearHeader) {
                $previousValue = $this->findFirstNormalizedValue($rowData, $placeholder);
            }

            $templateRowData[$placeholder] = $previousValue ?? '';
        }

        return $templateRowData;
    }

    /**
     * @param  array<string, string>  $rowData
     * @return array<string, string>|null
     */
    private function findPreviousWorkbookRow(
        DocumentBatch $batch,
        array $rowData,
        ExcelExtractionService $excelExtractionService
    ): ?array {
        $company = trim($this->extractCompanyFromRowData($rowData));
        if ($company === '') {
            return null;
        }

        $previousBatch = DocumentBatch::query()
            ->where('user_id', $batch->user_id)
            ->where('id', '<', $batch->id)
            ->whereNotNull('excel_path')
            ->latest('id')
            ->first();

        if (! $previousBatch instanceof DocumentBatch || ! is_string($previousBatch->excel_path)) {
            return null;
        }

        if (! \App\Support\DocumentStorage::disk()->exists($previousBatch->excel_path)) {
            return null;
        }

        $rows = $excelExtractionService->extractFromDocumentStorage($previousBatch->excel_path, 0)['rows'];
        foreach ($rows as $previousRowData) {
            if (trim($this->extractCompanyFromRowData($previousRowData)) === $company) {
                return $previousRowData;
            }
        }

        return null;
    }

    /**
     * @return array{left_operand: string, right_operand: string}|null
     */
    private function parseSubtractionPlaceholder(string $placeholder): ?array
    {
        if (substr_count($placeholder, '-') !== 1) {
            return null;
        }

        [$leftOperand, $rightOperand] = array_map('trim', explode('-', $placeholder, 2));
        if ($leftOperand === '' || $rightOperand === '') {
            return null;
        }

        return [
            'left_operand' => $leftOperand,
            'right_operand' => $rightOperand,
        ];
    }

    /**
     * @param  array<string, string>  $rowData
     */
    private function findCurrentYearValue(array $rowData, string $operand): ?string
    {
        $directValue = $this->findFirstNormalizedValue($rowData, $operand);
        if ($directValue !== null) {
            return $directValue;
        }

        if (! preg_match('/^(?<base>.+?)\s2025$/', trim($operand), $matches)) {
            return null;
        }

        $baseOperand = trim((string) ($matches['base'] ?? ''));
        if ($baseOperand === '') {
            return null;
        }

        return $this->findFirstNormalizedValue($rowData, $baseOperand);
    }

    /**
     * @param  array<string, string>  $rowData
     */
    private function hasNormalizedHeader(array $rowData, string $header): bool
    {
        return $this->findFirstNormalizedValue($rowData, $header) !== null;
    }

    /**
     * @param  array<string, string>|null  $rowData
     */
    private function findPreviousValue(?array $rowData, string $operand): ?string
    {
        if ($rowData === null) {
            return null;
        }

        return $this->findFirstNormalizedValue($rowData, $operand);
    }

    /**
     * @param  array<string, string>  $rowData
     */
    private function findFirstNormalizedValue(array $rowData, string $header): ?string
    {
        $normalizedHeader = $this->normalizeHeader($header);

        foreach ($rowData as $key => $value) {
            if ($this->normalizeHeader($key) === $normalizedHeader) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $rowData
     */
    private function extractCompanyFromRowData(array $rowData): string
    {
        $fallback = '';

        foreach ($rowData as $key => $value) {
            $normalizedKey = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($key)) ?? '';
            $stringValue = trim($value);

            if ($normalizedKey === 'company') {
                return $stringValue;
            }

            if ($fallback === '' && str_contains($normalizedKey, 'company')) {
                $fallback = $stringValue;
            }
        }

        return $fallback;
    }

    private function isNumericValue(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        return is_numeric(str_replace([',', ' '], '', $trimmed));
    }

    /**
     * @param  array<string, string>  $rowData
     */
    private function resolveTemplateForRow(DocumentBatch $batch, array $rowData): DocumentBatchTemplate
    {
        $year = $this->extractRegistrationYear($rowData);
        if ($year === null) {
            throw new \RuntimeException(
                'Invalid SEC REGISTRATION DATE. Expected a recognizable date such as 7/23/2024 00:00:00.'
            );
        }

        $template = $this->resolveTemplate($batch, $year);
        if (! $template instanceof DocumentBatchTemplate) {
            throw new \RuntimeException("No template configured for year {$year}.");
        }

        return $template;
    }

    private function resolveTemplate(DocumentBatch $batch, int $rowYear): ?DocumentBatchTemplate
    {
        /** @var \Illuminate\Support\Collection<int, DocumentBatchTemplate> $templates */
        $templates = $batch->templates->sortByDesc(static fn (DocumentBatchTemplate $template): int => $template->year ?? -1);

        $yearTemplate = $templates
            ->filter(static fn (DocumentBatchTemplate $template): bool => $template->year !== null)
            ->first(static fn (DocumentBatchTemplate $template): bool => (int) $template->year <= $rowYear);

        if ($yearTemplate instanceof DocumentBatchTemplate) {
            return $yearTemplate;
        }

        return $templates->first(static fn (DocumentBatchTemplate $template): bool => $template->year === null);
    }

    /**
     * @param  array<string, string>  $rowData
     */
    private function extractRegistrationYear(array $rowData): ?int
    {
        foreach ($rowData as $header => $value) {
            if ($this->normalizeHeader($header) !== 'sec_registration_date') {
                continue;
            }

            $normalizedValue = trim($value);
            if ($normalizedValue === '') {
                return null;
            }

            $year = $this->extractYearFromSupportedDateFormats($normalizedValue);
            if ($year !== null) {
                return $year;
            }

            if (preg_match('/\b(\d{4})\b/', $normalizedValue, $matches) === 1) {
                return (int) $matches[1];
            }

            return null;
        }

        return null;
    }

    private function extractYearFromSupportedDateFormats(string $value): ?int
    {
        $formats = [
            'n/j/Y G:i',
            'n/j/Y H:i',
            'n/j/Y G:i:s',
            'n/j/Y H:i:s',
            'm/d/Y G:i',
            'm/d/Y H:i',
            'm/d/Y G:i:s',
            'm/d/Y H:i:s',
            'n-d-Y G:i',
            'n-d-Y H:i',
            'n-d-Y G:i:s',
            'n-d-Y H:i:s',
            'm-d-Y G:i',
            'm-d-Y H:i',
            'm-d-Y G:i:s',
            'm-d-Y H:i:s',
            'n.j.Y G:i',
            'n.j.Y H:i',
            'n.j.Y G:i:s',
            'n.j.Y H:i:s',
            'm.d.Y G:i',
            'm.d.Y H:i',
            'm.d.Y G:i:s',
            'm.d.Y H:i:s',
            'Y-m-d',
            'Y-m-d H:i',
            'Y-m-d H:i:s',
            'Y-m-d G:i',
            'Y-m-d G:i:s',
            'Y/m/d',
            'Y/m/d H:i',
            'Y/m/d H:i:s',
            'Y/m/d G:i',
            'Y/m/d G:i:s',
            'Y.m.d',
            'Y.m.d H:i',
            'Y.m.d H:i:s',
            'Y.m.d G:i',
            'Y.m.d G:i:s',
            'Y-n-j',
            'Y-n-j H:i',
            'Y-n-j H:i:s',
            'Y-n-j G:i',
            'Y-n-j G:i:s',
            'Y/n/j',
            'Y/n/j H:i',
            'Y/n/j H:i:s',
            'Y/n/j G:i',
            'Y/n/j G:i:s',
            'Y.n.j',
            'Y.n.j H:i',
            'Y.n.j H:i:s',
            'Y.n.j G:i',
            'Y.n.j G:i:s',
            'm/d/y G:i',
            'm/d/y H:i',
            'm/d/y G:i:s',
            'm/d/y H:i:s',
            'm-d-y G:i',
            'm-d-y H:i',
            'm-d-y G:i:s',
            'm-d-y H:i:s',
            'm.d.y G:i',
            'm.d.y H:i',
            'm.d.y G:i:s',
            'm.d.y H:i:s',
            'n/j/y G:i',
            'n/j/y H:i',
            'n/j/y G:i:s',
            'n/j/y H:i:s',
            'n-d-y G:i',
            'n-d-y H:i',
            'n-d-y G:i:s',
            'n-d-y H:i:s',
            'n.d.y G:i',
            'n.d.y H:i',
            'n.d.y G:i:s',
            'n.d.y H:i:s',
        ];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = \DateTimeImmutable::getLastErrors();

            if (
                $date instanceof \DateTimeImmutable
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            ) {
                return (int) $date->format('Y');
            }
        }

        try {
            return CarbonImmutable::parse($value)->year;
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = strtr(trim($header), [
            "\u{00A0}" => ' ',
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
        ]);
        $normalized = mb_strtolower($normalized);
        $normalized = preg_replace('/[^\pL\pN]+/u', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    private function markItemProcessing(int $itemId): void
    {
        DB::transaction(function () use ($itemId): void {
            $item = DocumentBatchItem::query()->lockForUpdate()->find($itemId);
            if (! $item instanceof DocumentBatchItem) {
                return;
            }

            if (in_array($item->status, ['pdf_done', 'failed'], true)) {
                return;
            }

            if ($item->status !== 'processing') {
                $item->status = 'processing';
                $item->started_at = $item->started_at ?? now();
                $item->save();
            }

            $batch = DocumentBatch::query()->lockForUpdate()->find($item->document_batch_id);
            if ($batch instanceof DocumentBatch && $batch->status === 'queued') {
                $batch->status = 'processing';
                $batch->started_at = $batch->started_at ?? now();
                $batch->save();
            }
        });
    }

    private function markDocxDone(int $itemId, string $docxPath): void
    {
        DB::transaction(function () use ($itemId, $docxPath): void {
            $item = DocumentBatchItem::query()->lockForUpdate()->find($itemId);
            if (! $item instanceof DocumentBatchItem) {
                return;
            }

            if (in_array($item->status, ['pdf_done', 'failed'], true)) {
                return;
            }

            $item->status = 'docx_done';
            $item->docx_path = $docxPath;
            $item->save();
        });
    }

    private function markItemFinal(
        int $itemId,
        bool $isSuccess,
        ?string $docxPath,
        ?string $pdfPath,
        ?string $errorMessage = null,
        ?array $errorDetails = null
    ): void {
        DB::transaction(function () use ($itemId, $isSuccess, $docxPath, $pdfPath, $errorMessage, $errorDetails): void {
            $item = DocumentBatchItem::query()->lockForUpdate()->find($itemId);
            if (! $item instanceof DocumentBatchItem) {
                return;
            }

            if (in_array($item->status, ['pdf_done', 'failed'], true)) {
                return;
            }

            $item->status = $isSuccess ? 'pdf_done' : 'failed';
            $item->docx_path = $docxPath;
            $item->pdf_path = $pdfPath;
            $item->error_message = $errorMessage;
            $item->error_details = $errorDetails;
            $item->completed_at = now();
            $item->save();

            $batch = DocumentBatch::query()->lockForUpdate()->find($item->document_batch_id);
            if (! $batch instanceof DocumentBatch) {
                return;
            }

            $batch->processed_items++;
            if ($isSuccess) {
                $batch->success_items++;
            } else {
                $batch->failed_items++;
            }

            $isComplete = $batch->processed_items >= $batch->total_items;
            if ($isComplete) {
                $batch->status = $batch->failed_items > 0 ? 'failed' : 'completed';
                $batch->completed_at = now();
            } else {
                $batch->status = 'processing';
                $batch->started_at = $batch->started_at ?? now();
            }

            $batch->save();
        });
    }
}
