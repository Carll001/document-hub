<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use ZipArchive;

class DocxTemplateService
{
    /**
     * @return list<string>
     */
    public function placeholderKeys(string $templatePath): array
    {
        return $this->withPreparedTemplate($templatePath, function (string $preparedTemplatePath): array {
            $processor = new TemplateProcessor($preparedTemplatePath);
            $this->configureMacroChars($processor);

            return $this->extractPlaceholderKeys($processor);
        });
    }

    /**
     * @param  array<string, string>  $rowData
     * @return array{missing_data: list<string>, errors: list<string>}
     */
    public function validateRowData(string $templatePath, array $rowData, ?int $selectedTemplateYear = null): array
    {
        return $this->withPreparedTemplate(
            $templatePath,
            function (string $preparedTemplatePath) use ($rowData, $selectedTemplateYear): array {
                $normalizedMap = $this->normalizeRowData($rowData);

                $missingData = [];
                $errors = [];

                $processor = new TemplateProcessor($preparedTemplatePath);
                $this->configureMacroChars($processor);

                foreach ($this->extractPlaceholderKeys($processor) as $key) {
                    $resolution = $this->resolvePlaceholderValue($key, $normalizedMap, $selectedTemplateYear);

                    if ($resolution['status'] === 'missing_data') {
                        $missingData[] = $key;

                        continue;
                    }

                    if ($resolution['status'] === 'error') {
                        $errors[] = $resolution['message'];
                    }
                }

                return [
                    'missing_data' => array_values(array_unique($missingData)),
                    'errors' => array_values(array_unique($errors)),
                ];
            }
        );
    }

    /**
     * @param  array<string, string>  $rowData
     */
    public function render(string $templatePath, string $outputPath, array $rowData, ?int $selectedTemplateYear = null): void
    {
        $this->withPreparedTemplate(
            $templatePath,
            function (string $preparedTemplatePath) use ($outputPath, $rowData, $selectedTemplateYear): void {
                $processor = new TemplateProcessor($preparedTemplatePath);
                $this->configureMacroChars($processor);

                $normalizedMap = $this->normalizeRowData($rowData);

                $keysInTemplate = $this->extractPlaceholderKeys($processor);
                foreach ($keysInTemplate as $key) {
                    $resolution = $this->resolvePlaceholderValue($key, $normalizedMap, $selectedTemplateYear);
                    $value = $resolution['status'] === 'resolved'
                        ? $this->formatValueForTemplate($resolution['value'])
                        : '';

                    $processor->setValue($key, $value);
                }

                $processor->saveAs($outputPath);
            }
        );
    }

    /**
     * @template T
     * @param  callable(string): T  $callback
     * @return T
     */
    private function withPreparedTemplate(string $templatePath, callable $callback): mixed
    {
        $preparedTemplatePath = $this->createPreparedTemplateCopy($templatePath);

        try {
            return $callback($preparedTemplatePath);
        } finally {
            if (is_file($preparedTemplatePath)) {
                @unlink($preparedTemplatePath);
            }
        }
    }

    private function createPreparedTemplateCopy(string $templatePath): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'docx-template-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to allocate a temporary DOCX template path.');
        }

        $preparedTemplatePath = $temporaryPath.'.docx';
        @unlink($temporaryPath);

        if (! copy($templatePath, $preparedTemplatePath)) {
            throw new RuntimeException('Unable to create a working copy of the DOCX template.');
        }

        $this->normalizeTemplatePlaceholders($preparedTemplatePath);

        return $preparedTemplatePath;
    }

    private function normalizeTemplatePlaceholders(string $templatePath): void
    {
        $archive = new ZipArchive;
        if ($archive->open($templatePath) !== true) {
            throw new RuntimeException('Unable to open the DOCX template archive.');
        }

        try {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entryName = $archive->getNameIndex($index);
                if (! is_string($entryName) || ! preg_match('/^word\/(?:document|header\d+|footer\d+|footnotes|endnotes)\.xml$/', $entryName)) {
                    continue;
                }

                $xml = $archive->getFromName($entryName);
                if (! is_string($xml) || $xml === '') {
                    continue;
                }

                $normalizedXml = $this->normalizePlaceholderMarkup($xml);
                if ($normalizedXml === $xml) {
                    continue;
                }

                $archive->deleteName($entryName);
                $archive->addFromString($entryName, $normalizedXml);
            }
        } finally {
            $archive->close();
        }
    }

    private function normalizePlaceholderMarkup(string $xml): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;

        if (@$document->loadXML($xml) === false) {
            return $xml;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $textNodes = [];
        foreach ($xpath->query('//w:t') ?: [] as $textNode) {
            $textNodes[] = $textNode;
        }

        $changed = false;
        $isCapturingPlaceholder = false;
        $startNode = null;
        $capturedNodes = [];
        $capturedText = '';
        $leadingWhitespace = '';

        foreach ($textNodes as $textNode) {
            $text = $textNode->textContent;

            if (! $isCapturingPlaceholder) {
                $openPosition = mb_strpos($text, '{');
                if ($openPosition === false) {
                    continue;
                }

                $closePosition = mb_strpos($text, '}', $openPosition);
                if ($closePosition !== false) {
                    continue;
                }

                $leadingWhitespace = mb_substr($text, 0, $openPosition);
                if ($this->normalizeSpace($leadingWhitespace) !== '') {
                    continue;
                }

                $isCapturingPlaceholder = true;
                $startNode = $textNode;
                $capturedNodes = [$textNode];
                $capturedText = mb_substr($text, $openPosition);

                continue;
            }

            $capturedNodes[] = $textNode;
            $capturedText .= $text;

            $closePosition = mb_strpos($capturedText, '}');
            if ($closePosition === false) {
                continue;
            }

            $placeholderText = mb_substr($capturedText, 0, $closePosition + 1);
            $trailingWhitespace = mb_substr($capturedText, $closePosition + 1);

            if (
                $startNode !== null
                && $this->normalizeSpace($trailingWhitespace) === ''
                && $this->isPlaceholderCandidate($placeholderText)
            ) {
                $startNode->nodeValue = $leadingWhitespace.$placeholderText.$trailingWhitespace;

                foreach (array_slice($capturedNodes, 1) as $capturedNode) {
                    $capturedNode->nodeValue = '';
                }

                $changed = true;
            }

            $isCapturingPlaceholder = false;
            $startNode = null;
            $capturedNodes = [];
            $capturedText = '';
            $leadingWhitespace = '';
        }

        if (! $changed) {
            return $xml;
        }

        return $document->saveXML() ?: $xml;
    }

    private function isPlaceholderCandidate(string $text): bool
    {
        if (! str_starts_with($text, '{') || ! str_ends_with($text, '}')) {
            return false;
        }

        $innerText = trim(mb_substr($text, 1, -1));
        if ($innerText === '' || str_contains($innerText, '{') || str_contains($innerText, '}')) {
            return false;
        }

        return preg_match('/^[\pL\pN\s_\-\/&().,\'+:%]+$/u', $innerText) === 1;
    }

    private function normalizeSpace(string $text): string
    {
        $normalized = str_replace("\u{00A0}", ' ', $text);

        return trim($normalized);
    }

    private function configureMacroChars(TemplateProcessor $processor): void
    {
        if (method_exists($processor, 'setMacroChars')) {
            $processor->setMacroChars('{', '}');
        } else {
            $processor->setMacroOpeningChars('{');
            $processor->setMacroClosingChars('}');
        }
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholderKeys(TemplateProcessor $processor): array
    {
        $values = $processor->getVariables();

        return array_values(array_unique(array_map('trim', $values)));
    }

    /**
     * @param  array<string, string>  $rowData
     * @return array<string, string>
     */
    private function normalizeRowData(array $rowData): array
    {
        $normalizedMap = [];
        foreach ($rowData as $key => $value) {
            $normalizedMap[$this->normalizeHeader($key)] = $value;
        }

        return $normalizedMap;
    }

    /**
     * @param  array<string, string>  $normalizedMap
     * @return array{status: 'resolved', value: string}|array{status: 'missing_data'}|array{status: 'unmatched'}|array{status: 'error', message: string}
     */
    private function resolvePlaceholderValue(string $placeholder, array $normalizedMap, ?int $selectedTemplateYear): array
    {
        if ($selectedTemplateYear === 2025) {
            $subtractionFormula = $this->parseSubtractionPlaceholder($placeholder);
            if ($subtractionFormula['status'] === 'invalid') {
                return [
                    'status' => 'error',
                    'message' => sprintf(
                        'Invalid subtraction placeholder %s. Expected exactly two headers with a single - operator.',
                        $this->placeholderLabel($placeholder)
                    ),
                ];
            }

            if ($subtractionFormula['status'] === 'parsed') {
                return $this->resolveArithmeticPlaceholder(
                    $placeholder,
                    $subtractionFormula['left_operand'],
                    $subtractionFormula['right_operand'],
                    '-',
                    $normalizedMap
                );
            }

            $autoSumPlaceholder = $this->parse2025AutoSumPlaceholder($placeholder, $normalizedMap);
            if ($autoSumPlaceholder['status'] === 'parsed') {
                return $this->resolveArithmeticPlaceholder(
                    $placeholder,
                    $autoSumPlaceholder['current_year_operand'],
                    $autoSumPlaceholder['previous_year_operand'],
                    '+',
                    $normalizedMap
                );
            }
        }

        return $this->resolveDirectPlaceholderValue($placeholder, $normalizedMap);
    }

    /**
     * @param  array<string, string>  $normalizedMap
     * @return array{status: 'resolved', value: float}|array{status: 'error', message: string}
     */
    private function resolveNumericOperandValue(string $placeholder, string $operand, array $normalizedMap): array
    {
        $normalizedOperand = $this->normalizeHeader($operand);
        if (! array_key_exists($normalizedOperand, $normalizedMap)) {
            return [
                'status' => 'error',
                'message' => sprintf(
                    'Placeholder %s requires the "%s" header.',
                    $this->placeholderLabel($placeholder),
                    $operand
                ),
            ];
        }

        $value = trim($normalizedMap[$normalizedOperand]);
        $numericValue = $this->parseNumericValue($value);
        if ($numericValue === null) {
            return [
                'status' => 'error',
                'message' => sprintf(
                    'Placeholder %s requires a numeric value for "%s".',
                    $this->placeholderLabel($placeholder),
                    $operand
                ),
            ];
        }

        return [
            'status' => 'resolved',
            'value' => $numericValue,
        ];
    }

    /**
     * @param  array<string, string>  $normalizedMap
     * @return array{status: 'resolved', value: string}|array{status: 'error', message: string}
     */
    private function resolveArithmeticPlaceholder(
        string $placeholder,
        string $leftOperand,
        string $rightOperand,
        string $operator,
        array $normalizedMap
    ): array {
        $leftValue = $this->resolveNumericOperandValue($placeholder, $leftOperand, $normalizedMap);
        if ($leftValue['status'] === 'error') {
            return $leftValue;
        }

        $rightValue = $this->resolveNumericOperandValue($placeholder, $rightOperand, $normalizedMap);
        if ($rightValue['status'] === 'error') {
            return $rightValue;
        }

        $result = $operator === '+'
            ? $leftValue['value'] + $rightValue['value']
            : $leftValue['value'] - $rightValue['value'];

        return [
            'status' => 'resolved',
            'value' => (string) $result,
        ];
    }

    /**
     * @param  array<string, string>  $normalizedMap
     * @return array{status: 'resolved', value: string}|array{status: 'missing_data'}|array{status: 'unmatched'}
     */
    private function resolveDirectPlaceholderValue(string $placeholder, array $normalizedMap): array
    {
        $normalizedPlaceholder = $this->normalizeHeader($placeholder);
        if (! array_key_exists($normalizedPlaceholder, $normalizedMap)) {
            return ['status' => 'unmatched'];
        }

        $value = $normalizedMap[$normalizedPlaceholder];
        if (trim($value) === '') {
            return ['status' => 'missing_data'];
        }

        return [
            'status' => 'resolved',
            'value' => $value,
        ];
    }

    /**
     * @return array{status: 'parsed', left_operand: string, right_operand: string}|array{status: 'invalid'}|array{status: 'not_formula'}
     */
    private function parseSubtractionPlaceholder(string $placeholder): array
    {
        preg_match_all('/-/', $placeholder, $matches, PREG_OFFSET_CAPTURE);
        $operators = $matches[0] ?? [];

        if ($operators === []) {
            return ['status' => 'not_formula'];
        }

        if (count($operators) !== 1) {
            return ['status' => 'invalid'];
        }

        $position = $operators[0][1];

        $leftOperand = trim(substr($placeholder, 0, $position));
        $rightOperand = trim(substr($placeholder, $position + 1));

        if ($leftOperand === '' || $rightOperand === '') {
            return ['status' => 'invalid'];
        }

        return [
            'status' => 'parsed',
            'left_operand' => $leftOperand,
            'right_operand' => $rightOperand,
        ];
    }

    /**
     * @param  array<string, string>  $normalizedMap
     * @return array{status: 'parsed', current_year_operand: string, previous_year_operand: string}|array{status: 'not_formula'}
     */
    private function parse2025AutoSumPlaceholder(string $placeholder, array $normalizedMap): array
    {
        if (str_contains($placeholder, '+') || str_contains($placeholder, '-')) {
            return ['status' => 'not_formula'];
        }

        $baseHeader = trim($placeholder);
        if ($baseHeader === '') {
            return ['status' => 'not_formula'];
        }

        $currentYearOperand = "{$baseHeader} 2025";
        if (! array_key_exists($this->normalizeHeader($currentYearOperand), $normalizedMap)) {
            return ['status' => 'not_formula'];
        }

        return [
            'status' => 'parsed',
            'current_year_operand' => $currentYearOperand,
            'previous_year_operand' => $baseHeader,
        ];
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = strtr(trim($header), [
            "\u{00A0}" => ' ',
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
        ]);
        $normalized = mb_strtolower($normalized);
        $normalized = preg_replace('/[^\pL\pN]+/u', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    private function formatValueForTemplate(string $value): string
    {
        $numericValue = $this->parseNumericValue($value);
        if ($numericValue === null) {
            return $value;
        }

        return number_format($numericValue, 2, '.', ',');
    }

    private function parseNumericValue(string $value): ?float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $numeric = str_replace([',', ' '], '', $trimmed);
        if (! is_numeric($numeric)) {
            return null;
        }

        return (float) $numeric;
    }

    private function placeholderLabel(string $placeholder): string
    {
        return '{'.$placeholder.'}';
    }
}
