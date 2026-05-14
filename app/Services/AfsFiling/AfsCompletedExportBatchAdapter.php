<?php

declare(strict_types=1);

namespace App\Services\AfsFiling;

use App\Jobs\AfsFiling\FinalizeAfsCompletedExportBatch;
use App\Jobs\AfsFiling\ProcessAfsCompletedExportChunk;
use App\Services\DocumentGeneratorCompletedExportService;
use App\Services\ExportBatches\ExportBatchAdapterInterface;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

class AfsCompletedExportBatchAdapter implements ExportBatchAdapterInterface
{
    public function __construct(
        private readonly DocumentGeneratorCompletedExportService $completedExportService,
    ) {}

    public function key(): string
    {
        return 'afs-completed';
    }

    public function queueName(): string
    {
        return 'afs-filing';
    }

    /**
     * @param  list<int>  $sourceIds
     * @return list<ShouldQueue>
     */
    public function buildChunkJobs(int $userId, array $sourceIds, string $context): array
    {
        $chunkSize = 50;
        $jobs = [];

        foreach (array_chunk($sourceIds, $chunkSize) as $chunkIds) {
            /** @var list<int> $chunkIds */
            $jobs[] = new ProcessAfsCompletedExportChunk($userId, $chunkIds, $context);
        }

        return $jobs;
    }

    /**
     * @param  list<int>  $sourceIds
     */
    public function putQueuedState(int $userId, string $batchId, array $sourceIds, string $context): void
    {
        $this->completedExportService->forgetState($userId, $context);
        $this->completedExportService->putState($userId, [
            'status' => DocumentGeneratorCompletedExportService::STATUS_QUEUED,
            'error' => null,
            'itemCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
            'cancelRequested' => false,
            'batchId' => $batchId,
            'sourceItemIds' => $sourceIds,
        ], $context);
    }

    public function dispatchFinalize(int $userId, string $batchId, string $context): void
    {
        FinalizeAfsCompletedExportBatch::dispatch($userId, $batchId, $context)->onQueue($this->queueName());
    }

    public function handleBatchFailure(int $userId, Batch $batch, Throwable $exception, string $context): void
    {
        $this->completedExportService->putState($userId, [
            'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
            'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'The completed files ZIP could not be prepared right now.',
            'itemCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
            'cancelRequested' => false,
            'batchId' => $batch->id,
        ], $context);
    }

    public function handleBatchCancelled(int $userId, Batch $batch, string $context): void
    {
        $this->completedExportService->putState($userId, [
            'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
            'error' => null,
            'itemCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
            'cancelRequested' => false,
            'batchId' => $batch->id,
        ], $context);
    }

    public function currentBatchId(int $userId, string $context): ?string
    {
        return $this->completedExportService->currentBatchId($userId, $context);
    }
}
