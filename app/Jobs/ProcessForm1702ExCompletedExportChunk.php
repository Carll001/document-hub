<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Form1702ExCompletedExportService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessForm1702ExCompletedExportChunk implements ShouldQueue
{
    use Batchable;
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
        public readonly string $exportRunId,
        public readonly int $chunkIndex,
        public readonly array $rowIds,
    ) {
        $this->onQueue('filing-1702');
    }

    public function handle(Form1702ExCompletedExportService $completedExportService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $completedExportService->buildChunkZipFromRowIds(
            $this->userId,
            $this->exportRunId,
            $this->chunkIndex,
            $this->rowIds,
        );
    }
}
