<?php

declare(strict_types=1);

namespace App\Services\ExportBatches;

use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

interface ExportBatchAdapterInterface
{
    public function key(): string;

    public function queueName(): string;

    /**
     * @param  list<int>  $sourceIds
     * @return list<ShouldQueue>
     */
    public function buildChunkJobs(int $userId, array $sourceIds, string $context): array;

    /**
     * @param  list<int>  $sourceIds
     */
    public function putQueuedState(int $userId, string $batchId, array $sourceIds, string $context): void;

    public function dispatchFinalize(int $userId, string $batchId, string $context): void;

    public function handleBatchFailure(int $userId, Batch $batch, Throwable $exception, string $context): void;

    public function handleBatchCancelled(int $userId, Batch $batch, string $context): void;

    public function currentBatchId(int $userId, string $context): ?string;
}
