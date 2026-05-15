<?php

declare(strict_types=1);

namespace App\Services\Form1702Ex;

use App\Jobs\FinalizeForm1702ExCompletedExportBatch;
use App\Jobs\ProcessForm1702ExCompletedExportChunk;
use App\Services\ExportBatches\ExportBatchAdapterInterface;
use App\Services\Form1702ExCompletedExportService;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

class Form1702ExCompletedExportBatchAdapter implements ExportBatchAdapterInterface
{
    public function __construct(
        private readonly Form1702ExCompletedExportService $completedExportService,
    ) {}

    public function key(): string
    {
        return 'form1702ex-completed';
    }

    public function queueName(): string
    {
        return 'filing-1702';
    }

    /**
     * @param  list<int>  $sourceIds
     * @return list<ShouldQueue>
     */
    public function buildChunkJobs(int $userId, array $sourceIds, string $context): array
    {
        $jobs = [];
        $chunkSize = 40;

        foreach (array_values(array_chunk($sourceIds, $chunkSize)) as $chunkIndex => $chunkIds) {
            /** @var list<int> $chunkIds */
            $jobs[] = new ProcessForm1702ExCompletedExportChunk(
                $userId,
                $context,
                $chunkIndex,
                $chunkIds,
            );
        }

        return $jobs;
    }

    /**
     * @param  list<int>  $sourceIds
     */
    public function putQueuedState(int $userId, string $batchId, array $sourceIds, string $context): void
    {
        $this->completedExportService->forgetState($userId);
        $this->completedExportService->putState($userId, [
            'status' => Form1702ExCompletedExportService::STATUS_QUEUED,
            'error' => null,
            'rowCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
            'batchId' => $batchId,
            'sourceRowIds' => $sourceIds,
        ]);
    }

    public function dispatchFinalize(int $userId, string $batchId, string $context): void
    {
        FinalizeForm1702ExCompletedExportBatch::dispatch($userId, $batchId, $context)->onQueue($this->queueName());
    }

    public function handleBatchFailure(int $userId, Batch $batch, Throwable $exception, string $context): void
    {
        $this->completedExportService->putState($userId, [
            'status' => Form1702ExCompletedExportService::STATUS_FAILED,
            'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'The completed files ZIP could not be prepared right now.',
            'rowCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
            'batchId' => $batch->id,
        ]);
    }

    public function handleBatchCancelled(int $userId, Batch $batch, string $context): void
    {
        $this->completedExportService->putState($userId, [
            'status' => Form1702ExCompletedExportService::STATUS_FAILED,
            'error' => null,
            'rowCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
            'batchId' => $batch->id,
        ]);
    }

    public function currentBatchId(int $userId, string $context): ?string
    {
        return $this->completedExportService->currentBatchId($userId);
    }
}
