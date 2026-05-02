<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CompanyRepository
{
    public function paginateForIndex(
        string $search,
        string $sortColumn,
        string $sortDirection,
        int $perPage,
        int $page,
    ): LengthAwarePaginator;

    /**
     * @return array{
     *   totalCompanies: int,
     *   recentlyAdded: int,
     *   addedThisMonth: int,
     *   importedCompanies: int
     * }
     */
    public function stats(CarbonInterface $recentThreshold, CarbonInterface $startOfMonth): array;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Company;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Company $company, array $attributes): Company;

    public function delete(Company $company): void;

    public function findByNormalizedNameAndTin(string $normalizedName, string $normalizedTin): ?Company;
}

