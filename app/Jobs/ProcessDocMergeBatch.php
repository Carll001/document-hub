<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DocMergeBatch;
use App\Services\DocMergeBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;

class ProcessDocMergeBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $batchId,
        public readonly ?string $outputPrefix = null,
    ) {
    }

    public function handle(DocMergeBatchService $docMergeBatchService): void
    {
        $batch = DocMergeBatch::query()->find($this->batchId);

        if (! $batch instanceof DocMergeBatch) {
            return;
        }

        $batch->forceFill([
            'processing_status' => DocMergeBatch::PROCESSING_STATUS_PROCESSING,
            'processing_error' => null,
        ])->save();

        try {
            $docMergeBatchService->processBatch($batch, $this->outputPrefix);

            $batch->forceFill([
                'processing_status' => null,
                'processing_error' => null,
            ])->save();
        } catch (ValidationException $exception) {
            $batch->forceFill([
                'processing_status' => DocMergeBatch::PROCESSING_STATUS_FAILED,
                'processing_error' => $this->validationMessage($exception),
            ])->save();

            throw $exception;
        } catch (\Throwable $exception) {
            $batch->forceFill([
                'processing_status' => DocMergeBatch::PROCESSING_STATUS_FAILED,
                'processing_error' => $this->runtimeMessage($exception),
            ])->save();

            throw $exception;
        }
    }

    private function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())
            ->flatten()
            ->first();

        return is_string($message) && $message !== ''
            ? $message
            : 'The batch could not be processed right now.';
    }

    private function runtimeMessage(\Throwable $exception): string
    {
        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'The batch could not be processed right now.';
    }
}
