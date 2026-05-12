<?php

namespace Tests\Unit;

use App\Models\EmailSyncAccount;
use App\Services\EmailSync\EmailSyncRunner;
use App\Services\EmailSync\EmailSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailSyncRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_processes_work_in_global_batches_of_ten(): void
    {
        $accountOne = $this->createAccount('first@example.com');
        $accountTwo = $this->createAccount('second@example.com');

        $service = \Mockery::mock(EmailSyncService::class);
        $service->shouldReceive('uidsForAccountSync')->once()->withArgs(fn (EmailSyncAccount $a): bool => $a->id === $accountOne->id)->andReturn([1, 2, 3, 4, 5, 6, 7]);
        $service->shouldReceive('uidsForAccountSync')->once()->withArgs(fn (EmailSyncAccount $a): bool => $a->id === $accountTwo->id)->andReturn([11, 12, 13, 14, 15, 16, 17]);

        $receivedBatches = [];
        $service->shouldReceive('syncAccountUids')
            ->times(4)
            ->andReturnUsing(function (EmailSyncAccount $account, array $uids) use (&$receivedBatches): array {
                $receivedBatches[] = [
                    'accountId' => $account->id,
                    'uids' => $uids,
                ];

                return [
                    'accountId' => $account->id,
                    'accountLabel' => $account->label(),
                    'fetched' => count($uids),
                    'created' => count($uids),
                    'updated' => 0,
                    'filtered' => 0,
                    'mailbox' => (string) $account->mailbox,
                    'skipped' => false,
                    'emailIds' => [],
                ];
            });

        $runner = new EmailSyncRunner($service);
        $result = $runner->sync([$accountOne->id, $accountTwo->id]);

        $this->assertCount(4, $receivedBatches);
        $this->assertSame([1, 2, 3, 4, 5], $receivedBatches[0]['uids']);
        $this->assertSame([11, 12, 13, 14, 15], $receivedBatches[1]['uids']);
        $this->assertSame([6, 7], $receivedBatches[2]['uids']);
        $this->assertSame([16, 17], $receivedBatches[3]['uids']);

        $accountOneResult = collect($result['results'])->firstWhere('accountId', $accountOne->id);
        $accountTwoResult = collect($result['results'])->firstWhere('accountId', $accountTwo->id);

        $this->assertSame(7, $accountOneResult['fetched']);
        $this->assertSame(7, $accountTwoResult['fetched']);
        $this->assertSame([], $result['busyAccounts']);
    }

    public function test_sync_marks_busy_accounts_as_skipped_in_results(): void
    {
        $account = $this->createAccount('busy@example.com');
        $lock = cache()->lock(EmailSyncRunner::accountLockKey($account->id), EmailSyncRunner::LOCK_TTL_SECONDS);
        $this->assertTrue($lock->get());

        try {
            $service = \Mockery::mock(EmailSyncService::class);
            $service->shouldNotReceive('uidsForAccountSync');
            $service->shouldNotReceive('syncAccountUids');

            $runner = new EmailSyncRunner($service);
            $result = $runner->sync([$account->id]);

            $this->assertSame([$account->label()], $result['busyAccounts']);
            $this->assertTrue($result['results'][0]['skipped']);
            $this->assertSame(0, $result['results'][0]['filtered']);
        } finally {
            $lock->release();
        }
    }

    private function createAccount(string $username): EmailSyncAccount
    {
        return EmailSyncAccount::query()->create([
            'display_name' => '',
            'username' => $username,
            'password' => 'secret',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'mailbox' => 'INBOX',
            'validate_certificate' => true,
            'is_active' => true,
        ]);
    }
}
