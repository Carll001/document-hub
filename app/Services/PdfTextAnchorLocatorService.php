<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Illuminate\Support\Facades\Process;

class PdfTextAnchorLocatorService
{
    private const MAX_INTERMEDIATE_TOKENS = 6;
    private const DEBUG_TOKEN_PREVIEW_COUNT = 12;

    /**
     * @var array<string, string>
     */
    private array $bboxXmlCache = [];

    /**
     * @return array{page: int, x: float, y: float, width: float, height: float}|null
     */
    public function locate(string $pdfPath, string $anchorText, ?int $preferredPage = null): ?array
    {
        if (! is_file($pdfPath)) {
            throw new RuntimeException('The PDF file for anchor detection does not exist.');
        }

        $bboxXml = $this->bboxXml($pdfPath);

        $result = $this->locateInBboxXmlWithDiagnostics($bboxXml, $anchorText, $preferredPage);

        return $result['match'];
    }

    /**
     * @return array{
     *   match: array{page: int, x: float, y: float, width: float, height: float}|null,
     *   diagnostics: array{
     *     preferred_page: int|null,
     *     normalized_anchor_tokens: list<string>,
     *     searched_pages: list<int>,
     *     nearby_tokens: list<array{page: int, tokens: list<string>}>
     *   }
     * }
     */
    public function locateWithDiagnostics(string $pdfPath, string $anchorText, ?int $preferredPage = null): array
    {
        if (! is_file($pdfPath)) {
            throw new RuntimeException('The PDF file for anchor detection does not exist.');
        }

        $bboxXml = $this->bboxXml($pdfPath);

        return $this->locateInBboxXmlWithDiagnostics($bboxXml, $anchorText, $preferredPage);
    }

    /**
     * @return array{page: int, x: float, y: float, width: float, height: float}|null
     */
    public function locateInBboxXml(string $bboxXml, string $anchorText, ?int $preferredPage = null): ?array
    {
        $result = $this->locateInBboxXmlWithDiagnostics($bboxXml, $anchorText, $preferredPage);

        return $result['match'];
    }

    /**
     * @return array{
     *   match: array{page: int, x: float, y: float, width: float, height: float}|null,
     *   diagnostics: array{
     *     preferred_page: int|null,
     *     normalized_anchor_tokens: list<string>,
     *     searched_pages: list<int>,
     *     nearby_tokens: list<array{page: int, tokens: list<string>}>
     *   }
     * }
     */
    public function locateInBboxXmlWithDiagnostics(string $bboxXml, string $anchorText, ?int $preferredPage = null): array
    {
        $anchorTokens = $this->normalizeTokens($anchorText);
        $diagnostics = [
            'preferred_page' => $preferredPage,
            'normalized_anchor_tokens' => $anchorTokens,
            'searched_pages' => [],
            'nearby_tokens' => [],
            'parser_mode' => null,
        ];

        if ($anchorTokens === []) {
            return [
                'match' => null,
                'diagnostics' => $diagnostics,
            ];
        }

        $pagesResult = $this->extractPagesFromBbox($bboxXml);
        $diagnostics['parser_mode'] = $pagesResult['mode'];
        $pages = $pagesResult['pages'];

        if ($pages === []) {
            return [
                'match' => null,
                'diagnostics' => $diagnostics,
            ];
        }

        if ($preferredPage !== null) {
            usort($pages, static function (array $left, array $right) use ($preferredPage): int {
                if ($left['number'] === $preferredPage && $right['number'] !== $preferredPage) {
                    return -1;
                }
                if ($right['number'] === $preferredPage && $left['number'] !== $preferredPage) {
                    return 1;
                }

                return $left['number'] <=> $right['number'];
            });
        }

        foreach ($pages as $pageData) {
            $pageNumber = (int) $pageData['number'];
            /** @var DOMElement $pageElement */
            $pageElement = $pageData['element'];

            $diagnostics['searched_pages'][] = $pageNumber;
            $xpath = new DOMXPath($pageElement->ownerDocument);
            $wordNodes = $xpath->query('.//*[local-name()="word"]', $pageElement) ?: [];
            $words = [];

            foreach ($wordNodes as $wordNode) {
                if (! $wordNode instanceof DOMElement) {
                    continue;
                }

                $text = trim($wordNode->textContent);
                $tokens = $this->normalizeTokens($text);
                if ($tokens === []) {
                    continue;
                }

                foreach ($tokens as $token) {
                    $words[] = [
                        'token' => $token,
                        'xMin' => $this->floatAttribute($wordNode, ['xmin', 'xMin']),
                        'yMin' => $this->floatAttribute($wordNode, ['ymin', 'yMin']),
                        'xMax' => $this->floatAttribute($wordNode, ['xmax', 'xMax']),
                        'yMax' => $this->floatAttribute($wordNode, ['ymax', 'yMax']),
                    ];
                }
            }

            $diagnostics['nearby_tokens'][] = [
                'page' => $pageNumber,
                'tokens' => array_values(array_slice(array_column($words, 'token'), 0, self::DEBUG_TOKEN_PREVIEW_COUNT)),
            ];

            $windowLength = count($anchorTokens);
            $maxStart = count($words) - $windowLength;

            for ($start = 0; $start <= $maxStart; $start++) {
                $matched = true;
                for ($offset = 0; $offset < $windowLength; $offset++) {
                    if (($words[$start + $offset]['token'] ?? null) !== $anchorTokens[$offset]) {
                        $matched = false;
                        break;
                    }
                }

                if (! $matched) {
                    continue;
                }

                $matchedWords = array_slice($words, $start, $windowLength);
                $minX = min(array_column($matchedWords, 'xMin'));
                $minY = min(array_column($matchedWords, 'yMin'));
                $maxX = max(array_column($matchedWords, 'xMax'));
                $maxY = max(array_column($matchedWords, 'yMax'));

                return [
                    'match' => [
                        'page' => $pageNumber,
                        'x' => (float) $minX,
                        'y' => (float) $minY,
                        'width' => (float) ($maxX - $minX),
                        'height' => (float) ($maxY - $minY),
                    ],
                    'diagnostics' => $diagnostics,
                ];
            }

            $fuzzyMatch = $this->findOrderedTokenMatch($words, $anchorTokens);
            if ($fuzzyMatch !== null) {
                $minX = min(array_column($fuzzyMatch, 'xMin'));
                $minY = min(array_column($fuzzyMatch, 'yMin'));
                $maxX = max(array_column($fuzzyMatch, 'xMax'));
                $maxY = max(array_column($fuzzyMatch, 'yMax'));

                return [
                    'match' => [
                        'page' => $pageNumber,
                        'x' => (float) $minX,
                        'y' => (float) $minY,
                        'width' => (float) ($maxX - $minX),
                        'height' => (float) ($maxY - $minY),
                    ],
                    'diagnostics' => $diagnostics,
                ];
            }
        }

        return [
            'match' => null,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array{
     *   mode: 'xml'|'html'|'none',
     *   pages: list<array{number: int, element: DOMElement}>
     * }
     */
    private function extractPagesFromBbox(string $bboxXml): array
    {
        $xmlDocument = new DOMDocument;
        if (@$xmlDocument->loadXML($bboxXml) !== false) {
            $xmlPages = $this->extractPagesFromDocument($xmlDocument);
            if ($xmlPages !== []) {
                return [
                    'mode' => 'xml',
                    'pages' => $xmlPages,
                ];
            }
        }

        $htmlDocument = new DOMDocument;
        if (@$htmlDocument->loadHTML($bboxXml) !== false) {
            $htmlPages = $this->extractPagesFromDocument($htmlDocument);
            if ($htmlPages !== []) {
                return [
                    'mode' => 'html',
                    'pages' => $htmlPages,
                ];
            }
        }

        return [
            'mode' => 'none',
            'pages' => [],
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private function floatAttribute(DOMElement $element, array $names): float
    {
        foreach ($names as $name) {
            $raw = $element->getAttribute($name);
            if ($raw === '') {
                continue;
            }

            if (is_numeric($raw)) {
                return (float) $raw;
            }
        }

        return 0.0;
    }

    /**
     * @return list<array{number: int, element: DOMElement}>
     */
    private function extractPagesFromDocument(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $pageNodes = $xpath->query('//*[local-name()="page"]') ?: [];
        $pages = [];
        foreach ($pageNodes as $pageElement) {
            if (! $pageElement instanceof DOMElement) {
                continue;
            }

            $pageNumber = (int) ($pageElement->getAttribute('number') ?: '0');
            if ($pageNumber < 1) {
                continue;
            }

            $pages[] = ['number' => $pageNumber, 'element' => $pageElement];
        }

        return $pages;
    }

    /**
     * @param  list<array{token: string, xMin: float, yMin: float, xMax: float, yMax: float}>  $words
     * @param  list<string>  $anchorTokens
     * @return list<array{token: string, xMin: float, yMin: float, xMax: float, yMax: float}>|null
     */
    private function findOrderedTokenMatch(array $words, array $anchorTokens): ?array
    {
        if ($anchorTokens === []) {
            return null;
        }

        $totalWords = count($words);
        $totalTokens = count($anchorTokens);

        for ($startIndex = 0; $startIndex < $totalWords; $startIndex++) {
            if (($words[$startIndex]['token'] ?? null) !== $anchorTokens[0]) {
                continue;
            }

            $matchedWords = [$words[$startIndex]];
            $currentIndex = $startIndex;
            $matchedAll = true;

            for ($tokenIndex = 1; $tokenIndex < $totalTokens; $tokenIndex++) {
                $nextToken = $anchorTokens[$tokenIndex];
                $foundIndex = null;
                $searchUntil = min($totalWords - 1, $currentIndex + self::MAX_INTERMEDIATE_TOKENS + 1);

                for ($candidateIndex = $currentIndex + 1; $candidateIndex <= $searchUntil; $candidateIndex++) {
                    if (($words[$candidateIndex]['token'] ?? null) === $nextToken) {
                        $foundIndex = $candidateIndex;
                        break;
                    }
                }

                if ($foundIndex === null) {
                    $matchedAll = false;
                    break;
                }

                $matchedWords[] = $words[$foundIndex];
                $currentIndex = $foundIndex;
            }

            if ($matchedAll) {
                return $matchedWords;
            }
        }

        return null;
    }

    private function bboxXml(string $pdfPath): string
    {
        $cacheKey = $pdfPath.'|'.(string) @filemtime($pdfPath);
        if (isset($this->bboxXmlCache[$cacheKey])) {
            return $this->bboxXmlCache[$cacheKey];
        }

        $configuredBinary = trim((string) config('services.document_generator.pdftotext_binary', 'pdftotext'));
        $binaries = array_values(array_unique(array_filter([
            $configuredBinary,
            '/usr/bin/pdftotext',
            'pdftotext',
        ], static fn (string $binary): bool => $binary !== '')));
        $errors = [];
        foreach ($binaries as $binary) {
            foreach (['-bbox-layout', '-bbox'] as $bboxMode) {
                $process = Process::timeout(30)->run([
                    $binary,
                    $bboxMode,
                    '-enc',
                    'UTF-8',
                    $pdfPath,
                    '-',
                ]);

                if (! $process->successful()) {
                    $message = trim((string) ($process->errorOutput() ?: $process->output()));
                    if ($message === '') {
                        $message = 'Unknown pdftotext error.';
                    }
                    $errors[] = sprintf('[%s %s] %s', $binary, $bboxMode, $message);
                    continue;
                }

                $output = (string) $process->output();
                if (! $this->containsPageMarkup($output)) {
                    $preview = mb_substr(trim(preg_replace('/\s+/', ' ', $output) ?? ''), 0, 180);
                    if ($preview === '') {
                        $preview = '[empty output]';
                    }

                    $errors[] = sprintf(
                        '[%s %s] output had no page markup (preview: %s)',
                        $binary,
                        $bboxMode,
                        $preview
                    );
                    continue;
                }

                $this->bboxXmlCache[$cacheKey] = $output;
                return $output;
            }
        }

        throw new RuntimeException(
            'Unable to detect anchor text from PDF. Ensure Poppler pdftotext is installed and configured. '.
            'Details: '.implode(' | ', $errors)
        );
    }

    /**
     * @return list<string>
     */
    private function normalizeTokens(string $value): array
    {
        $tokens = preg_split('/\s+/u', trim($value)) ?: [];

        $normalized = array_map(static function (string $token): string {
            return preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($token)) ?? '';
        }, $tokens);

        return array_values(array_filter($normalized, static fn (string $token): bool => $token !== ''));
    }

    private function containsPageMarkup(string $value): bool
    {
        return preg_match('/<(?:[a-z0-9_:-]+:)?page\b/i', $value) === 1;
    }
}
