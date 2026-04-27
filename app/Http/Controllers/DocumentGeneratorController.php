<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentBatchStoreRequest;
use App\Jobs\GenerateDocumentBatchItemJob;
use App\Jobs\ProcessDocumentGeneratorCompletedExport;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentBatchItemActivityLog;
use App\Models\DocumentBatchTemplate;
use App\Models\DocumentGeneratorSignature;
use App\Models\DocumentGeneratorTemplate;
use App\Models\User;
use App\Services\DocumentGeneratorCompletedExportService;
use App\Services\DocumentBatchActivityLogger;
use App\Services\DocxTemplateService;
use App\Services\ExcelExtractionService;
use App\Services\PdfConversionService;
use App\Services\SignatureImageService;
use App\Support\FormFieldAliasResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentGeneratorController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:uploadedAt,generatedAt,pdfStatus,sourceRowNumber,created_at,updated_at,status,row_number'],
            'direction' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:queued,processing,docx_done,pdf_done,failed'],
        ]);

        $sort = (string) ($validated['sort'] ?? 'uploadedAt');
        $sortBy = match ($sort) {
            'uploadedAt' => 'created_at',
            'generatedAt' => 'updated_at',
            'pdfStatus' => 'status',
            'sourceRowNumber' => 'row_number',
            default => $sort,
        };
        $direction = (string) ($validated['direction'] ?? 'desc');
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';

        /** @var User $user */
        $user = $request->user();
        $signatureEnabled = $this->signatureFeatureEnabled();

        return Inertia::render('DocumentGenerator', [
            'initialItems' => $this->allItemsPayload($request, [
                'per_page' => $validated['per_page'] ?? null,
                'sort_by' => $sortBy,
                'sort_direction' => $direction,
                'status' => $validated['status'] ?? null,
                'company_search' => $search !== '' ? $search : null,
                'unsigned_only' => true,
            ]),
            'initialFilters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
                'status' => (string) ($validated['status'] ?? 'all'),
                'per_page' => (int) ($validated['per_page'] ?? 25),
            ],
            'initialMapping' => $this->globalTemplateMappingPayload(),
            'initialSignature' => $signatureEnabled ? $this->signaturePayload($user) : ['signature' => null],
            'signatureEnabled' => $signatureEnabled,
        ]);
    }

    public function signature(Request $request): JsonResponse
    {
        $this->ensureSignatureFeatureEnabledOr404();

        /** @var User $user */
        $user = $request->user();

        return response()->json($this->signaturePayload($user));
    }

    public function storeSignature(Request $request, SignatureImageService $signatureImageService): JsonResponse
    {
        $this->ensureSignatureFeatureEnabledOr404();

        $validated = $request->validate([
            'signature_file' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'page2_anchor' => ['required', 'in:top_left,top_right,bottom_left,bottom_right,center'],
            'page2_placement_mode' => ['nullable', 'in:fixed,text_anchor'],
            'page2_anchor_text' => ['nullable', 'string', 'max:255', 'required_if:page2_placement_mode,text_anchor'],
            'page2_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page2_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page2_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page2_height' => ['required', 'numeric', 'min:1', 'max:300'],
            'page3_anchor' => ['required', 'in:top_left,top_right,bottom_left,bottom_right,center'],
            'page3_placement_mode' => ['nullable', 'in:fixed,text_anchor'],
            'page3_anchor_text' => ['nullable', 'string', 'max:255', 'required_if:page3_placement_mode,text_anchor'],
            'page3_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page3_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page3_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page3_height' => ['required', 'numeric', 'min:1', 'max:300'],
            'page4_anchor' => ['required', 'in:top_left,top_right,bottom_left,bottom_right,center'],
            'page4_placement_mode' => ['nullable', 'in:fixed,text_anchor'],
            'page4_anchor_text' => ['nullable', 'string', 'max:255', 'required_if:page4_placement_mode,text_anchor'],
            'page4_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page4_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page4_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page4_height' => ['required', 'numeric', 'min:1', 'max:300'],
            'page8_anchor' => ['required', 'in:top_left,top_right,bottom_left,bottom_right,center'],
            'page8_placement_mode' => ['nullable', 'in:fixed,text_anchor'],
            'page8_anchor_text' => ['nullable', 'string', 'max:255', 'required_if:page8_placement_mode,text_anchor'],
            'page8_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page8_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page8_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page8_height' => ['required', 'numeric', 'min:1', 'max:300'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $signature = DocumentGeneratorSignature::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $request->hasFile('signature_file') && ! $signature instanceof DocumentGeneratorSignature) {
            return response()->json([
                'message' => 'Signature image is required.',
                'errors' => [
                    'signature_file' => ['Signature image is required.'],
                ],
            ], 422);
        }

        $processedPath = $signature?->processed_signature_path;
        $originalPath = $signature?->original_signature_path;
        $oldPaths = [];

        if ($request->hasFile('signature_file')) {
            $uploaded = $request->file('signature_file');
            if (! $uploaded) {
                return response()->json(['message' => 'Signature file upload failed.'], 422);
            }

            $oldPaths = array_filter([
                $signature?->processed_signature_path,
                $signature?->original_signature_path,
            ]);

            $processedTempPath = $signatureImageService->processToTransparentPng(
                $uploaded->getPathname(),
            );
            $originalPath = $uploaded->store("document-generator/{$user->id}/signature", \App\Support\DocumentStorage::diskName());

            $processedPath = "document-generator/{$user->id}/signature/processed-".Str::uuid().'.png';
            $processedFile = new File($processedTempPath);
            \App\Support\DocumentStorage::disk()->putFileAs(
                "document-generator/{$user->id}/signature",
                $processedFile,
                basename($processedPath),
            );
            @unlink($processedTempPath);
        }

        if (! is_string($processedPath) || trim($processedPath) === '') {
            return response()->json(['message' => 'Processed signature was not generated.'], 422);
        }

        $attributes = [
            'processed_signature_path' => $processedPath,
            'original_signature_path' => $originalPath,
            'anchor' => (string) $validated['page2_anchor'],
            'offset_x' => (float) $validated['page2_offset_x'],
            'offset_y' => (float) $validated['page2_offset_y'],
            'width' => (float) $validated['page2_width'],
            'height' => (float) $validated['page2_height'],
        ];

        if ($this->supportsPageSpecificSignatureLayout()) {
            $attributes = [
                ...$attributes,
                'page2_anchor' => (string) $validated['page2_anchor'],
                'page2_offset_x' => (float) $validated['page2_offset_x'],
                'page2_offset_y' => (float) $validated['page2_offset_y'],
                'page2_width' => (float) $validated['page2_width'],
                'page2_height' => (float) $validated['page2_height'],
                'page3_anchor' => (string) $validated['page3_anchor'],
                'page3_offset_x' => (float) $validated['page3_offset_x'],
                'page3_offset_y' => (float) $validated['page3_offset_y'],
                'page3_width' => (float) $validated['page3_width'],
                'page3_height' => (float) $validated['page3_height'],
            ];
        }

        if ($this->supportsGetorPageSpecificSignatureLayout()) {
            $attributes = [
                ...$attributes,
                'page4_anchor' => (string) $validated['page4_anchor'],
                'page4_offset_x' => (float) $validated['page4_offset_x'],
                'page4_offset_y' => (float) $validated['page4_offset_y'],
                'page4_width' => (float) $validated['page4_width'],
                'page4_height' => (float) $validated['page4_height'],
                'page8_anchor' => (string) $validated['page8_anchor'],
                'page8_offset_x' => (float) $validated['page8_offset_x'],
                'page8_offset_y' => (float) $validated['page8_offset_y'],
                'page8_width' => (float) $validated['page8_width'],
                'page8_height' => (float) $validated['page8_height'],
            ];
        }

        if ($this->supportsTextAnchorSignatureLayout()) {
            $attributes = [
                ...$attributes,
                'page2_placement_mode' => (string) ($validated['page2_placement_mode'] ?? 'fixed'),
                'page2_anchor_text' => $this->normalizeAnchorText($validated['page2_anchor_text'] ?? null),
                'page3_placement_mode' => (string) ($validated['page3_placement_mode'] ?? 'fixed'),
                'page3_anchor_text' => $this->normalizeAnchorText($validated['page3_anchor_text'] ?? null),
                'page4_placement_mode' => (string) ($validated['page4_placement_mode'] ?? 'fixed'),
                'page4_anchor_text' => $this->normalizeAnchorText($validated['page4_anchor_text'] ?? null),
                'page8_placement_mode' => (string) ($validated['page8_placement_mode'] ?? 'fixed'),
                'page8_anchor_text' => $this->normalizeAnchorText($validated['page8_anchor_text'] ?? null),
            ];
        }

        DocumentGeneratorSignature::query()->updateOrCreate(
            ['user_id' => $user->id],
            $attributes,
        );

        $this->deleteSignatureFiles($oldPaths);

        return response()->json($this->signaturePayload($user->fresh('documentGeneratorSignature')));
    }

    public function destroySignature(Request $request): JsonResponse
    {
        $this->ensureSignatureFeatureEnabledOr404();

        /** @var User $user */
        $user = $request->user();
        $signature = DocumentGeneratorSignature::query()
            ->where('user_id', $user->id)
            ->first();

        if ($signature instanceof DocumentGeneratorSignature) {
            $paths = array_filter([
                $signature->processed_signature_path,
                $signature->original_signature_path,
            ]);
            $signature->delete();
            $this->deleteSignatureFiles($paths);
        }

        return response()->json([
            'signature' => null,
        ]);
    }

    public function signaturePreview(Request $request): StreamedResponse
    {
        $this->ensureSignatureFeatureEnabledOr404();

        /** @var User $user */
        $user = $request->user();
        $signature = DocumentGeneratorSignature::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $signature instanceof DocumentGeneratorSignature) {
            abort(404);
        }

        $path = $signature->processed_signature_path;
        if (! \App\Support\DocumentStorage::disk()->exists($path)) {
            abort(404);
        }

        return \App\Support\DocumentStorage::disk()->response($path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function generatedFiles(Request $request, DocumentGeneratorCompletedExportService $completedExportService): Response
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_by' => ['nullable', 'in:created_at,status,row_number,updated_at'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:pdf_done'],
            'company_search' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('GeneratedFiles', [
            'initialItems' => $this->allItemsPayload($request, [
                ...$validated,
                'completed_only' => true,
            ]),
            'initialExportState' => $completedExportService->getState((int) $user->getKey()),
        ]);
    }

    public function queueCompletedDownload(
        Request $request,
        DocumentGeneratorCompletedExportService $completedExportService,
    ): JsonResponse {
        $validated = $request->validate([
            'company_search' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', 'in:created_at,status,row_number,updated_at'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'item_ids' => ['nullable', 'array', 'min:1'],
            'item_ids.*' => ['required', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $userId = (int) $user->getKey();

        if ($this->completedExportIsBusy($userId, $completedExportService)) {
            return response()->json([
                'message' => 'A completed files export is already processing.',
                'export_state' => $completedExportService->getState($userId),
            ], 409);
        }

        $query = $this->completedItemsQuery($user, $validated);
        $itemIds = collect($validated['item_ids'] ?? [])
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($itemIds !== []) {
            $query->whereIn('id', $itemIds);
        }

        $resolvedIds = $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($resolvedIds === []) {
            return response()->json([
                'message' => 'No completed files matched this export request.',
            ], 422);
        }

        $completedExportService->forgetState($userId);
        $completedExportService->putState($userId, [
            'status' => DocumentGeneratorCompletedExportService::STATUS_QUEUED,
            'error' => null,
            'itemCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
        ]);

        ProcessDocumentGeneratorCompletedExport::dispatch($userId, $resolvedIds);

        return response()->json([
            'message' => 'Completed files export queued. Your ZIP will be ready shortly.',
            'export_state' => $completedExportService->getState($userId),
        ]);
    }

    public function completedDownloadState(
        Request $request,
        DocumentGeneratorCompletedExportService $completedExportService,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return response()->json($completedExportService->getState((int) $user->getKey()));
    }

    public function downloadCompletedPrepared(
        Request $request,
        DocumentGeneratorCompletedExportService $completedExportService,
    ): BinaryFileResponse {
        /** @var User $user */
        $user = $request->user();
        $userId = (int) $user->getKey();
        $state = $completedExportService->getState($userId);
        $cached = cache()->get($completedExportService->cacheKey($userId));

        abort_unless(
            $state['status'] === DocumentGeneratorCompletedExportService::STATUS_READY
                && is_array($cached)
                && is_string($cached['storagePath'] ?? null)
                && \App\Support\DocumentStorage::disk()->exists($cached['storagePath']),
            404,
        );

        $storagePath = (string) $cached['storagePath'];

        return response()->download(
            \App\Support\DocumentStorage::disk()->path($storagePath),
            'afs-completed-files.zip',
            ['Content-Type' => 'application/zip'],
        )->deleteFileAfterSend(true);
    }

    public function destroyCompletedItemsBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['required', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $itemIds = collect($validated['item_ids'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $query = $this->completedItemsQuery($user, []);
        $items = $query
            ->whereIn('id', $itemIds->all())
            ->get(['id', 'document_batch_id']);

        if ($items->isEmpty()) {
            return response()->json([
                'message' => 'No completed rows matched the selected items.',
            ], 422);
        }

        $deleted = 0;
        DB::transaction(function () use ($items, &$deleted): void {
            /** @var array<int, list<int>> $batchItemMap */
            $batchItemMap = [];
            foreach ($items as $item) {
                $batchId = (int) $item->document_batch_id;
                $batchItemMap[$batchId] ??= [];
                $batchItemMap[$batchId][] = (int) $item->id;
            }

            foreach ($batchItemMap as $batchId => $batchItemIds) {
                $lockedBatch = DocumentBatch::query()->lockForUpdate()->find($batchId);
                if (! $lockedBatch instanceof DocumentBatch) {
                    continue;
                }

                $itemModels = DocumentBatchItem::query()
                    ->where('document_batch_id', $batchId)
                    ->whereIn('id', $batchItemIds)
                    ->lockForUpdate()
                    ->get();

                foreach ($itemModels as $itemModel) {
                    $itemModel->delete();
                    $deleted++;
                }

                $this->recalculateBatchState($lockedBatch);
            }
        });

        return response()->json([
            'message' => $deleted === 1
                ? 'Deleted 1 completed row.'
                : "Deleted {$deleted} completed rows.",
            'deleted_count' => $deleted,
        ]);
    }

    public function templateMapping(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('TemplateMapping', [
            'mapping' => $this->globalTemplateMappingPayload(),
            'initialSignature' => $this->signatureFeatureEnabled() ? $this->signaturePayload($user) : ['signature' => null],
            'signatureEnabled' => $this->signatureFeatureEnabled(),
        ]);
    }

    public function generatedFilesTemplateMapping(Request $request, DocumentBatch $batch): Response
    {
        $this->assertBatchOwnership($request, $batch);

        return Inertia::render('BatchTemplateMapping', [
            'batch' => $this->templateMappingBatchPayload($batch),
        ]);
    }

    public function generatedFilesBatch(Request $request, DocumentBatch $batch): Response
    {
        $this->assertBatchOwnership($request, $batch);

        return Inertia::render('GeneratedBatchItems', [
            'batch' => $this->historyBatchPayload($batch),
            'signatureEnabled' => $this->signatureFeatureEnabled(),
        ]);
    }

    public function store(
        DocumentBatchStoreRequest $request,
        ExcelExtractionService $excelExtractionService
    ): JsonResponse {
        $sheetIndex = 0;
        $excelPath = null;
        $storedTemplatePaths = [];
        $batch = null;

        try {
            $excelFile = $request->file('excel_file');
            $defaultTemplateFile = $request->file('default_template_file');
            if (! $excelFile) {
                return response()->json(['message' => 'Files are required.'], 422);
            }

            $excelPath = $excelFile->store("document-generator/{$request->user()->id}/uploads", \App\Support\DocumentStorage::diskName());
            $resolvedTemplates = $this->resolveTemplatesForBatch($request, $defaultTemplateFile);
            $defaultTemplate = $resolvedTemplates['default'];
            $yearTemplatePayload = $resolvedTemplates['year_templates'];

            $storedTemplatePaths = array_values(array_filter([
                $defaultTemplate['template_path'] ?? null,
                ...array_map(static fn (array $template): ?string => $template['template_path'] ?? null, $yearTemplatePayload),
            ]));

            $extracted = $excelExtractionService->extractFromDocumentStorage($excelPath, $sheetIndex);
            $headers = $extracted['headers'];
            $rows = $extracted['rows'];
            $previousWorkbookPath = $this->resolvePreviousWorkbookPath($request->user()->id);

            if ($previousWorkbookPath !== null) {
                $previousWorkbookRows = $excelExtractionService->extractFromDocumentStorage($previousWorkbookPath, $sheetIndex)['rows'];
                $rows = $this->enrichRowsWithPreviousWorkbookData($rows, $previousWorkbookRows);
                $headers = $this->mergeHeadersWithRows($headers, $rows);
            }

            $batch = DB::transaction(function () use (
                $request,
                $headers,
                $rows,
                $sheetIndex,
                $excelFile,
                $excelPath,
                $defaultTemplate,
                $yearTemplatePayload
            ): DocumentBatch {
                $batch = DocumentBatch::query()->create([
                    'user_id' => $request->user()->id,
                    'source_excel_name' => $excelFile->getClientOriginalName(),
                    'template_name' => $defaultTemplate['template_name'],
                    'excel_path' => $excelPath,
                    'template_path' => $defaultTemplate['template_path'],
                    'sheet_index' => $sheetIndex,
                    'headers_json' => $headers,
                    'total_items' => count($rows),
                    'status' => count($rows) > 0 ? 'queued' : 'completed',
                    'completed_at' => count($rows) > 0 ? null : now(),
                ]);

                $templatePayload = [[
                    'document_batch_id' => $batch->id,
                    'year' => null,
                    'template_name' => $defaultTemplate['template_name'],
                    'template_path' => $defaultTemplate['template_path'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]];

                foreach ($yearTemplatePayload as $template) {
                    $templatePayload[] = [
                        'document_batch_id' => $batch->id,
                        'year' => $template['year'],
                        'template_name' => $template['template_name'],
                        'template_path' => $template['template_path'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DocumentBatchTemplate::query()->insert($templatePayload);

                if ($rows !== []) {
                    $itemPayload = [];
                    foreach ($rows as $index => $rowData) {
                        $itemPayload[] = [
                            'document_batch_id' => $batch->id,
                            'row_number' => $index + 2,
                            'row_data' => json_encode($rowData, JSON_THROW_ON_ERROR),
                            'status' => 'queued',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    DocumentBatchItem::query()->insert($itemPayload);
                }

                return $batch;
            });

            $dispatchFailures = 0;
            $firstDispatchFailureMessage = null;
            $initialGenerationDelaySeconds = $this->generationQueueInitialDelaySeconds();
            DocumentBatchItem::query()
                ->where('document_batch_id', $batch->id)
                ->pluck('id')
                ->each(function (int $itemId) use (&$dispatchFailures, &$firstDispatchFailureMessage, $initialGenerationDelaySeconds): void {
                    try {
                        GenerateDocumentBatchItemJob::dispatch($itemId)
                            ->delay(now()->addSeconds($initialGenerationDelaySeconds));
                    } catch (Throwable $exception) {
                        $dispatchFailures++;
                        if ($firstDispatchFailureMessage === null) {
                            $firstDispatchFailureMessage = $exception->getMessage();
                        }
                        Log::error('Document batch item dispatch failed.', [
                            'item_id' => $itemId,
                            'exception' => $exception,
                        ]);
                    }
                });

            if ($dispatchFailures > 0 && $batch->total_items > 0) {
                $dispatchedCount = max(0, $batch->total_items - $dispatchFailures);
                if ($dispatchedCount === 0) {
                    $batch->status = 'failed';
                    $batch->completed_at = now();
                }
                $batch->save();

                return response()->json([
                    'batch_id' => $batch->id,
                    'status' => $batch->status,
                    'total_items' => $batch->total_items,
                    'message' => $dispatchedCount === 0
                        ? ($firstDispatchFailureMessage !== null && trim($firstDispatchFailureMessage) !== ''
                            ? $firstDispatchFailureMessage
                            : 'Batch was created, but processing jobs could not be queued. Check queue/database configuration.')
                        : "Batch created. {$dispatchedCount} of {$batch->total_items} rows were queued for processing.",
                ], 201);
            }

            return response()->json([
                'batch_id' => $batch->id,
                'status' => $batch->status,
                'total_items' => $batch->total_items,
                'message' => 'Document generation has started for the uploaded file.',
            ], 201);
        } catch (ValidationException $exception) {
            if (! $batch instanceof DocumentBatch) {
                $this->deleteTemplateFiles($storedTemplatePaths);
                if (is_string($excelPath) && $excelPath !== '' && \App\Support\DocumentStorage::disk()->exists($excelPath)) {
                    \App\Support\DocumentStorage::disk()->delete($excelPath);
                }
            }

            $errors = $exception->errors();

            return response()->json([
                'message' => $this->firstValidationMessage($errors, 'Validation failed.'),
                'errors' => $errors,
            ], 422);
        } catch (InvalidArgumentException $exception) {
            if (! $batch instanceof DocumentBatch) {
                $this->deleteTemplateFiles($storedTemplatePaths);
                if (is_string($excelPath) && $excelPath !== '' && \App\Support\DocumentStorage::disk()->exists($excelPath)) {
                    \App\Support\DocumentStorage::disk()->delete($excelPath);
                }
            }

            $message = trim($exception->getMessage());

            return response()->json([
                'message' => $message !== '' ? $message : 'The uploaded Excel file could not be processed.',
            ], 422);
        } catch (QueryException $exception) {
            if (! $batch instanceof DocumentBatch) {
                $this->deleteTemplateFiles($storedTemplatePaths);
                if (is_string($excelPath) && $excelPath !== '' && \App\Support\DocumentStorage::disk()->exists($excelPath)) {
                    \App\Support\DocumentStorage::disk()->delete($excelPath);
                }
            }

            Log::error('Document batch creation failed due to database error.', [
                'exception' => $exception,
            ]);

            return response()->json([
                'message' => 'The batch could not be created because the database is unavailable. Please try again.',
            ], 503);
        } catch (Throwable $exception) {
            if (! $batch instanceof DocumentBatch) {
                $this->deleteTemplateFiles($storedTemplatePaths);
                if (is_string($excelPath) && $excelPath !== '' && \App\Support\DocumentStorage::disk()->exists($excelPath)) {
                    \App\Support\DocumentStorage::disk()->delete($excelPath);
                }
            }

            Log::error('Document batch creation failed unexpectedly.', [
                'exception' => $exception,
            ]);

            return response()->json([
                'message' => 'The batch could not be created right now. Please try again.',
            ], 500);
        }
    }

    public function progress(Request $request, DocumentBatch $batch): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);

        return response()->json($this->batchProgressPayload($batch));
    }

    public function items(Request $request, DocumentBatch $batch): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_by' => ['nullable', 'in:row_number,status,created_at,updated_at'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:queued,processing,docx_done,pdf_done,failed'],
            'company_search' => ['nullable', 'string', 'max:255'],
            'signature_filter' => ['nullable', 'in:signed,unsigned'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $sortBy = (string) ($validated['sort_by'] ?? 'row_number');
        $sortDirection = (string) ($validated['sort_direction'] ?? 'asc');

        $query = DocumentBatchItem::query()->where('document_batch_id', $batch->id);
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['company_search']) && trim($validated['company_search']) !== '') {
            $this->applyCompanySearch(
                $query,
                $validated['company_search'],
                FormFieldAliasResolver::FORM_AFS,
            );
        }
        if (isset($validated['signature_filter']) && $this->supportsItemSignatureAppliedAt()) {
            if ($validated['signature_filter'] === 'signed') {
                $query->whereNotNull('signature_applied_at');
            }
            if ($validated['signature_filter'] === 'unsigned') {
                $query->whereNull('signature_applied_at');
            }
        }

        $items = $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage)
            ->through(static function (DocumentBatchItem $item): array {
                return [
                    'id' => $item->id,
                    'row_number' => $item->row_number,
                    'company' => self::extractCompanyFromRowData($item->row_data ?? []),
                    'tin' => FormFieldAliasResolver::resolveTin(
                        $item->row_data ?? [],
                        FormFieldAliasResolver::FORM_AFS,
                    ),
                    'status' => $item->status,
                    'row_data' => $item->row_data ?? [],
                    'docx_available' => ! empty($item->docx_path),
                    'pdf_available' => ! empty($item->pdf_path),
                    'signature_applied' => $item->signature_applied_at !== null,
                    'signature_applied_at' => $item->signature_applied_at?->toISOString(),
                    'error_message' => $item->error_message,
                    'error_details' => $item->error_details ?? null,
                    'created_at' => $item->created_at?->toISOString(),
                    'updated_at' => $item->updated_at?->toISOString(),
                ];
            });

        return response()->json($items);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'history_per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_by' => ['nullable', 'in:created_at,status,total_items,processed_items'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);

        return response()->json($this->historyPayload($request, $validated));
    }

    public function allItems(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_by' => ['nullable', 'in:created_at,status,row_number,updated_at'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:queued,processing,docx_done,pdf_done,failed'],
            'company_search' => ['nullable', 'string', 'max:255'],
            'signature_filter' => ['nullable', 'in:signed,unsigned'],
            'files_only' => ['nullable', 'boolean'],
            'completed_only' => ['nullable', 'boolean'],
            'unsigned_only' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->allItemsPayload($request, $validated));
    }

    public function download(
        Request $request,
        DocumentBatch $batch,
        DocumentBatchItem $item,
        string $type
    ): StreamedResponse|BinaryFileResponse {
        $this->assertBatchOwnership($request, $batch);
        $this->assertItemBelongsToBatch($batch, $item);

        if (! in_array($type, ['docx', 'pdf'], true)) {
            abort(404);
        }

        $path = $type === 'docx' ? $item->docx_path : $item->pdf_path;
        if (! is_string($path) || $path === '' || ! \App\Support\DocumentStorage::disk()->exists($path)) {
            abort(404);
        }

        if ($type === 'pdf') {
            return \App\Support\DocumentStorage::disk()->response($path, null, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"batch-{$batch->id}-row-{$item->row_number}.pdf\"",
            ]);
        }

        return \App\Support\DocumentStorage::disk()->download($path, "batch-{$batch->id}-row-{$item->row_number}.docx");
    }

    public function showItem(Request $request, DocumentBatch $batch, DocumentBatchItem $item): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);
        $this->assertItemBelongsToBatch($batch, $item);

        return response()->json($this->batchItemPayload($item));
    }

    public function preflightAnchorCheck(
        Request $request,
        DocumentBatch $batch,
        DocumentBatchItem $item,
        DocxTemplateService $docxTemplateService,
    ): JsonResponse {
        $this->ensureSignatureFeatureEnabledOr404();
        $this->assertBatchOwnership($request, $batch);
        $this->assertItemBelongsToBatch($batch, $item);

        /** @var User $user */
        $user = $request->user();
        $this->resolveSignatureOrFail($user);

        if ($this->canUseDocxSignaturePlaceholderFlow($item, $docxTemplateService)) {
            return response()->json([
                'ok' => true,
                'message' => 'DOCX signature placeholders detected. Preflight passed.',
                'targets' => [],
            ]);
        }

        return response()->json([
            'ok' => false,
            'message' => 'DOCX signature placeholders were not found in this generated file. Regenerate with {president_signature} and {getor_signature} placeholders.',
            'targets' => [],
        ], 422);
    }

    public function signItem(
        Request $request,
        DocumentBatch $batch,
        DocumentBatchItem $item,
        DocxTemplateService $docxTemplateService,
        PdfConversionService $pdfConversionService,
        SignatureImageService $signatureImageService
    ): JsonResponse {
        $this->ensureSignatureFeatureEnabledOr404();

        $this->assertBatchOwnership($request, $batch);
        $this->assertItemBelongsToBatch($batch, $item);

        $validated = $request->validate([
            'president_signature_file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $presidentSignature = $request->file('president_signature_file');
        if (! $presidentSignature instanceof UploadedFile) {
            return response()->json([
                'message' => 'President signature image is required.',
                'errors' => [
                    'president_signature_file' => ['President signature image is required.'],
                ],
            ], 422);
        }

        $processedPresidentSignaturePath = $this->processPresidentSignatureUpload(
            $presidentSignature,
            $signatureImageService
        );

        try {
            try {
                $this->signSingleItem(
                    $request->user(),
                    $item,
                    $docxTemplateService,
                    $pdfConversionService,
                    $processedPresidentSignaturePath
                );
            } catch (ValidationException $exception) {
                $errors = $exception->errors();

                return response()->json([
                    'message' => $this->firstValidationMessage(
                        $errors,
                        'Unable to apply signature. Switch to fixed placement or update anchor text.'
                    ),
                    'errors' => $errors,
                ], 422);
            }
        } finally {
            @unlink($processedPresidentSignaturePath);
        }

        return response()->json([
            'message' => 'Signature applied.',
            'item' => $this->batchItemPayload($item->fresh() ?? $item),
            'pdf_url' => route('document-generator.batches.items.download', [$batch, $item, 'pdf']),
        ]);
    }

    public function signItemsBulk(
        Request $request,
        DocxTemplateService $docxTemplateService,
        PdfConversionService $pdfConversionService,
        SignatureImageService $signatureImageService
    ): JsonResponse {
        $this->ensureSignatureFeatureEnabledOr404();

        $validated = $request->validate([
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.batch_id' => ['required', 'integer'],
            'targets.*.item_id' => ['required', 'integer'],
            'president_signature_file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $presidentSignature = $request->file('president_signature_file');
        if (! $presidentSignature instanceof UploadedFile) {
            return response()->json([
                'message' => 'President signature image is required.',
                'errors' => [
                    'president_signature_file' => ['President signature image is required.'],
                ],
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $this->resolveSignatureOrFail($user);

        $processedPresidentSignaturePath = $this->processPresidentSignatureUpload(
            $presidentSignature,
            $signatureImageService
        );

        $results = [];
        try {
            foreach ($validated['targets'] as $target) {
                $batchId = (int) $target['batch_id'];
                $itemId = (int) $target['item_id'];

                $batch = DocumentBatch::query()->find($batchId);
                $item = DocumentBatchItem::query()->find($itemId);

                if (! $batch instanceof DocumentBatch || ! $item instanceof DocumentBatchItem) {
                    $results[] = [
                        'batch_id' => $batchId,
                        'item_id' => $itemId,
                        'success' => false,
                        'message' => 'Item not found.',
                    ];
                    continue;
                }

                if ((int) $batch->user_id !== (int) $user->id || (int) $item->document_batch_id !== (int) $batch->id) {
                    $results[] = [
                        'batch_id' => $batchId,
                        'item_id' => $itemId,
                        'success' => false,
                        'message' => 'Item not accessible.',
                    ];
                    continue;
                }

                try {
                    $this->signSingleItem(
                        $user,
                        $item,
                        $docxTemplateService,
                        $pdfConversionService,
                        $processedPresidentSignaturePath
                    );
                    $results[] = [
                        'batch_id' => $batchId,
                        'item_id' => $itemId,
                        'success' => true,
                    ];
                } catch (\Throwable $exception) {
                    $results[] = [
                        'batch_id' => $batchId,
                        'item_id' => $itemId,
                        'success' => false,
                        'message' => mb_substr($exception->getMessage(), 0, 300),
                    ];
                }
            }
        } finally {
            @unlink($processedPresidentSignaturePath);
        }

        return response()->json([
            'results' => $results,
        ]);
    }

    public function updateItem(
        Request $request,
        DocumentBatch $batch,
        DocumentBatchItem $item,
        DocumentBatchActivityLogger $activityLogger
    ): JsonResponse {
        $this->assertBatchOwnership($request, $batch);
        $this->assertItemBelongsToBatch($batch, $item);

        $validated = $request->validate([
            'row_data' => ['required', 'array', 'min:1'],
            'row_data.*' => ['nullable', 'string'],
        ]);

        /** @var array<string, string|null> $submittedRowData */
        $submittedRowData = $validated['row_data'];
        $existingRowData = $item->row_data ?? [];
        $updatedRowData = [];

        foreach ($existingRowData as $key => $value) {
            $updatedRowData[$key] = (string) ($submittedRowData[$key] ?? '');
        }

        foreach ($submittedRowData as $key => $value) {
            if (! array_key_exists($key, $updatedRowData)) {
                $updatedRowData[$key] = (string) ($value ?? '');
            }
        }

        DB::transaction(function () use ($request, $batch, $item, $updatedRowData, $existingRowData, $activityLogger): void {
            $lockedItem = DocumentBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            $lockedBatch = DocumentBatch::query()->lockForUpdate()->findOrFail($batch->id);

            $oldDocxPath = $lockedItem->docx_path;
            $oldPdfPath = $lockedItem->pdf_path;
            $previousStatus = $lockedItem->status;

            if ($lockedItem->completed_at !== null) {
                $lockedBatch->processed_items = max(0, $lockedBatch->processed_items - 1);
            }
            if ($previousStatus === 'pdf_done') {
                $lockedBatch->success_items = max(0, $lockedBatch->success_items - 1);
            }
            if ($previousStatus === 'failed') {
                $lockedBatch->failed_items = max(0, $lockedBatch->failed_items - 1);
            }

            $lockedItem->row_data = $updatedRowData;
            $lockedItem->status = 'queued';
            $lockedItem->docx_path = null;
            $lockedItem->pdf_path = null;
            $lockedItem->error_message = null;
            $lockedItem->error_details = null;
            $lockedItem->started_at = null;
            $lockedItem->completed_at = null;
            if ($this->supportsItemSignatureAppliedAt()) {
                $lockedItem->signature_applied_at = null;
            }
            $lockedItem->save();

            $lockedBatch->status = $lockedBatch->total_items > 0 ? 'queued' : 'completed';
            $lockedBatch->started_at = null;
            $lockedBatch->completed_at = null;
            $lockedBatch->save();

            foreach ([$oldDocxPath, $oldPdfPath] as $path) {
                if (is_string($path) && $path !== '' && \App\Support\DocumentStorage::disk()->exists($path)) {
                    \App\Support\DocumentStorage::disk()->delete($path);
                }
            }

            /** @var User|null $actor */
            $actor = $request->user();
            $activityLogger->log(
                $lockedBatch,
                $lockedItem,
                $actor,
                'row_updated',
                "Row {$lockedItem->row_number} was edited and queued for regeneration.",
                [
                    'before' => $existingRowData,
                    'after' => $updatedRowData,
                    'previous_status' => $previousStatus,
                ]
            );

            if ($oldDocxPath || $oldPdfPath) {
                $activityLogger->log(
                    $lockedBatch,
                    $lockedItem,
                    $actor,
                    'old_outputs_deleted',
                    "Old generated files for row {$lockedItem->row_number} were deleted.",
                    [
                        'docx_path' => $oldDocxPath,
                        'pdf_path' => $oldPdfPath,
                    ]
                );
            }

            $activityLogger->log(
                $lockedBatch,
                $lockedItem,
                $actor,
                'regeneration_requested',
                "Regeneration requested for row {$lockedItem->row_number}.",
                [
                    'status' => 'queued',
                ]
            );
        });

        GenerateDocumentBatchItemJob::dispatch($item->id)
            ->delay(now()->addSeconds($this->generationQueueInitialDelaySeconds()));

        $refreshedItem = DocumentBatchItem::query()->findOrFail($item->id);

        return response()->json($this->batchItemPayload($refreshedItem));
    }

    public function logs(Request $request, DocumentBatch $batch): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);

        if (! Schema::hasTable('document_batch_item_activity_logs')) {
            return response()->json([
                'current_page' => 1,
                'data' => [],
                'last_page' => 1,
                'per_page' => 10,
                'total' => 0,
            ]);
        }

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);

        $logs = DocumentBatchItemActivityLog::query()
            ->with(['item:id,row_number', 'user:id,name'])
            ->where('document_batch_id', $batch->id)
            ->latest()
            ->paginate($perPage)
            ->through(static function (DocumentBatchItemActivityLog $log): array {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'summary' => $log->summary,
                    'details' => $log->details ?? [],
                    'created_at' => $log->created_at?->toISOString(),
                    'row_number' => $log->item?->row_number,
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                    ] : null,
                ];
            });

        return response()->json($logs);
    }

    public function destroyBatch(Request $request, DocumentBatch $batch): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);

        DB::transaction(function () use ($batch): void {
            $lockedBatch = DocumentBatch::query()->lockForUpdate()->findOrFail($batch->id);

            DocumentBatchItem::query()
                ->where('document_batch_id', $lockedBatch->id)
                ->delete();

            $lockedBatch->delete();
        });

        return response()->json([
            'message' => 'Batch deleted.',
        ]);
    }

    public function destroyItem(Request $request, DocumentBatch $batch, DocumentBatchItem $item): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);
        $this->assertItemBelongsToBatch($batch, $item);

        DB::transaction(function () use ($batch, $item): void {
            $lockedBatch = DocumentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $lockedItem = DocumentBatchItem::query()->lockForUpdate()->findOrFail($item->id);

            $lockedItem->delete();
            $this->recalculateBatchState($lockedBatch);
        });

        return response()->json([
            'message' => 'Batch item deleted.',
        ]);
    }

    public function updateGlobalDefaultTemplate(Request $request): JsonResponse
    {
        $request->validate([
            'template_file' => ['required', 'file', 'mimes:docx'],
        ]);

        $file = $request->file('template_file');
        if (! $file) {
            return response()->json(['message' => 'Template file is required.'], 422);
        }

        $defaultTemplate = DocumentGeneratorTemplate::query()->whereNull('year')->first();
        $oldPath = $defaultTemplate?->template_path;
        $templatePath = $file->store('document-generator/global-templates', \App\Support\DocumentStorage::diskName());
        $templateName = $file->getClientOriginalName();

        if ($defaultTemplate) {
            $defaultTemplate->forceFill([
                'template_name' => $templateName,
                'template_path' => $templatePath,
            ])->save();
        } else {
            DocumentGeneratorTemplate::query()->create([
                'year' => null,
                'template_name' => $templateName,
                'template_path' => $templatePath,
            ]);
        }

        $this->deleteTemplateFiles($oldPath ? [$oldPath] : []);

        return response()->json($this->globalTemplateMappingPayload());
    }

    public function storeGlobalTemplate(Request $request): JsonResponse
    {
        abort(404);
    }

    public function updateGlobalTemplate(Request $request, DocumentGeneratorTemplate $template): JsonResponse
    {
        abort(404);
    }

    public function destroyGlobalTemplate(DocumentGeneratorTemplate $template): JsonResponse
    {
        abort(404);
    }

    public function updateDefaultTemplate(Request $request, DocumentBatch $batch): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);

        $request->validate([
            'template_file' => ['required', 'file', 'mimes:docx'],
        ]);

        $file = $request->file('template_file');
        if (! $file) {
            return response()->json(['message' => 'Template file is required.'], 422);
        }

        /** @var DocumentBatchTemplate $defaultTemplate */
        $defaultTemplate = $batch->templates()->whereNull('year')->firstOrFail();
        $oldPaths = array_values(array_filter([$batch->template_path, $defaultTemplate->template_path]));

        DB::transaction(function () use ($request, $batch, $defaultTemplate, $file): void {
            $templatePath = $file->store("document-generator/{$request->user()->id}/uploads", \App\Support\DocumentStorage::diskName());
            $templateName = $file->getClientOriginalName();

            $batch->forceFill([
                'template_name' => $templateName,
                'template_path' => $templatePath,
            ])->save();

            $defaultTemplate->forceFill([
                'template_name' => $templateName,
                'template_path' => $templatePath,
            ])->save();
        });

        $this->deleteTemplateFiles($oldPaths);

        return response()->json($this->templateMappingBatchPayload($batch->fresh('templates')));
    }

    public function storeTemplate(Request $request, DocumentBatch $batch): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'digits:4'],
            'template_file' => ['required', 'file', 'mimes:docx'],
        ]);

        $year = (int) $validated['year'];
        $this->ensureUniqueTemplateYear($batch, $year);

        $file = $request->file('template_file');
        if (! $file) {
            return response()->json(['message' => 'Template file is required.'], 422);
        }

        DocumentBatchTemplate::query()->create([
            'document_batch_id' => $batch->id,
            'year' => $year,
            'template_name' => $file->getClientOriginalName(),
            'template_path' => $file->store("document-generator/{$request->user()->id}/uploads", \App\Support\DocumentStorage::diskName()),
        ]);

        return response()->json($this->templateMappingBatchPayload($batch->fresh('templates')), 201);
    }

    public function updateTemplate(Request $request, DocumentBatch $batch, DocumentBatchTemplate $template): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);
        $this->assertTemplateBelongsToBatch($batch, $template);

        abort_if($template->year === null, 404);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'digits:4'],
            'template_file' => ['nullable', 'file', 'mimes:docx'],
        ]);

        $year = (int) $validated['year'];
        $this->ensureUniqueTemplateYear($batch, $year, $template->id);

        $oldPath = null;
        $file = $request->file('template_file');

        DB::transaction(function () use ($request, $template, $year, $file, &$oldPath): void {
            $updates = [
                'year' => $year,
            ];

            if ($file) {
                $oldPath = $template->template_path;
                $updates['template_name'] = $file->getClientOriginalName();
                $updates['template_path'] = $file->store("document-generator/{$request->user()->id}/uploads", \App\Support\DocumentStorage::diskName());
            }

            $template->forceFill($updates)->save();
        });

        $this->deleteTemplateFiles($oldPath ? [$oldPath] : []);

        return response()->json($this->templateMappingBatchPayload($batch->fresh('templates')));
    }

    public function destroyTemplate(Request $request, DocumentBatch $batch, DocumentBatchTemplate $template): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);
        $this->assertTemplateBelongsToBatch($batch, $template);

        abort_if($template->year === null, 404);

        $oldPath = $template->template_path;
        $template->delete();

        $this->deleteTemplateFiles([$oldPath]);

        return response()->json($this->templateMappingBatchPayload($batch->fresh('templates')));
    }

    private function assertBatchOwnership(Request $request, DocumentBatch $batch): void
    {
        abort_unless($batch->user_id === $request->user()->id, 404);
    }

    private function assertItemBelongsToBatch(DocumentBatch $batch, DocumentBatchItem $item): void
    {
        abort_unless($item->document_batch_id === $batch->id, 404);
    }

    private function assertTemplateBelongsToBatch(DocumentBatch $batch, DocumentBatchTemplate $template): void
    {
        abort_unless($template->document_batch_id === $batch->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function historyPayload(Request $request, ?array $validated): array
    {
        $validated ??= [];
        $perPage = (int) ($validated['history_per_page'] ?? $request->integer('history_per_page', 10));
        $perPage = max(5, min($perPage, 100));
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = (string) ($validated['sort_direction'] ?? 'desc');

        return $request->user()
            ->documentBatches()
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage)
            ->through(fn (DocumentBatch $batch): array => $this->historyBatchPayload($batch))
            ->toArray();
    }

    /**
     * @return array<string, int|string|null>
     */
    private function historyBatchPayload(DocumentBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'source_excel_name' => $batch->source_excel_name,
            'template_name' => $batch->template_name,
            'status' => $batch->status,
            'total_items' => $batch->total_items,
            'processed_items' => $batch->processed_items,
            'success_items' => $batch->success_items,
            'failed_items' => $batch->failed_items,
            'created_at' => $batch->created_at?->toISOString(),
            'completed_at' => $batch->completed_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function allItemsPayload(Request $request, ?array $validated): array
    {
        $validated ??= [];
        $perPage = (int) ($validated['per_page'] ?? $request->integer('per_page', 25));
        $perPage = max(5, min($perPage, 100));
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = (string) ($validated['sort_direction'] ?? 'desc');
        $filesOnly = (bool) ($validated['files_only'] ?? false);
        $completedOnly = (bool) ($validated['completed_only'] ?? false);
        $unsignedOnly = (bool) ($validated['unsigned_only'] ?? false);

        $query = DocumentBatchItem::query()
            ->with([
                'batch:id,user_id,source_excel_name,template_name',
            ])
            ->whereHas('batch', static function (Builder $batchQuery) use ($request): void {
                $batchQuery->where('user_id', $request->user()->id);
            });

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['company_search']) && trim($validated['company_search']) !== '') {
            $this->applyCompanySearch(
                $query,
                $validated['company_search'],
                FormFieldAliasResolver::FORM_AFS,
            );
        }
        if (! $completedOnly && isset($validated['signature_filter']) && $this->supportsItemSignatureAppliedAt()) {
            if ($validated['signature_filter'] === 'signed') {
                $query->whereNotNull('signature_applied_at');
            }
            if ($validated['signature_filter'] === 'unsigned') {
                $query->whereNull('signature_applied_at');
            }
        }

        if (! $completedOnly && $unsignedOnly && $this->supportsItemSignatureAppliedAt()) {
            $query->whereNull('signature_applied_at');
        }

        if ($completedOnly) {
            $query->where('status', 'pdf_done');
            if ($this->supportsItemSignatureAppliedAt()) {
                $query->whereNotNull('signature_applied_at');
            }
        }

        if ($filesOnly) {
            $query->where(static function (Builder $builder): void {
                $builder
                    ->whereNotNull('docx_path')
                    ->orWhereNotNull('pdf_path');
            });
        }

        $items = $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage)
            ->through(function (DocumentBatchItem $item): array {
                $batch = $item->batch;

                return [
                    ...$this->batchItemPayload($item),
                    'batch_id' => $item->document_batch_id,
                    'source_excel_name' => $batch?->source_excel_name ?? '',
                    'template_name' => $batch?->template_name ?? '',
                ];
            });

        return $items->toArray();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function completedItemsQuery(User $user, array $filters): Builder
    {
        $query = DocumentBatchItem::query()
            ->where('status', 'pdf_done')
            ->whereHas('batch', static function (Builder $batchQuery) use ($user): void {
                $batchQuery->where('user_id', $user->id);
            });

        if ($this->supportsItemSignatureAppliedAt()) {
            $query->whereNotNull('signature_applied_at');
        }

        if (isset($filters['company_search']) && is_string($filters['company_search']) && trim($filters['company_search']) !== '') {
            $this->applyCompanySearch(
                $query,
                $filters['company_search'],
                FormFieldAliasResolver::FORM_AFS,
            );
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        $sortDirection = (string) ($filters['sort_direction'] ?? 'desc');
        $sortBy = in_array($sortBy, ['created_at', 'status', 'row_number', 'updated_at'], true) ? $sortBy : 'created_at';
        $sortDirection = in_array($sortDirection, ['asc', 'desc'], true) ? $sortDirection : 'desc';

        return $query->orderBy($sortBy, $sortDirection);
    }

    private function completedExportIsBusy(
        int $userId,
        DocumentGeneratorCompletedExportService $completedExportService,
    ): bool {
        $state = $completedExportService->getState($userId);

        return in_array(
            $state['status'],
            [
                DocumentGeneratorCompletedExportService::STATUS_QUEUED,
                DocumentGeneratorCompletedExportService::STATUS_PROCESSING,
            ],
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function templateMappingBatchPayload(DocumentBatch $batch): array
    {
        $batch->loadMissing('templates');

        /** @var DocumentBatchTemplate|null $defaultTemplate */
        $defaultTemplate = $batch->templates->first(static fn (DocumentBatchTemplate $template): bool => $template->year === null);

        return [
            ...$this->historyBatchPayload($batch),
            'default_template' => $defaultTemplate ? $this->templatePayload($defaultTemplate) : null,
            'year_templates' => $batch->templates
                ->filter(static fn (DocumentBatchTemplate $template): bool => $template->year !== null)
                ->sortBy('year')
                ->values()
                ->map(fn (DocumentBatchTemplate $template): array => $this->templatePayload($template))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function globalTemplateMappingPayload(): array
    {
        /** @var DocumentGeneratorTemplate|null $defaultTemplate */
        $defaultTemplate = DocumentGeneratorTemplate::query()
            ->whereNull('year')
            ->first();

        return [
            'default_template' => $defaultTemplate ? $this->globalTemplatePayload($defaultTemplate) : null,
            'year_templates' => [],
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function batchProgressPayload(DocumentBatch $batch): array
    {
        $total = max(1, $batch->total_items);
        $percent = (int) floor(($batch->processed_items / $total) * 100);

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'total_items' => $batch->total_items,
            'processed_items' => $batch->processed_items,
            'success_items' => $batch->success_items,
            'failed_items' => $batch->failed_items,
            'progress_percent' => $batch->total_items === 0 ? 100 : min(100, $percent),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function batchItemPayload(DocumentBatchItem $item): array
    {
        return [
            'id' => $item->id,
            'row_number' => $item->row_number,
            'company' => self::extractCompanyFromRowData($item->row_data ?? []),
            'tin' => FormFieldAliasResolver::resolveTin(
                $item->row_data ?? [],
                FormFieldAliasResolver::FORM_AFS,
            ),
            'status' => $item->status,
            'row_data' => $item->row_data ?? [],
            'docx_available' => ! empty($item->docx_path),
            'pdf_available' => ! empty($item->pdf_path),
            'signature_applied' => $item->signature_applied_at !== null,
            'signature_applied_at' => $item->signature_applied_at?->toISOString(),
            'error_message' => $item->error_message,
            'error_details' => $item->error_details ?? null,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }

    private function applyCompanySearch(
        Builder $query,
        string $companySearch,
        string $formType
    ): void
    {
        $rawSearch = mb_strtolower(trim($companySearch));
        $search = '%'.$rawSearch.'%';
        $normalizedSearch = '%'.(preg_replace('/[^a-z0-9]+/', '', $rawSearch) ?? '').'%';
        $tinDigits = preg_replace('/\D+/', '', $rawSearch) ?? '';
        $driver = $query->getModel()->getConnection()->getDriverName();
        $normalizedTinAliases = array_map(
            static fn (string $alias): string => FormFieldAliasResolver::normalizeKey($alias),
            FormFieldAliasResolver::aliasesFor('tin', $formType),
        );

        if ($driver === 'pgsql') {
            $query->where(function (Builder $innerQuery) use ($search, $normalizedSearch, $tinDigits, $normalizedTinAliases): void {
                $innerQuery->whereRaw(
                    "exists (
                        select 1
                        from jsonb_each_text(row_data::jsonb) as row_entry(key, value)
                        where lower(row_entry.value) like ?
                        or regexp_replace(lower(row_entry.value), '[^a-z0-9]+', '', 'g') like ?
                    )",
                    [$search, $normalizedSearch]
                );

                if ($tinDigits === '' || $normalizedTinAliases === []) {
                    return;
                }

                $tinAliasPlaceholders = implode(
                    ', ',
                    array_fill(0, count($normalizedTinAliases), '?')
                );
                $tinDigitSearch = '%'.$tinDigits.'%';

                $innerQuery->orWhereRaw(
                    "exists (
                        select 1
                        from jsonb_each_text(row_data::jsonb) as row_entry(key, value)
                        where regexp_replace(lower(row_entry.key), '[^a-z0-9]+', '', 'g')
                            in ({$tinAliasPlaceholders})
                        and regexp_replace(row_entry.value, '\\D+', '', 'g') like ?
                    )",
                    [...$normalizedTinAliases, $tinDigitSearch]
                );
            });

            return;
        }

        $query->whereRaw(
            "LOWER(CAST(row_data AS CHAR)) LIKE ?
            OR REPLACE(REPLACE(REPLACE(LOWER(CAST(row_data AS CHAR)), '-', ''), ' ', ''), '.', '') LIKE ?",
            [$search, $normalizedSearch]
        );
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    private static function extractCompanyFromRowData(array $rowData): string
    {
        $fallback = '';

        foreach ($rowData as $key => $value) {
            $normalizedKey = self::normalizeCompanyKey((string) $key);
            $stringValue = is_scalar($value) ? trim((string) $value) : '';

            if ($normalizedKey === 'company') {
                return $stringValue;
            }

            if ($fallback === '' && str_contains($normalizedKey, 'company')) {
                $fallback = $stringValue;
            }
        }

        return $fallback;
    }

    private static function normalizeCompanyKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($key)) ?? '';
    }

    private function resolvePreviousWorkbookPath(int $userId): ?string
    {
        $disk = \App\Support\DocumentStorage::disk();

        /** @var \Illuminate\Support\Collection<int, DocumentBatch> $previousBatches */
        $previousBatches = DocumentBatch::query()
            ->where('user_id', $userId)
            ->whereNotNull('excel_path')
            ->latest('id')
            ->get(['id', 'excel_path']);

        foreach ($previousBatches as $previousBatch) {
            $excelPath = $previousBatch->excel_path;
            if (! is_string($excelPath) || trim($excelPath) === '') {
                continue;
            }

            if (! $disk->exists($excelPath)) {
                continue;
            }

            return $excelPath;
        }

        return null;
    }

    /**
     * @param  list<array<string, string>>  $currentRows
     * @param  list<array<string, string>>  $previousRows
     * @return list<array<string, string>>
     */
    private function enrichRowsWithPreviousWorkbookData(array $currentRows, array $previousRows): array
    {
        $previousRowsByCompany = $this->mapRowsByCompany($previousRows);
        $enrichedRows = [];

        foreach ($currentRows as $rowData) {
            $company = trim(self::extractCompanyFromRowData($rowData));
            if ($company === '' || ! array_key_exists($company, $previousRowsByCompany)) {
                $enrichedRows[] = $rowData;

                continue;
            }

            $enrichedRows[] = $rowData + $previousRowsByCompany[$company];
        }

        return $enrichedRows;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, array<string, string>>
     */
    private function mapRowsByCompany(array $rows): array
    {
        $rowsByCompany = [];

        foreach ($rows as $rowData) {
            $company = trim(self::extractCompanyFromRowData($rowData));
            if ($company === '' || array_key_exists($company, $rowsByCompany)) {
                continue;
            }

            $rowsByCompany[$company] = $rowData;
        }

        return $rowsByCompany;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $rows
     * @return list<string>
     */
    private function mergeHeadersWithRows(array $headers, array $rows): array
    {
        $mergedHeaders = $headers;

        foreach ($rows as $rowData) {
            foreach (array_keys($rowData) as $header) {
                if (! in_array($header, $mergedHeaders, true)) {
                    $mergedHeaders[] = $header;
                }
            }
        }

        return $mergedHeaders;
    }

    private function recalculateBatchState(DocumentBatch $batch): void
    {
        $counts = DocumentBatchItem::query()
            ->where('document_batch_id', $batch->id)
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) as processed_items')
            ->selectRaw("SUM(CASE WHEN status = 'pdf_done' THEN 1 ELSE 0 END) as success_items")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_items")
            ->first();

        $totalItems = (int) ($counts?->total_items ?? 0);
        $processedItems = (int) ($counts?->processed_items ?? 0);
        $successItems = (int) ($counts?->success_items ?? 0);
        $failedItems = (int) ($counts?->failed_items ?? 0);

        $batch->total_items = $totalItems;
        $batch->processed_items = $processedItems;
        $batch->success_items = $successItems;
        $batch->failed_items = $failedItems;

        if ($totalItems === 0) {
            $batch->status = 'completed';
            $batch->started_at = null;
            $batch->completed_at = now();
            $batch->save();

            return;
        }

        if ($processedItems === 0) {
            $batch->status = 'queued';
            $batch->started_at = null;
            $batch->completed_at = null;
            $batch->save();

            return;
        }

        if ($processedItems < $totalItems) {
            $batch->status = 'processing';
            $batch->completed_at = null;
            $batch->save();

            return;
        }

        $batch->status = $failedItems > 0 && $successItems === 0 ? 'failed' : 'completed';
        $batch->completed_at = $batch->completed_at ?? now();
        $batch->save();
    }

    /**
     * @return list<array{year: int, template_name: string, template_path: string}>
     */
    private function storeYearTemplates(DocumentBatchStoreRequest $request): array
    {
        $templates = [];
        $inputTemplates = $request->input('year_templates', []);

        if (! is_array($inputTemplates)) {
            return $templates;
        }

        foreach ($inputTemplates as $index => $template) {
            if (! is_array($template)) {
                continue;
            }

            $file = $request->file("year_templates.{$index}.template_file");
            $year = $template['year'] ?? null;
            if (! $file || ! is_numeric((string) $year)) {
                continue;
            }

            $templates[] = [
                'year' => (int) $year,
                'template_name' => $file->getClientOriginalName(),
                'template_path' => $this->storeTemplateUploadWithAvailabilityCheck(
                    $file,
                    "document-generator/{$request->user()->id}/uploads",
                ),
            ];
        }

        return $templates;
    }

    /**
     * @param  array{template_name: string, template_path: string}|null  $uploadedDefaultTemplate
     * @return array{
     *   default: array{template_name: string, template_path: string},
     *   year_templates: list<array{year: int, template_name: string, template_path: string}>
     * }
     */
    private function resolveTemplatesForBatch(DocumentBatchStoreRequest $request, $uploadedDefaultTemplateFile): array
    {
        $uploadedYearTemplates = $this->storeYearTemplates($request);
        $defaultTemplate = null;

        if ($uploadedDefaultTemplateFile) {
            $defaultTemplate = [
                'template_name' => $uploadedDefaultTemplateFile->getClientOriginalName(),
                'template_path' => $this->storeTemplateUploadWithAvailabilityCheck(
                    $uploadedDefaultTemplateFile,
                    "document-generator/{$request->user()->id}/uploads",
                ),
            ];
        }

        if ($defaultTemplate !== null) {
            return [
                'default' => $defaultTemplate,
                'year_templates' => $uploadedYearTemplates,
            ];
        }

        return $this->cloneGlobalTemplatesForBatch($request->user()->id);
    }

    private function storeTemplateUploadWithAvailabilityCheck(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, \App\Support\DocumentStorage::diskName());

        if (! is_string($path) || trim($path) === '') {
            throw new \RuntimeException('Template file could not be stored.');
        }

        if (! $this->storagePathExistsWithRetry($path, 120, 500)) {
            throw new \RuntimeException("Template file is not yet available in storage. Path: {$path}.");
        }

        return $path;
    }

    private function generationQueueInitialDelaySeconds(): int
    {
        return max(0, (int) config('services.document_generator.generation_queue_initial_delay_seconds', 10));
    }

    /**
     * @return array{
     *   default: array{template_name: string, template_path: string},
     *   year_templates: list<array{year: int, template_name: string, template_path: string}>
     * }
     */
    private function cloneGlobalTemplatesForBatch(int $userId): array
    {
        $templates = DocumentGeneratorTemplate::query()->orderBy('year')->get();

        /** @var DocumentGeneratorTemplate|null $defaultTemplate */
        $defaultTemplate = $templates->first(static fn (DocumentGeneratorTemplate $template): bool => $template->year === null);

        if (! $defaultTemplate) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'default_template_file' => ['A default DOCX template is required when no global default template is configured.'],
            ]);
        }

        $clonedDefault = [
            'template_name' => $defaultTemplate->template_name,
            'template_path' => $this->copyTemplateToUserUploads($defaultTemplate->template_path, $userId, $defaultTemplate->template_name),
        ];

        $yearTemplates = $templates
            ->filter(static fn (DocumentGeneratorTemplate $template): bool => $template->year !== null)
            ->values()
            ->map(fn (DocumentGeneratorTemplate $template): array => [
                'year' => (int) $template->year,
                'template_name' => $template->template_name,
                'template_path' => $this->copyTemplateToUserUploads($template->template_path, $userId, $template->template_name),
            ])
            ->all();

        return [
            'default' => $clonedDefault,
            'year_templates' => $yearTemplates,
        ];
    }

    private function copyTemplateToUserUploads(string $sourcePath, int $userId, string $templateName): string
    {
        $extension = pathinfo($templateName, PATHINFO_EXTENSION);
        $filename = pathinfo($templateName, PATHINFO_FILENAME);
        $targetPath = "document-generator/{$userId}/uploads/{$filename}-".Str::uuid().($extension !== '' ? ".{$extension}" : '');

        $disk = \App\Support\DocumentStorage::disk();
        $copied = $disk->copy($sourcePath, $targetPath);
        if ($copied !== true) {
            throw new \RuntimeException('Unable to copy global template into user storage.');
        }

        if (! $this->storagePathExistsWithRetry($targetPath)) {
            throw new \RuntimeException('Copied template is not yet available in storage.');
        }

        return $targetPath;
    }

    private function storagePathExistsWithRetry(string $path, int $attempts = 8, int $delayMilliseconds = 250): bool
    {
        $disk = \App\Support\DocumentStorage::disk();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($disk->exists($path)) {
                return true;
            }

            if ($attempt < $attempts) {
                usleep($delayMilliseconds * 1000);
            }
        }

        return false;
    }

    /**
     * @return array{id: int, year: int|null, template_name: string}
     */
    private function templatePayload(DocumentBatchTemplate $template): array
    {
        return [
            'id' => $template->id,
            'year' => $template->year,
            'template_name' => $template->template_name,
        ];
    }

    private function ensureUniqueTemplateYear(DocumentBatch $batch, int $year, ?int $ignoreTemplateId = null): void
    {
        $query = $batch->templates()->where('year', $year);

        if ($ignoreTemplateId !== null) {
            $query->whereKeyNot($ignoreTemplateId);
        }

        if ($query->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'year' => ['Year template entries must use unique years.'],
            ]);
        }
    }

    /**
     * @return array{id: int, year: int|null, template_name: string}
     */
    private function globalTemplatePayload(DocumentGeneratorTemplate $template): array
    {
        return [
            'id' => $template->id,
            'year' => $template->year,
            'template_name' => $template->template_name,
        ];
    }

    private function ensureUniqueGlobalTemplateYear(int $year, ?int $ignoreTemplateId = null): void
    {
        $query = DocumentGeneratorTemplate::query()->where('year', $year);

        if ($ignoreTemplateId !== null) {
            $query->whereKeyNot($ignoreTemplateId);
        }

        if ($query->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'year' => ['Year template entries must use unique years.'],
            ]);
        }
    }

    private function signSingleItem(
        User $user,
        DocumentBatchItem $item,
        DocxTemplateService $docxTemplateService,
        PdfConversionService $pdfConversionService,
        string $presidentSignatureImagePath
    ): void {
        $signature = $this->resolveSignatureOrFail($user);

        if ($this->supportsItemSignatureAppliedAt() && $item->signature_applied_at !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'signature' => ['Signature is already applied for this file.'],
            ]);
        }

        if (! $this->canUseDocxSignaturePlaceholderFlow($item, $docxTemplateService)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'signature' => [
                    'DOCX signature placeholders were not found for this file. Regenerate with {president_signature} and {getor_signature} placeholders.',
                ],
            ]);
        }

        $this->signWithDocxPlaceholders($item, $signature, $presidentSignatureImagePath, $docxTemplateService, $pdfConversionService);

        if ($this->supportsItemSignatureAppliedAt()) {
            $item->signature_applied_at = now();
            $item->save();
        }
    }

    private function resolveSignatureOrFail(User $user): DocumentGeneratorSignature
    {
        $signature = DocumentGeneratorSignature::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $signature instanceof DocumentGeneratorSignature) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'signature' => ['Please upload a default signature first.'],
            ]);
        }

        if (! is_string($signature->processed_signature_path) || trim($signature->processed_signature_path) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'signature' => ['Processed signature file is not configured.'],
            ]);
        }

        if (! \App\Support\DocumentStorage::disk()->exists($signature->processed_signature_path)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'signature' => ['Processed signature file is missing on disk. Please upload again.'],
            ]);
        }

        return $signature;
    }

    private function supportsPageSpecificSignatureLayout(): bool
    {
        static $supportsPageSpecificLayout = null;

        if ($supportsPageSpecificLayout !== null) {
            return $supportsPageSpecificLayout;
        }

        $supportsPageSpecificLayout = Schema::hasColumns('document_generator_signatures', [
            'page2_anchor',
            'page2_offset_x',
            'page2_offset_y',
            'page2_width',
            'page2_height',
            'page3_anchor',
            'page3_offset_x',
            'page3_offset_y',
            'page3_width',
            'page3_height',
        ]);

        return $supportsPageSpecificLayout;
    }

    private function supportsGetorPageSpecificSignatureLayout(): bool
    {
        static $supportsGetorPageSpecificLayout = null;

        if ($supportsGetorPageSpecificLayout !== null) {
            return $supportsGetorPageSpecificLayout;
        }

        $supportsGetorPageSpecificLayout = Schema::hasColumns('document_generator_signatures', [
            'page4_anchor',
            'page4_offset_x',
            'page4_offset_y',
            'page4_width',
            'page4_height',
            'page8_anchor',
            'page8_offset_x',
            'page8_offset_y',
            'page8_width',
            'page8_height',
        ]);

        return $supportsGetorPageSpecificLayout;
    }

    private function supportsTextAnchorSignatureLayout(): bool
    {
        static $supportsTextAnchorLayout = null;

        if ($supportsTextAnchorLayout !== null) {
            return $supportsTextAnchorLayout;
        }

        $supportsTextAnchorLayout = Schema::hasColumns('document_generator_signatures', [
            'page2_placement_mode',
            'page2_anchor_text',
            'page3_placement_mode',
            'page3_anchor_text',
            'page4_placement_mode',
            'page4_anchor_text',
            'page8_placement_mode',
            'page8_anchor_text',
        ]);

        return $supportsTextAnchorLayout;
    }

    private function supportsItemSignatureAppliedAt(): bool
    {
        static $supportsSignatureAppliedAt = null;

        if ($supportsSignatureAppliedAt !== null) {
            return $supportsSignatureAppliedAt;
        }

        $supportsSignatureAppliedAt = Schema::hasColumn('document_batch_items', 'signature_applied_at');

        return $supportsSignatureAppliedAt;
    }

    private function signatureFeatureEnabled(): bool
    {
        return (bool) config('services.document_generator.signature_enabled', true);
    }

    private function ensureSignatureFeatureEnabledOr404(): void
    {
        abort_unless($this->signatureFeatureEnabled(), 404);
    }

    /**
     * @return array{signature: array<string, mixed>|null}
     */
    private function signaturePayload(User $user): array
    {
        $signature = $user->documentGeneratorSignature;
        if (! $signature instanceof DocumentGeneratorSignature) {
            return ['signature' => null];
        }

        return [
            'signature' => [
                'president' => [
                    'page2' => $this->signatureLayout($signature, 'page2'),
                    'page3' => $this->signatureLayout($signature, 'page3'),
                ],
                'getor' => [
                    'page4' => $this->signatureLayout($signature, 'page4'),
                    'page8' => $this->signatureLayout($signature, 'page8'),
                    'preview_url' => route('document-generator.signature.preview', [
                        'v' => $signature->updated_at?->timestamp,
                    ]),
                ],
            ],
        ];
    }

    /**
     * @return array{
     *   anchor: string,
     *   placement_mode: string,
     *   anchor_text: string,
     *   offset_x: float,
     *   offset_y: float,
     *   width: float,
     *   height: float
     * }
     */
    private function signatureLayout(DocumentGeneratorSignature $signature, string $pageKey): array
    {
        $placementMode = 'fixed';
        $anchorText = '';

        if ($this->supportsTextAnchorSignatureLayout()) {
            $placementMode = (string) ($signature->{"{$pageKey}_placement_mode"} ?: 'fixed');
            $anchorText = (string) ($signature->{"{$pageKey}_anchor_text"} ?: '');
        }

        return [
            'anchor' => (string) ($signature->{"{$pageKey}_anchor"} ?: $signature->anchor),
            'placement_mode' => $placementMode,
            'anchor_text' => $anchorText,
            'offset_x' => (float) ($signature->{"{$pageKey}_offset_x"} ?? $signature->offset_x),
            'offset_y' => (float) ($signature->{"{$pageKey}_offset_y"} ?? $signature->offset_y),
            'width' => (float) ($signature->{"{$pageKey}_width"} ?? $signature->width),
            'height' => (float) ($signature->{"{$pageKey}_height"} ?? $signature->height),
        ];
    }

    private function canUseDocxSignaturePlaceholderFlow(
        DocumentBatchItem $item,
        DocxTemplateService $docxTemplateService,
    ): bool {
        if (! (bool) config('services.document_generator.signature_docx_placeholder_enabled', true)) {
            return false;
        }

        if (! is_string($item->docx_path) || trim($item->docx_path) === '') {
            return false;
        }

        if (! \App\Support\DocumentStorage::disk()->exists($item->docx_path)) {
            return false;
        }

        $temporaryDocxPath = null;

        try {
            $temporaryDocxPath = $this->copyStorageFileToTemporaryPath($item->docx_path, '.docx');

            return $docxTemplateService->hasSignatureImagePlaceholders($temporaryDocxPath);
        } catch (\Throwable) {
            return false;
        } finally {
            if (is_string($temporaryDocxPath) && is_file($temporaryDocxPath)) {
                @unlink($temporaryDocxPath);
            }
        }
    }

    private function signWithDocxPlaceholders(
        DocumentBatchItem $item,
        DocumentGeneratorSignature $signature,
        string $presidentSignatureImagePath,
        DocxTemplateService $docxTemplateService,
        PdfConversionService $pdfConversionService,
    ): void {
        if (! is_string($item->docx_path) || trim($item->docx_path) === '') {
            throw new \RuntimeException('DOCX file is not available for this item.');
        }

        if (! \App\Support\DocumentStorage::disk()->exists($item->docx_path)) {
            throw new \RuntimeException('DOCX file is missing on disk.');
        }

        $docxPath = $this->copyStorageFileToTemporaryPath($item->docx_path, '.docx');
        $getorSignatureImagePath = $this->copyStorageFileToTemporaryPath($signature->processed_signature_path, '.png');
        $convertedPdfPath = null;

        try {
            $page2Layout = $this->signatureLayout($signature, 'page2');
            $page4Layout = $this->signatureLayout($signature, 'page4');

            $docxTemplateService->injectSignatureImages($docxPath, $docxPath, [
                'president_signature' => [
                    'path' => $presidentSignatureImagePath,
                    'width_mm' => (float) ($page2Layout['width'] ?? 40.0),
                    'height_mm' => (float) ($page2Layout['height'] ?? 16.0),
                ],
                'getor_signature' => [
                    'path' => $getorSignatureImagePath,
                    'width_mm' => (float) ($page4Layout['width'] ?? 40.0),
                    'height_mm' => (float) ($page4Layout['height'] ?? 16.0),
                ],
            ]);

            $convertedPdfPath = $pdfConversionService->convertDocxToPdf($docxPath);
            $expectedPdfRelativePath = $this->expectedPdfRelativePath($item);

            $this->storeLocalFileToDocumentStorage($docxPath, $item->docx_path);
            $this->storeLocalFileToDocumentStorage($convertedPdfPath, $expectedPdfRelativePath);

            $item->pdf_path = $expectedPdfRelativePath;
            $item->save();
        } finally {
            if (is_file($docxPath)) {
                @unlink($docxPath);
            }
            if (is_file($getorSignatureImagePath)) {
                @unlink($getorSignatureImagePath);
            }
            if (is_string($convertedPdfPath) && is_file($convertedPdfPath)) {
                @unlink($convertedPdfPath);
            }
        }
    }

    private function expectedPdfRelativePath(DocumentBatchItem $item): string
    {
        $existingPdfPath = trim((string) ($item->pdf_path ?? ''));
        if ($existingPdfPath !== '') {
            return $existingPdfPath;
        }

        $docxPath = trim((string) ($item->docx_path ?? ''));
        if ($docxPath !== '') {
            $replaced = preg_replace('/\.docx$/i', '.pdf', $docxPath);
            if (is_string($replaced) && $replaced !== $docxPath) {
                return $replaced;
            }
        }

        throw new \RuntimeException('Unable to resolve the expected PDF path for this item.');
    }

    private function normalizeAnchorText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function firstValidationMessage(array $errors, string $fallback): string
    {
        foreach ($errors as $messages) {
            if (! is_array($messages) || $messages === []) {
                continue;
            }

            $first = trim((string) $messages[0]);
            if ($first !== '') {
                return $first;
            }
        }

        return $fallback;
    }

    private function processPresidentSignatureUpload(
        UploadedFile $presidentSignature,
        SignatureImageService $signatureImageService
    ): string {
        return $signatureImageService->processToTransparentPng($presidentSignature->getPathname());
    }

    private function copyStorageFileToTemporaryPath(string $storagePath, string $extension = ''): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-gen-sign-');
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

        $input = \App\Support\DocumentStorage::disk()->readStream($storagePath);
        if (! is_resource($input)) {
            if (is_file($resolvedPath)) {
                @unlink($resolvedPath);
            }
            throw new \RuntimeException('Unable to read file from storage.');
        }

        $output = @fopen($resolvedPath, 'wb');
        if (! is_resource($output)) {
            fclose($input);
            if (is_file($resolvedPath)) {
                @unlink($resolvedPath);
            }
            throw new \RuntimeException('Unable to write temporary file.');
        }

        try {
            stream_copy_to_stream($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }

        return $resolvedPath;
    }

    private function storeLocalFileToDocumentStorage(string $localPath, string $storagePath): void
    {
        if (! is_file($localPath)) {
            throw new \RuntimeException('Local file to store does not exist.');
        }

        $input = @fopen($localPath, 'rb');
        if (! is_resource($input)) {
            throw new \RuntimeException('Unable to open local file for storage upload.');
        }

        try {
            $stored = \App\Support\DocumentStorage::disk()->writeStream($storagePath, $input);
            if ($stored === false) {
                throw new \RuntimeException('Unable to write file to storage.');
            }
        } finally {
            fclose($input);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteSignatureFiles(array $paths): void
    {
        foreach (array_values(array_unique($paths)) as $path) {
            if (\App\Support\DocumentStorage::disk()->exists($path)) {
                \App\Support\DocumentStorage::disk()->delete($path);
            }
        }
    }

    /**
     * @param  list<string|null>  $paths
     */
    private function deleteTemplateFiles(array $paths): void
    {
        $uniquePaths = array_unique(
            array_values(
                array_filter($paths, static fn (?string $path): bool => is_string($path) && $path !== '')
            )
        );

        foreach ($uniquePaths as $path) {
            if (\App\Support\DocumentStorage::disk()->exists($path)) {
                \App\Support\DocumentStorage::disk()->delete($path);
            }
        }
    }
}
