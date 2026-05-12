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
    public const GLOBAL_UID_BATCH_SIZE = 10;

    public function __construct(
        private readonly EmailSyncService $emailSyncService,
    ) {
    }

    /**
     * @param  list<int>  $accountIds
     * @return array{results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>, busyAccounts: list<string>}
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
     * @return list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>|null
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
     * @return array{results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>, busyAccounts: list<string>}
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

    /**
     * @param  list<int>  $accountIds
     * @param  array<int, list<int>>|null  $remainingUidsByAccount
     * @return array{
     *     results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>,
     *     busyAccounts: list<string>,
     *     remainingUidsByAccount: array<int, list<int>>,
     *     hasMore: bool
     * }
     */
    public function syncSingleBatch(
        array $accountIds = [],
        ?callable $shouldContinue = null,
        ?array $remainingUidsByAccount = null,
    ): array {
        $lock = Cache::lock(self::aggregateLockKey(), 30);

        if (! $lock->get()) {
            throw new RuntimeException('Email sync is currently queued or running. Please wait for the current queue to finish, then try again.');
        }

        try {
            return $this->runSingleGlobalBatch(
                $this->accountsFor($accountIds),
                null,
                $shouldContinue,
                $remainingUidsByAccount,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<int>  $accountIds
     * @param  array<int, list<int>>|null  $remainingUidsByAccount
     * @return array{
     *     results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>,
     *     busyAccounts: list<string>,
     *     remainingUidsByAccount: array<int, list<int>>,
     *     hasMore: bool
     * }
     */
    public function backfillSingleBatch(
        CarbonImmutable $startDate,
        array $accountIds = [],
        ?callable $shouldContinue = null,
        ?array $remainingUidsByAccount = null,
    ): array {
        $lock = Cache::lock(self::aggregateLockKey(), 30);

        if (! $lock->get()) {
            throw new RuntimeException('Email sync is currently queued or running. Please wait for the current queue to finish, then try again.');
        }

        try {
            return $this->runSingleGlobalBatch(
                $this->accountsFor($accountIds),
                $startDate,
                $shouldContinue,
                $remainingUidsByAccount,
            );
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
     * @return list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>
     */
    private function runAcrossAccounts(Collection $accounts, ?CarbonImmutable $startDate): array
    {
        $result = $this->runAcrossAccountsInGlobalChunks($accounts, $startDate, null, false);

        return $result['results'];
    }

    /**
     * @param  Collection<int, EmailSyncAccount>  $accounts
     * @return array{results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>, busyAccounts: list<string>}
     */
    private function runAcrossAccountsManually(
        Collection $accounts,
        ?CarbonImmutable $startDate,
        ?callable $shouldContinue = null,
    ): array {
        return $this->runAcrossAccountsInGlobalChunks($accounts, $startDate, $shouldContinue, true);
    }

    /**
     * @param  Collection<int, EmailSyncAccount>  $accounts
     * @return array{results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>, busyAccounts: list<string>}
     */
    private function runAcrossAccountsInGlobalChunks(
        Collection $accounts,
        ?CarbonImmutable $startDate,
        ?callable $shouldContinue,
        bool $collectBusyAccounts,
    ): array {
        $resultsByAccountId = [];
        $busyAccounts = [];
        $locksByAccountId = [];
        $uidsByAccountId = [];

        foreach ($accounts as $account) {
            if ($shouldContinue !== null && ! $shouldContinue($account)) {
                continue;
            }

            $accountId = (int) $account->getKey();
            $resultsByAccountId[$accountId] = $this->emptyResultForAccount($account);

            $lock = Cache::lock(self::accountLockKey($accountId), self::LOCK_TTL_SECONDS);

            if (! $lock->get()) {
                $resultsByAccountId[$accountId]['skipped'] = true;

                if ($collectBusyAccounts) {
                    $busyAccounts[] = $account->label();
                }

                continue;
            }

            $locksByAccountId[$accountId] = $lock;

            $uidsByAccountId[$accountId] = $startDate === null
                ? $this->emailSyncService->uidsForAccountSync($account)
                : $this->emailSyncService->uidsForAccountBackfill($account, $startDate);
        }

        try {
            $pendingAccountIds = array_values(array_filter(
                array_keys($uidsByAccountId),
                fn (int $accountId): bool => ($uidsByAccountId[$accountId] ?? []) !== [],
            ));

            while ($pendingAccountIds !== []) {
                $batch = $this->nextGlobalBatch($pendingAccountIds, $uidsByAccountId);

                foreach ($batch as $accountId => $uids) {
                    $account = $accounts->firstWhere('id', $accountId);

                    if (! $account instanceof EmailSyncAccount) {
                        continue;
                    }

                    if ($shouldContinue !== null && ! $shouldContinue($account)) {
                        continue;
                    }

                    $partial = $this->emailSyncService->syncAccountUids($account, $uids, $shouldContinue);
                    $resultsByAccountId[$accountId] = $this->mergeResults(
                        $resultsByAccountId[$accountId] ?? $this->emptyResultForAccount($account),
                        $partial,
                    );
                }
            }
        } finally {
            foreach ($locksByAccountId as $lock) {
                $lock->release();
            }
        }

        return [
            'results' => array_values($resultsByAccountId),
            'busyAccounts' => array_values(array_unique($busyAccounts)),
        ];
    }

    /**
     * @param  Collection<int, EmailSyncAccount>  $accounts
     * @param  array<int, list<int>>|null  $remainingUidsByAccount
     * @return array{
     *     results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>,
     *     busyAccounts: list<string>,
     *     remainingUidsByAccount: array<int, list<int>>,
     *     hasMore: bool
     * }
     */
    private function runSingleGlobalBatch(
        Collection $accounts,
        ?CarbonImmutable $startDate,
        ?callable $shouldContinue,
        ?array $remainingUidsByAccount,
    ): array {
        $resultsByAccountId = [];
        $busyAccounts = [];
        $locksByAccountId = [];
        $uidsByAccountId = [];

        foreach ($accounts as $account) {
            if ($shouldContinue !== null && ! $shouldContinue($account)) {
                continue;
            }

            $accountId = (int) $account->getKey();
            $resultsByAccountId[$accountId] = $this->emptyResultForAccount($account);

            $lock = Cache::lock(self::accountLockKey($accountId), self::LOCK_TTL_SECONDS);

            if (! $lock->get()) {
                $resultsByAccountId[$accountId]['skipped'] = true;
                $busyAccounts[] = $account->label();

                continue;
            }

            $locksByAccountId[$accountId] = $lock;

            if (is_array($remainingUidsByAccount)) {
                $uidsByAccountId[$accountId] = array_values(array_map(
                    static fn (mixed $uid): int => (int) $uid,
                    $remainingUidsByAccount[$accountId] ?? [],
                ));
            } else {
                $uidsByAccountId[$accountId] = $startDate === null
                    ? $this->emailSyncService->uidsForAccountSync($account)
                    : $this->emailSyncService->uidsForAccountBackfill($account, $startDate);
            }
        }

        try {
            $pendingAccountIds = array_values(array_filter(
                array_keys($uidsByAccountId),
                fn (int $accountId): bool => ($uidsByAccountId[$accountId] ?? []) !== [],
            ));

            $batch = $this->nextGlobalBatch($pendingAccountIds, $uidsByAccountId);

            foreach ($batch as $accountId => $uids) {
                $account = $accounts->firstWhere('id', $accountId);

                if (! $account instanceof EmailSyncAccount) {
                    continue;
                }

                if ($shouldContinue !== null && ! $shouldContinue($account)) {
                    continue;
                }

                $partial = $this->emailSyncService->syncAccountUids($account, $uids, $shouldContinue);
                $resultsByAccountId[$accountId] = $this->mergeResults(
                    $resultsByAccountId[$accountId] ?? $this->emptyResultForAccount($account),
                    $partial,
                );
            }
        } finally {
            foreach ($locksByAccountId as $lock) {
                $lock->release();
            }
        }

        $remaining = [];
        foreach ($uidsByAccountId as $accountId => $uids) {
            if ($uids !== []) {
                $remaining[(int) $accountId] = array_values($uids);
            }
        }

        return [
            'results' => array_values($resultsByAccountId),
            'busyAccounts' => array_values(array_unique($busyAccounts)),
            'remainingUidsByAccount' => $remaining,
            'hasMore' => $remaining !== [],
        ];
    }

    /**
     * @param  list<int>  $pendingAccountIds
     * @param  array<int, list<int>>  $uidsByAccountId
     * @return array<int, list<int>>
     */
    private function nextGlobalBatch(array &$pendingAccountIds, array &$uidsByAccountId): array
    {
        $batch = [];
        $remainingSlots = self::GLOBAL_UID_BATCH_SIZE;
        $queue = array_values($pendingAccountIds);

        while ($remainingSlots > 0 && $queue !== []) {
            $nextQueue = [];
            $processedAny = false;

            foreach ($queue as $accountId) {
                if ($remainingSlots <= 0) {
                    $nextQueue[] = $accountId;

                    continue;
                }

                $uids = $uidsByAccountId[$accountId] ?? [];

                if ($uids === []) {
                    continue;
                }

                $batch[$accountId][] = array_shift($uids);
                $uidsByAccountId[$accountId] = $uids;
                $remainingSlots--;
                $processedAny = true;

                if ($uids !== []) {
                    $nextQueue[] = $accountId;
                }
            }

            if (! $processedAny) {
                break;
            }

            $queue = $nextQueue;
        }

        $pendingAccountIds = $queue;

        return $batch;
    }

    /**
     * @return array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}
     */
    private function emptyResultForAccount(EmailSyncAccount $account): array
    {
        return [
            'accountId' => (int) $account->getKey(),
            'accountLabel' => $account->label(),
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'filtered' => 0,
            'mailbox' => (string) $account->mailbox,
            'skipped' => false,
            'emailIds' => [],
        ];
    }

    /**
     * @param  array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}  $current
     * @param  array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}  $partial
     * @return array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}
     */
    private function mergeResults(array $current, array $partial): array
    {
        return [
            'accountId' => $current['accountId'],
            'accountLabel' => $current['accountLabel'],
            'fetched' => (int) $current['fetched'] + (int) $partial['fetched'],
            'created' => (int) $current['created'] + (int) $partial['created'],
            'updated' => (int) $current['updated'] + (int) $partial['updated'],
            'filtered' => (int) $current['filtered'] + (int) $partial['filtered'],
            'mailbox' => $current['mailbox'],
            'skipped' => (bool) $current['skipped'],
            'emailIds' => array_values(array_unique(array_merge(
                $current['emailIds'],
                $partial['emailIds'],
            ))),
        ];
    }
}
