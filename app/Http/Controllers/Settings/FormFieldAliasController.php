<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\FormFieldAlias;
use App\Support\FormFieldAliasResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FormFieldAliasController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/Aliases', [
            'registry' => FormFieldAliasResolver::exportRegistry(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array'],
            'entries.*.form_type' => ['required', 'string', 'in:global,afs,1702ex,2551q'],
            'entries.*.canonical_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_]+$/'],
            'entries.*.aliases' => ['present', 'array'],
            'entries.*.aliases.*' => ['string', 'max:255'],
        ]);

        /** @var array<int, array{form_type:string, canonical_key:string, aliases:array<int, string>}> $entries */
        $entries = $validated['entries'];

        DB::transaction(function () use ($entries): void {
            $upsertRows = [];

            foreach ($entries as $entry) {
                $canonicalKey = $this->normalizeCanonicalKey($entry['canonical_key']);
                if ($canonicalKey === '') {
                    continue;
                }

                $compoundKey = sprintf('%s|%s', $entry['form_type'], $canonicalKey);
                $aliases = $this->normalizeAliases($entry['aliases']);

                if (isset($upsertRows[$compoundKey])) {
                    $upsertRows[$compoundKey]['aliases_json'] = $this->normalizeAliases([
                        ...$upsertRows[$compoundKey]['aliases_json'],
                        ...$aliases,
                    ]);
                    continue;
                }

                $upsertRows[$compoundKey] = [
                    'form_type' => $entry['form_type'],
                    'canonical_key' => $canonicalKey,
                    'aliases_json' => $aliases,
                ];
            }

            FormFieldAlias::query()
                ->whereIn('form_type', [
                    FormFieldAliasResolver::FORM_GLOBAL,
                    FormFieldAliasResolver::FORM_AFS,
                    FormFieldAliasResolver::FORM_1702EX,
                    FormFieldAliasResolver::FORM_2551Q,
                ])
                ->delete();

            if ($upsertRows !== []) {
                $rowsForUpsert = array_map(
                    static fn (array $row): array => [
                        ...$row,
                        'aliases_json' => json_encode($row['aliases_json'], JSON_UNESCAPED_UNICODE),
                    ],
                    array_values($upsertRows),
                );

                FormFieldAlias::query()->upsert(
                    $rowsForUpsert,
                    ['form_type', 'canonical_key'],
                    ['aliases_json'],
                );
            }
        });

        FormFieldAliasResolver::clearCache();

        return back()->with('status', 'Alias registry updated.');
    }

    /**
     * @param  list<string>  $aliases
     * @return list<string>
     */
    private function normalizeAliases(array $aliases): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map(static fn (string $alias): string => trim($alias), $aliases),
                    static fn (string $alias): bool => $alias !== '',
                ),
            ),
        );
    }

    private function normalizeCanonicalKey(string $key): string
    {
        $normalized = strtolower(trim($key));
        $normalized = preg_replace('/\s+/', '_', $normalized) ?? '';

        return preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';
    }
}
