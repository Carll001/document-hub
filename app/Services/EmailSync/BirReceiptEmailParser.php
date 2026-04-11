<?php

declare(strict_types=1);

namespace App\Services\EmailSync;

class BirReceiptEmailParser
{
    /**
     * @return array{
     *     file_name: string|null,
     *     date_received_by_bir: string|null,
     *     time_received_by_bir: string|null,
     *     tin: string|null,
     *     form_type: string|null
     * }|null
     */
    public function parse(?string $text): ?array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $normalizedText = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        $fileName = $this->matchField(
            $normalizedText,
            '/(?:^|\n)\s*File\s*name\s*:\s*(.+?)\s*(?=\n|$)/im',
        );
        $dateReceived = $this->matchField(
            $normalizedText,
            '/(?:^|\n)\s*Date\s*received(?:\s+by\s+BIR)?\s*:\s*(.+?)\s*(?=\n|$)/im',
        );
        $timeReceived = $this->matchField(
            $normalizedText,
            '/(?:^|\n)\s*Time\s*received(?:\s+by\s+BIR)?\s*:\s*(.+?)\s*(?=\n|$)/im',
        );

        if ($fileName === null && $dateReceived === null && $timeReceived === null) {
            return null;
        }

        return [
            'file_name' => $fileName,
            'date_received_by_bir' => $dateReceived,
            'time_received_by_bir' => $timeReceived,
            'tin' => $this->tinFromFileName($fileName),
            'form_type' => $this->formTypeFromFileName($fileName),
        ];
    }

    private function matchField(string $text, string $pattern): ?string
    {
        if (! preg_match($pattern, $text, $matches)) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim((string) ($matches[1] ?? '')));
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function tinFromFileName(?string $fileName): ?string
    {
        $fileName = trim((string) $fileName);

        if ($fileName === '') {
            return null;
        }

        if (! preg_match('/^(\d+)/', $fileName, $matches)) {
            return null;
        }

        $tin = preg_replace('/\D+/', '', (string) ($matches[1] ?? ''));
        $tin = is_string($tin) ? $tin : '';

        return $tin !== '' ? $tin : null;
    }

    private function formTypeFromFileName(?string $fileName): ?string
    {
        $fileName = trim((string) $fileName);

        if ($fileName === '') {
            return null;
        }

        if (! preg_match('/^\d+-([A-Za-z0-9]+)/', $fileName, $matches)) {
            return null;
        }

        $formType = strtoupper(trim((string) ($matches[1] ?? '')));

        return $formType !== '' ? $formType : null;
    }
}
