<?php

declare(strict_types=1);

namespace App\Services\Companies;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CompanyService
{
    /**
     * @var array<string, list<string>>
     */
    private const IMPORT_FIELD_ALIASES = [
        'name' => ['name', 'company', 'companyname', 'registered_name', 'companylegalname', 'COMPANY NAME'],
        'tin' => ['tin', 'companytin', 'taxid', 'taxidentificationnumber'],
        'address' => ['address', 'companyaddress', 'registeredaddress', 'businessaddress'],
    ];

    /**
     * @return array{
     *   inserted: int,
     *   updated: int,
     *   skipped: int,
     *   skipped_missing_name: int,
     *   skipped_missing_tin: int,
     *   skipped_invalid_tin: int
     * }
     */
    public function importCompanies(UploadedFile $spreadsheet, int $userId): array
    {
        $extension = Str::lower($spreadsheet->getClientOriginalExtension() ?: $spreadsheet->extension() ?: '');
        $rows = $this->importRowsFromFile($spreadsheet->getRealPath() ?: $spreadsheet->getPathname(), $extension);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $skippedMissingName = 0;
        $skippedMissingTin = 0;
        $skippedInvalidTin = 0;

        DB::transaction(function () use (
            $rows,
            $userId,
            &$inserted,
            &$updated,
            &$skipped,
            &$skippedMissingName,
            &$skippedMissingTin,
            &$skippedInvalidTin,
        ): void {
            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                $tin = trim((string) ($row['tin'] ?? ''));
                $address = trim((string) ($row['address'] ?? ''));
                $data = is_array($row['data'] ?? null) ? $row['data'] : [];

                if ($name === '' || $tin === '') {
                    if ($name === '') {
                        $skippedMissingName++;
                    }
                    if ($tin === '') {
                        $skippedMissingTin++;
                    }
                    $skipped++;
                    continue;
                }

                $normalizedName = $this->normalizeName($name);
                $normalizedTin = $this->normalizeTin($tin);

                if ($normalizedTin === '') {
                    $skippedInvalidTin++;
                    $skipped++;
                    continue;
                }

                $existing = Company::query()
                    ->where('name_normalized', $normalizedName)
                    ->where('tin_normalized', $normalizedTin)
                    ->first();

                if ($existing instanceof Company) {
                    $existingData = is_array($existing->data) ? $existing->data : [];
                    $mergedData = array_replace($existingData, $data);

                    $existing->forceFill([
                        'name' => $name,
                        'tin' => $tin,
                        'address' => $address !== '' ? $address : $existing->address,
                        'data' => $mergedData !== [] ? $mergedData : null,
                        'imported_via_excel' => true,
                    ])->save();

                    $updated++;
                    continue;
                }

                Company::query()->create([
                    'user_id' => $userId,
                    'client_id' => null,
                    'name' => $name,
                    'name_normalized' => $normalizedName,
                    'tin' => $tin,
                    'tin_normalized' => $normalizedTin,
                    'address' => $address !== '' ? $address : null,
                    'data' => $data !== [] ? $data : null,
                    'imported_via_excel' => true,
                ]);

                $inserted++;
            }
        });

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'skipped_missing_name' => $skippedMissingName,
            'skipped_missing_tin' => $skippedMissingTin,
            'skipped_invalid_tin' => $skippedInvalidTin,
        ];
    }

    private function normalizeName(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $collapsed = preg_replace('/\s+/u', ' ', $normalized);

        return $collapsed !== null ? $collapsed : $normalized;
    }

    private function normalizeTin(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);

        return is_string($digits) ? $digits : '';
    }

    /**
     * @return list<array{name: string, tin: string, address: string, data: array<string, string>}>
     */
    private function importRowsFromFile(string $path, string $extension): array
    {
        if ($extension === 'csv' || $extension === 'txt') {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if (! is_array($lines) || $lines === []) {
                return [];
            }

            $rows = array_map('str_getcsv', $lines);
            $headers = array_map(static fn ($header): string => trim((string) $header), $rows[0] ?? []);
            $result = [];

            foreach (array_slice($rows, 1) as $row) {
                $record = $this->combineRowWithHeaders($headers, $row);
                $result[] = $this->extractCanonicalImportRow($record);
            }

            return $result;
        }

        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray(null, true, true, false);
        $headers = array_map(static fn ($header): string => trim((string) $header), $rows[0] ?? []);
        $result = [];

        foreach (array_slice($rows, 1) as $row) {
            $record = $this->combineRowWithHeaders($headers, $row);
            $result[] = $this->extractCanonicalImportRow($record);
        }

        return $result;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<mixed>  $row
     * @return array<string, string>
     */
    private function combineRowWithHeaders(array $headers, array $row): array
    {
        $record = [];

        foreach ($headers as $index => $header) {
            $trimmedHeader = trim($header);
            if ($trimmedHeader === '') {
                continue;
            }

            $record[$trimmedHeader] = trim((string) ($row[$index] ?? ''));
        }

        return $record;
    }

    /**
     * @param  array<string, string>  $record
     * @return array{name: string, tin: string, address: string, data: array<string, string>}
     */
    private function extractCanonicalImportRow(array $record): array
    {
        $normalizedRecord = [];

        foreach ($record as $header => $value) {
            $normalizedHeader = $this->normalizeImportHeader($header);
            if ($normalizedHeader === '') {
                continue;
            }

            $normalizedRecord[$normalizedHeader] = trim((string) $value);
        }

        $name = $this->firstImportFieldValue($normalizedRecord, self::IMPORT_FIELD_ALIASES['name']);
        $tin = $this->firstImportFieldValue($normalizedRecord, self::IMPORT_FIELD_ALIASES['tin']);
        $address = $this->firstImportFieldValue($normalizedRecord, self::IMPORT_FIELD_ALIASES['address']);
        $data = [];

        foreach ($record as $header => $value) {
            $trimmedHeader = trim((string) $header);
            $trimmedValue = trim((string) $value);

            if ($trimmedHeader === '' || $trimmedValue === '') {
                continue;
            }

            $data[$trimmedHeader] = $trimmedValue;
        }

        return [
            'name' => $name,
            'tin' => $tin,
            'address' => $address,
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, string>  $normalizedRecord
     * @param  list<string>  $aliases
     */
    private function firstImportFieldValue(array $normalizedRecord, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $value = trim((string) ($normalizedRecord[$alias] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeImportHeader(string $header): string
    {
        $normalized = mb_strtolower(trim($header));
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized);

        return is_string($normalized) ? $normalized : '';
    }
}
