<?php

declare(strict_types=1);

namespace App\Services;

class PdfTinExtractorService
{
    private const LABEL_PATTERN = '(?:Taxpayer\s+Identification\s+Number(?:\s*\(TIN\))?|Tax\s+Identification\s+Number|T\.?\s*I\.?\s*N\.?)';
    private const MAX_TIN_WINDOW_LINES = 4;

    public function __construct(
        private readonly PdfTextExtractionService $pdfTextExtractionService,
    ) {
    }

    /**
     * @param  list<array{path: string, displayName: string}>  $normalizedSources
     */
    public function extractTinNumber(array $normalizedSources): ?string
    {
        foreach ($normalizedSources as $source) {
            try {
                $text = $this->pdfTextExtractionService->extractText($source['path']);
            } catch (\Throwable $exception) {
                report($exception);

                continue;
            }

            $tinNumber = $this->extractTinNumberFromText($text);

            if ($tinNumber !== null) {
                return $tinNumber;
            }
        }

        return null;
    }

    /**
     * Extract the first labeled TIN value from already-extracted PDF text.
     */
    public function extractTinNumberFromText(string $text): ?string
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $index => $line) {
            for ($windowSize = 1; $windowSize <= self::MAX_TIN_WINDOW_LINES; $windowSize++) {
                $windowLines = array_slice($lines, $index, $windowSize);
                $candidate = trim(implode(' ', $windowLines));

                if ($candidate === '') {
                    continue;
                }

                $tinNumber = $this->extractTinFromCandidate($candidate);

                if ($tinNumber !== null) {
                    return $tinNumber;
                }
            }
        }

        return $this->extractTinFromCandidate(
            preg_replace('/\s+/u', ' ', trim($text)) ?? '',
        );
    }

    private function extractTinFromCandidate(string $candidate): ?string
    {
        if ($candidate === '') {
            return null;
        }

        if (
            preg_match('/(?<label>'.self::LABEL_PATTERN.')/iu', $candidate, $matches, PREG_OFFSET_CAPTURE) !== 1
            || ! isset($matches['label'][1], $matches['label'][0])
        ) {
            return null;
        }

        $label = (string) $matches['label'][0];
        $labelOffset = (int) $matches['label'][1];
        $tail = substr($candidate, $labelOffset + strlen($label));

        if ($tail === false) {
            return null;
        }

        $structuredTin = $this->extractStructuredTinFromTail($tail);

        if ($structuredTin !== null) {
            return $structuredTin;
        }

        foreach ([
            '/^(?:[\s:#().-]+)?(?<tin>\d(?:[\d\s-]{7,24}\d))/u',
            '/(?<tin>\d{9,15})/u',
        ] as $pattern) {
            if (preg_match($pattern, $tail, $matches) !== 1) {
                continue;
            }

            $tinNumber = $this->normalizeTinCandidate(
                (string) ($matches['tin'] ?? ''),
            );

            if ($tinNumber !== null) {
                return $tinNumber;
            }
        }

        return null;
    }

    private function extractStructuredTinFromTail(string $tail): ?string
    {
        if (
            preg_match(
                '/(?<tin>\d{1,4}(?:\s*-\s*|\s+)\d{1,4}(?:\s*-\s*|\s+)\d{1,4}(?:(?:\s*-\s*|\s+)\d{1,4}){0,2})/u',
                $tail,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return $this->normalizeTinCandidate((string) ($matches['tin'] ?? ''));
    }

    private function normalizeTinCandidate(string $candidate): ?string
    {
        $trimmedCandidate = trim($candidate);

        if ($trimmedCandidate === '') {
            return null;
        }

        preg_match_all('/\d+/u', $trimmedCandidate, $matches);
        $digitGroups = $matches[0] ?? [];

        if ($digitGroups === []) {
            return null;
        }

        $digitsOnly = implode('', $digitGroups);

        if (strlen($digitsOnly) < 9 || strlen($digitsOnly) > 15) {
            return null;
        }

        return count($digitGroups) === 1
            ? $digitGroups[0]
            : implode('-', $digitGroups);
    }
}
