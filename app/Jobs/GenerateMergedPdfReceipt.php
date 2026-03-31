<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MergedPdf;
use App\Services\MergedPdfReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMergedPdfReceipt implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  list<string>  $placeholders
     * @param  array<string, scalar|null>  $placeholderValues
     */
    public function __construct(
        public readonly int $mergedPdfId,
        public readonly string $templateStoragePath,
        public readonly array $placeholders,
        public readonly array $placeholderValues,
    ) {
    }

    public function handle(MergedPdfReceiptService $mergedPdfReceiptService): void
    {
        $mergedPdf = MergedPdf::query()->find($this->mergedPdfId);

        if (! $mergedPdf instanceof MergedPdf) {
            return;
        }

        $mergedPdf->forceFill([
            'receipt_job_status' => MergedPdf::RECEIPT_JOB_STATUS_PROCESSING,
            'receipt_job_error' => null,
        ])->save();

        try {
            $mergedPdfReceiptService->generateAndAttachReceipt(
                $mergedPdf,
                $this->templateStoragePath,
                $this->placeholders,
                $this->placeholderValues,
            );

            $mergedPdf->forceFill([
                'receipt_job_status' => null,
                'receipt_job_error' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $mergedPdf->forceFill([
                'receipt_job_status' => MergedPdf::RECEIPT_JOB_STATUS_FAILED,
                'receipt_job_error' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'The receipt could not be generated right now. Please try again.',
            ])->save();

            throw $exception;
        }
    }
}
