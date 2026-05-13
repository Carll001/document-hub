<?php

declare(strict_types=1);

namespace App\Services\AfsFiling;

use App\Models\AfsFilingItem;
use App\Support\FormFieldAliasResolver;
use Illuminate\Support\Collection;
use ZipArchive;

class AfsFilingMissingDataExportService
{
    /**
     * @param Collection<int, AfsFilingItem> $items
     */
    public function buildXlsx(Collection $items): string
    {
        $temporaryOutputPath = storage_path('app/tmp/afs-filing-missing-data-'.\Illuminate\Support\Str::uuid().'.xlsx');

        if (! is_dir(dirname($temporaryOutputPath))) {
            mkdir(dirname($temporaryOutputPath), 0777, true);
        }

        $headers = [
            'Row Number',
            'Company',
            'TIN',
            'Status',
            'Missing Fields',
            'Error Message',
            'Source Excel Name',
            'Uploaded At',
            'Updated At',
        ];

        $rows = $this->buildRows($items);
        $this->writeSimpleXlsx($temporaryOutputPath, $headers, $rows);

        return $temporaryOutputPath;
    }

    /**
     * @param Collection<int, AfsFilingItem> $items
     * @return array<int, array<int, string>>
     */
    private function buildRows(Collection $items): array
    {
        return $items
            ->map(function (AfsFilingItem $item): array {
                $rowData = is_array($item->row_data) ? $item->row_data : [];
                $errorDetails = is_array($item->error_details) ? $item->error_details : [];
                $missingRaw = $errorDetails['missing_data'] ?? [];
                $missingFields = is_array($missingRaw)
                    ? array_values(array_filter($missingRaw, static fn (mixed $value): bool => is_string($value) && trim($value) !== ''))
                    : [];
                $company = FormFieldAliasResolver::resolveCompany($rowData, FormFieldAliasResolver::FORM_AFS);
                $tin = FormFieldAliasResolver::resolveTin($rowData, FormFieldAliasResolver::FORM_AFS);

                return [
                    (string) $item->row_number,
                    is_string($company) && trim($company) !== '' ? trim($company) : '-',
                    is_string($tin) ? trim($tin) : '',
                    (string) $item->status,
                    implode(', ', $missingFields),
                    is_string($item->error_message) ? $item->error_message : '',
                    is_string($item->source_excel_name) ? $item->source_excel_name : '',
                    $item->created_at?->toIso8601String() ?? '',
                    $item->updated_at?->toIso8601String() ?? '',
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
            throw new \RuntimeException('The missing-data Excel file could not be created.');
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
        <sheet name="AFS Missing Data" sheetId="1" r:id="rId1"/>
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

    private function escapeXml(string $value): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            $value,
        );
    }
}
