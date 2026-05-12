<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ZipArchive;

class Form1702ExRowsExportService
{
    private const CACHE_TTL_SECONDS = 21600;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    public const STATUS_READY = 'ready';

    public function cacheKey(int $userId): string
    {
        return "forms:1702-ex:rows-export:{$userId}";
    }

    public function getState(int $userId): array
    {
        $state = Cache::get($this->cacheKey($userId));

        if (! is_array($state)) {
            return $this->emptyState();
        }

        $status = $state['status'] ?? null;

        if (! in_array($status, [
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
            self::STATUS_FAILED,
            self::STATUS_READY,
        ], true)) {
            return $this->emptyState();
        }

        if ($status === self::STATUS_READY) {
            $storagePath = is_string($state['storagePath'] ?? null) ? $state['storagePath'] : null;

            if ($storagePath === null || ! \App\Support\DocumentStorage::exists($storagePath)) {
                $this->forgetState($userId);

                return $this->emptyState();
            }
        }

        return [
            'status' => $status,
            'error' => is_string($state['error'] ?? null) ? $state['error'] : null,
            'rowCount' => is_numeric($state['rowCount'] ?? null) ? (int) $state['rowCount'] : null,
            'downloadUrl' => is_string($state['downloadUrl'] ?? null) ? $state['downloadUrl'] : null,
        ];
    }

    /**
     * @param  array{status: string, error?: string|null, rowCount?: int|null, downloadUrl?: string|null, storagePath?: string|null}  $state
     */
    public function putState(int $userId, array $state): void
    {
        Cache::put($this->cacheKey($userId), $state, now()->addSeconds(self::CACHE_TTL_SECONDS));
    }

    public function forgetState(int $userId): void
    {
        $cached = Cache::get($this->cacheKey($userId));

        if (is_array($cached)) {
            $storagePath = $cached['storagePath'] ?? null;

            if (is_string($storagePath) && $storagePath !== '') {
                \App\Support\DocumentStorage::disk()->delete($storagePath);
            }
        }

        Cache::forget($this->cacheKey($userId));
    }

    /**
     * @param  Collection<int, Form1702ExBatchRow>  $rows
     * @return array{storagePath: string, downloadFileName: string, rowCount: int}
     */
    public function buildXlsx(Collection $rows, int $userId): array
    {
        $directory = "tmp/form-1702-ex-rows-exports/user-{$userId}";
        $disk = \App\Support\DocumentStorage::disk();
        $disk->makeDirectory($directory);

        $fileName = '1702-ex-unmatched-rows-'.Str::uuid().'.xlsx';
        $storagePath = "{$directory}/{$fileName}";
        $temporaryOutputPath = storage_path('app/tmp/form-1702-ex-unmatched-rows-'.Str::uuid().'.xlsx');

        if (! is_dir(dirname($temporaryOutputPath))) {
            mkdir(dirname($temporaryOutputPath), 0777, true);
        }

        $headers = [
            'File name',
            'Taxpayer',
            'TIN',
            'Source file',
            'PDF Email Address',
            'Recipient',
            'Uploaded at',
            'Receipt acceptance',
        ];
        $exportRows = $this->buildRows($rows);

        if ($exportRows === []) {
            throw new \RuntimeException('No imported rows matched this export request.');
        }

        try {
            $this->writeSimpleXlsx($temporaryOutputPath, $headers, $exportRows);
            $stream = fopen($temporaryOutputPath, 'rb');

            if (! is_resource($stream)) {
                throw new \RuntimeException('The imported rows Excel file could not be created.');
            }

            try {
                if (! $disk->put($storagePath, $stream)) {
                    throw new \RuntimeException('The imported rows Excel file could not be stored.');
                }
            } finally {
                fclose($stream);
            }
        } finally {
            if (is_file($temporaryOutputPath)) {
                @unlink($temporaryOutputPath);
            }
        }

        return [
            'storagePath' => $storagePath,
            'downloadFileName' => '1702-ex-unmatched-rows.xlsx',
            'rowCount' => count($exportRows),
        ];
    }

    /**
     * @return array{status: null, error: null, rowCount: null, downloadUrl: null}
     */
    private function emptyState(): array
    {
        return [
            'status' => null,
            'error' => null,
            'rowCount' => null,
            'downloadUrl' => null,
        ];
    }

    /**
     * @param  Collection<int, Form1702ExBatchRow>  $rows
     * @return array<int, array<int, string>>
     */
    private function buildRows(Collection $rows): array
    {
        return $rows
            ->map(function (Form1702ExBatchRow $row): array {
                /** @var array<string, mixed> $payload */
                $payload = is_array($row->payload) ? $row->payload : [];
                /** @var Form1702ExBatch|null $batch */
                $batch = $row->batch;

                return [
                    filled($row->generated_pdf_file_name)
                        ? (string) $row->generated_pdf_file_name
                        : 'Not generated yet',
                    trim((string) ($payload['taxpayer_name'] ?? $payload['registered_name'] ?? 'Row '.$row->source_row_number)),
                    (string) ($payload['tin'] ?? ''),
                    (string) $row->source_name,
                    trim((string) ($payload['email_address'] ?? '')),
                    trim((string) ($row->completed_email_recipient ?? '')),
                    $this->exportDateTimeValue($row->uploaded_at?->toIso8601String()),
                    $this->exportDateValue(
                        is_scalar($payload['receipt_acceptance_start_date'] ?? null)
                            ? (string) $payload['receipt_acceptance_start_date']
                            : $batch?->receipt_acceptance_start_date?->toDateString(),
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function writeSimpleXlsx(string $filePath, array $headers, array $rows): void
    {
        $archive = new ZipArchive;

        if ($archive->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The imported rows Excel file could not be created.');
        }

        $archive->addFromString('[Content_Types].xml', $this->xlsxContentTypesXml());
        $archive->addFromString('_rels/.rels', $this->xlsxRootRelsXml());
        $archive->addFromString('xl/workbook.xml', $this->xlsxWorkbookXml());
        $archive->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRelsXml());
        $archive->addFromString('xl/styles.xml', $this->xlsxStylesXml());
        $archive->addFromString('xl/worksheets/sheet1.xml', $this->xlsxWorksheetXml($headers, $rows));
        $archive->close();
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function xlsxWorksheetXml(array $headers, array $rows): string
    {
        $sheetRows = [$this->xlsxRowXml(1, $headers, true)];

        foreach ($rows as $index => $row) {
            $sheetRows[] = $this->xlsxRowXml($index + 2, $row, false);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $sheetRows).'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param  array<int, string>  $values
     */
    private function xlsxRowXml(int $rowNumber, array $values, bool $header): string
    {
        $cells = [];

        foreach (array_values($values) as $index => $value) {
            $reference = $this->xlsxColumnName($index + 1).$rowNumber;
            $style = $header ? ' s="1"' : '';

            $cells[] = sprintf(
                '<c r="%s" t="inlineStr"%s><is><t xml:space="preserve">%s</t></is></c>',
                $reference,
                $style,
                $this->escapeXml((string) $value),
            );
        }

        return sprintf('<row r="%d">%s</row>', $rowNumber, implode('', $cells));
    }

    private function xlsxColumnName(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $name = chr(65 + $remainder).$name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }

    private function xlsxContentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;
    }

    private function xlsxRootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
    }

    private function xlsxWorkbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Unmatched Rows" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>
XML;
    }

    private function xlsxWorkbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function xlsxStylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="2">
        <font>
            <sz val="11"/>
            <name val="Aptos"/>
        </font>
        <font>
            <b/>
            <sz val="11"/>
            <name val="Aptos"/>
        </font>
    </fonts>
    <fills count="1">
        <fill>
            <patternFill patternType="none"/>
        </fill>
    </fills>
    <borders count="1">
        <border>
            <left/>
            <right/>
            <top/>
            <bottom/>
            <diagonal/>
        </border>
    </borders>
    <cellStyleXfs count="1">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
    </cellStyleXfs>
    <cellXfs count="2">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
    </cellXfs>
    <cellStyles count="1">
        <cellStyle name="Normal" xfId="0" builtinId="0"/>
    </cellStyles>
</styleSheet>
XML;
    }

    private function exportDateTimeValue(?string $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return (string) \Illuminate\Support\Carbon::parse($value)->format('M j, Y g:i A');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function exportDateValue(?string $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return (string) \Illuminate\Support\Carbon::parse($value)->format('M j, Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
