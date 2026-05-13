<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\AfsFilingItemRepository as AfsFilingItemRepositoryContract;
use App\Models\AfsFilingItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AfsFilingItemRepository implements AfsFilingItemRepositoryContract
{
    public function paginateForUser(int $userId, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(5, min(100, (int) ($filters['per_page'] ?? 15)));
        $sortBy = (string) ($filters['sort_by'] ?? 'updated_at');
        $direction = (string) ($filters['sort_direction'] ?? 'desc');

        $query = AfsFilingItem::query()->where('user_id', $userId);

        $statusFilter = is_string($filters['status'] ?? null) ? trim((string) $filters['status']) : '';
        if ($statusFilter !== '') {
            $query->where('status', $statusFilter);
        }

        $unsignedOnly = filter_var($filters['unsigned_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($unsignedOnly) {
            $query->whereNull('signature_applied_at');
        }

        $completedOnly = filter_var($filters['completed_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($completedOnly) {
            $query->where('status', 'pdf_done')->whereNotNull('signature_applied_at');
        }

        if (is_string($filters['company_search'] ?? null) && trim($filters['company_search']) !== '') {
            $search = trim($filters['company_search']);
            $needle = '%'.$search.'%';
            $isPostgres = DB::connection()->getDriverName() === 'pgsql';
            $operator = $isPostgres ? 'ilike' : 'like';
            $query->where(function ($builder) use ($needle, $operator, $isPostgres): void {
                $builder->where('row_data->COMPANY', $operator, $needle)
                    ->orWhere('row_data->company', $operator, $needle)
                    ->orWhere('row_data->Company Name', $operator, $needle)
                    ->orWhere('row_data->TIN', $operator, $needle)
                    ->orWhere('row_data->tin', $operator, $needle)
                    ->orWhere('row_data->Taxpayer TIN', $operator, $needle)
                    ->orWhereRaw($isPostgres ? 'CAST(row_data AS TEXT) ILIKE ?' : 'CAST(row_data AS CHAR) LIKE ?', [$needle]);
            });
        }

        // For "All statuses", keep rows grouped in the product-defined sequence
        // before applying the regular sort column, so pagination also respects it.
        if ($statusFilter === '' && ! $completedOnly) {
            $query->orderByRaw("
                CASE status
                    WHEN 'failed' THEN 0
                    WHEN 'signing' THEN 1
                    WHEN 'deleting' THEN 2
                    WHEN 'processing' THEN 3
                    WHEN 'docx_done' THEN 4
                    WHEN 'queued' THEN 5
                    WHEN 'pdf_done' THEN 6
                    ELSE 99
                END ASC
            ");
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
