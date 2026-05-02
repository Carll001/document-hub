<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Repositories\CompanyRepository as CompanyRepositoryContract;
use App\Http\Controllers\Concerns\BasePagination;
use App\Http\Requests\Companies\CompaniesImportRequest;
use App\Http\Requests\Companies\CompaniesIndexRequest;
use App\Http\Requests\Companies\CompanyStoreRequest;
use App\Http\Requests\Companies\CompanyUpdateRequest;
use App\Http\Resources\Companies\CompanyDataResource;
use App\Http\Resources\Companies\CompanyIndexResource;
use App\Models\Company;
use App\Services\Companies\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CompaniesController extends Controller
{
    use BasePagination;

    public function __construct(
        private readonly CompanyService $companyService,
        private readonly CompanyRepositoryContract $companyRepository,
    ) {}

    public function index(CompaniesIndexRequest $request): Response
    {
        $validated = $request->validated();

        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $sort = (string) ($validated['sort'] ?? 'created_at');
        $direction = (string) ($validated['direction'] ?? 'desc');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['perPage'] ?? 10);

        $sortColumn = match ($sort) {
            'name' => 'name',
            'tin' => 'tin',
            default => 'created_at',
        };
        $sortDirection = $direction === 'asc' ? 'asc' : 'desc';

        $pageResult = $this->companyRepository->paginateForIndex(
            $search,
            $sortColumn,
            $sortDirection,
            $perPage,
            $page,
        );

        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $recentThreshold = $now->copy()->subDays(30);
        $stats = $this->companyRepository->stats($recentThreshold, $startOfMonth);

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
                'data' => CompanyIndexResource::collection(collect($pageResult->items()))->resolve(),
                'pagination' => $this->basePagination($pageResult),
            ],
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $sortDirection,
                'perPage' => $perPage,
            ],
            'stats' => $stats,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('companies/Create');
    }

    public function store(CompanyStoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->companyRepository->create([
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
        return response()->json(
            (new CompanyDataResource($company))->resolve(),
            200,
            [],
            JSON_PRETTY_PRINT,
        );
    }

    public function update(CompanyUpdateRequest $request, Company $company): RedirectResponse
    {
        $validated = $request->validated();

        $this->companyRepository->update($company, [
            'name' => trim((string) $validated['name']),
            'name_normalized' => $this->normalizeName((string) $validated['name']),
            'tin' => trim((string) $validated['tin']),
            'tin_normalized' => $this->normalizeTin((string) $validated['tin']),
            'address' => $this->normalizeOptionalText($validated['address'] ?? null),
        ]);

        return to_route('companies.index')
            ->with('success', 'Company updated.');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $this->companyRepository->delete($company);

        return to_route('companies.index')
            ->with('success', 'Company deleted.');
    }

    public function import(CompaniesImportRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $result = $this->companyService->importCompanies(
            $validated['spreadsheet'],
            (int) $request->user()->getKey(),
            (bool) ($validated['overwrite_existing'] ?? false),
        );

        if ($result['inserted'] === 0 && $result['updated'] === 0 && $result['skipped'] > 0) {
            return to_route('companies.index')->with(
                'error',
                "No rows were imported. Skipped {$result['skipped']} rows (missing name: {$result['skipped_missing_name']}, missing tin: {$result['skipped_missing_tin']}, invalid tin: {$result['skipped_invalid_tin']}).",
            );
        }

        return to_route('companies.index')
            ->with('success', "Company import complete. Inserted {$result['inserted']}, updated {$result['updated']}, kept existing {$result['kept_existing']}, skipped {$result['skipped']}.");
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
