<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Smalot\PdfParser\Parser;

class PdfTextExtractionService
{
    public function __construct(
        private readonly ?Parser $parser = null,
    ) {
    }

    /**
     * Extract searchable text from a text-based PDF file.
     */
    public function extractText(string $pdfPath): string
    {
        if (! is_file($pdfPath)) {
            throw new RuntimeException('The PDF file could not be read for text extraction.');
        }

        try {
            return ($this->parser ?? new Parser)->parseFile($pdfPath)->getText();
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'The PDF text could not be extracted.',
                previous: $exception,
            );
        }
    }
}
