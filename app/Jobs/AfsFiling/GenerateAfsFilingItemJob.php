<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Services\AfsFiling\AfsFilingItemGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAfsFilingItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $afsFilingItemId,
    ) {}

    public function handle(AfsFilingItemGenerationService $generationService): void
    {
        $generationService->generate($this->afsFilingItemId);
    }
}
