<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Models\AfsFilingItem;
use App\Services\AfsFiling\AfsFilingItemGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAfsFilingItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Must exceed the LibreOffice process timeout (120s) so the process
    // timeout error is caught inside generate() before the worker is killed.
    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $afsFilingItemId,
    ) {}

    public function handle(AfsFilingItemGenerationService $generationService): void
    {
        $generationService->generate($this->afsFilingItemId);
    }

    // Called when the job is killed by a timeout or exhausts tries.
    // Ensures the item never stays stuck in queued/processing.
    public function failed(\Throwable $exception): void
    {
        $item = AfsFilingItem::query()->find($this->afsFilingItemId);
        if (! $item instanceof AfsFilingItem) {
            return;
        }

        if (in_array((string) $item->status, ['pdf_done', 'failed'], true)) {
            return;
        }

        $item->status = 'failed';
        $item->error_message = mb_substr($exception->getMessage(), 0, 2000);
        $item->completed_at = now();
        $item->save();
    }
}
