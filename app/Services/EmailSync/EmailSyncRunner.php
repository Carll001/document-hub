<?php

declare(strict_types=1);

namespace App\Services\EmailSync;

use Carbon\CarbonImmutable;
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
     * Run the shared inbox sync immediately, failing fast if it is already running.
     *
     * @return array{fetched: int, created: int, updated: int, mailbox: string}
     */
    public function sync(): array
    {
        $lock = Cache::lock(self::lockKey(), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException('Inbox sync is already running.');
        }

        try {
            return $this->emailSyncService->sync();
        } finally {
            $lock->release();
        }
    }

    /**
     * Attempt a shared sync for queued/background work and quietly skip if it is already running.
     *
     * @return array{fetched: int, created: int, updated: int, mailbox: string}|null
     */
    public function syncIfAvailable(): ?array
    {
        $lock = Cache::lock(self::lockKey(), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->emailSyncService->sync();
        } finally {
            $lock->release();
        }
    }

    /**
     * Run a shared inbox backfill immediately using the same shared lock.
     *
     * @return array{fetched: int, created: int, updated: int, mailbox: string}
     */
    public function backfill(CarbonImmutable $startDate): array
    {
        $lock = Cache::lock(self::lockKey(), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException('Inbox sync is already running.');
        }

        try {
            return $this->emailSyncService->backfill($startDate);
        } finally {
            $lock->release();
        }
    }

    public static function lockKey(): string
    {
        return 'email-sync:shared:lock';
    }
}
