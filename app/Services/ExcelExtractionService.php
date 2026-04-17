<?php

namespace App\Services;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelExtractionService
{
    /**
     * @return array{
     *     headers: list<string>,
     *     rows: list<array<string, string>>,
     * }
     */
    public function extract(string $filePath, int $sheetIndex = 0): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheet($sheetIndex);

        if (! $sheet instanceof Worksheet) {
            throw new InvalidArgumentException('Invalid worksheet index.');
        }

        $highestColumn = $sheet->getHighestDataColumn();
        $highestRow = $sheet->getHighestDataRow();
        $maxColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        $headers = [];
        for ($column = 1; $column <= $maxColumnIndex; $column++) {
            $rawHeader = (string) $sheet->getCell([$column, 1])->getFormattedValue();
            $header = trim($rawHeader) !== '' ? trim($rawHeader) : "Column{$column}";
            $headers[] = $header;
        }

        $rows = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $mappedRow = [];
            $hasValue = false;

            for ($column = 1; $column <= $maxColumnIndex; $column++) {
                $value = (string) $sheet->getCell([$column, $row])->getFormattedValue();
                if (trim($value) !== '') {
                    $hasValue = true;
                }

                $mappedRow[$headers[$column - 1]] = $value;
            }

            if ($hasValue) {
                $rows[] = $mappedRow;
            }
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

}
