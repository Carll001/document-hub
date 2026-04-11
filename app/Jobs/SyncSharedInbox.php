<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\EmailSync\EmailSyncRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSharedInbox implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(EmailSyncRunner $runner): void
    {
        $runner->syncIfAvailable();
    }
}
