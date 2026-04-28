<?php

declare(strict_types=1);

namespace App\Http\Controllers\AfsFiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\AfsFiling\AfsFilingDestroyCompletedItemsRequest;
use App\Http\Requests\AfsFiling\AfsFilingQueueCompletedDownloadRequest;
use App\Jobs\AfsFiling\DeleteAfsFilingItemJob;
use App\Jobs\AfsFiling\ProcessAfsFilingCompletedExport;
use App\Models\AfsFilingItem;
use App\Models\User;
use App\Services\DocumentGeneratorCompletedExportService;
use App\Support\DocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AfsFilingExportController extends Controller
{
    public function queue(AfsFilingQueueCompletedDownloadRequest $request, DocumentGeneratorCompletedExportService $completedExportService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $userId = (int) $user->getKey();

        $state = $completedExportService->getState($userId);
        if (in_array($state['status'], [
            DocumentGeneratorCompletedExportService::STATUS_QUEUED,
            DocumentGeneratorCompletedExportService::STATUS_PROCESSING,
        ], true)) {
            return response()->json([
                'message' => 'A completed files export is already processing.',
                'export_state' => $state,
            ], 409);
        }

        $validated = $request->validated();

        $query = AfsFilingItem::query()
            ->where('user_id', $userId)
            ->where('status', 'pdf_done')
            ->whereNotNull('signature_applied_at');

        if (is_string($validated['company_search'] ?? null) && trim((string) $validated['company_search']) !== '') {
            $search = mb_strtolower(trim((string) $validated['company_search']));
            $query->whereRaw('LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, "$.COMPANY")), "")) LIKE ?', ["%{$search}%"]);
        }

        $itemIds = collect($validated['item_ids'] ?? [])->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        if ($itemIds !== []) {
            $query->whereIn('id', $itemIds);
        }

        $resolvedIds = $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($resolvedIds === []) {
            return response()->json(['message' => 'No completed files matched this export request.'], 422);
        }

        $completedExportService->forgetState($userId);
        $completedExportService->putState($userId, [
            'status' => DocumentGeneratorCompletedExportService::STATUS_QUEUED,
            'error' => null,
            'itemCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
        ]);

        ProcessAfsFilingCompletedExport::dispatch($userId, $resolvedIds);

        return response()->json([
            'message' => 'Completed files export queued. Your ZIP will be ready shortly.',
            'export_state' => $completedExportService->getState($userId),
        ]);
    }

    public function state(Request $request, DocumentGeneratorCompletedExportService $completedExportService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($completedExportService->getState((int) $user->getKey()));
    }

    public function download(Request $request, DocumentGeneratorCompletedExportService $completedExportService): StreamedResponse|BinaryFileResponse
    {
        /** @var User $user */
        $user = $request->user();
        $userId = (int) $user->getKey();
        $state = $completedExportService->getState($userId);
        $cached = cache()->get($completedExportService->cacheKey($userId));

        abort_unless(
            $state['status'] === DocumentGeneratorCompletedExportService::STATUS_READY
                && is_array($cached)
                && is_string($cached['storagePath'] ?? null)
                && DocumentStorage::disk()->exists($cached['storagePath']),
            404,
        );

        return DocumentStorage::disk()->download((string) $cached['storagePath'], 'afs_filing-completed-files.zip');
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
}
