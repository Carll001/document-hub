<?php

declare(strict_types=1);

namespace App\Services\EmailSync;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class EmailSyncRunner
{
    public const LOCK_TTL_SECONDS = 300;

    public function __construct(
        private readonly EmailSyncService $emailSyncService,
    ) {
    }

    /**
     * Run an inbox sync immediately, failing fast if the same user already has
     * a sync in progress.
     *
     * @return array{fetched: int, created: int, updated: int, mailbox: string}
     */
    public function sync(User $user): array
    {
        $lock = Cache::lock(self::lockKey($user), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException('Inbox sync is already running.');
        }

        try {
            return $this->emailSyncService->sync($user);
        } finally {
            $lock->release();
        }
    }

    /**
     * Attempt a sync for queued/background work and quietly skip if another
     * sync for the user is already running.
     *
     * @return array{fetched: int, created: int, updated: int, mailbox: string}|null
     */
    public function syncIfAvailable(User $user): ?array
    {
        $lock = Cache::lock(self::lockKey($user), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->emailSyncService->sync($user);
        } finally {
            $lock->release();
        }
    }

    public static function lockKey(User|int $user): string
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return "email-sync:user:{$userId}:lock";
    }
}
