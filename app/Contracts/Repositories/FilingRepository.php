<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\AfsFilingItem;
use App\Models\Company;
use App\Models\DocumentGeneratorTemplate;
use App\Models\FilingOutput;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface FilingRepository
{
    public function findLatestAfsDefaultTemplate(): ?DocumentGeneratorTemplate;

    /**
     * @param  array<int, int>  $companyIds
     * @return Collection<int, Company>
     */
    public function getCompaniesByIds(array $companyIds): Collection;

    public function paginateCompaniesForFilingIndex(string $search, int $page, int $perPage): LengthAwarePaginator;

    /**
     * @param  array<int, int>  $companyIds
     * @return Collection<int, FilingOutput>
     */
    public function getAfsOutputs(array $companyIds): Collection;

    /**
     * @return Collection<int, FilingOutput>
     */
    public function getOutputs(?string $formType, string $status): Collection;

    public function createFilingOutput(array $attributes): FilingOutput;

    public function saveFilingOutput(FilingOutput $item): FilingOutput;

    public function findCompanyById(int $id): ?Company;

    public function nextAfsRowNumber(): int;

    public function createAfsFilingItem(array $attributes): AfsFilingItem;
}
