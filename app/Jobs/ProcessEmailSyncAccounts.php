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

class ProcessEmailSyncAccounts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

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
            $result = $this->startDate === null
                ? $runner->sync($accounts->modelKeys(), $this->shouldContinue(...))
                : $runner->backfill(
                    CarbonImmutable::createFromFormat('Y-m-d', $this->startDate)->startOfDay(),
                    $accounts->modelKeys(),
                    $this->shouldContinue(...),
                );

            $resultsByAccountId = collect($result['results'] ?? [])
                ->keyBy(fn (array $item): int => (int) $item['accountId']);
            $busyLabels = collect($result['busyAccounts'] ?? [])
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
        } catch (\Throwable $exception) {
            $message = $this->runtimeMessage($exception);

            EmailSyncAccount::query()
                ->whereIn('id', $accounts->modelKeys())
                ->where('processing_run_uuid', $this->runUuid)
                ->update([
                    'processing_status' => EmailSyncAccount::PROCESSING_STATUS_FAILED,
                    'processing_error' => $message,
                ]);

            throw $exception;
        }
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
