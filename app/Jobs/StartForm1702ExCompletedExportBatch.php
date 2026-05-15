<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ExportBatches\ExportBatchOrchestrator;
use App\Services\Form1702Ex\Form1702ExCompletedExportBatchAdapter;
use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StartForm1702ExCompletedExportBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  list<int>  $rowIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $rowIds,
    ) {
        $this->onQueue('filing-1702');
    }

    public function handle(
        ExportBatchOrchestrator $orchestrator,
        Form1702ExCompletedExportBatchAdapter $adapter,
    ): void {
        $orchestrator->queue($this->userId, $this->rowIds, $adapter, (string) Str::uuid());
    }
}
