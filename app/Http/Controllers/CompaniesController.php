<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Companies\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompaniesController extends Controller
{
    public function __construct(
        private readonly CompanyService $companyService,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'in:name,tin,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'perPage' => ['nullable', 'integer', 'in:10,25,50,100'],
        ]);

        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $sort = (string) ($validated['sort'] ?? 'created_at');
        $direction = (string) ($validated['direction'] ?? 'desc');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['perPage'] ?? 10);

        $query = Company::query();

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $like = '%'.$search.'%';
                $searchQuery
                    ->where('name', 'like', $like)
                    ->orWhere('tin', 'like', $like)
                    ->orWhere('address', 'like', $like);
            });
        }

        $sortColumn = match ($sort) {
            'name' => 'name',
            'tin' => 'tin',
            default => 'created_at',
        };
        $sortDirection = $direction === 'asc' ? 'asc' : 'desc';

        $pageResult = $query
            ->orderBy($sortColumn, $sortDirection)
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $recentThreshold = $now->copy()->subDays(30);

        return Inertia::render('companies/Index', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'routes' => [
                'index' => route('companies.index'),
                'create' => route('companies.create'),
                'import' => route('companies.import'),
                'store' => route('companies.store'),
            ],
            'companies' => [
                'data' => collect($pageResult->items())
                    ->map(fn (Company $company): array => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'tin' => $company->tin,
                        'address' => (string) ($company->address ?? ''),
                        'created_at' => $company->created_at?->toDateString(),
                    ])
                    ->values()
                    ->all(),
                'pagination' => [
                    'current_page' => $pageResult->currentPage(),
                    'last_page' => $pageResult->lastPage(),
                    'per_page' => $pageResult->perPage(),
                    'total' => $pageResult->total(),
                    'from' => $pageResult->firstItem() ?? 0,
                    'to' => $pageResult->lastItem() ?? 0,
                ],
            ],
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $sortDirection,
                'perPage' => $perPage,
            ],
            'stats' => [
                'totalCompanies' => Company::query()->count(),
                'recentlyAdded' => Company::query()->where('created_at', '>=', $recentThreshold)->count(),
                'addedThisMonth' => Company::query()->where('created_at', '>=', $startOfMonth)->count(),
                'importedCompanies' => Company::query()->where('imported_via_excel', true)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('companies/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tin' => ['required', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        Company::query()->create([
            'user_id' => (int) $request->user()->getKey(),
            'client_id' => null,
            'name' => trim((string) $validated['name']),
            'name_normalized' => $this->normalizeName((string) $validated['name']),
            'tin' => trim((string) $validated['tin']),
            'tin_normalized' => $this->normalizeTin((string) $validated['tin']),
            'address' => $this->normalizeOptionalText($validated['address'] ?? null),
            'imported_via_excel' => false,
        ]);

        return to_route('companies.index')
            ->with('success', 'Company created.');
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('companies/Edit', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'tin' => $company->tin,
                'address' => (string) ($company->address ?? ''),
            ],
        ]);
    }

    public function data(Company $company): JsonResponse
    {
        return response()->json([
            'id' => (int) $company->id,
            'name' => (string) $company->name,
            'tin' => (string) $company->tin,
            'address' => (string) ($company->address ?? ''),
            'imported_via_excel' => (bool) $company->imported_via_excel,
            'data' => is_array($company->data) ? $company->data : [],
        ], 200, [], JSON_PRETTY_PRINT);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tin' => ['required', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $company->forceFill([
            'name' => trim((string) $validated['name']),
            'name_normalized' => $this->normalizeName((string) $validated['name']),
            'tin' => trim((string) $validated['tin']),
            'tin_normalized' => $this->normalizeTin((string) $validated['tin']),
            'address' => $this->normalizeOptionalText($validated['address'] ?? null),
        ])->save();

        return to_route('companies.index')
            ->with('success', 'Company updated.');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $company->delete();

        return to_route('companies.index')
            ->with('success', 'Company deleted.');
    }

    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'spreadsheet' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:15360'],
        ]);

        $result = $this->companyService->importCompanies(
            $validated['spreadsheet'],
            (int) $request->user()->getKey(),
        );

        if ($result['inserted'] === 0 && $result['updated'] === 0 && $result['skipped'] > 0) {
            return to_route('companies.index')->with(
                'error',
                "No rows were imported. Skipped {$result['skipped']} rows (missing name: {$result['skipped_missing_name']}, missing tin: {$result['skipped_missing_tin']}, invalid tin: {$result['skipped_invalid_tin']}).",
            );
        }

        return to_route('companies.index')
            ->with('success', "Company import complete. Inserted {$result['inserted']}, updated {$result['updated']}, skipped {$result['skipped']}.");
    }

    private function normalizeName(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $collapsed = preg_replace('/\s+/u', ' ', $normalized);

        return $collapsed !== null ? $collapsed : $normalized;
    }

    private function normalizeTin(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);

        return is_string($digits) ? $digits : '';
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

}
