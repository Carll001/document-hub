<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

class Form1702ExFieldValueFormatter
{
    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $payload
     */
    public function formatText(array $field, array $payload): string
    {
        $key = (string) ($field['key'] ?? '');
        $value = $payload[$key] ?? '';
        $text = is_scalar($value) || $value === null
            ? (string) $value
            : '';
        $formatter = $field['formatter'] ?? null;
        $squished = Str::of($text)->squish()->value();

        return match ($formatter) {
            'uppercase' => Str::upper($squished),
            'digits_only', 'tin_digits' => preg_replace('/\D+/', '', $squished) ?? '',
            'date_parts' => $this->formatDateParts($squished),
            'currency' => $squished,
            default => $squished,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $payload
     */
    public function isChecked(array $field, array $payload): bool
    {
        $key = (string) ($field['key'] ?? '');

        if (str_starts_with($key, 'atc_')) {
            return $this->normalizedToken((string) ($payload['atc'] ?? '')) === $this->normalizedToken(substr($key, 4));
        }

        return $this->checkboxValue($payload, $key);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function splitCharacters(array $field, array $payload): array
    {
        if (($field['formatter'] ?? null) === 'tin_groups_4') {
            $digits = preg_replace(
                '/\D+/',
                '',
                (string) ($payload[(string) ($field['key'] ?? '')] ?? ''),
            ) ?? '';
            $characters = [
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 3),
                substr($digits, 9),
            ];
        } else {
            $characters = preg_split(
                '//u',
                $this->formatText($field, $payload),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
        }

        $characterCount = max(1, (int) ($field['boxCount'] ?? count($characters) ?: 1));

        while (count($characters) < $characterCount) {
            $characters[] = '';
        }

        return array_slice($characters, 0, $characterCount);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function checkboxValue(array $payload, string $key): bool
    {
        $value = $payload[$key] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(Str::lower($value), ['1', 'true', 'yes', 'on'], true);
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        return false;
    }

    private function formatDateParts(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            return "{$matches[2]}{$matches[3]}{$matches[1]}";
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) >= 8
            ? substr($digits, 0, 8)
            : $digits;
    }

    private function normalizedToken(string $value): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', '', $value) ?? '';

        return Str::lower($normalized);
    }
}
