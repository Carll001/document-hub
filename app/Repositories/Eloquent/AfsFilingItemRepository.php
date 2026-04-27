<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\AfsFilingItemRepository as AfsFilingItemRepositoryContract;
use App\Models\AfsFilingItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AfsFilingItemRepository implements AfsFilingItemRepositoryContract
{
    public function paginateForUser(int $userId, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(5, min(100, (int) ($filters['per_page'] ?? 25)));
        $sortBy = (string) ($filters['sort_by'] ?? 'updated_at');
        $direction = (string) ($filters['sort_direction'] ?? 'desc');

        $query = AfsFilingItem::query()->where('user_id', $userId);

        if (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (($filters['unsigned_only'] ?? false) === true) {
            $query->whereNull('signature_applied_at');
        }

        if (($filters['completed_only'] ?? false) === true) {
            $query->where('status', 'pdf_done')->whereNotNull('signature_applied_at');
        }

        if (is_string($filters['company_search'] ?? null) && trim($filters['company_search']) !== '') {
            $search = trim($filters['company_search']);
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function ($builder) use ($needle): void {
                $builder->whereRaw('LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, "$.COMPANY")), "")) like ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, "$.company")), "")) like ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, "$.Company Name")), "")) like ?', [$needle]);
            });
        }

        return $query->orderBy($sortBy, $direction === 'asc' ? 'asc' : 'desc')->paginate($perPage);
    }

    public function findForUser(int $userId, int $itemId): ?AfsFilingItem
    {
        return AfsFilingItem::query()
            ->where('user_id', $userId)
            ->whereKey($itemId)
            ->first();
    }
}
