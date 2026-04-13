<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;
use ZipArchive;

class Form1702ExImportService
{
    /**
     * @var array<string, array{key: string, formatter: string|null}>|null
     */
    private ?array $fieldMetadataByNormalizedKey = null;

    public function __construct(
        private readonly Form1702ExService $form1702ExService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $basePayload
     * @return array{
     *     sourceName: string,
     *     sourceType: string,
     *     importedAt: string,
     *     headers: list<string>,
     *     rows: list<array{rowNumber: int, payload: array<string, mixed>}>
     * }
     */
    public function import(UploadedFile $file, array $basePayload): array
    {
        $extension = Str::lower($file->getClientOriginalExtension() ?: $file->extension() ?: '');

        $parsed = match ($extension) {
            'csv', 'txt' => $this->parseCsv($file->getRealPath() ?: $file->getPathname()),
            'xlsx' => $this->parseXlsx($file->getRealPath() ?: $file->getPathname()),
            default => throw new RuntimeException('Only CSV and XLSX files are supported for 1702-EX imports.'),
        };

        $rows = [];

        foreach ($parsed['rows'] as $row) {
            $payload = $this->normalizePayload($row['values'], $basePayload);

            $rows[] = [
                'rowNumber' => $row['rowNumber'],
                'payload' => $payload,
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('The uploaded file does not contain any usable 1702-EX rows.');
        }

        return [
            'sourceName' => $file->getClientOriginalName(),
            'sourceType' => $extension,
            'importedAt' => Carbon::now()->toIso8601String(),
            'headers' => $parsed['headers'],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     headers: list<string>,
     *     rows: list<array{rowNumber: int, values: array<string, string>}>
     * }
     */
    private function parseCsv(string $path): array
    {
        $delimiter = $this->detectCsvDelimiter($path);
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter);

        $headers = null;
        $rows = [];

        foreach ($file as $index => $record) {
            if (! is_array($record)) {
                continue;
            }

            if ($record === [null] || $this->rowIsEmpty($record)) {
                continue;
            }

            if ($headers === null) {
                $headers = $this->normalizeHeaders($record);

                continue;
            }

            $rows[] = [
                'rowNumber' => $index + 1,
                'values' => $this->combineRowWithHeaders($headers, $record),
            ];
        }

        if ($headers === null) {
            throw new RuntimeException('The CSV file is empty.');
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     headers: list<string>,
     *     rows: list<array{rowNumber: int, values: array<string, string>}>
     * }
     */
    private function parseXlsx(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The XLSX file could not be opened.');
        }

        try {
            $sharedStrings = $this->parseSharedStrings(
                $this->zipEntryContents($zip, 'xl/sharedStrings.xml'),
            );

            $worksheetPath = $this->firstWorksheetPath($zip);
            $worksheetXml = $this->zipEntryContents($zip, $worksheetPath);

            if ($worksheetXml === null) {
                throw new RuntimeException('The first worksheet in the XLSX file could not be read.');
            }

            $parsedRows = $this->parseWorksheetRows($worksheetXml, $sharedStrings);

            if ($parsedRows === []) {
                throw new RuntimeException('The XLSX file does not contain any worksheet rows.');
            }

            $headerRow = array_shift($parsedRows);
            $headers = $this->headersFromWorksheetRow($headerRow['values']);
            $rows = [];

            foreach ($parsedRows as $row) {
                $rows[] = [
                    'rowNumber' => $row['rowNumber'],
                    'values' => $this->combineWorksheetRowWithHeaders(
                        $headers,
                        $row['values'],
                    ),
                ];
            }

            return [
                'headers' => $headers,
                'rows' => $rows,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $basePayload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $row, array $basePayload): array
    {
        $normalizedRow = [];

        foreach ($row as $header => $value) {
            $normalizedRow[$this->normalizeHeader($header)] = $this->cleanCellText($value);
        }

        $payload = $basePayload;

        $registeredName = $this->firstFilled($normalizedRow, [
            'registeredname',
            'taxpayername',
            'name',
        ]);
        $tin = $this->normalizeTin(
            $this->firstFilled($normalizedRow, [
                'tin',
                'taxpayeridentificationnumbertin',
            ]),
            [
                $this->firstFilled($normalizedRow, ['tin1', 'tin_1', 'tinpart1']),
                $this->firstFilled($normalizedRow, ['tin2', 'tin_2', 'tinpart2']),
                $this->firstFilled($normalizedRow, ['tin3', 'tin_3', 'tinpart3']),
                $this->firstFilled($normalizedRow, ['tin4', 'tin_4', 'tinpart4']),
            ],
        );
        $filingPeriod = $this->normalizeChoice(
            $this->firstFilled($normalizedRow, ['filingperiod', 'filingisperiod']),
            'calendar',
            ['calendar', 'fiscal'],
        );
        $isAmendedReturn = $this->normalizeBooleanChoice(
            $this->firstFilled($normalizedRow, ['amendedreturn', 'isamendedreturn']),
            false,
        );
        $isShortPeriodReturn = $this->normalizeBooleanChoice(
            $this->firstFilled($normalizedRow, ['shortperiodreturn', 'isshortperiodreturn']),
            false,
        );
        $deductionMethod = $this->normalizeChoice(
            $this->firstFilled($normalizedRow, ['deductionmethod', 'methodofdeductions']),
            'itemized',
            ['itemized', 'osd'],
        );
        $yearMonth = $this->normalizeMonthValue(
            $this->firstFilled($normalizedRow, ['yearmonth', 'month', 'mm']),
            (string) ($payload['year_month'] ?? ''),
        );
        $fourDigitYear = $this->normalizeFourDigitYear(
            $this->firstFilled($normalizedRow, [
                'returnperiodyear',
                'calendaryearended',
                'yearyear',
                'year',
                'yyyy',
            ]),
            $this->firstFilled($normalizedRow, ['yearyear', 'year', 'yyyy']),
            (string) ($payload['return_period_year'] ?? ''),
        );
        $twoDigitYear = substr($fourDigitYear, -2);
        $calendarYearEnded = $this->normalizeCalendarYearEnded(
            $this->firstFilled($normalizedRow, ['calendaryearended']),
            $yearMonth,
            $fourDigitYear,
            (string) ($payload['calendar_year_ended'] ?? ''),
        );

        $payload['registered_name'] = $registeredName ?: (string) ($payload['registered_name'] ?? '');
        $payload['taxpayer_name'] = $registeredName ?: (string) ($payload['taxpayer_name'] ?? '');
        $payload['tin'] = $tin !== '' ? $tin : (string) ($payload['tin'] ?? '');

        [$payload['tin_1'], $payload['tin_2'], $payload['tin_3'], $payload['tin_4']] = $this->tinGroups(
            (string) $payload['tin'],
            (string) ($payload['tin_1'] ?? ''),
            (string) ($payload['tin_2'] ?? ''),
            (string) ($payload['tin_3'] ?? ''),
            (string) ($payload['tin_4'] ?? ''),
        );

        $payload['rdo_code'] = $this->upperValue(
            $this->firstFilled($normalizedRow, ['rdocode', 'rdo']),
            (string) ($payload['rdo_code'] ?? ''),
        );

        $payload['filing_period'] = $filingPeriod;
        $payload['filing_is_calendar'] = $filingPeriod === 'calendar';
        $payload['filing_is_fiscal'] = $filingPeriod === 'fiscal';

        $payload['amended_return'] = $isAmendedReturn ? 'yes' : 'no';
        $payload['amended_return_yes'] = $isAmendedReturn;
        $payload['amended_return_no'] = ! $isAmendedReturn;
        $payload['is_amended_return'] = $isAmendedReturn;

        $payload['short_period_return'] = $isShortPeriodReturn ? 'yes' : 'no';
        $payload['short_period_return_yes'] = $isShortPeriodReturn;
        $payload['short_period_return_no'] = ! $isShortPeriodReturn;
        $payload['is_short_period_return'] = $isShortPeriodReturn;

        $payload['atc'] = $this->upperValue(
            $this->firstFilled($normalizedRow, ['atc', 'alphanumerictaxcodeatc']),
            (string) ($payload['atc'] ?? ''),
        );
        $payload['year_month'] = $yearMonth;
        $payload['year_year'] = $twoDigitYear;
        $payload['return_period_year'] = $fourDigitYear;
        $payload['calendar_year_ended'] = $calendarYearEnded;

        $payload['registered_address'] = $this->firstFilled($normalizedRow, [
            'registeredaddress',
            'address',
        ]) ?: (string) ($payload['registered_address'] ?? '');
        $payload['zip_code'] = $this->firstFilled($normalizedRow, ['zipcode', 'zip']) ?: (string) ($payload['zip_code'] ?? '');
        $payload['incorporation_date'] = $this->normalizeDisplayDate(
            $this->firstFilled($normalizedRow, [
                'incorporationdate',
                'dateofincorporationorganization',
            ]),
            (string) ($payload['incorporation_date'] ?? ''),
        );
        $payload['contact_number'] = $this->normalizePhoneNumber(
            $this->firstFilled($normalizedRow, ['contactnumber', 'contactno', 'contactnumbermobileno', 'mobilenumber']),
            (string) ($payload['contact_number'] ?? ''),
        );
        $payload['client_name'] = $this->firstFilled($normalizedRow, [
            'clientname',
            'client_name',
        ]) ?: (string) ($payload['client_name'] ?? '');
        $payload['email_address'] = $this->firstFilled($normalizedRow, [
            'recipient',
            'recipientemail',
            'emailaddress',
            'email',
        ]) ?: (string) ($payload['email_address'] ?? '');

        $payload['deduction_method'] = $deductionMethod;
        $payload['deduction_method_itemized'] = $deductionMethod === 'itemized';
        $payload['deduction_method_osd'] = $deductionMethod === 'osd';

        $payload['legal_basis'] = $this->firstFilled($normalizedRow, [
            'legalbasis',
            'legalbasisoftaxreliefexemptionspecify',
        ]) ?: (string) ($payload['legal_basis'] ?? '');
        $payload['investment_agency'] = $this->firstFilled($normalizedRow, [
            'investmentagency',
            'investmentpromotionagencyipagovernmentagencyspecify',
        ]) ?: (string) ($payload['investment_agency'] ?? '');
        $payload['registered_activity'] = $this->firstFilled($normalizedRow, [
            'registeredactivity',
            'registeredactivityprogramregistrationnumber',
            'registrationnumber',
        ]) ?: (string) ($payload['registered_activity'] ?? '');
        $payload['effectivity_from'] = $this->normalizeDisplayDate(
            $this->firstFilled($normalizedRow, ['effectivityfrom', 'from']),
            (string) ($payload['effectivity_from'] ?? ''),
        );
        $payload['effectivity_to'] = $this->normalizeDisplayDate(
            $this->firstFilled($normalizedRow, ['effectivityto', 'to']),
            (string) ($payload['effectivity_to'] ?? ''),
        );

        $payload['line_of_business'] = $this->firstFilled($normalizedRow, [
            'lineofbusiness',
        ]) ?: (string) ($payload['line_of_business'] ?? '');
        $payload['exempt_under_section'] = $this->firstFilled($normalizedRow, [
            'exemptundersection',
        ]) ?: (string) ($payload['exempt_under_section'] ?? '');
        $payload['total_assets'] = $this->normalizeMoney(
            $this->firstFilled($normalizedRow, ['totalassets']),
            (string) ($payload['total_assets'] ?? ''),
        );
        $payload['authorized_representative'] = $this->firstFilled($normalizedRow, [
            'authorizedrepresentative',
        ]) ?: (string) ($payload['authorized_representative'] ?? '');
        $payload['representative_tin'] = $this->normalizeDigits(
            $this->firstFilled($normalizedRow, ['representativetin']),
            (string) ($payload['representative_tin'] ?? ''),
        );
        $payload['signatory_title'] = $this->firstFilled($normalizedRow, [
            'signatorytitle',
            'titleofsignatory',
        ]) ?: (string) ($payload['signatory_title'] ?? '');
        $payload['date_signed'] = $this->normalizeIsoDate(
            $this->firstFilled($normalizedRow, ['datesigned']),
            (string) ($payload['date_signed'] ?? ''),
        );

        $payload['tax_due'] = $this->normalizeMoney(
            $this->firstFilled($normalizedRow, ['taxdue']),
            (string) ($payload['tax_due'] ?? ''),
        );
        $payload['tax_credits'] = $this->normalizeMoney(
            $this->firstFilled($normalizedRow, ['taxcredits', 'lesstotaltaxcreditspayments']),
            (string) ($payload['tax_credits'] ?? ''),
        );
        $payload['overpayment'] = $this->normalizeMoney(
            $this->firstFilled($normalizedRow, ['overpayment', 'totaloverpayment']),
            (string) ($payload['overpayment'] ?? ''),
        );
        $payload['penalty_compromise'] = $this->normalizeMoney(
            $this->firstFilled($normalizedRow, ['penaltycompromise']),
            (string) ($payload['penalty_compromise'] ?? ''),
        );
        $payload['total_amount_payable'] = $this->normalizeMoney(
            $this->firstFilled($normalizedRow, ['totalamountpayable', 'totalamountpayableoverpayment']),
            (string) ($payload['total_amount_payable'] ?? ''),
        );
        $payload['number_of_attachments'] = $this->normalizeAttachmentCount(
            $this->firstFilled($normalizedRow, ['numberofattachments']),
            (string) ($payload['number_of_attachments'] ?? ''),
        );

        return $this->mergeGenericSchemaFields($payload, $normalizedRow, [
            'registered_name',
            'taxpayer_name',
            'tin',
            'tin_1',
            'tin_2',
            'tin_3',
            'tin_4',
            'rdo_code',
            'filing_period',
            'filing_is_calendar',
            'filing_is_fiscal',
            'amended_return',
            'amended_return_yes',
            'amended_return_no',
            'is_amended_return',
            'short_period_return',
            'short_period_return_yes',
            'short_period_return_no',
            'is_short_period_return',
            'atc',
            'year_month',
            'year_year',
            'return_period_year',
            'calendar_year_ended',
            'registered_address',
            'zip_code',
            'incorporation_date',
            'contact_number',
            'client_name',
            'email_address',
            'deduction_method',
            'deduction_method_itemized',
            'deduction_method_osd',
            'legal_basis',
            'investment_agency',
            'registered_activity',
            'effectivity_from',
            'effectivity_to',
            'line_of_business',
            'exempt_under_section',
            'total_assets',
            'authorized_representative',
            'representative_tin',
            'signatory_title',
            'date_signed',
            'tax_due',
            'tax_credits',
            'overpayment',
            'penalty_compromise',
            'total_amount_payable',
            'number_of_attachments',
            'footer_source_path',
            'footer_printed_date',
        ]);
    }

    /**
     * @param  list<string|null>  $record
     * @return list<string>
     */
    private function normalizeHeaders(array $record): array
    {
        $headers = [];

        foreach (array_values($record) as $index => $header) {
            $trimmed = $this->cleanCellText((string) ($header ?? ''));
            $headers[] = $trimmed !== '' ? $trimmed : 'Column '.($index + 1);
        }

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int, string|null>  $record
     * @return array<string, string>
     */
    private function combineRowWithHeaders(array $headers, array $record): array
    {
        $values = [];

        foreach ($headers as $index => $header) {
            $values[$header] = $this->cleanCellText((string) ($record[$index] ?? ''));
        }

        return $values;
    }

    /**
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private function headersFromWorksheetRow(array $values): array
    {
        $highestIndex = $values === [] ? 0 : max(array_keys($values));
        $headers = [];

        for ($index = 1; $index <= $highestIndex; $index++) {
            $header = $this->cleanCellText($values[$index] ?? '');
            $headers[] = $header !== '' ? $header : 'Column '.$index;
        }

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int, string>  $values
     * @return array<string, string>
     */
    private function combineWorksheetRowWithHeaders(array $headers, array $values): array
    {
        $record = [];

        foreach ($headers as $index => $header) {
            $record[$header] = $this->cleanCellText($values[$index + 1] ?? '');
        }

        return $record;
    }

    /**
     * @param  string|null  $contents
     * @return list<string>
     */
    private function parseSharedStrings(?string $contents): array
    {
        if (! is_string($contents) || $contents === '') {
            return [];
        }

        preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $contents, $matches);

        return array_map(function (string $item): string {
            preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $item, $textMatches);

            return $this->decodeXmlText(implode('', $textMatches[1] ?? []));
        }, $matches[1] ?? []);
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<array{rowNumber: int, values: array<int, string>}>
     */
    private function parseWorksheetRows(string $worksheetXml, array $sharedStrings): array
    {
        preg_match_all('/<row\b([^>]*)>(.*?)<\/row>/s', $worksheetXml, $rowMatches, PREG_SET_ORDER);

        $rows = [];
        $fallbackRowNumber = 1;

        foreach ($rowMatches as $rowMatch) {
            $attributes = $rowMatch[1] ?? '';
            $rowNumber = $this->matchAttribute($attributes, 'r');
            $rowXml = $rowMatch[2] ?? '';
            $values = [];

            preg_match_all('/<c\b([^>]*)>(.*?)<\/c>|<c\b([^>]*)\/>/s', $rowXml, $cellMatches, PREG_SET_ORDER);

            foreach ($cellMatches as $cellMatch) {
                $cellAttributes = $cellMatch[1] !== '' ? $cellMatch[1] : ($cellMatch[3] ?? '');
                $cellXml = $cellMatch[2] ?? '';
                $reference = $this->matchAttribute($cellAttributes, 'r') ?? '';
                $type = $this->matchAttribute($cellAttributes, 't');
                $columnIndex = $this->columnIndexFromReference($reference);

                if ($columnIndex < 1) {
                    continue;
                }

                $values[$columnIndex] = $this->worksheetCellValue($type, $cellXml, $sharedStrings);
            }

            if ($values === []) {
                $fallbackRowNumber++;

                continue;
            }

            ksort($values);

            $rows[] = [
                'rowNumber' => (int) ($rowNumber ?? $fallbackRowNumber),
                'values' => $values,
            ];

            $fallbackRowNumber++;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $sharedStrings
     */
    private function worksheetCellValue(?string $type, string $cellXml, array $sharedStrings): string
    {
        if ($type === 'inlineStr') {
            preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $cellXml, $matches);

            return $this->decodeXmlText(implode('', $matches[1] ?? []));
        }

        if (preg_match('/<v>(.*?)<\/v>/s', $cellXml, $valueMatch) !== 1) {
            if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $cellXml, $textMatches) > 0) {
                return $this->decodeXmlText(implode('', $textMatches[1] ?? []));
            }

            return '';
        }

        $value = $this->decodeXmlText($valueMatch[1] ?? '');

        if ($type === 's') {
            $sharedIndex = (int) $value;

            return $sharedStrings[$sharedIndex] ?? '';
        }

        if ($type === 'b') {
            return $value === '1' ? 'TRUE' : 'FALSE';
        }

        return $value;
    }

    private function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbook = $this->zipEntryContents($zip, 'xl/workbook.xml');
        $relationships = $this->zipEntryContents($zip, 'xl/_rels/workbook.xml.rels');

        if (is_string($workbook) && is_string($relationships)) {
            if (preg_match('/<sheet\b[^>]*r:id="([^"]+)"/', $workbook, $sheetMatch) === 1) {
                $relationshipId = $sheetMatch[1];
                $pattern = '/<Relationship\b[^>]*Id="'.preg_quote($relationshipId, '/').'"[^>]*Target="([^"]+)"/';

                if (preg_match($pattern, $relationships, $relationshipMatch) === 1) {
                    $target = str_replace('\\', '/', $relationshipMatch[1]);

                    return str_starts_with($target, 'xl/')
                        ? $target
                        : 'xl/'.ltrim($target, '/');
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function zipEntryContents(ZipArchive $zip, string $entryName): ?string
    {
        $contents = $zip->getFromName($entryName);

        return is_string($contents) ? $contents : null;
    }

    /**
     * @param  list<string|null>  $record
     */
    private function rowIsEmpty(array $record): bool
    {
        foreach ($record as $value) {
            if ($this->cleanCellText((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function detectCsvDelimiter(string $path): string
    {
        $sample = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $sampleLines = array_slice(is_array($sample) ? $sample : [], 0, 5);
        $scores = [
            ',' => 0,
            ';' => 0,
            "\t" => 0,
        ];

        foreach ($sampleLines as $line) {
            foreach ($scores as $delimiter => $score) {
                $scores[$delimiter] += substr_count((string) $line, $delimiter);
            }
        }

        arsort($scores);

        return (string) array_key_first($scores);
    }

    private function normalizeHeader(string $header): string
    {
        return (string) Str::of($header)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->trim();
    }

    private function cleanCellText(string $value): string
    {
        $normalized = str_replace(["\u{00A0}", "\r", "\n", "\t"], ' ', trim($value));

        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $keys
     */
    private function firstFilled(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->cleanCellText($row[$key] ?? '');

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeTin(string $value, array $parts): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits !== '') {
            return $digits;
        }

        $combined = '';

        foreach ($parts as $part) {
            $combined .= preg_replace('/\D+/', '', (string) $part) ?? '';
        }

        return $combined;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function tinGroups(
        string $tin,
        string $fallbackOne,
        string $fallbackTwo,
        string $fallbackThree,
        string $fallbackFour,
    ): array {
        $digits = preg_replace('/\D+/', '', $tin) ?? '';

        if ($digits !== '') {
            return [
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 3),
                substr($digits, 9),
            ];
        }

        return [$fallbackOne, $fallbackTwo, $fallbackThree, $fallbackFour];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeChoice(string $value, string $fallback, array $allowed): string
    {
        $normalized = Str::lower($this->cleanCellText($value));

        return in_array($normalized, $allowed, true)
            ? $normalized
            : $fallback;
    }

    private function normalizeBooleanChoice(string $value, bool $fallback): bool
    {
        $normalized = Str::lower($this->cleanCellText($value));

        if ($normalized === '') {
            return $fallback;
        }

        if (in_array($normalized, ['yes', 'true', '1', 'y'], true)) {
            return true;
        }

        if (in_array($normalized, ['no', 'false', '0', 'n'], true)) {
            return false;
        }

        return $fallback;
    }

    private function normalizeMonthValue(string $value, string $fallback): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return str_pad(preg_replace('/\D+/', '', $fallback) ?? '', 2, '0', STR_PAD_LEFT);
        }

        return str_pad(substr($digits, -2), 2, '0', STR_PAD_LEFT);
    }

    private function normalizeFourDigitYear(string $yearValue, string $twoDigitYearValue, string $fallback): string
    {
        $trimmedYearValue = $this->cleanCellText($yearValue);

        if ($trimmedYearValue !== '') {
            if (is_numeric($trimmedYearValue) && strlen($trimmedYearValue) > 4) {
                $excelDate = $this->excelSerialDateToCarbon((float) $trimmedYearValue);

                if ($excelDate instanceof Carbon) {
                    return $excelDate->format('Y');
                }
            }

            try {
                return Carbon::parse($trimmedYearValue)->format('Y');
            } catch (\Throwable) {
            }
        }

        $yearDigits = preg_replace('/\D+/', '', $trimmedYearValue) ?? '';

        if (strlen($yearDigits) >= 4) {
            return substr($yearDigits, 0, 4);
        }

        $shortYearDigits = preg_replace('/\D+/', '', $twoDigitYearValue) ?? '';

        if (strlen($shortYearDigits) >= 2) {
            return '20'.substr($shortYearDigits, -2);
        }

        $fallbackDigits = preg_replace('/\D+/', '', $fallback) ?? '';

        return strlen($fallbackDigits) >= 4
            ? substr($fallbackDigits, -4)
            : (date('Y'));
    }

    private function normalizeCalendarYearEnded(
        string $value,
        string $month,
        string $year,
        string $fallback,
    ): string {
        $direct = $this->normalizeIsoDate($value, '');

        if ($direct !== '') {
            return $direct;
        }

        if ($month !== '' && $year !== '') {
            $date = Carbon::createFromDate((int) $year, max(1, (int) $month), 1)->endOfMonth();

            return $date->format('Y-m-d');
        }

        return $fallback;
    }

    private function normalizeDisplayDate(string $value, string $fallback): string
    {
        $trimmed = $this->cleanCellText($value);

        if ($trimmed === '') {
            return $fallback;
        }

        if (is_numeric($trimmed)) {
            return $this->excelSerialDateToCarbon((float) $trimmed)?->format('m/d/Y') ?? $fallback;
        }

        foreach (['m/d/Y', 'n/j/Y', 'm-d-Y', 'n-j-Y', 'Y-m-d', 'm/d/y', 'n/j/y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $trimmed)->format('m/d/Y');
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($trimmed)->format('m/d/Y');
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function normalizeIsoDate(string $value, string $fallback): string
    {
        $trimmed = $this->cleanCellText($value);

        if ($trimmed === '') {
            return $fallback;
        }

        if (is_numeric($trimmed)) {
            return $this->excelSerialDateToCarbon((float) $trimmed)?->format('Y-m-d') ?? $fallback;
        }

        foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'm-d-Y', 'n-j-Y', 'm/d/y', 'n/j/y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $trimmed)->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($trimmed)->format('Y-m-d');
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function excelSerialDateToCarbon(float $serial): ?Carbon
    {
        if ($serial <= 0) {
            return null;
        }

        try {
            return Carbon::create(1899, 12, 30)->addDays((int) floor($serial));
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizePhoneNumber(string $value, string $fallback): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits !== '' ? $digits : $fallback;
    }

    private function normalizeMoney(string $value, string $fallback): string
    {
        $trimmed = $this->cleanCellText($value);

        if ($trimmed === '') {
            return $fallback;
        }

        if (is_numeric(str_replace(',', '', $trimmed))) {
            return number_format((float) str_replace(',', '', $trimmed), 2, '.', '');
        }

        return $trimmed;
    }

    private function normalizeDigits(string $value, string $fallback): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits !== '' ? $digits : $fallback;
    }

    private function normalizeAttachmentCount(string $value, string $fallback): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return $fallback;
        }

        return str_pad(substr($digits, -2), 2, '0', STR_PAD_LEFT);
    }

    private function upperValue(string $value, string $fallback): string
    {
        $trimmed = $this->cleanCellText($value);

        return $trimmed !== '' ? Str::upper($trimmed) : $fallback;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $normalizedRow
     * @param  list<string>  $handledKeys
     * @return array<string, mixed>
     */
    private function mergeGenericSchemaFields(
        array $payload,
        array $normalizedRow,
        array $handledKeys,
    ): array {
        $handledNormalizedKeys = [];

        foreach ($handledKeys as $handledKey) {
            $handledNormalizedKeys[$this->normalizeHeader($handledKey)] = true;
        }

        foreach ($this->fieldMetadataByNormalizedKey() as $normalizedKey => $metadata) {
            if (isset($handledNormalizedKeys[$normalizedKey])) {
                continue;
            }

            if (! array_key_exists($normalizedKey, $normalizedRow)) {
                continue;
            }

            $value = $normalizedRow[$normalizedKey];

            if ($value === '') {
                continue;
            }

            $key = $metadata['key'];

            $payload[$key] = $this->normalizeFieldByFormatter(
                $value,
                $metadata['formatter'],
                is_scalar($payload[$key] ?? null) || ($payload[$key] ?? null) === null
                    ? (string) ($payload[$key] ?? '')
                    : '',
            );
        }

        return $payload;
    }

    private function normalizeFieldByFormatter(
        string $value,
        ?string $formatter,
        string $fallback,
    ): string {
        return match ($formatter) {
            'uppercase' => $this->upperValue($value, $fallback),
            'currency' => $this->normalizeMoney($value, $fallback),
            'digits_only', 'tin_digits' => $this->normalizeDigits($value, $fallback),
            default => $this->cleanCellText($value) !== ''
                ? $this->cleanCellText($value)
                : $fallback,
        };
    }

    /**
     * @return array<string, array{key: string, formatter: string|null}>
     */
    private function fieldMetadataByNormalizedKey(): array
    {
        if (is_array($this->fieldMetadataByNormalizedKey)) {
            return $this->fieldMetadataByNormalizedKey;
        }

        $metadata = [];

        foreach ([
            $this->form1702ExService->fieldSchema(),
            $this->form1702ExService->page2FieldSchema(),
            $this->form1702ExService->page3FieldSchema(),
        ] as $schema) {
            foreach ($schema['fields'] as $field) {
                $key = (string) ($field['key'] ?? '');

                if ($key === '') {
                    continue;
                }

                $normalizedKey = $this->normalizeHeader($key);

                $metadata[$normalizedKey] = [
                    'key' => $key,
                    'formatter' => isset($field['formatter']) && is_string($field['formatter'])
                        ? $field['formatter']
                        : null,
                ];
            }
        }

        $this->fieldMetadataByNormalizedKey = $metadata;

        return $metadata;
    }

    private function matchAttribute(string $attributes, string $attribute): ?string
    {
        if (preg_match('/\b'.preg_quote($attribute, '/').'="([^"]*)"/', $attributes, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function columnIndexFromReference(string $reference): int
    {
        if (preg_match('/^([A-Z]+)/', $reference, $matches) !== 1) {
            return 0;
        }

        $column = $matches[1];
        $index = 0;

        foreach (str_split($column) as $character) {
            $index = ($index * 26) + (ord($character) - 64);
        }

        return $index;
    }

    private function decodeXmlText(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $decoded = str_replace('_x000D_', ' ', $decoded);

        return $this->cleanCellText($decoded);
    }
}
