<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\FilingRepository as FilingRepositoryContract;
use App\Models\AfsFilingItem;
use App\Models\Company;
use App\Models\DocumentGeneratorTemplate;
use App\Models\FilingOutput;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FilingRepository implements FilingRepositoryContract
{
    public function findLatestAfsDefaultTemplate(): ?DocumentGeneratorTemplate
    {
        return DocumentGeneratorTemplate::query()
            ->whereNull('year')
            ->latest('updated_at')
            ->first();
    }

    public function getCompaniesByIds(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return collect();
        }

        return Company::query()->whereIn('id', $companyIds)->get();
    }

    public function paginateCompaniesForFilingIndex(string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $query = Company::query();

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $like = '%'.$search.'%';
                $searchQuery
                    ->where('name', 'like', $like)
                    ->orWhere('tin', 'like', $like);
            });
        }

        return $query->orderBy('name', 'asc')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }

    public function getAfsOutputs(array $companyIds): Collection
    {
        $latestIds = FilingOutput::query()
            ->where('form_type', 'afs')
            ->when($companyIds !== [], fn ($query) => $query->whereIn('company_id', $companyIds))
            ->selectRaw('MAX(id) as id')
            ->groupBy('company_id');

        return FilingOutput::query()
            ->whereIn('id', $latestIds)
            ->latest('id')
            ->get();
    }

    public function getOutputs(?string $formType, string $status): Collection
    {
        return FilingOutput::query()
            ->when($formType !== null, fn ($query) => $query->where('form_type', $formType))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->get();
    }

    public function createFilingOutput(array $attributes): FilingOutput
    {
        /** @var FilingOutput $output */
        $output = FilingOutput::query()->create($attributes);

        return $output;
    }

    public function saveFilingOutput(FilingOutput $item): FilingOutput
    {
        $item->save();

        return $item;
    }

    public function findCompanyById(int $id): ?Company
    {
        return Company::query()->find($id);
    }

    public function nextAfsRowNumber(): int
    {
        return ((int) (AfsFilingItem::query()->max('row_number') ?? 0)) + 1;
    }

    public function createAfsFilingItem(array $attributes): AfsFilingItem
    {
        /** @var AfsFilingItem $item */
        $item = AfsFilingItem::query()->create($attributes);

        return $item;
    }
}
