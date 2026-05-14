<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Services\DocumentGeneratorCompletedExportService;
use App\Services\AfsFiling\AfsCompletedExportBatchAdapter;
use App\Services\ExportBatches\ExportBatchOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StartAfsCompletedExportBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  list<int>  $itemIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $itemIds,
        public readonly string $context = 'index',
    ) {
        $this->onQueue('afs-filing');
    }

    public function handle(
        ExportBatchOrchestrator $orchestrator,
        AfsCompletedExportBatchAdapter $adapter,
        DocumentGeneratorCompletedExportService $completedExportService,
    ): void
    {
        if ($completedExportService->cancellationRequested($this->userId, $this->context)) {
            $completedExportService->putState($this->userId, [
                'status' => DocumentGeneratorCompletedExportService::STATUS_FAILED,
                'error' => null,
                'itemCount' => null,
                'downloadUrl' => null,
                'storagePath' => null,
                'cancelRequested' => false,
            ], $this->context);

            return;
        }

        $orchestrator->queue($this->userId, $this->itemIds, $adapter, $this->context);
    }
}
