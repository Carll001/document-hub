<?php

declare(strict_types=1);

namespace App\Contracts\Services;

interface DocumentBatchItemGenerationService
{
    public function generate(int $documentBatchItemId): void;
}
