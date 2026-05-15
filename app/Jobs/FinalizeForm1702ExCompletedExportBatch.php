<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Form1702ExCompletedExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeForm1702ExCompletedExportBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $userId,
        public readonly string $batchId,
        public readonly string $exportRunId,
    ) {
        $this->onQueue('filing-1702');
    }

    public function handle(Form1702ExCompletedExportService $completedExportService): void
    {
        try {
            $export = $completedExportService->buildZipFromChunkArchives($this->userId, $this->exportRunId);

            $completedExportService->putState($this->userId, [
                'status' => Form1702ExCompletedExportService::STATUS_READY,
                'error' => null,
                'rowCount' => $export['rowCount'],
                'downloadUrl' => route('forms.form1702ex.completed.download.file'),
                'storagePath' => $export['storagePath'],
                'batchId' => $this->batchId,
            ]);
        } catch (\Throwable $exception) {
            $completedExportService->putState($this->userId, [
                'status' => Form1702ExCompletedExportService::STATUS_FAILED,
                'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'The completed files ZIP could not be prepared right now.',
                'rowCount' => null,
                'downloadUrl' => null,
                'storagePath' => null,
                'batchId' => $this->batchId,
            ]);
        } finally {
            $completedExportService->cleanupChunkArchives($this->userId, $this->exportRunId);
        }
    }
}
