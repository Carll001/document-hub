<?php

namespace Tests\Unit;

use App\Services\EmailSync\EmailSyncRunner;
use App\Services\EmailSync\EmailSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class EmailSyncRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_calls_the_sync_service_when_the_lock_is_available()
    {
        $service = \Mockery::mock(EmailSyncService::class);
        $service->shouldReceive('sync')
            ->once()
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
        ], $runner->sync());
    }

    public function test_sync_throws_a_busy_error_when_the_lock_is_already_held()
    {
        $service = \Mockery::mock(EmailSyncService::class);
        $service->shouldNotReceive('sync');

        $lock = Cache::lock(
            EmailSyncRunner::lockKey(),
            EmailSyncRunner::LOCK_TTL_SECONDS,
        );

        $this->assertTrue($lock->get());

        try {
            $runner = new EmailSyncRunner($service);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Inbox sync is already running.');

            $runner->sync();
        } finally {
            $lock->release();
        }
    }

    public function test_sync_if_available_quietly_skips_when_the_lock_is_already_held()
    {
        $service = \Mockery::mock(EmailSyncService::class);
        $service->shouldNotReceive('sync');

        $lock = Cache::lock(
            EmailSyncRunner::lockKey(),
            EmailSyncRunner::LOCK_TTL_SECONDS,
        );

        $this->assertTrue($lock->get());

        try {
            $runner = new EmailSyncRunner($service);

            $this->assertNull($runner->syncIfAvailable());
        } finally {
            $lock->release();
        }
    }

    public function test_backfill_calls_the_sync_service_with_the_selected_start_date_when_the_lock_is_available()
    {
        $startDate = CarbonImmutable::parse('2026-01-01');

        $service = \Mockery::mock(EmailSyncService::class);
        $service->shouldReceive('backfill')
            ->once()
            ->with(\Mockery::on(
                fn ($candidate): bool => $candidate instanceof CarbonImmutable
                    && $candidate->equalTo($startDate),
            ))
            ->andReturn([
                'fetched' => 3,
                'created' => 3,
                'updated' => 0,
                'mailbox' => 'INBOX',
            ]);

        $runner = new EmailSyncRunner($service);

        $this->assertSame([
            'fetched' => 3,
            'created' => 3,
            'updated' => 0,
            'mailbox' => 'INBOX',
        ], $runner->backfill($startDate));
    }

    public function test_backfill_throws_a_busy_error_when_the_lock_is_already_held()
    {
        $service = \Mockery::mock(EmailSyncService::class);
        $service->shouldNotReceive('backfill');

        $lock = Cache::lock(
            EmailSyncRunner::lockKey(),
            EmailSyncRunner::LOCK_TTL_SECONDS,
        );

        $this->assertTrue($lock->get());

        try {
            $runner = new EmailSyncRunner($service);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Inbox sync is already running.');

            $runner->backfill(CarbonImmutable::parse('2026-01-01'));
        } finally {
            $lock->release();
        }
    }
}
