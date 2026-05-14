<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Models\AfsFilingItem;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAfsCompletedExportChunk implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  list<int>  $itemIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $itemIds,
        public readonly string $context = 'index',
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        AfsFilingItem::query()
            ->where('user_id', $this->userId)
            ->whereIn('id', $this->itemIds)
            ->whereNotNull('pdf_path')
            ->select('id')
            ->get();
    }
}
