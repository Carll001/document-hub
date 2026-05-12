<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DocMerge\DocMergeBatchChunkUploadService;
use Illuminate\Console\Command;

class PurgeDocMergeChunkUploads extends Command
{
    protected $signature = 'doc-merge:purge-chunk-uploads';

    protected $description = 'Purge expired Doc Merge chunk upload sessions and temporary files.';

    public function handle(DocMergeBatchChunkUploadService $chunkUploadService): int
    {
        $deleted = $chunkUploadService->purgeExpired();
        $this->info("Purged {$deleted} chunk upload session(s).");

        return self::SUCCESS;
    }
}

