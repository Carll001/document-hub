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
use App\Jobs\AfsFiling\GenerateAfsFilingItemJob;
use App\Models\AfsFilingItem;
use App\Models\DocumentGeneratorTemplate;
use App\Models\User;
use App\Services\AfsFiling\AfsFilingItemSigningService;
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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AfsFilingItemController extends Controller
{
    public function __construct(
        private readonly AfsFilingItemRepositoryContract $items,
        private readonly AfsFilingItemSigningService $signingService,
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
        Log::debug('[AfsFilingStore] Starting upload', [
            'disk' => $diskName,
            'user_id' => $user->getKey(),
            'excel_original_name' => $excelFile->getClientOriginalName(),
            'excel_size' => $excelFile->getSize(),
            'excel_mime' => $excelFile->getMimeType(),
        ]);

        $excelPath = $excelFile->store("afs_filing/{$user->id}/uploads", $diskName);
        Log::debug('[AfsFilingStore] Excel store result', [
            'excel_path' => $excelPath,
            'store_succeeded' => $excelPath !== false,
        ]);

        if ($excelPath === false) {
            Log::error('[AfsFilingStore] Excel file store() returned false — S3 upload failed');

            return response()->json(['message' => 'Failed to upload Excel file to storage.'], 500);
        }

        $uploadedDefaultTemplate = $request->file('default_template_file');
        $templateName = null;
        if ($uploadedDefaultTemplate) {
            $templateName = $uploadedDefaultTemplate->getClientOriginalName();
            Log::debug('[AfsFilingStore] Storing template', [
                'template_name' => $templateName,
                'template_size' => $uploadedDefaultTemplate->getSize(),
            ]);
            $templatePath = $uploadedDefaultTemplate->store("afs_filing/{$user->id}/templates", $diskName);
            Log::debug('[AfsFilingStore] Template store result', [
                'template_path' => $templatePath,
                'store_succeeded' => $templatePath !== false,
            ]);
            DocumentGeneratorTemplate::query()->updateOrCreate(
                ['year' => null],
                ['template_name' => $templateName, 'template_path' => $templatePath],
            );
        }

        Log::debug('[AfsFilingStore] Reading back Excel from storage for extraction', ['excel_path' => $excelPath]);
        $extracted = $excelExtractionService->extractFromDocumentStorage($excelPath, 0);
        $rows = $extracted['rows'] ?? [];
        Log::debug('[AfsFilingStore] Extraction result', [
            'row_count' => count($rows),
            'header_count' => count($extracted['headers'] ?? []),
        ]);

        if (! is_array($rows) || $rows === []) {
            return response()->json(['message' => 'No data rows found in the uploaded Excel file.'], 422);
        }

        $this->syncAfsFilingItemSequence();
        $createdIds = [];

        try {
            $createdIds = $this->createItemsFromRows($rows, $user, $excelFile, $templateName);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isAfsFilingPrimaryKeyCollision($exception)) {
                throw $exception;
            }

            Log::debug('[AfsFilingStore] Primary key collision — resyncing sequence and retrying');
            $this->syncAfsFilingItemSequence();
            $createdIds = $this->createItemsFromRows($rows, $user, $excelFile, $templateName);
        }

        Log::debug('[AfsFilingStore] Items created, dispatching jobs', ['created_count' => count($createdIds)]);
        foreach ($createdIds as $position => $id) {
            GenerateAfsFilingItemJob::dispatch($id)->delay(now()->addSeconds($position > 0 ? 1 : 0));
        }

        return response()->json([
            'message' => 'AFS filing rows queued for generation.',
            'status' => 'queued',
            'total_items' => count($createdIds),
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

        $presidentFilePath = (string) $request->file('president_signature_file')?->getPathname();
        if ($presidentFilePath === '') {
            return response()->json(['message' => 'President signature image is required.'], 422);
        }

        $this->signingService->sign($item, $user, $presidentFilePath);

        return response()->json([
            'message' => 'Signature applied.',
            'item' => $this->itemPayload($item->fresh() ?? $item),
            'pdf_url' => route('afs-filing.items.download', ['item' => $item, 'type' => 'pdf']),
        ]);
    }

    public function signBulk(AfsFilingSignBulkRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $items = AfsFilingItem::query()
            ->where('user_id', (int) $user->getKey())
            ->whereIn('id', $validated['item_ids'])
            ->get();

        $presidentFilePath = (string) $request->file('president_signature_file')?->getPathname();
        if ($presidentFilePath === '') {
            return response()->json(['message' => 'President signature image is required.'], 422);
        }

        $results = [];
        foreach ($items as $item) {
            try {
                $this->signingService->sign($item, $user, $presidentFilePath);
                $results[] = [
                    'item_id' => (int) $item->id,
                    'success' => true,
                    'message' => 'Signature applied.',
                ];
            } catch (\Throwable $exception) {
                $results[] = [
                    'item_id' => (int) $item->id,
                    'success' => false,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return response()->json(['results' => $results]);
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

    public function destroy(Request $request, AfsFilingItem $item): JsonResponse
    {
        $this->assertOwnership($request, $item);

        $this->deleteItemFiles($item);
        $item->delete();

        return response()->json(['message' => 'Row deleted.']);
    }

    public function download(Request $request, AfsFilingItem $item, string $type): StreamedResponse|BinaryFileResponse
    {
        $this->assertOwnership($request, $item);
        abort_unless(in_array($type, ['docx', 'pdf'], true), 404);

        $path = $type === 'docx' ? $item->docx_path : $item->pdf_path;
        abort_unless(is_string($path) && $path !== '' && DocumentStorage::disk()->exists($path), 404);

        $fileName = "afs_filing-row-{$item->row_number}.{$type}";

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
     * @return array<int, int>
     */
    private function createItemsFromRows(array $rows, User $user, UploadedFile $excelFile, ?string $templateName): array
    {
        $createdIds = [];

        DB::transaction(function () use ($rows, $user, $excelFile, $templateName, &$createdIds): void {
            foreach ($rows as $index => $rowData) {
                if (! is_array($rowData)) {
                    continue;
                }

                $item = AfsFilingItem::query()->create([
                    'user_id' => (int) $user->getKey(),
                    'row_number' => $index + 2,
                    'row_data' => $rowData,
                    'status' => 'queued',
                    'source_excel_name' => $excelFile->getClientOriginalName(),
                    'template_name' => $templateName,
                ]);

                $createdIds[] = (int) $item->id;
            }
        });

        return $createdIds;
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
