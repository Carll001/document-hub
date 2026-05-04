<?php

declare(strict_types=1);

namespace App\Services\Filing;

use App\Contracts\Repositories\FilingRepository as FilingRepositoryContract;
use App\Models\Company;
use App\Models\FilingOutput;
use App\Support\DocumentStorage;

class FilingService
{
    public function __construct(
        private readonly FilingRepositoryContract $filingRepository,
    ) {}

    /**
     * @param  array<int, int>  $companyIds
     * @return array<int, FilingOutput>
     */
    public function listAfsOutputs(array $companyIds, string $search): array
    {
        $rows = $this->filingRepository->getAfsOutputs($companyIds)
            ->map(fn (FilingOutput $row): array => [
                'id' => (int) $row->id,
                'company_id' => (int) $row->company_id,
                'name' => (string) $row->company_name,
                'tin' => (string) $row->tin,
                'form_type' => (string) $row->form_type,
                'status' => (string) $row->status,
                'file_path' => is_string($row->file_path) && trim($row->file_path) !== '' ? $row->file_path : null,
                'pdf_available' => is_string($row->file_path) && trim($row->file_path) !== '' && DocumentStorage::disk()->exists($row->file_path),
                'file_name' => (string) ($row->file_name ?? ''),
                'error_message' => (string) ($row->error_message ?? ''),
                'updated_at' => optional($row->updated_at)?->toIso8601String(),
            ])
            ->values();

        return $this->applyOutputSearchFilter($rows->all(), $search, ['name', 'tin', 'status']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOutputs(?string $formType, string $status, string $search): array
    {
        $rows = $this->filingRepository->getOutputs($formType, $status)
            ->map(fn (FilingOutput $row): array => [
                'id' => (int) $row->id,
                'company_id' => (int) $row->company_id,
                'name' => (string) $row->company_name,
                'tin' => (string) $row->tin,
                'form_type' => (string) $row->form_type,
                'status' => (string) $row->status,
                'file_path' => is_string($row->file_path) && trim($row->file_path) !== '' ? $row->file_path : null,
                'pdf_available' => is_string($row->file_path) && trim($row->file_path) !== '' && DocumentStorage::disk()->exists($row->file_path),
                'file_name' => (string) ($row->file_name ?? ''),
                'error_message' => (string) ($row->error_message ?? ''),
                'updated_at' => optional($row->updated_at)?->toIso8601String(),
            ])
            ->values();

        return $this->applyOutputSearchFilter($rows->all(), $search, ['name', 'tin', 'status', 'form_type']);
    }

    public function buildAfsRowData(
        Company $company,
        int $filingOutputId,
        ?string $presidentSignaturePath = null,
        ?string $getorSignaturePath = null,
    ): array
    {
        $rowData = is_array($company->data) ? $company->data : [];
        foreach ($rowData as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $normalizedKey = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($key))) ?? '';
            $normalizedKey = trim($normalizedKey, '_');
            if (in_array($normalizedKey, ['address', 'company_address', 'registered_address', 'business_address'], true)) {
                $rowData[$key] = $this->normalizeSingleLineValue((string) $value);
            }
        }

        $rowData['company'] = (string) $company->name;
        $rowData['company name'] = (string) $company->name;
        $rowData['registered name'] = (string) $company->name;
        $rowData['tin'] = (string) $company->tin;
        $rowData['address'] = $this->normalizeSingleLineValue((string) $company->address);
        $rowData['company address'] = $this->normalizeSingleLineValue((string) $company->address);
        $rowData['__company_id'] = (string) $company->id;
        $rowData['__flow'] = 'filing_step4_afs';
        $rowData['__filing_output_id'] = (string) $filingOutputId;
        if (is_string($presidentSignaturePath) && trim($presidentSignaturePath) !== '') {
            $rowData['__president_signature_path'] = trim($presidentSignaturePath);
        }
        if (is_string($getorSignaturePath) && trim($getorSignaturePath) !== '') {
            $rowData['__getor_signature_path'] = trim($getorSignaturePath);
        }

        return $rowData;
    }

    private function normalizeSingleLineValue(string $value): string
    {
        $flattened = preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r", "\n"], ' ', $value));

        return is_string($flattened) ? trim($flattened) : trim($value);
    }

    public function resetOutputForRegeneration(FilingOutput $item): FilingOutput
    {
        $item->status = 'queued';
        $item->error_message = null;
        $item->file_path = null;
        $item->file_name = null;

        return $this->filingRepository->saveFilingOutput($item);
    }

    public function queueOutputForDeletion(FilingOutput $item): FilingOutput
    {
        $item->status = 'deleting';

        return $this->filingRepository->saveFilingOutput($item);
    }

    public function afsOutputFileName(FilingOutput $item): string
    {
        $saved = trim((string) ($item->file_name ?? ''));
        if ($saved !== '') {
            return $saved;
        }

        $company = trim((string) $item->company_name);
        $base = $company !== '' ? $company : 'afs-output-'.$item->id;

        $normalized = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
        $normalized = is_string($normalized) ? trim($normalized, '-._') : '';

        return ($normalized !== '' ? $normalized : 'afs-output-'.$item->id).'.pdf';
    }

    public function outputFileExists(FilingOutput $item): bool
    {
        $pdfPath = (string) ($item->file_path ?? '');

        return $pdfPath !== '' && DocumentStorage::disk()->exists($pdfPath);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    private function applyOutputSearchFilter(array $rows, string $search, array $keys): array
    {
        $needle = trim($search);
        if ($needle === '') {
            return $rows;
        }

        $needle = mb_strtolower($needle);

        return array_values(array_filter($rows, function (array $row) use ($needle, $keys): bool {
            foreach ($keys as $key) {
                if (str_contains(mb_strtolower((string) ($row[$key] ?? '')), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
