<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentBatchStoreRequest;
use App\Jobs\GenerateDocumentBatchItemJob;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentBatchItemActivityLog;
use App\Models\DocumentBatchTemplate;
use App\Models\DocumentGeneratorSignature;
use App\Models\DocumentGeneratorTemplate;
use App\Models\User;
use App\Services\DocumentBatchActivityLogger;
use App\Services\ExcelExtractionService;
use App\Services\PdfSignatureStampService;
use App\Services\SignatureImageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentGeneratorController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $signatureEnabled = $this->signatureFeatureEnabled();

        return Inertia::render('DocumentGenerator', [
            'initialItems' => $this->allItemsPayload($request, null),
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
            'page2_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page2_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page2_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page2_height' => ['required', 'numeric', 'min:1', 'max:300'],
            'page3_anchor' => ['required', 'in:top_left,top_right,bottom_left,bottom_right,center'],
            'page3_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page3_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page3_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page3_height' => ['required', 'numeric', 'min:1', 'max:300'],
            'page4_anchor' => ['required', 'in:top_left,top_right,bottom_left,bottom_right,center'],
            'page4_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page4_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page4_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page4_height' => ['required', 'numeric', 'min:1', 'max:300'],
            'page8_anchor' => ['required', 'in:top_left,top_right,bottom_left,bottom_right,center'],
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

            $originalPath = $uploaded->store("document-generator/{$user->id}/signature", 'local');
            $processedTempPath = $signatureImageService->processToTransparentPng(
                Storage::disk('local')->path($originalPath),
            );

            $processedPath = "document-generator/{$user->id}/signature/processed-".Str::uuid().'.png';
            $processedFile = new File($processedTempPath);
            Storage::disk('local')->putFileAs(
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

    public function signaturePreview(Request $request): BinaryFileResponse
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
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function generatedFiles(Request $request): Response
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_by' => ['nullable', 'in:created_at,status,row_number,updated_at'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:queued,processing,docx_done,pdf_done,failed'],
            'company_search' => ['nullable', 'string', 'max:255'],
            'signature_filter' => ['nullable', 'in:signed,unsigned'],
        ]);

        return Inertia::render('GeneratedFiles', [
            'initialItems' => $this->allItemsPayload($request, [
                ...$validated,
                'files_only' => true,
            ]),
            'signatureEnabled' => $this->signatureFeatureEnabled(),
        ]);
    }

    public function templateMapping(): Response
    {
        return Inertia::render('TemplateMapping', [
            'mapping' => $this->globalTemplateMappingPayload(),
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

        $excelFile = $request->file('excel_file');
        $defaultTemplateFile = $request->file('default_template_file');
        if (! $excelFile) {
            return response()->json(['message' => 'Files are required.'], 422);
        }

        $excelPath = $excelFile->store("document-generator/{$request->user()->id}/uploads", 'local');
        $resolvedTemplates = $this->resolveTemplatesForBatch($request, $defaultTemplateFile);
        $defaultTemplate = $resolvedTemplates['default'];
        $yearTemplatePayload = $resolvedTemplates['year_templates'];

        $extracted = $excelExtractionService->extract(Storage::disk('local')->path($excelPath), $sheetIndex);
        $headers = $extracted['headers'];
        $rows = $extracted['rows'];
        $previousWorkbookPath = $this->resolvePreviousWorkbookPath($request->user()->id);

        if ($previousWorkbookPath !== null) {
            $previousWorkbookRows = $excelExtractionService->extract($previousWorkbookPath, $sheetIndex)['rows'];
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

        DocumentBatchItem::query()
            ->where('document_batch_id', $batch->id)
            ->pluck('id')
            ->each(static function (int $itemId): void {
                GenerateDocumentBatchItemJob::dispatch($itemId);
            });

        return response()->json([
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'total_items' => $batch->total_items,
        ], 201);
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
            $this->applyCompanySearch($query, $validated['company_search']);
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
        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        if ($type === 'pdf') {
            return response()->file(Storage::disk('local')->path($path), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"batch-{$batch->id}-row-{$item->row_number}.pdf\"",
            ]);
        }

        return Storage::disk('local')->download($path, "batch-{$batch->id}-row-{$item->row_number}.docx");
    }

    public function showItem(Request $request, DocumentBatch $batch, DocumentBatchItem $item): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);
        $this->assertItemBelongsToBatch($batch, $item);

        return response()->json($this->batchItemPayload($item));
    }

    public function signItem(
        Request $request,
        DocumentBatch $batch,
        DocumentBatchItem $item,
        PdfSignatureStampService $pdfSignatureStampService,
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
            $this->signSingleItem(
                $request->user(),
                $batch,
                $item,
                $pdfSignatureStampService,
                $processedPresidentSignaturePath
            );
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
        PdfSignatureStampService $pdfSignatureStampService,
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
                        $batch,
                        $item,
                        $pdfSignatureStampService,
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
                if (is_string($path) && $path !== '' && Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
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

        GenerateDocumentBatchItemJob::dispatch($item->id);

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
        $templatePath = $file->store('document-generator/global-templates', 'local');
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
        $validated = $request->validate([
            'year' => ['required', 'integer', 'digits:4'],
            'template_file' => ['required', 'file', 'mimes:docx'],
        ]);

        $year = (int) $validated['year'];
        $this->ensureUniqueGlobalTemplateYear($year);

        $file = $request->file('template_file');
        if (! $file) {
            return response()->json(['message' => 'Template file is required.'], 422);
        }

        DocumentGeneratorTemplate::query()->create([
            'year' => $year,
            'template_name' => $file->getClientOriginalName(),
            'template_path' => $file->store('document-generator/global-templates', 'local'),
        ]);

        return response()->json($this->globalTemplateMappingPayload(), 201);
    }

    public function updateGlobalTemplate(Request $request, DocumentGeneratorTemplate $template): JsonResponse
    {
        abort_if($template->year === null, 404);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'digits:4'],
            'template_file' => ['nullable', 'file', 'mimes:docx'],
        ]);

        $year = (int) $validated['year'];
        $this->ensureUniqueGlobalTemplateYear($year, $template->id);

        $oldPath = null;
        $file = $request->file('template_file');
        $updates = ['year' => $year];

        if ($file) {
            $oldPath = $template->template_path;
            $updates['template_name'] = $file->getClientOriginalName();
            $updates['template_path'] = $file->store('document-generator/global-templates', 'local');
        }

        $template->forceFill($updates)->save();

        $this->deleteTemplateFiles($oldPath ? [$oldPath] : []);

        return response()->json($this->globalTemplateMappingPayload());
    }

    public function destroyGlobalTemplate(DocumentGeneratorTemplate $template): JsonResponse
    {
        abort_if($template->year === null, 404);

        $oldPath = $template->template_path;
        $template->delete();
        $this->deleteTemplateFiles([$oldPath]);

        return response()->json($this->globalTemplateMappingPayload());
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
            $templatePath = $file->store("document-generator/{$request->user()->id}/uploads", 'local');
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
            'template_path' => $file->store("document-generator/{$request->user()->id}/uploads", 'local'),
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
                $updates['template_path'] = $file->store("document-generator/{$request->user()->id}/uploads", 'local');
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
        $perPage = (int) ($validated['per_page'] ?? $request->integer('per_page', 10));
        $perPage = max(5, min($perPage, 100));
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = (string) ($validated['sort_direction'] ?? 'desc');
        $filesOnly = (bool) ($validated['files_only'] ?? false);

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
            $this->applyCompanySearch($query, $validated['company_search']);
        }
        if (isset($validated['signature_filter']) && $this->supportsItemSignatureAppliedAt()) {
            if ($validated['signature_filter'] === 'signed') {
                $query->whereNotNull('signature_applied_at');
            }
            if ($validated['signature_filter'] === 'unsigned') {
                $query->whereNull('signature_applied_at');
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
        $templates = DocumentGeneratorTemplate::query()
            ->orderByRaw('case when year is null then 0 else 1 end')
            ->orderBy('year')
            ->get();

        /** @var DocumentGeneratorTemplate|null $defaultTemplate */
        $defaultTemplate = $templates->first(static fn (DocumentGeneratorTemplate $template): bool => $template->year === null);

        return [
            'default_template' => $defaultTemplate ? $this->globalTemplatePayload($defaultTemplate) : null,
            'year_templates' => $templates
                ->filter(static fn (DocumentGeneratorTemplate $template): bool => $template->year !== null)
                ->values()
                ->map(fn (DocumentGeneratorTemplate $template): array => $this->globalTemplatePayload($template))
                ->all(),
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

    private function applyCompanySearch(Builder $query, string $companySearch): void
    {
        $search = '%'.mb_strtolower(trim($companySearch)).'%';
        $driver = $query->getModel()->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $query->whereRaw(
                'exists (
                    select 1
                    from jsonb_each_text(row_data::jsonb) as company_entry(key, value)
                    where lower(company_entry.key) like ?
                    and lower(company_entry.value) like ?
                )',
                ['%company%', $search]
            );

            return;
        }

        $query->whereRaw('LOWER(CAST(row_data AS CHAR)) LIKE ?', [$search]);
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
        $disk = Storage::disk('local');

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

            return $disk->path($excelPath);
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
                'template_path' => $file->store("document-generator/{$request->user()->id}/uploads", 'local'),
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
                'template_path' => $uploadedDefaultTemplateFile->store("document-generator/{$request->user()->id}/uploads", 'local'),
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

        Storage::disk('local')->copy($sourcePath, $targetPath);

        return $targetPath;
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
        DocumentBatch $batch,
        DocumentBatchItem $item,
        PdfSignatureStampService $pdfSignatureStampService,
        string $presidentSignatureImagePath
    ): void {
        $signature = $this->resolveSignatureOrFail($user);

        if ($this->supportsItemSignatureAppliedAt() && $item->signature_applied_at !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'signature' => ['Signature is already applied for this file.'],
            ]);
        }

        if (! is_string($item->pdf_path) || trim($item->pdf_path) === '') {
            throw new \RuntimeException('PDF file is not available for this item.');
        }

        if (! Storage::disk('local')->exists($item->pdf_path)) {
            throw new \RuntimeException('PDF file is missing on disk.');
        }

        $pdfPath = Storage::disk('local')->path($item->pdf_path);
        $getorSignatureImagePath = Storage::disk('local')->path($signature->processed_signature_path);

        $pdfSignatureStampService->stampFileWithPageLayouts(
            $pdfPath,
            $presidentSignatureImagePath,
            [
                2 => $this->signatureLayout($signature, 'page2'),
                3 => $this->signatureLayout($signature, 'page3'),
            ],
        );

        $pdfSignatureStampService->stampFileWithPageLayouts(
            $pdfPath,
            $getorSignatureImagePath,
            [
                4 => $this->signatureLayout($signature, 'page4'),
                8 => $this->signatureLayout($signature, 'page8'),
            ],
        );

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

        if (! Storage::disk('local')->exists($signature->processed_signature_path)) {
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
     * @return array{anchor: string, offset_x: float, offset_y: float, width: float, height: float}
     */
    private function signatureLayout(DocumentGeneratorSignature $signature, string $pageKey): array
    {
        return [
            'anchor' => (string) ($signature->{"{$pageKey}_anchor"} ?: $signature->anchor),
            'offset_x' => (float) ($signature->{"{$pageKey}_offset_x"} ?? $signature->offset_x),
            'offset_y' => (float) ($signature->{"{$pageKey}_offset_y"} ?? $signature->offset_y),
            'width' => (float) ($signature->{"{$pageKey}_width"} ?? $signature->width),
            'height' => (float) ($signature->{"{$pageKey}_height"} ?? $signature->height),
        ];
    }

    private function processPresidentSignatureUpload(
        UploadedFile $presidentSignature,
        SignatureImageService $signatureImageService
    ): string {
        return $signatureImageService->processToTransparentPng($presidentSignature->getPathname());
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteSignatureFiles(array $paths): void
    {
        foreach (array_values(array_unique($paths)) as $path) {
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
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
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
