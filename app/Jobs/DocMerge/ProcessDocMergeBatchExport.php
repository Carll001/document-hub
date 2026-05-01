<?php

declare(strict_types=1);

namespace App\Jobs\DocMerge;

use App\Models\DocMergeBatch;
use App\Services\DocMerge\DocMergeBatchExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDocMergeBatchExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $userId,
        public readonly int $batchId,
    ) {
        $this->onQueue('document-merger');
    }

    public function handle(DocMergeBatchExportService $exportService): void
    {
        $batch = DocMergeBatch::query()
            ->where('id', $this->batchId)
            ->where('user_id', $this->userId)
            ->first();

        if (! $batch instanceof DocMergeBatch) {
            return;
        }

        $exportService->putState($this->userId, $this->batchId, [
            'status' => DocMergeBatchExportService::STATUS_PROCESSING,
            'error' => null,
            'itemCount' => null,
            'downloadUrl' => null,
            'localPath' => null,
        ]);

        try {
            $export = $exportService->buildZip($batch);

            $exportService->putState($this->userId, $this->batchId, [
                'status' => DocMergeBatchExportService::STATUS_READY,
                'error' => null,
                'itemCount' => $export['itemCount'],
                'downloadUrl' => route('doc-merge.batches.download', ['docMergeBatch' => $batch]),
                'localPath' => $export['localPath'],
            ]);
        } catch (\Throwable $exception) {
            $exportService->putState($this->userId, $this->batchId, [
                'status' => DocMergeBatchExportService::STATUS_FAILED,
                'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'The batch ZIP could not be prepared right now.',
                'itemCount' => null,
                'downloadUrl' => null,
                'localPath' => null,
            ]);

            throw $exception;
        }
    }
}
