<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Services\Form1702ExBatchService;
use App\Services\Form1702ExImportService;
use App\Support\DocumentStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;

class ProcessForm1702ExBatchImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $batchId,
    ) {
        $this->onQueue('filing-1702');
    }

    public function handle(
        Form1702ExBatchService $form1702ExBatchService,
        Form1702ExImportService $form1702ExImportService,
    ): void {
        $batch = Form1702ExBatch::query()->find($this->batchId);

        if (! $batch instanceof Form1702ExBatch) {
            return;
        }

        if ($batch->import_status !== Form1702ExBatch::IMPORT_STATUS_QUEUED) {
            return;
        }

        $batch->forceFill([
            'import_status' => Form1702ExBatch::IMPORT_STATUS_PROCESSING,
            'import_error' => null,
        ])->save();

        try {
            $importSourcePath = $batch->import_source_path;
            $importSourceName = $batch->import_source_name;

            if (! DocumentStorage::isValidPath($importSourcePath) || ! DocumentStorage::disk()->exists($importSourcePath)) {
                throw new \RuntimeException('The uploaded spreadsheet could not be found. Please upload it again.');
            }

            if (! is_string($importSourceName) || $importSourceName === '') {
                throw new \RuntimeException('The uploaded spreadsheet name is missing. Please upload it again.');
            }

            $basePayload = app(\App\Services\Form1702ExService::class)->batchPayloadDefaults();
            $basePayload['file_name_prefix'] = $batch->file_name_prefix;
            $basePayload['footer_source_path'] = $batch->footer_source_path;
            $basePayload['footer_printed_date'] = $batch->footer_printed_date;

            $import = $form1702ExImportService->importStoredFile(
                $importSourcePath,
                $importSourceName,
                $basePayload,
            );

            $rows = $form1702ExBatchService->storeImport(
                $batch,
                $import,
                false,
            );

            $processableRows = $rows
                ->filter(fn (Form1702ExBatchRow $row): bool => ! $row->isSkippedDuplicate())
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->chunk(15);

            foreach ($processableRows as $rowIds) {
                $rowIds = $rowIds->values()->all();

                if ($rowIds !== []) {
                    ProcessForm1702ExBatchRows::dispatch($rowIds);
                }
            }

            $batch->forceFill([
                'import_status' => null,
                'import_error' => null,
                'import_completed_at' => now(),
            ])->save();
        } catch (ValidationException $exception) {
            $batch->forceFill([
                'import_status' => Form1702ExBatch::IMPORT_STATUS_FAILED,
                'import_error' => $this->validationMessage($exception),
            ])->save();

            throw $exception;
        } catch (\Throwable $exception) {
            $batch->forceFill([
                'import_status' => Form1702ExBatch::IMPORT_STATUS_FAILED,
                'import_error' => $this->runtimeMessage($exception),
            ])->save();

            throw $exception;
        } finally {
            $batch->refresh();

            if (is_string($batch->import_source_path) && trim($batch->import_source_path) !== '') {
                if (DocumentStorage::isValidPath($batch->import_source_path)) {
                    DocumentStorage::disk()->delete($batch->import_source_path);
                }

                $batch->forceFill([
                    'import_source_path' => null,
                ])->save();
            }
        }
    }

    private function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())
            ->flatten()
            ->first();

        return is_string($message) && $message !== ''
            ? $message
            : 'The spreadsheet import could not be processed right now.';
    }

    private function runtimeMessage(\Throwable $exception): string
    {
        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'The spreadsheet import could not be processed right now.';
    }
}
