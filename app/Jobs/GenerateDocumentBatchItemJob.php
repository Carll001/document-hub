<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Services\DocumentBatchItemGenerationService;
use App\Exceptions\RetryableStorageConsistencyException;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateDocumentBatchItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        public readonly int $documentBatchItemId
    ) {
        $this->onQueue('afs-filing');
    }

    public function handle(DocumentBatchItemGenerationService $documentBatchItemGenerationService): void
    {
        try {
            $documentBatchItemGenerationService->generate($this->documentBatchItemId);
        } catch (RetryableStorageConsistencyException $exception) {
            $attempt = method_exists($this, 'attempts') ? $this->attempts() : 1;
            if ($attempt >= $this->tries) {
                throw $exception;
            }

            $delaySeconds = $this->retryDelayForAttempt($attempt);
            Log::warning('Document batch item generation deferred due to storage consistency.', [
                'document_batch_item_id' => $this->documentBatchItemId,
                'attempt' => $attempt,
                'retry_in_seconds' => $delaySeconds,
                'message' => $exception->getMessage(),
            ]);

            $this->release($delaySeconds);
        }
    }

    private function retryDelayForAttempt(int $attempt): int
    {
        $index = max(0, $attempt - 1);

        return (int) ($this->backoff[$index] ?? end($this->backoff) ?: 10);
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $item = DocumentBatchItem::query()->lockForUpdate()->find($this->documentBatchItemId);
            if (! $item instanceof DocumentBatchItem) {
                return;
            }

            if (in_array($item->status, ['pdf_done', 'failed'], true)) {
                return;
            }

            $item->status = 'failed';
            $item->error_message = mb_substr($exception->getMessage(), 0, 2000);
            $item->error_details = null;
            $item->completed_at = now();
            $item->save();

            $batch = DocumentBatch::query()->lockForUpdate()->find($item->document_batch_id);
            if (! $batch instanceof DocumentBatch) {
                return;
            }

            $batch->processed_items++;
            $batch->failed_items++;

            $isComplete = $batch->processed_items >= $batch->total_items;
            if ($isComplete) {
                $batch->status = 'failed';
                $batch->completed_at = now();
            } else {
                $batch->status = 'processing';
                $batch->started_at = $batch->started_at ?? now();
            }

            $batch->save();
        });
    }
}
