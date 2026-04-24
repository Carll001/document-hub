<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Services\DocumentBatchItemGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateDocumentBatchItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $documentBatchItemId
    ) {}

    public function handle(DocumentBatchItemGenerationService $documentBatchItemGenerationService): void
    {
        $documentBatchItemGenerationService->generate($this->documentBatchItemId);
    }
}
