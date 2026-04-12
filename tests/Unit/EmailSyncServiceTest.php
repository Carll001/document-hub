<?php

namespace Tests\Unit;

use App\Models\SyncedEmail;
use App\Models\SyncedEmailAttachment;
use App\Services\EmailSync\BirReceiptAutoMatchService;
use App\Services\EmailSync\EmailSyncClient;
use App\Services\EmailSync\EmailSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_sync_imports_the_newest_twenty_five_emails()
    {
        Storage::fake('local');

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

        $result = $service->sync();

        $this->assertSame([['connect'], ['selectMailbox', 'INBOX'], ['latestUids', 25], ['fetchMessage', 9001], ['fetchMessage', 9002], ['disconnect']], $client->calls);
        $this->assertSame([
            'fetched' => 2,
            'created' => 2,
            'updated' => 0,
            'mailbox' => 'INBOX',
        ], $result);
        $this->assertDatabaseCount('synced_emails', 2);
        $this->assertDatabaseHas('synced_emails', [
            'imap_uid' => '9001',
            'subject' => 'Message 9001',
            'body_html' => '<p>Body 9001</p>',
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
        SyncedEmail::query()->create([
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

        $result = $service->sync();

        $this->assertTrue($client->wasCalled('uidsNewerThan', [200]));
        $this->assertFalse($client->wasCalled('latestUids'));
        $this->assertSame(2, $result['created']);
        $this->assertDatabaseCount('synced_emails', 3);
        $this->assertDatabaseHas('synced_emails', [
            'imap_uid' => '202',
        ]);
    }

    public function test_backfill_imports_emails_received_on_or_after_the_selected_date()
    {
        $client = new FakeEmailSyncClient(
            receivedSinceUids: [150, 151],
            messages: [
                150 => $this->messagePayload(150),
                151 => $this->messagePayload(151),
            ],
        );

        $service = $this->makeServiceWithClient($client);
        $startDate = CarbonImmutable::parse('2026-01-01');

        $result = $service->backfill($startDate);

        $this->assertTrue($client->wasCalled('uidsReceivedSince'));
        $this->assertSame(2, $result['created']);
        $this->assertDatabaseCount('synced_emails', 2);
        $this->assertDatabaseHas('synced_emails', [
            'imap_uid' => '150',
        ]);
    }

    public function test_backfill_allows_a_date_import_even_without_a_previous_sync_anchor()
    {
        $startDate = CarbonImmutable::parse('2026-01-01');
        $client = new FakeEmailSyncClient(receivedSinceUids: []);
        $service = $this->makeServiceWithClient($client);

        $result = $service->backfill($startDate);

        $this->assertSame([
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'mailbox' => 'INBOX',
        ], $result);
        $this->assertTrue($client->wasCalled('uidsReceivedSince'));
    }

    /**
     * @param  array{
     *     body_html?: string|null,
     *     attachments?: list<array{
     *         file_name: string,
     *         content_type: string|null,
     *         content: string,
     *         size: int,
     *         content_id?: string|null,
     *         is_inline?: bool
     *     }>
     * }  $overrides
     * @return array{
     *     imap_uid: string,
     *     message_id: string,
     *     from_name: string,
     *     from_email: string,
     *     subject: string,
     *     received_at: CarbonImmutable,
     *     body_text: string,
     *     body_html: string|null,
     *     attachments: list<array{
     *         file_name: string,
     *         content_type: string|null,
     *         content: string,
     *         size: int,
     *         content_id?: string|null,
     *         is_inline?: bool
     *     }>
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
            'body_html' => "<p>Body {$uid}</p>",
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

        $autoMatchService = \Mockery::mock(BirReceiptAutoMatchService::class);
        $autoMatchService->shouldReceive('syncEmail')->andReturnNull();

        return new class($client, $autoMatchService) extends EmailSyncService
        {
            public function __construct(
                private readonly FakeEmailSyncClient $client,
                BirReceiptAutoMatchService $birReceiptAutoMatchService,
            ) {
                parent::__construct($birReceiptAutoMatchService);
            }

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
     * @param  list<int>  $receivedSinceUids
     * @param  array<int, array{
     *     imap_uid: string,
     *     message_id: string,
     *     from_name: string,
     *     from_email: string,
     *     subject: string,
     *     received_at: CarbonImmutable,
     *     body_text: string,
     *     body_html: string|null,
     *     attachments: list<array{
     *         file_name: string,
     *         content_type: string|null,
     *         content: string,
     *         size: int,
     *         content_id?: string|null,
     *         is_inline?: bool
     *     }>
     * }>  $messages
     */
    public function __construct(
        private readonly array $latestUids = [],
        private readonly array $newerUids = [],
        private readonly array $olderUids = [],
        private readonly array $receivedSinceUids = [],
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

    public function uidsReceivedSince(CarbonImmutable $date): array
    {
        $this->calls[] = ['uidsReceivedSince', $date];

        return $this->receivedSinceUids;
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
