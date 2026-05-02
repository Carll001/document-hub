<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\CompanyRepository as CompanyRepositoryContract;
use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CompanyRepository implements CompanyRepositoryContract
{
    public function paginateForIndex(
        string $search,
        string $sortColumn,
        string $sortDirection,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
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

        return $query
            ->orderBy($sortColumn, $sortDirection)
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }

    public function stats(CarbonInterface $recentThreshold, CarbonInterface $startOfMonth): array
    {
        return [
            'totalCompanies' => Company::query()->count(),
            'recentlyAdded' => Company::query()->where('created_at', '>=', $recentThreshold)->count(),
            'addedThisMonth' => Company::query()->where('created_at', '>=', $startOfMonth)->count(),
            'importedCompanies' => Company::query()->where('imported_via_excel', true)->count(),
        ];
    }

    public function create(array $attributes): Company
    {
        /** @var Company $company */
        $company = Company::query()->create($attributes);

        return $company;
    }

    public function update(Company $company, array $attributes): Company
    {
        $company->forceFill($attributes)->save();

        return $company;
    }

    public function delete(Company $company): void
    {
        $company->delete();
    }

    public function findByNormalizedNameAndTin(string $normalizedName, string $normalizedTin): ?Company
    {
        return Company::query()
            ->where('name_normalized', $normalizedName)
            ->where('tin_normalized', $normalizedTin)
            ->first();
    }
}

