<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\EmailSync\EmailSyncRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncInboxForUser implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?int $userId = null,
    ) {
    }

    public function handle(EmailSyncRunner $runner): void
    {
        if ($this->userId !== null) {
            $user = User::query()->find($this->userId);

            if (! $user instanceof User || ! $user->isStaff()) {
                return;
            }
        } elseif (! User::query()->where('role', \App\Enums\UserRole::Staff)->exists()) {
            return;
        }

        $runner->syncIfAvailable();
    }
}
