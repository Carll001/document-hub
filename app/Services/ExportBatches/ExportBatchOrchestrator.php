<?php

declare(strict_types=1);

namespace App\Services\ExportBatches;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Throwable;

class ExportBatchOrchestrator
{
    /**
     * @param  list<int>  $sourceIds
     */
    public function queue(int $userId, array $sourceIds, ExportBatchAdapterInterface $adapter, string $context): void
    {
        $adapterClass = $adapter::class;
        $jobs = $adapter->buildChunkJobs($userId, $sourceIds, $context);

        $batch = Bus::batch($jobs)
            ->name(sprintf('%s-export-user-%d', $adapter->key(), $userId))
            ->onQueue($adapter->queueName())
            ->catch(function (Batch $batch, Throwable $exception) use ($adapterClass, $userId, $context): void {
                /** @var ExportBatchAdapterInterface $resolved */
                $resolved = app($adapterClass);
                $resolved->handleBatchFailure($userId, $batch, $exception, $context);
            })
            ->finally(function (Batch $batch) use ($adapterClass, $userId, $context): void {
                /** @var ExportBatchAdapterInterface $resolved */
                $resolved = app($adapterClass);

                if ($batch->cancelled()) {
                    $resolved->handleBatchCancelled($userId, $batch, $context);

                    return;
                }

                if ($batch->hasFailures()) {
                    return;
                }

                $resolved->dispatchFinalize($userId, $batch->id, $context);
            })
            ->dispatch();

        $adapter->putQueuedState($userId, $batch->id, $sourceIds, $context);
    }

    public function cancel(int $userId, ExportBatchAdapterInterface $adapter, string $context): bool
    {
        $batchId = $adapter->currentBatchId($userId, $context);
        if (! is_string($batchId) || $batchId === '') {
            return false;
        }

        $batch = Bus::findBatch($batchId);
        if ($batch instanceof Batch) {
            $batch->cancel();
        }

        return true;
    }
}
