<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Models\User;
use App\Services\EmailSync\BirReceiptAutoMatchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Form1702ExBatchService
{
    public function __construct(
        private readonly BirReceiptAutoMatchService $birReceiptAutoMatchService,
    ) {
    }

    public function createBatch(User $user, string $name): Form1702ExBatch
    {
        $normalizedName = $this->normalizeBatchName($name);

        if ($normalizedName === '') {
            throw ValidationException::withMessages([
                'name' => 'Enter a batch name.',
            ]);
        }

        return Form1702ExBatch::query()->create([
            'user_id' => $user->id,
            'name' => $normalizedName,
        ]);
    }

    /**
     * @param  array{
     *     sourceName: string,
     *     sourceType: string,
     *     importedAt: string,
     *     rows: list<array{rowNumber: int, payload: array<string, mixed>}>
     * }  $import
     * @return Collection<int, Form1702ExBatchRow>
     */
    public function storeImport(Form1702ExBatch $batch, array $import, bool $replaceExisting): Collection
    {
        $rows = collect($import['rows'] ?? [])
            ->filter(static fn (mixed $row): bool => is_array($row) && is_array($row['payload'] ?? null))
            ->values();

        if ($rows->isEmpty()) {
            throw new RuntimeException('The uploaded file did not contain any importable rows.');
        }

        $uploadedAt = Carbon::parse((string) ($import['importedAt'] ?? now()->toIso8601String()));
        $sourceName = (string) ($import['sourceName'] ?? '1702-ex-import');
        $sourceType = (string) ($import['sourceType'] ?? 'csv');

        return DB::transaction(function () use (
            $batch,
            $replaceExisting,
            $rows,
            $uploadedAt,
            $sourceName,
            $sourceType,
        ): Collection {
            if ($replaceExisting) {
                $batch->rows()->get()->each->delete();
            }

            /** @var Collection<int, Form1702ExBatchRow> $existingRows */
            $existingRows = Form1702ExBatchRow::query()
                ->whereHas('batch', fn ($query) => $query->where('user_id', $batch->user_id))
                ->get();

            /** @var Collection<int, Form1702ExBatchRow> $createdRows */
            $createdRows = collect();
            $tinsToSync = [];

            foreach ($rows as $row) {
                $payload = $row['payload'];
                $normalizedTin = $this->normalizeTin($payload['tin'] ?? null);
                $receiptOwner = $normalizedTin !== null
                    ? $existingRows->first(fn (Form1702ExBatchRow $existingRow): bool => $this->rowOwnsReceiptForTin($existingRow, $normalizedTin))
                    : null;

                $attributes = [
                    'form_1702_ex_batch_id' => $batch->id,
                    'source_name' => $sourceName,
                    'source_type' => $sourceType,
                    'source_row_number' => (int) ($row['rowNumber'] ?? 0),
                    'uploaded_at' => $uploadedAt,
                    'payload' => array_replace($payload, [
                        'receipt_acceptance_start_date' => $batch->receipt_acceptance_start_date?->toDateString(),
                    ]),
                    'generated_pdf_file_name' => null,
                    'generated_pdf_storage_path' => null,
                    'generated_pdf_file_size' => null,
                    'generated_at' => null,
                    'receipt_file_name' => null,
                    'receipt_storage_path' => null,
                    'receipt_file_size' => null,
                    'receipt_job_status' => null,
                    'receipt_job_error' => null,
                ];

                if ($receiptOwner instanceof Form1702ExBatchRow) {
                    $attributes = array_replace($attributes, [
                        'pdf_status' => Form1702ExBatchRow::PDF_STATUS_FAILED,
                        'pdf_error' => 'Skipped duplicate TIN because an earlier row already has a receipt.',
                        'duplicate_resolution_status' => Form1702ExBatchRow::DUPLICATE_RESOLUTION_SKIPPED,
                        'duplicate_of_form_1702_ex_batch_row_id' => $receiptOwner->id,
                        'duplicate_resolved_at' => now(),
                    ]);
                } else {
                    $attributes = array_replace($attributes, [
                        'pdf_status' => Form1702ExBatchRow::PDF_STATUS_QUEUED,
                        'pdf_error' => null,
                    ]);
                }

                $createdRows->push(
                    Form1702ExBatchRow::query()->create($attributes),
                );

                $existingRows->push($createdRows->last());

                if ($normalizedTin !== null && ! ($receiptOwner instanceof Form1702ExBatchRow)) {
                    $tinsToSync[] = $normalizedTin;
                }
            }

            $this->birReceiptAutoMatchService->syncStoredEmailsForTins(
                (int) $batch->user_id,
                $tinsToSync,
            );

            return $createdRows;
        });
    }

    private function normalizeBatchName(string $name): string
    {
        return (string) Str::of($name)->squish()->trim();
    }

    private function normalizeTin(mixed $tin): ?string
    {
        $digits = preg_replace('/\D+/', '', trim((string) $tin));
        $digits = is_string($digits) ? $digits : '';

        return $digits !== '' ? $digits : null;
    }

    private function rowOwnsReceiptForTin(Form1702ExBatchRow $row, string $normalizedTin): bool
    {
        if ($row->isSkippedDuplicate()) {
            return false;
        }

        $payload = is_array($row->payload) ? $row->payload : [];

        return $this->normalizeTin($payload['tin'] ?? null) === $normalizedTin
            && filled($row->receipt_storage_path)
            && filled($row->receipt_file_name);
    }
}
