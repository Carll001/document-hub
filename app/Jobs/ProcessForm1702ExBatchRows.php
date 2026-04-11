<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Form1702ExBatchRow;
use App\Services\EmailSync\BirReceiptAutoMatchService;
use App\Services\Form1702ExService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessForm1702ExBatchRows implements ShouldQueue
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
        public readonly array $rowIds,
    ) {
    }

    public function handle(
        Form1702ExService $form1702ExService,
        BirReceiptAutoMatchService $birReceiptAutoMatchService,
    ): void
    {
        $rows = Form1702ExBatchRow::query()
            ->with('batch')
            ->whereIn('id', $this->rowIds)
            ->get();

        foreach ($rows as $row) {
            $row->forceFill([
                'pdf_status' => Form1702ExBatchRow::PDF_STATUS_PROCESSING,
                'pdf_error' => null,
            ])->save();

            try {
                $row = $form1702ExService->generateBatchRowPdf($row);
                $birReceiptAutoMatchService->applyPendingForRow($row);
            } catch (\Throwable $exception) {
                report($exception);

                $row->forceFill([
                    'pdf_status' => Form1702ExBatchRow::PDF_STATUS_FAILED,
                    'pdf_error' => $this->runtimeMessage($exception),
                    'generated_pdf_file_name' => null,
                    'generated_pdf_storage_path' => null,
                    'generated_pdf_file_size' => null,
                    'generated_at' => null,
                ])->save();
            }
        }
    }

    private function runtimeMessage(\Throwable $exception): string
    {
        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'The 1702-EX PDF could not be generated right now.';
    }
}
