<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent\DocMerge;

use App\Models\DocMergeBatch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DocMergeBatchRepository
{
    public function paginateForUser(int $userId, int $page = 1, int $perPage = 10): LengthAwarePaginator
    {
        $resolvedPerPage = max(5, min(100, $perPage));

        return DocMergeBatch::query()
            ->where('user_id', $userId)
            ->withCount(['mergedPdfs', 'bulkMergeFailures'])
            ->latest()
            ->paginate($resolvedPerPage, ['*'], 'page', max(1, $page));
    }
}

