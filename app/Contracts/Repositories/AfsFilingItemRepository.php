<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\AfsFilingItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AfsFilingItemRepository
{
    public function paginateForUser(int $userId, array $filters = []): LengthAwarePaginator;

    public function findForUser(int $userId, int $itemId): ?AfsFilingItem;
}
