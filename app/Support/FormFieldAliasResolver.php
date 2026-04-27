<?php

namespace App\Support;

use App\Models\FormFieldAlias;
use Illuminate\Support\Facades\Schema;

final class FormFieldAliasResolver
{
    public const FORM_GLOBAL = 'global';

    public const FORM_AFS = 'afs';

    public const FORM_1702EX = '1702ex';

    public const FORM_2551Q = '2551q';

    /**
     * @var array<string, list<string>>
     */
    private const GLOBAL_ALIASES = [
        'tin' => [
            'tin',
            'tax identification number',
            'taxpayer tin',
        ],
        'company' => [
            'company',
            'company name',
            'registered name',
            'taxpayer name',
            'business name',
            'corporate name',
            'entity name',
        ],
    ];

    /**
     * @var array<string, array<string, list<string>>>
     */
    private const PER_FORM_ALIASES = [
        self::FORM_AFS => [
            'tin' => ['company tin', 'company_tin', 'companytin'],
        ],
        self::FORM_1702EX => [
            'tin' => [],
        ],
        self::FORM_2551Q => [
            'tin' => [],
        ],
    ];

    /**
     * @var array{
     *   global: array<string, list<string>>,
     *   perForm: array<string, array<string, list<string>>>
     * }|null
     */
    private static ?array $cachedRegistry = null;

    /**
     * @param  array<string, mixed>  $rowData
     */
    public static function resolveAliasedField(array $rowData, string $canonicalKey, string $formType): ?string
    {
        $normalizedAliases = array_map(
            static fn (string $alias): string => self::normalizeKey($alias),
            self::aliasesFor($canonicalKey, $formType),
        );

        foreach ($rowData as $fieldKey => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            if (! in_array(self::normalizeKey((string) $fieldKey), $normalizedAliases, true)) {
                continue;
            }

            $stringValue = trim((string) $value);

            if ($stringValue !== '') {
                return $stringValue;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    public static function resolveTin(array $rowData, string $formType): ?string
    {
        $value = self::resolveAliasedField($rowData, 'tin', $formType);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        return $digits;
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    public static function resolveCompany(array $rowData, string $formType): ?string
    {
        return self::resolveAliasedField($rowData, 'company', $formType);
    }

    /**
     * @return list<string>
     */
    public static function aliasesFor(string $canonicalKey, string $formType): array
    {
        $registry = self::registry();
        $globalAliases = $registry['global'][$canonicalKey] ?? [];
        $formAliases = $registry['perForm'][$formType][$canonicalKey] ?? [];

        return array_values(array_unique([...$formAliases, ...$globalAliases]));
    }

    /**
     * @return array{
     *   global: array<string, list<string>>,
     *   perForm: array<string, array<string, list<string>>>
     * }
     */
    public static function exportRegistry(): array
    {
        return self::registry();
    }

    public static function clearCache(): void
    {
        self::$cachedRegistry = null;
    }

    public static function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($key)) ?? '';
    }

    /**
     * @return array{
     *   global: array<string, list<string>>,
     *   perForm: array<string, array<string, list<string>>>
     * }
     */
    private static function registry(): array
    {
        if (self::$cachedRegistry !== null) {
            return self::$cachedRegistry;
        }

        $registry = [
            'global' => self::GLOBAL_ALIASES,
            'perForm' => self::PER_FORM_ALIASES,
        ];

        if (! Schema::hasTable('form_field_aliases')) {
            self::$cachedRegistry = $registry;

            return $registry;
        }

        $rows = FormFieldAlias::query()
            ->get(['form_type', 'canonical_key', 'aliases_json']);

        foreach ($rows as $row) {
            $canonicalKey = (string) $row->canonical_key;
            $aliases = array_values(
                array_filter(
                    array_map(
                        static fn (mixed $value): string => trim((string) $value),
                        is_array($row->aliases_json) ? $row->aliases_json : []
                    ),
                    static fn (string $value): bool => $value !== ''
                )
            );

            if ((string) $row->form_type === self::FORM_GLOBAL) {
                $registry['global'][$canonicalKey] = $aliases;
                continue;
            }

            $registry['perForm'][(string) $row->form_type][$canonicalKey] = $aliases;
        }

        self::$cachedRegistry = $registry;

        return $registry;
    }
}
