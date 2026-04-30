<?php

declare(strict_types=1);

namespace App\Jobs\AfsFiling;

use App\Services\AfsFiling\AfsFilingImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAfsFilingUploadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $userId,
        public readonly string $excelPath,
        public readonly string $excelOriginalName,
    ) {
        $this->onQueue('afs-filing');
    }

    public function handle(AfsFilingImportService $importService): void
    {
        $importService->importStoredUpload(
            $this->userId,
            $this->excelPath,
            $this->excelOriginalName,
        );
    }
}

