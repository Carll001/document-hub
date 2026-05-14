<?php

declare(strict_types=1);

namespace App\Http\Controllers\AfsFiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\AfsFiling\AfsFilingDestroyCompletedItemsRequest;
use App\Http\Requests\AfsFiling\AfsFilingQueueCompletedDownloadRequest;
use App\Jobs\AfsFiling\DeleteAfsFilingItemJob;
use App\Jobs\AfsFiling\StartAfsCompletedExportBatch;
use App\Models\AfsFilingItem;
use App\Models\User;
use App\Services\AfsFiling\AfsCompletedExportBatchAdapter;
use App\Services\DocumentGeneratorCompletedExportService;
use App\Services\ExportBatches\ExportBatchOrchestrator;
use App\Support\DocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AfsFilingExportController extends Controller
{
    public function queue(AfsFilingQueueCompletedDownloadRequest $request, DocumentGeneratorCompletedExportService $completedExportService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $userId = (int) $user->getKey();

        $validated = $request->validated();
        $includeUnsigned = (bool) ($validated['include_unsigned'] ?? false);
        $context = $includeUnsigned ? 'index' : 'completed';

        $state = $completedExportService->getState($userId, $context);
        if (in_array($state['status'], [
            DocumentGeneratorCompletedExportService::STATUS_QUEUED,
            DocumentGeneratorCompletedExportService::STATUS_PROCESSING,
            DocumentGeneratorCompletedExportService::STATUS_CANCELLING,
        ], true)) {
            return response()->json([
                'message' => 'A completed files export is already processing.',
                'export_state' => $state,
            ], 409);
        }

        $status = is_string($validated['status'] ?? null) ? trim((string) $validated['status']) : 'all';

        $query = AfsFilingItem::query()
            ->where('user_id', $userId)
            ->whereNotNull('pdf_path');

        if (! $includeUnsigned) {
            $query
                ->where('status', 'pdf_done')
                ->whereNotNull('signature_applied_at');
        } else {
            // "Index" export context: keep this limited to unsigned workspace rows.
            $query->whereNull('signature_applied_at');

            if ($status !== '' && $status !== 'all') {
                $query->where('status', $status);
            }
        }

        if (is_string($validated['company_search'] ?? null) && trim((string) $validated['company_search']) !== '') {
            $search = '%'.trim((string) $validated['company_search']).'%';
            $isPostgres = DB::connection()->getDriverName() === 'pgsql';
            $operator = $isPostgres ? 'ilike' : 'like';
            $query->where(function ($searchQuery) use ($search, $operator, $isPostgres): void {
                $searchQuery
                    ->where('row_data->COMPANY', $operator, $search)
                    ->orWhere('row_data->company', $operator, $search)
                    ->orWhere('row_data->Company Name', $operator, $search)
                    ->orWhere('row_data->TIN', $operator, $search)
                    ->orWhere('row_data->tin', $operator, $search)
                    ->orWhere('row_data->Taxpayer TIN', $operator, $search)
                    ->orWhereRaw($isPostgres ? 'CAST(row_data AS TEXT) ILIKE ?' : 'CAST(row_data AS CHAR) LIKE ?', [$search]);
            });
        }

        $itemIds = collect($validated['item_ids'] ?? [])->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        if ($itemIds !== []) {
            $query->whereIn('id', $itemIds);
        }

        $resolvedIds = $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($resolvedIds === []) {
            return response()->json(['message' => 'No generated PDFs matched this export request.'], 422);
        }

        $completedExportService->forgetState($userId, $context);
        $completedExportService->putState($userId, [
            'status' => DocumentGeneratorCompletedExportService::STATUS_QUEUED,
            'error' => null,
            'itemCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
            'cancelRequested' => false,
        ], $context);

        StartAfsCompletedExportBatch::dispatch($userId, $resolvedIds, $context);

        return response()->json([
            'message' => 'Completed files export queued. Your ZIP will be ready shortly.',
            'export_state' => $completedExportService->getState($userId, $context),
        ]);
    }

    public function state(Request $request, DocumentGeneratorCompletedExportService $completedExportService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $context = $this->context($request);

        return response()->json($completedExportService->getState((int) $user->getKey(), $context));
    }

    public function cancel(
        Request $request,
        DocumentGeneratorCompletedExportService $completedExportService,
        ExportBatchOrchestrator $orchestrator,
        AfsCompletedExportBatchAdapter $adapter,
    ): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->context($request);
        $cancelled = $completedExportService->requestCancel((int) $user->getKey(), $context);
        if ($cancelled) {
            $batchCancelled = $orchestrator->cancel((int) $user->getKey(), $adapter, $context);
            if (! $batchCancelled) {
                $completedExportService->putState((int) $user->getKey(), [
                    'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
                    'error' => null,
                    'itemCount' => null,
                    'downloadUrl' => null,
                    'storagePath' => null,
                    'cancelRequested' => false,
                    'batchId' => null,
                ], $context);
            }
        }

        return response()->json([
            'message' => $cancelled
                ? 'PDF export cancel requested.'
                : 'No queued PDF export to cancel.',
            'export_state' => $completedExportService->getState((int) $user->getKey(), $context),
        ], $cancelled ? 200 : 409);
    }

    public function download(Request $request, DocumentGeneratorCompletedExportService $completedExportService): StreamedResponse|BinaryFileResponse
    {
        /** @var User $user */
        $user = $request->user();
        $userId = (int) $user->getKey();
        $context = $this->context($request);
        $state = $completedExportService->getState($userId, $context);
        $cached = cache()->get($completedExportService->cacheKey($userId, $context));

        abort_unless(
            $state['status'] === DocumentGeneratorCompletedExportService::STATUS_READY
                && is_array($cached)
                && is_string($cached['storagePath'] ?? null)
                && DocumentStorage::disk()->exists($cached['storagePath']),
            404,
        );

        $downloadName = is_string($cached['downloadFileName'] ?? null) && $cached['downloadFileName'] !== ''
            ? (string) $cached['downloadFileName']
            : 'afs_filing-completed-files.zip';

        $stream = DocumentStorage::disk()->readStream((string) $cached['storagePath']);
        if (! is_resource($stream)) {
            abort(404);
        }

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, $downloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function destroyCompletedItems(AfsFilingDestroyCompletedItemsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $itemIds = collect($request->validated()['item_ids'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $resolvedIds = AfsFilingItem::query()
            ->where('user_id', (int) $user->getKey())
            ->whereIn('id', $itemIds->all())
            ->where('status', 'pdf_done')
            ->whereNotNull('signature_applied_at')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($resolvedIds === []) {
            return response()->json(['message' => 'No completed rows matched the selected items.'], 422);
        }

        AfsFilingItem::query()
            ->where('user_id', (int) $user->getKey())
            ->whereIn('id', $resolvedIds)
            ->update(['status' => 'deleting']);

        foreach ($resolvedIds as $itemId) {
            DeleteAfsFilingItemJob::dispatch((int) $user->getKey(), $itemId);
        }

        $queuedCount = count($resolvedIds);

        return response()->json([
            'message' => $queuedCount === 1 ? 'Queued 1 delete task.' : "Queued {$queuedCount} delete tasks.",
            'queued_count' => $queuedCount,
        ]);
    }

    private function context(Request $request): string
    {
        $context = strtolower(trim((string) $request->query('context', 'index')));

        return $context === 'completed' ? 'completed' : 'index';
    }
}
