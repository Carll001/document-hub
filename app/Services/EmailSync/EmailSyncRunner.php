<?php

declare(strict_types=1);

namespace App\Services\EmailSync;

use App\Models\EmailSyncAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
     * @param  list<int>  $accountIds
     * @return array{
     *     results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, mailbox: string, skipped: bool, emailIds: list<int>}>,
     *     busyAccounts: list<string>
     * }
     */
    public function sync(array $accountIds = [], ?callable $shouldContinue = null): array
    {
        $lock = Cache::lock(self::aggregateLockKey(), 30);

        if (! $lock->get()) {
            throw new RuntimeException('Email sync is currently queued or running. Please wait for the current queue to finish, then try again.');
        }

        try {
            return $this->runAcrossAccountsManually($this->accountsFor($accountIds), null, $shouldContinue);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<int>  $accountIds
     * @return list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, mailbox: string, skipped: bool, emailIds: list<int>}>|null
     */
    public function syncIfAvailable(array $accountIds = []): ?array
    {
        $lock = Cache::lock(self::aggregateLockKey(), 30);

        if (! $lock->get()) {
            return null;
        }

        try {
            try {
                return $this->runAcrossAccounts($this->accountsFor($accountIds), null);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'No active mailbox accounts are configured.') {
                    return null;
                }

                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<int>  $accountIds
     * @return array{
     *     results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, mailbox: string, skipped: bool, emailIds: list<int>}>,
     *     busyAccounts: list<string>
     * }
     */
    public function backfill(CarbonImmutable $startDate, array $accountIds = [], ?callable $shouldContinue = null): array
    {
        $lock = Cache::lock(self::aggregateLockKey(), 30);

        if (! $lock->get()) {
            throw new RuntimeException('Email sync is currently queued or running. Please wait for the current queue to finish, then try again.');
        }

        try {
            return $this->runAcrossAccountsManually($this->accountsFor($accountIds), $startDate, $shouldContinue);
        } finally {
            $lock->release();
        }
    }

    public static function aggregateLockKey(): string
    {
        return 'email-sync:shared:aggregate-lock';
    }

    public static function accountLockKey(int $accountId): string
    {
        return "email-sync:account:{$accountId}:lock";
    }

    /**
     * @param  list<int>  $accountIds
     * @return Collection<int, EmailSyncAccount>
     */
    private function accountsFor(array $accountIds): Collection
    {
        $query = EmailSyncAccount::query()
            ->where('is_active', true)
            ->orderBy('display_name')
            ->orderBy('id');

        if ($accountIds !== []) {
            $query->whereIn('id', $accountIds);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            throw new RuntimeException('No active mailbox accounts are configured.');
        }

        return $accounts;
    }

    /**
     * @param  Collection<int, EmailSyncAccount>  $accounts
     * @return list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, mailbox: string, skipped: bool, emailIds: list<int>}>
     */
    private function runAcrossAccounts(Collection $accounts, ?CarbonImmutable $startDate): array
    {
        $results = [];

        foreach ($accounts as $account) {
            $lock = Cache::lock(self::accountLockKey((int) $account->getKey()), self::LOCK_TTL_SECONDS);

            if (! $lock->get()) {
                $results[] = [
                    'accountId' => (int) $account->getKey(),
                    'accountLabel' => $account->label(),
                    'fetched' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'mailbox' => (string) $account->mailbox,
                    'skipped' => true,
                    'emailIds' => [],
                ];

                continue;
            }

            try {
                $results[] = $startDate === null
                    ? $this->emailSyncService->syncAccount($account)
                    : $this->emailSyncService->backfillAccount($account, $startDate);
            } finally {
                $lock->release();
            }
        }

        return $results;
    }

    /**
     * @param  Collection<int, EmailSyncAccount>  $accounts
     * @return array{
     *     results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, mailbox: string, skipped: bool, emailIds: list<int>}>,
     *     busyAccounts: list<string>
     * }
     */
    private function runAcrossAccountsManually(
        Collection $accounts,
        ?CarbonImmutable $startDate,
        ?callable $shouldContinue = null,
    ): array {
        $results = [];
        $busyAccounts = [];

        foreach ($accounts as $account) {
            if ($shouldContinue !== null && ! $shouldContinue($account)) {
                continue;
            }

            $lock = Cache::lock(self::accountLockKey((int) $account->getKey()), self::LOCK_TTL_SECONDS);

            if (! $lock->get()) {
                $busyAccounts[] = $account->label();

                continue;
            }

            try {
                $results[] = $startDate === null
                    ? $this->emailSyncService->syncAccount($account, $shouldContinue)
                    : $this->emailSyncService->backfillAccount($account, $startDate, $shouldContinue);
            } finally {
                $lock->release();
            }
        }

        return [
            'results' => $results,
            'busyAccounts' => array_values(array_unique($busyAccounts)),
        ];
    }
}
