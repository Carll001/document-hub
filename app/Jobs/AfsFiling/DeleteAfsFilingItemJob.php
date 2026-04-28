<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Models\AfsFilingItem;
use App\Support\DocumentStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteAfsFilingItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $userId,
        public readonly int $itemId,
    ) {
        $this->onQueue('afs-filing');
    }

    public function handle(): void
    {
        $item = AfsFilingItem::query()
            ->where('id', $this->itemId)
            ->where('user_id', $this->userId)
            ->first();

        if (! $item instanceof AfsFilingItem) {
            return;
        }

        $paths = array_filter([(string) $item->docx_path, (string) $item->pdf_path]);
        if ($paths !== []) {
            DocumentStorage::disk()->delete($paths);
        }

        $item->delete();
    }
}

