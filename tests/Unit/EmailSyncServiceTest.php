<?php

namespace Tests\Unit;

use App\Models\SyncedEmail;
use App\Models\SyncedEmailAttachment;
use App\Models\User;
use App\Services\EmailSync\EmailSyncClient;
use App\Services\EmailSync\EmailSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class EmailSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_sync_imports_the_newest_twenty_five_emails()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $client = new FakeEmailSyncClient(
            latestUids: [9001, 9002],
            messages: [
                9001 => $this->messagePayload(9001, [
                    'attachments' => [[
                        'file_name' => 'brief.txt',
                        'content_type' => 'text/plain',
                        'content' => 'Quarterly brief',
                        'size' => 15,
                    ]],
                ]),
                9002 => $this->messagePayload(9002),
            ],
        );

        $service = $this->makeServiceWithClient($client);

        $result = $service->sync($user);

        $this->assertSame([['connect'], ['selectMailbox', 'INBOX'], ['latestUids', 25], ['fetchMessage', 9001], ['fetchMessage', 9002], ['disconnect']], $client->calls);
        $this->assertSame([
            'fetched' => 2,
            'created' => 2,
            'updated' => 0,
            'mailbox' => 'INBOX',
        ], $result);
        $this->assertDatabaseCount('synced_emails', 2);
        $this->assertDatabaseHas('synced_emails', [
            'user_id' => $user->id,
            'imap_uid' => '9001',
            'subject' => 'Message 9001',
        ]);
        $this->assertDatabaseHas('synced_email_attachments', [
            'file_name' => 'brief.txt',
            'content_type' => 'text/plain',
        ]);

        $attachment = SyncedEmailAttachment::query()->firstOrFail();

        Storage::disk('local')->assertExists($attachment->storage_path);
    }

    public function test_incremental_sync_only_fetches_uids_newer_than_the_latest_saved_email()
    {
        $user = User::factory()->create();

        SyncedEmail::query()->create([
            'user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '200',
            'message_id' => '<message-200@example.com>',
            'from_name' => 'Support Team',
            'from_email' => 'support@example.com',
            'subject' => 'Existing message',
            'body_preview' => 'Existing preview',
            'body_text' => 'Existing body',
            'received_at' => now()->subMinutes(30),
            'synced_at' => now(),
        ]);

        $client = new FakeEmailSyncClient(
            newerUids: [201, 202],
            messages: [
                201 => $this->messagePayload(201),
                202 => $this->messagePayload(202),
            ],
        );

        $service = $this->makeServiceWithClient($client);

        $result = $service->sync($user);

        $this->assertTrue($client->wasCalled('uidsNewerThan', [200]));
        $this->assertFalse($client->wasCalled('latestUids'));
        $this->assertSame(2, $result['created']);
        $this->assertDatabaseCount('synced_emails', 3);
        $this->assertDatabaseHas('synced_emails', [
            'user_id' => $user->id,
            'imap_uid' => '202',
        ]);
    }

    public function test_backfill_imports_older_emails_before_the_oldest_saved_uid()
    {
        $user = User::factory()->create();

        SyncedEmail::query()->create([
            'user_id' => $user->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '200',
            'message_id' => '<message-200@example.com>',
            'from_name' => 'Support Team',
            'from_email' => 'support@example.com',
            'subject' => 'Existing oldest message',
            'body_preview' => 'Existing preview',
            'body_text' => 'Existing body',
            'received_at' => now()->subMinutes(30),
            'synced_at' => now(),
        ]);

        $client = new FakeEmailSyncClient(
            olderUids: [150, 151],
            messages: [
                150 => $this->messagePayload(150),
                151 => $this->messagePayload(151),
            ],
        );

        $service = $this->makeServiceWithClient($client);

        $result = $service->backfill($user, 10);

        $this->assertTrue($client->wasCalled('olderUidsBefore', [200, 10]));
        $this->assertSame(2, $result['created']);
        $this->assertDatabaseCount('synced_emails', 3);
        $this->assertDatabaseHas('synced_emails', [
            'user_id' => $user->id,
            'imap_uid' => '150',
        ]);
    }

    public function test_backfill_requires_an_existing_sync_anchor()
    {
        $user = User::factory()->create();
        $service = $this->makeServiceWithClient(new FakeEmailSyncClient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sync inbox now first before importing older mail.');

        $service->backfill($user, null);
    }

    /**
     * @param  array{
     *     attachments?: list<array{file_name: string, content_type: string|null, content: string, size: int}>
     * }  $overrides
     * @return array{
     *     imap_uid: string,
     *     message_id: string,
     *     from_name: string,
     *     from_email: string,
     *     subject: string,
     *     received_at: CarbonImmutable,
     *     body_text: string,
     *     attachments: list<array{file_name: string, content_type: string|null, content: string, size: int}>
     * }
     */
    private function messagePayload(int $uid, array $overrides = []): array
    {
        return array_replace([
            'imap_uid' => (string) $uid,
            'message_id' => "<message-{$uid}@example.com>",
            'from_name' => 'Support Team',
            'from_email' => 'support@example.com',
            'subject' => "Message {$uid}",
            'received_at' => CarbonImmutable::parse('2026-03-24 10:00:00')->addMinutes($uid),
            'body_text' => "Body {$uid}",
            'attachments' => [],
        ], $overrides);
    }

    private function makeServiceWithClient(FakeEmailSyncClient $client): EmailSyncService
    {
        config([
            'services.email_sync' => [
                'host' => 'imap.gmail.com',
                'port' => 993,
                'username' => 'mailbox@example.com',
                'password' => 'app-password',
                'encryption' => 'ssl',
                'mailbox' => 'INBOX',
                'validate_certificate' => true,
            ],
        ]);

        return new class($client) extends EmailSyncService
        {
            public function __construct(private readonly FakeEmailSyncClient $client) {}

            protected function makeClient(array $config): EmailSyncClient
            {
                return $this->client;
            }
        };
    }
}

class FakeEmailSyncClient implements EmailSyncClient
{
    /**
     * @var list<array<int, int|string>>
     */
    public array $calls = [];

    /**
     * @param  list<int>  $latestUids
     * @param  list<int>  $newerUids
     * @param  list<int>  $olderUids
     * @param  array<int, array{
     *     imap_uid: string,
     *     message_id: string,
     *     from_name: string,
     *     from_email: string,
     *     subject: string,
     *     received_at: CarbonImmutable,
     *     body_text: string,
     *     attachments: list<array{file_name: string, content_type: string|null, content: string, size: int}>
     * }>  $messages
     */
    public function __construct(
        private readonly array $latestUids = [],
        private readonly array $newerUids = [],
        private readonly array $olderUids = [],
        private readonly array $messages = [],
    ) {}

    public function connect(): void
    {
        $this->calls[] = ['connect'];
    }

    public function selectMailbox(string $mailbox): void
    {
        $this->calls[] = ['selectMailbox', $mailbox];
    }

    public function latestUids(int $limit): array
    {
        $this->calls[] = ['latestUids', $limit];

        return $this->latestUids;
    }

    public function uidsNewerThan(int $uid): array
    {
        $this->calls[] = ['uidsNewerThan', $uid];

        return $this->newerUids;
    }

    public function olderUidsBefore(int $uid, int $limit = 0): array
    {
        $this->calls[] = ['olderUidsBefore', $uid, $limit];

        return $this->olderUids;
    }

    public function fetchMessage(int $uid): array
    {
        $this->calls[] = ['fetchMessage', $uid];

        return $this->messages[$uid];
    }

    public function disconnect(): void
    {
        $this->calls[] = ['disconnect'];
    }

    /**
     * @param  list<int>  $arguments
     */
    public function wasCalled(string $method, array $arguments = []): bool
    {
        foreach ($this->calls as $call) {
            if ($call[0] !== $method) {
                continue;
            }

            if ($arguments === []) {
                return true;
            }

            if (array_slice($call, 1) === $arguments) {
                return true;
            }
        }

        return false;
    }
}
