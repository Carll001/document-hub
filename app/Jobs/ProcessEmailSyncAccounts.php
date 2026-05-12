<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmailSyncAccount;
use App\Services\EmailSync\EmailSyncRunner;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ProcessEmailSyncAccounts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;
    public bool $failOnTimeout = true;

    /**
     * @param  list<int>  $accountIds
     */
    public function __construct(
        public readonly array $accountIds,
        public readonly string $actionLabel,
        public readonly string $runUuid,
        public readonly ?string $startDate = null,
    ) {
        $this->onQueue('email-sync');
    }

    public function handle(EmailSyncRunner $runner): void
    {
        $accounts = EmailSyncAccount::query()
            ->whereIn('id', $this->accountIds)
            ->where('processing_run_uuid', $this->runUuid)
            ->whereIn('processing_status', [
                EmailSyncAccount::PROCESSING_STATUS_QUEUED,
                EmailSyncAccount::PROCESSING_STATUS_PROCESSING,
            ])
            ->get();

        if ($accounts->isEmpty()) {
            return;
        }

        EmailSyncAccount::query()
            ->whereIn('id', $accounts->modelKeys())
            ->where('processing_run_uuid', $this->runUuid)
            ->update([
                'processing_status' => EmailSyncAccount::PROCESSING_STATUS_PROCESSING,
                'processing_error' => null,
            ]);

        try {
            $remainingUidsByAccount = Cache::get($this->remainingUidsCacheKey());
            $result = $this->startDate === null
                ? $runner->syncSingleBatch(
                    $accounts->modelKeys(),
                    $this->shouldContinue(...),
                    is_array($remainingUidsByAccount) ? $remainingUidsByAccount : null,
                )
                : $runner->backfillSingleBatch(
                    CarbonImmutable::createFromFormat('Y-m-d', $this->startDate)->startOfDay(),
                    $accounts->modelKeys(),
                    $this->shouldContinue(...),
                    is_array($remainingUidsByAccount) ? $remainingUidsByAccount : null,
                );

            $aggregate = $this->mergeAggregate(
                is_array(Cache::get($this->aggregateCacheKey()))
                    ? Cache::get($this->aggregateCacheKey())
                    : [],
                is_array($result['results'] ?? null)
                    ? $result['results']
                    : [],
                is_array($result['busyAccounts'] ?? null)
                    ? $result['busyAccounts']
                    : [],
            );

            Cache::put($this->aggregateCacheKey(), $aggregate, now()->addHour());
            Cache::put(
                $this->remainingUidsCacheKey(),
                is_array($result['remainingUidsByAccount'] ?? null) ? $result['remainingUidsByAccount'] : [],
                now()->addHour(),
            );

            if ((bool) ($result['hasMore'] ?? false)) {
                self::dispatch(
                    $this->accountIds,
                    $this->actionLabel,
                    $this->runUuid,
                    $this->startDate,
                )->onQueue('email-sync')->afterCommit();

                return;
            }

            $resultsByAccountId = collect($aggregate['results'] ?? [])
                ->keyBy(fn (array $item): int => (int) $item['accountId']);
            $busyLabels = collect($aggregate['busyAccounts'] ?? [])
                ->filter(fn (mixed $label): bool => is_string($label) && $label !== '');

            foreach ($accounts as $account) {
                if (! $this->shouldContinue($account)) {
                    continue;
                }

                $resultForAccount = $resultsByAccountId->get((int) $account->getKey());

                if (is_array($resultForAccount)) {
                    $account->forceFill([
                        'processing_status' => null,
                        'processing_action' => null,
                        'processing_run_uuid' => null,
                        'processing_error' => null,
                        'processing_started_at' => null,
                        'last_sync_run_uuid' => $this->runUuid,
                        'last_sync_action' => $this->actionLabel,
                        'last_sync_fetched_count' => (int) $resultForAccount['fetched'],
                        'last_sync_created_count' => (int) $resultForAccount['created'],
                        'last_sync_updated_count' => (int) $resultForAccount['updated'],
                        'last_sync_completed_at' => now(),
                    ])->save();

                    continue;
                }

                $message = $busyLabels->contains($account->label())
                    ? 'This mailbox account is already syncing. Please wait for the current queue to finish, then try again.'
                    : 'The email sync could not be processed right now.';

                $account->forceFill([
                    'processing_status' => EmailSyncAccount::PROCESSING_STATUS_FAILED,
                    'processing_error' => $message,
                ])->save();
            }

            Cache::forget($this->aggregateCacheKey());
            Cache::forget($this->remainingUidsCacheKey());
            Cache::forget($this->runMetaCacheKey());
        } catch (\Throwable $exception) {
            $message = $this->runtimeMessage($exception);

            EmailSyncAccount::query()
                ->whereIn('id', $accounts->modelKeys())
                ->where('processing_run_uuid', $this->runUuid)
                ->update([
                    'processing_status' => EmailSyncAccount::PROCESSING_STATUS_FAILED,
                    'processing_error' => $message,
                ]);

            Cache::forget($this->aggregateCacheKey());
            Cache::forget($this->remainingUidsCacheKey());
            Cache::forget($this->runMetaCacheKey());

            throw $exception;
        }
    }

    private function aggregateCacheKey(): string
    {
        return "email-sync:run:{$this->runUuid}:aggregate";
    }

    private function remainingUidsCacheKey(): string
    {
        return "email-sync:run:{$this->runUuid}:remaining-uids";
    }

    private function runMetaCacheKey(): string
    {
        return "email-sync:run:{$this->runUuid}:meta";
    }

    /**
     * @param  array{
     *     results?: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>,
     *     busyAccounts?: list<string>
     * }  $current
     * @param  list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>  $partialResults
     * @param  list<string>  $partialBusyAccounts
     * @return array{
     *     results: list<array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, filtered: int, mailbox: string, skipped: bool, emailIds: list<int>}>,
     *     busyAccounts: list<string>
     * }
     */
    private function mergeAggregate(array $current, array $partialResults, array $partialBusyAccounts): array
    {
        $currentByAccount = collect($current['results'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && isset($item['accountId']))
            ->keyBy(fn (array $item): int => (int) $item['accountId']);

        foreach ($partialResults as $partial) {
            $accountId = (int) ($partial['accountId'] ?? 0);

            if ($accountId <= 0) {
                continue;
            }

            $existing = $currentByAccount->get($accountId);

            if (! is_array($existing)) {
                $currentByAccount->put($accountId, $partial);
                continue;
            }

            $currentByAccount->put($accountId, [
                'accountId' => $existing['accountId'],
                'accountLabel' => $existing['accountLabel'],
                'fetched' => (int) $existing['fetched'] + (int) ($partial['fetched'] ?? 0),
                'created' => (int) $existing['created'] + (int) ($partial['created'] ?? 0),
                'updated' => (int) $existing['updated'] + (int) ($partial['updated'] ?? 0),
                'filtered' => (int) $existing['filtered'] + (int) ($partial['filtered'] ?? 0),
                'mailbox' => $existing['mailbox'],
                'skipped' => (bool) $existing['skipped'] || (bool) ($partial['skipped'] ?? false),
                'emailIds' => array_values(array_unique(array_merge(
                    is_array($existing['emailIds'] ?? null) ? $existing['emailIds'] : [],
                    is_array($partial['emailIds'] ?? null) ? $partial['emailIds'] : [],
                ))),
            ]);
        }

        $busyAccounts = array_values(array_unique(array_merge(
            is_array($current['busyAccounts'] ?? null) ? $current['busyAccounts'] : [],
            $partialBusyAccounts,
        )));

        return [
            'results' => array_values($currentByAccount->all()),
            'busyAccounts' => $busyAccounts,
        ];
    }

    private function runtimeMessage(\Throwable $exception): string
    {
        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'The email sync could not be processed right now.';
    }

    private function shouldContinue(EmailSyncAccount $account): bool
    {
        return EmailSyncAccount::query()
            ->whereKey($account->getKey())
            ->where('processing_run_uuid', $this->runUuid)
            ->whereIn('processing_status', [
                EmailSyncAccount::PROCESSING_STATUS_QUEUED,
                EmailSyncAccount::PROCESSING_STATUS_PROCESSING,
            ])
            ->exists();
    }
}
