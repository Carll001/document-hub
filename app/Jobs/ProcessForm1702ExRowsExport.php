<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Form1702ExBatchRow;
use App\Models\User;
use App\Services\Form1702ExRowsExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessForm1702ExRowsExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $userId,
        public readonly string $search,
        public readonly string $sort,
        public readonly string $direction,
    ) {
    }

    public function handle(Form1702ExRowsExportService $rowsExportService): void
    {
        $user = User::query()->find($this->userId);

        if (! $user instanceof User) {
            return;
        }

        $rowsExportService->putState($this->userId, [
            'status' => Form1702ExRowsExportService::STATUS_PROCESSING,
            'error' => null,
            'rowCount' => null,
            'downloadUrl' => null,
            'storagePath' => null,
        ]);

        try {
            $rows = $this->rowsQuery($user)->get();

            if ($rows->isEmpty()) {
                throw new \RuntimeException('No imported rows matched this export request.');
            }

            $export = $rowsExportService->buildXlsx($rows, $this->userId);

            $rowsExportService->putState($this->userId, [
                'status' => Form1702ExRowsExportService::STATUS_READY,
                'error' => null,
                'rowCount' => $export['rowCount'],
                'downloadUrl' => route('forms.form1702ex.rows.export.file'),
                'storagePath' => $export['storagePath'],
            ]);
        } catch (\Throwable $exception) {
            $rowsExportService->putState($this->userId, [
                'status' => Form1702ExRowsExportService::STATUS_FAILED,
                'error' => $this->runtimeMessage($exception),
                'rowCount' => null,
                'downloadUrl' => null,
                'storagePath' => null,
            ]);

            throw $exception;
        }
    }

    private function rowsQuery(User $user)
    {
        $query = Form1702ExBatchRow::query()
            ->with(['batch', 'client', 'company'])
            ->whereHas('batch', fn ($batchQuery) => $batchQuery->whereBelongsTo($user))
            ->whereNull('duplicate_resolution_status')
            ->where(function ($rowQuery): void {
                $rowQuery
                    ->where('pdf_status', '!=', Form1702ExBatchRow::PDF_STATUS_GENERATED)
                    ->orWhereNull('generated_pdf_storage_path')
                    ->orWhereNull('receipt_storage_path')
                    ->orWhereNull('receipt_file_name')
                    ->orWhere('receipt_is_temporary', true);
            });

        $search = trim($this->search);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($searchQuery) use ($like): void {
                $searchQuery
                    ->where('generated_pdf_file_name', 'like', $like)
                    ->orWhere('source_name', 'like', $like)
                    ->orWhere('pdf_status', 'like', $like)
                    ->orWhere('payload->taxpayer_name', 'like', $like)
                    ->orWhere('payload->registered_name', 'like', $like)
                    ->orWhere('payload->client_name', 'like', $like)
                    ->orWhere('payload->tin', 'like', $like)
                    ->orWhere('payload->email_address', 'like', $like)
                    ->orWhere('completed_email_recipient', 'like', $like)
                    ->orWhere('receipt_file_name', 'like', $like)
                    ->orWhere('receipt_job_status', 'like', $like)
                    ->orWhere('receipt_job_error', 'like', $like)
                    ->orWhere('pdf_error', 'like', $like)
                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('name', 'like', $like))
                    ->orWhereHas('company', function ($companyQuery) use ($like): void {
                        $companyQuery
                            ->where('name', 'like', $like)
                            ->orWhere('tin', 'like', $like);
                    });
            });
        }

        $sortColumn = match ($this->sort) {
            'generatedAt' => 'generated_at',
            'pdfStatus' => 'pdf_status',
            'sourceRowNumber' => 'source_row_number',
            default => 'uploaded_at',
        };
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderByDesc('id');
    }

    private function runtimeMessage(\Throwable $exception): string
    {
        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'The imported rows Excel file could not be prepared right now.';
    }
}
