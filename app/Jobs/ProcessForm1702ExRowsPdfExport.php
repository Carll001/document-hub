<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Form1702ExBatchRow;
use App\Models\User;
use App\Services\Form1702ExRowsPdfExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ProcessForm1702ExRowsPdfExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(
        public readonly int $userId,
        public readonly string $search,
        public readonly string $sort,
        public readonly string $direction,
        public readonly string $status = 'all',
    ) {
        $this->onQueue('filing-1702');
    }

    public function handle(Form1702ExRowsPdfExportService $rowsPdfExportService): void
    {
        $user = User::query()->find($this->userId);

        if (! $user instanceof User) {
            return;
        }

        $rowsPdfExportService->putState($this->userId, [
            'status' => Form1702ExRowsPdfExportService::STATUS_PROCESSING,
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

            $export = $rowsPdfExportService->buildZip($rows, $this->userId);

            $rowsPdfExportService->putState($this->userId, [
                'status' => Form1702ExRowsPdfExportService::STATUS_READY,
                'error' => null,
                'rowCount' => $export['rowCount'],
                'downloadUrl' => route('forms.form1702ex.rows.export.pdf.file'),
                'downloadFileName' => $this->downloadFileName(),
                'storagePath' => $export['storagePath'],
            ]);
        } catch (\Throwable $exception) {
            $rowsPdfExportService->putState($this->userId, [
                'status' => Form1702ExRowsPdfExportService::STATUS_FAILED,
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
            ->whereHas('batch', fn ($batchQuery) => $batchQuery->whereBelongsTo($user))
            ->whereNull('duplicate_resolution_status')
            ->where(function ($rowQuery): void {
                $rowQuery
                    ->where('pdf_status', '!=', Form1702ExBatchRow::PDF_STATUS_GENERATED)
                    ->orWhereNull('generated_pdf_storage_path')
                    ->orWhereNull('receipt_storage_path')
                    ->orWhereNull('receipt_file_name')
                    ->orWhere('receipt_is_temporary', true)
                    ->orWhereNull('payload->signature')
                    ->orWhere('payload->signature', '=', '');
            });

        $search = trim($this->search);
        $status = trim($this->status);

        if ($status !== '' && $status !== 'all') {
            switch ($status) {
                case 'generated':
                    $query->where('pdf_status', Form1702ExBatchRow::PDF_STATUS_GENERATED);
                    break;
                case 'processing':
                    $query->whereIn('pdf_status', [
                        Form1702ExBatchRow::PDF_STATUS_QUEUED,
                        Form1702ExBatchRow::PDF_STATUS_PROCESSING,
                    ]);
                    break;
                case 'signed':
                    $query
                        ->whereNotNull('payload->signature')
                        ->where('payload->signature', '!=', '');
                    break;
                case 'not_signed':
                    $query->where(function ($signatureQuery): void {
                        $signatureQuery
                            ->whereNull('payload->signature')
                            ->orWhere('payload->signature', '=', '');
                    });
                    break;
                case 'receipt_attached':
                    $query
                        ->whereNotNull('receipt_storage_path')
                        ->whereNotNull('receipt_file_name');
                    break;
            }
        }

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
            : 'The imported rows PDF ZIP could not be prepared right now.';
    }

    private function downloadFileName(): string
    {
        $status = Str::of($this->status)
            ->trim()
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->trim('_')
            ->upper()
            ->value();

        if ($status === '') {
            $status = 'ALL';
        }

        return sprintf('1702EX_%s_%s.zip', $status, now()->format('Ymd_His'));
    }
}
