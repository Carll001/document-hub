<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Form1702ExBatchRow;
use App\Models\SyncedEmail;
use App\Services\EmailSync\BirReceiptAutoMatchService;
use App\Services\Form1702ExCompletedEmailService;
use App\Services\Form1702ExRowReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateForm1702ExRowReceipt implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<string, scalar|null>  $values
     */
    public function __construct(
        public readonly int $rowId,
        public readonly array $values,
        public readonly ?int $syncedEmailId = null,
    ) {
        $this->onQueue('filing-1702');
    }

    public function handle(
        Form1702ExRowReceiptService $form1702ExRowReceiptService,
        BirReceiptAutoMatchService $birReceiptAutoMatchService,
        Form1702ExCompletedEmailService $form1702ExCompletedEmailService,
    ): void
    {
        $row = Form1702ExBatchRow::query()
            ->with('batch')
            ->findOrFail($this->rowId);
        $email = $this->syncedEmailId !== null
            ? SyncedEmail::query()->find($this->syncedEmailId)
            : null;

        $row->forceFill([
            'receipt_job_status' => Form1702ExBatchRow::RECEIPT_JOB_STATUS_PROCESSING,
            'receipt_job_error' => null,
        ])->save();

        try {
            $form1702ExRowReceiptService->generateAndAttachReceipt(
                $row,
                $this->values,
            );

            $row->forceFill([
                'receipt_job_status' => null,
                'receipt_job_error' => null,
            ])->save();

            if ($email instanceof SyncedEmail) {
                $birReceiptAutoMatchService->markReceiptApplied($row, $email);
            }

            try {
                $form1702ExCompletedEmailService->queueAutomaticIfNeeded($row->fresh() ?? $row);
            } catch (\Throwable $exception) {
                report($exception);
            }
        } catch (\Throwable $exception) {
            report($exception);

            $message = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'The 1702-EX receipt could not be generated right now.';

            $row->forceFill([
                'receipt_job_status' => Form1702ExBatchRow::RECEIPT_JOB_STATUS_FAILED,
                'receipt_job_error' => $message,
            ])->save();

            if ($email instanceof SyncedEmail) {
                $birReceiptAutoMatchService->markReceiptFailed($row, $email, $message);
            }
        }
    }
}
