<?php

declare(strict_types=1);

namespace App\Http\Controllers\AfsFiling;

use App\Contracts\Repositories\AfsFilingItemRepository as AfsFilingItemRepositoryContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\AfsFiling\AfsFilingItemSignRequest;
use App\Http\Requests\AfsFiling\AfsFilingItemsIndexRequest;
use App\Http\Requests\AfsFiling\AfsFilingItemUpdateRequest;
use App\Http\Requests\AfsFiling\AfsFilingSignBulkRequest;
use App\Http\Requests\AfsFiling\AfsFilingUploadRequest;
use App\Jobs\AfsFiling\ApplyAfsFilingItemSignatureJob;
use App\Jobs\AfsFiling\DeleteAfsFilingItemJob;
use App\Jobs\AfsFiling\GenerateAfsFilingItemJob;
use App\Models\AfsFilingItem;
use App\Models\DocumentGeneratorTemplate;
use App\Models\User;
use App\Services\AfsFiling\AfsFilingItemSigningService;
use App\Services\DocxTemplateService;
use App\Services\ExcelExtractionService;
use App\Support\DocumentStorage;
use App\Support\FormFieldAliasResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AfsFilingItemController extends Controller
{
    public function __construct(
        private readonly AfsFilingItemRepositoryContract $items,
        private readonly AfsFilingItemSigningService $signingService,
        private readonly DocxTemplateService $docxTemplateService,
    ) {}

    public function store(AfsFilingUploadRequest $request, ExcelExtractionService $excelExtractionService): JsonResponse
    {
        $excelFile = $request->file('excel_file');
        if (! $excelFile) {
            return response()->json(['message' => 'Excel file is required.'], 422);
        }

        /** @var User $user */
        $user = $request->user();

        $diskName = DocumentStorage::diskName();

        $excelStorePath = "afs_filing/{$user->id}/uploads";
        $excelFileName = $excelFile->hashName();
        Storage::disk($diskName)->putFileAs($excelStorePath, $excelFile, $excelFileName);
        $excelPath = "{$excelStorePath}/{$excelFileName}";

        $uploadedDefaultTemplate = $request->file('default_template_file');
        $templateName = null;
        if ($uploadedDefaultTemplate) {
            $templateName = $uploadedDefaultTemplate->getClientOriginalName();
            $templatePath = $uploadedDefaultTemplate->store("afs_filing/{$user->id}/templates", $diskName);
            DocumentGeneratorTemplate::query()->updateOrCreate(
                ['year' => null],
                ['template_name' => $templateName, 'template_path' => $templatePath],
            );
        }

        $extracted = $excelExtractionService->extractFromDocumentStorage($excelPath, 0);
        $rows = $extracted['rows'] ?? [];

        if (! is_array($rows) || $rows === []) {
            return response()->json(['message' => 'No data rows found in the uploaded Excel file.'], 422);
        }

        $this->syncAfsFilingItemSequence();
        $createdIds = [];
        $queuedCount = 0;
        $failedCount = 0;

        try {
            ['created_ids' => $createdIds, 'queued_count' => $queuedCount, 'failed_count' => $failedCount] =
                $this->createItemsFromRows($rows, $user, $excelFile, $templateName);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isAfsFilingPrimaryKeyCollision($exception)) {
                throw $exception;
            }

            $this->syncAfsFilingItemSequence();
            ['created_ids' => $createdIds, 'queued_count' => $queuedCount, 'failed_count' => $failedCount] =
                $this->createItemsFromRows($rows, $user, $excelFile, $templateName);
        }

        foreach ($createdIds as $position => $id) {
            GenerateAfsFilingItemJob::dispatch($id)->delay(now()->addSeconds($position));
        }

        return response()->json([
            'message' => 'AFS filing rows queued for generation.',
            'status' => 'queued',
            'total_items' => count($rows),
            'queued_items' => $queuedCount,
            'failed_items' => $failedCount,
        ], 201);
    }

    public function items(AfsFilingItemsIndexRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $paginator = $this->items->paginateForUser((int) $user->getKey(), $request->validated());

        return response()->json([
            'current_page' => $paginator->currentPage(),
            'data' => $paginator->getCollection()->map(fn(AfsFilingItem $item): array => $this->itemPayload($item))->values(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function show(Request $request, AfsFilingItem $item): JsonResponse
    {
        $this->assertOwnership($request, $item);

        return response()->json($this->itemPayload($item));
    }

    public function preflightAnchorCheck(Request $request, AfsFilingItem $item): JsonResponse
    {
        $this->assertOwnership($request, $item);

        $preflight = $this->signingService->preflight($item);
        $status = $preflight['ok'] ? 200 : 422;

        return response()->json($preflight, $status);
    }

    public function sign(AfsFilingItemSignRequest $request, AfsFilingItem $item): JsonResponse
    {
        $this->assertOwnership($request, $item);

        /** @var User $user */
        $user = $request->user();

        $preflight = $this->signingService->preflight($item);
        if (! $preflight['ok']) {
            return response()->json($preflight, 422);
        }

        if ($item->signature_applied_at !== null) {
            return response()->json([
                'message' => 'Signature is already applied for this row.',
                'errors' => ['signature' => ['Signature is already applied for this row.']],
            ], 422);
        }

        $uploadedSignature = $request->file('president_signature_file');
        if (! $uploadedSignature) {
            return response()->json(['message' => 'President signature image is required.'], 422);
        }

        $signaturePath = $uploadedSignature->store("afs_filing/{$user->id}/signatures/queued", DocumentStorage::diskName());

        $item->status = 'signing';
        $item->error_message = null;
        $item->error_details = null;
        $item->save();

        ApplyAfsFilingItemSignatureJob::dispatch((int) $user->getKey(), (int) $item->getKey(), $signaturePath);

        return response()->json([
            'message' => 'Signature application queued.',
            'status' => 'signing',
            'item_id' => (int) $item->getKey(),
        ], 202);
    }

    public function signBulk(AfsFilingSignBulkRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $items = AfsFilingItem::query()
            ->where('user_id', (int) $user->getKey())
            ->whereIn('id', $validated['item_ids'])
            ->where('status', 'pdf_done')
            ->whereNull('signature_applied_at')
            ->get();

        $uploadedSignature = $request->file('president_signature_file');
        if (! $uploadedSignature) {
            return response()->json(['message' => 'President signature image is required.'], 422);
        }

        if ($items->isEmpty()) {
            return response()->json([
                'message' => 'No eligible rows matched the selected items.',
                'queued_count' => 0,
                'failed_to_queue_count' => count($validated['item_ids']),
            ], 422);
        }

        $signaturePath = $uploadedSignature->store("afs_filing/{$user->id}/signatures/queued", DocumentStorage::diskName());

        $itemIds = $items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        AfsFilingItem::query()
            ->where('user_id', (int) $user->getKey())
            ->whereIn('id', $itemIds)
            ->update([
                'status' => 'signing',
                'error_message' => null,
                'error_details' => null,
            ]);

        $queuedCount = 0;
        $failedToQueueCount = 0;

        foreach ($items as $item) {
            try {
                ApplyAfsFilingItemSignatureJob::dispatch((int) $user->getKey(), (int) $item->getKey(), $signaturePath);
                $queuedCount++;
            } catch (\Throwable $exception) {
                $failedToQueueCount++;
            }
        }

        $submittedCount = count(array_unique(array_map(static fn (mixed $id): int => (int) $id, $validated['item_ids'])));
        $failedToQueueCount += max(0, $submittedCount - count($itemIds));

        return response()->json([
            'message' => $queuedCount === 1 ? 'Queued 1 signature task.' : "Queued {$queuedCount} signature tasks.",
            'queued_count' => $queuedCount,
            'failed_to_queue_count' => $failedToQueueCount,
        ], 202);
    }

    public function update(AfsFilingItemUpdateRequest $request, AfsFilingItem $item): JsonResponse
    {
        $this->assertOwnership($request, $item);

        $validated = $request->validated();

        $this->deleteItemFiles($item);

        $item->row_data = $validated['row_data'];
        $item->status = 'queued';
        $item->docx_path = null;
        $item->pdf_path = null;
        $item->error_message = null;
        $item->error_details = null;
        $item->signature_applied_at = null;
        $item->started_at = null;
        $item->completed_at = null;
        $item->save();

        GenerateAfsFilingItemJob::dispatch((int) $item->id);

        return response()->json($this->itemPayload($item));
    }

    public function retry(Request $request, AfsFilingItem $item): JsonResponse
    {
        $this->assertOwnership($request, $item);

        abort_unless($item->status === 'failed', 422, 'Only failed items can be retried.');

        $this->deleteItemFiles($item);

        $item->status = 'queued';
        $item->error_message = null;
        $item->error_details = null;
        $item->docx_path = null;
        $item->pdf_path = null;
        $item->started_at = null;
        $item->completed_at = null;
        $item->save();

        GenerateAfsFilingItemJob::dispatch((int) $item->id);

        return response()->json($this->itemPayload($item));
    }

    public function destroy(Request $request, AfsFilingItem $item): JsonResponse
    {
        $this->assertOwnership($request, $item);

        $item->status = 'deleting';
        $item->save();

        DeleteAfsFilingItemJob::dispatch((int) $item->user_id, (int) $item->id);

        return response()->json(['message' => 'Row deletion queued.', 'status' => 'deleting']);
    }

    public function download(Request $request, AfsFilingItem $item, string $type): StreamedResponse|BinaryFileResponse
    {
        $this->assertOwnership($request, $item);
        abort_unless(in_array($type, ['docx', 'pdf'], true), 404);

        $path = $type === 'docx' ? $item->docx_path : $item->pdf_path;
        abort_unless(is_string($path) && $path !== '' && DocumentStorage::disk()->exists($path), 404);

        $fileName = "afs_filing-row-{$item->row_number}.{$type}";
        $inline = $request->boolean('inline');

        if ($type === 'pdf' && $inline) {
            return DocumentStorage::disk()->response($path, $fileName, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$fileName}\"",
            ]);
        }

        return DocumentStorage::disk()->download($path, $fileName);
    }

    private function assertOwnership(Request $request, AfsFilingItem $item): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless((int) $item->user_id === (int) $user->getKey(), 404);
    }

    private function itemPayload(AfsFilingItem $item): array
    {
        $rowData = is_array($item->row_data) ? $item->row_data : [];
        $company = FormFieldAliasResolver::resolveCompany($rowData, FormFieldAliasResolver::FORM_AFS);

        return [
            'id' => (int) $item->id,
            'row_number' => (int) $item->row_number,
            'company' => is_string($company) && trim($company) !== '' ? $company : '-',
            'tin' => FormFieldAliasResolver::resolveTin($rowData, FormFieldAliasResolver::FORM_AFS),
            'status' => (string) $item->status,
            'row_data' => $rowData,
            'docx_available' => is_string($item->docx_path) && $item->docx_path !== '' && DocumentStorage::disk()->exists($item->docx_path),
            'pdf_available' => is_string($item->pdf_path) && $item->pdf_path !== '' && DocumentStorage::disk()->exists($item->pdf_path),
            'signature_applied' => $item->signature_applied_at !== null,
            'signature_applied_at' => $item->signature_applied_at?->toIso8601String(),
            'error_message' => $item->error_message,
            'error_details' => is_array($item->error_details) ? $item->error_details : null,
            'source_excel_name' => $item->source_excel_name,
            'template_name' => $item->template_name,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    private function deleteItemFiles(AfsFilingItem $item): void
    {
        $paths = array_filter([(string) $item->docx_path, (string) $item->pdf_path]);
        if ($paths !== []) {
            DocumentStorage::disk()->delete($paths);
        }
    }

    /**
     * @param array<int, mixed> $rows
     * @return array{created_ids: array<int, int>, queued_count: int, failed_count: int}
     */
    private function createItemsFromRows(array $rows, User $user, UploadedFile $excelFile, ?string $templateName): array
    {
        $createdIds = [];
        $queuedCount = 0;
        $failedCount = 0;

        DB::transaction(function () use ($rows, $user, $excelFile, $templateName, &$createdIds, &$queuedCount, &$failedCount): void {
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
                    'user_id' => (int) $user->getKey(),
                    'row_number' => $index + 2,
                    'row_data' => $rowData,
                    'status' => $isFailed ? 'failed' : 'queued',
                    'error_message' => $errorMessage,
                    'error_details' => $errorDetails,
                    'completed_at' => $isFailed ? now() : null,
                    'source_excel_name' => $excelFile->getClientOriginalName(),
                    'template_name' => $templateName,
                ]);

                if ($isFailed) {
                    $failedCount++;
                } else {
                    $queuedCount++;
                    $createdIds[] = (int) $item->id;
                }
            }
        });

        return [
            'created_ids' => $createdIds,
            'queued_count' => $queuedCount,
            'failed_count' => $failedCount,
        ];
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
            $placeholders = $this->docxTemplateService->placeholderKeys($templatePath);

            Log::info('AFS DOCX placeholders extracted for upload validation.', [
                'row_number' => $rowNumber,
                'template_id' => (int) $template->getKey(),
                'template_name' => $template->template_name,
                'template_year' => $template->year,
                'placeholders' => $placeholders,
            ]);

            Log::info('AFS Excel row data extracted for upload validation.', [
                'row_number' => $rowNumber,
                'row_data' => $this->sanitizeForLog($rowData),
            ]);

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

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeForLog(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value;
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeForLog($item);
            }

            return $sanitized;
        }

        return $value;
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
