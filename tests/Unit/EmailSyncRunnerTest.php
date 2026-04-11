<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\EmailSync\EmailSyncRunner;
use App\Services\EmailSync\EmailSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class EmailSyncRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_calls_the_sync_service_when_the_lock_is_available()
    {
        $user = User::factory()->create();

        $service = \Mockery::mock(EmailSyncService::class);
        $service->shouldReceive('sync')
            ->once()
            ->with(\Mockery::on(fn (User $candidate): bool => $candidate->is($user)))
            ->andReturn([
                'fetched' => 3,
                'created' => 2,
                'updated' => 1,
                'mailbox' => 'INBOX',
            ]);

        $runner = new EmailSyncRunner($service);

        $this->assertSame([
            'fetched' => 3,
            'created' => 2,
            'updated' => 1,
            'mailbox' => 'INBOX',
        ], $runner->sync($user));
    }

    public function test_sync_throws_a_busy_error_when_the_lock_is_already_held()
    {
        $user = User::factory()->create();

        $service = \Mockery::mock(EmailSyncService::class);
        $service->shouldNotReceive('sync');

        $lock = Cache::lock(
            EmailSyncRunner::lockKey($user),
            EmailSyncRunner::LOCK_TTL_SECONDS,
        );

        $this->assertTrue($lock->get());

        try {
            $runner = new EmailSyncRunner($service);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Inbox sync is already running.');

            $runner->sync($user);
        } finally {
            $lock->release();
        }
    }

    public function test_sync_if_available_quietly_skips_when_the_lock_is_already_held()
    {
        $user = User::factory()->create();

        $service = \Mockery::mock(EmailSyncService::class);
        $service->shouldNotReceive('sync');

        $lock = Cache::lock(
            EmailSyncRunner::lockKey($user),
            EmailSyncRunner::LOCK_TTL_SECONDS,
        );

        $this->assertTrue($lock->get());

        try {
            $runner = new EmailSyncRunner($service);

            $this->assertNull($runner->syncIfAvailable($user));
        } finally {
            $lock->release();
        }
    }
}
