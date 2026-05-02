<?php

declare(strict_types=1);

namespace App\Jobs\Filing;

use App\Models\FilingOutput;
use App\Support\DocumentStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteFilingOutputJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $filingOutputId,
    ) {
        $this->onQueue('afs-filing');
    }

    public function handle(): void
    {
        $output = FilingOutput::query()->find($this->filingOutputId);
        if (! $output instanceof FilingOutput) {
            return;
        }

        $filePath = trim((string) ($output->file_path ?? ''));
        if ($filePath !== '') {
            DocumentStorage::disk()->delete($filePath);
        }

        $output->delete();
    }
}

