<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait BuildsPaginationPayload
{
    /**
     * @return array{
     *     currentPage: int,
     *     lastPage: int,
     *     perPage: int,
     *     total: int,
     *     from: int|null,
     *     to: int|null
     * }
     */
    protected function paginationPayload(LengthAwarePaginator $page): array
    {
        return [
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }
}
