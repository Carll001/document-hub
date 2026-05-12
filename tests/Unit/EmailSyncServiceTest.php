<?php

namespace Tests\Unit;

use App\Models\EmailSyncAccount;
use App\Models\SyncedEmail;
use App\Services\EmailSync\BirReceiptAutoMatchService;
use App\Services\EmailSync\BirReceiptEmailParser;
use App\Services\EmailSync\EmailSyncClient;
use App\Services\EmailSync\EmailSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_account_persists_only_supported_confirmation_emails(): void
    {
        $account = $this->createAccount();
        $client = new FakeEmailSyncClient(
            latestUids: [101, 102, 103],
            messages: [
                101 => $this->messagePayload(101, [
                    'from_email' => 'ebirforms-noreply@bir.gov.ph',
                    'body_text' => $this->confirmationBody('010860961000-1702EXv2018C-122025.xml'),
                ]),
                102 => $this->messagePayload(102, [
                    'from_email' => 'other@bir.gov.ph',
                    'body_text' => $this->confirmationBody('010860961000-1702EXv2018C-122025.xml'),
                ]),
                103 => $this->messagePayload(103, [
                    'from_email' => 'ebirforms-noreply@bir.gov.ph',
                    'body_text' => 'Random body without receipt markers',
                ]),
            ],
        );

        $service = $this->makeServiceWithClient($client, $autoMatchCalls);
        $result = $service->syncAccount($account);

        $this->assertSame(3, $result['fetched']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, $result['filtered']);
        $this->assertSame(1, $autoMatchCalls);
        $this->assertDatabaseCount('synced_emails', 1);
        $this->assertDatabaseHas('synced_emails', [
            'email_sync_account_id' => $account->id,
            'imap_uid' => '101',
        ]);
    }

    public function test_sync_account_matches_sender_case_insensitively(): void
    {
        $account = $this->createAccount();
        $client = new FakeEmailSyncClient(
            latestUids: [201],
            messages: [
                201 => $this->messagePayload(201, [
                    'from_email' => 'EBIRFORMS-NOREPLY@BIR.GOV.PH',
                    'body_text' => $this->confirmationBody('010860961000-1702EXv2018C-122025.xml'),
                ]),
            ],
        );

        $service = $this->makeServiceWithClient($client, $autoMatchCalls);
        $result = $service->syncAccount($account);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['filtered']);
        $this->assertSame(1, $autoMatchCalls);
    }

    public function test_incremental_sync_uses_newer_uids_from_newest_saved_email(): void
    {
        $account = $this->createAccount();

        SyncedEmail::query()->create([
            'email_sync_account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '300',
            'message_id' => '<message-300@example.com>',
            'from_name' => 'BIR',
            'from_email' => 'ebirforms-noreply@bir.gov.ph',
            'subject' => 'Existing',
            'body_preview' => 'Existing',
            'body_text' => $this->confirmationBody('010860961000-1702EXv2018C-122025.xml'),
            'received_at' => now()->subMinute(),
            'synced_at' => now(),
        ]);

        $client = new FakeEmailSyncClient(
            newerUids: [301],
            messages: [
                301 => $this->messagePayload(301, [
                    'from_email' => 'ebirforms-noreply@bir.gov.ph',
                    'body_text' => $this->confirmationBody('010860961000-1702EXv2018C-122025.xml'),
                ]),
            ],
        );

        $service = $this->makeServiceWithClient($client, $autoMatchCalls);
        $result = $service->syncAccount($account);

        $this->assertTrue($client->wasCalled('uidsNewerThan', [300]));
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['filtered']);
        $this->assertSame(1, $autoMatchCalls);
    }

    private function createAccount(): EmailSyncAccount
    {
        return EmailSyncAccount::query()->create([
            'display_name' => 'Shared Inbox',
            'username' => 'sync@example.com',
            'password' => 'secret',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'mailbox' => 'INBOX',
            'validate_certificate' => true,
            'is_active' => true,
        ]);
    }

    private function confirmationBody(string $fileName): string
    {
        return "File name: {$fileName}\nDate received by BIR: 12 May 2026\nTime received by BIR: 09:30 AM";
    }

    /**
     * @param  array{
     *     from_email?: string,
     *     body_text?: string,
     *     body_html?: string|null
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
            'from_name' => 'BIR',
            'from_email' => 'ebirforms-noreply@bir.gov.ph',
            'subject' => "Message {$uid}",
            'received_at' => CarbonImmutable::parse('2026-05-12 10:00:00')->addMinutes($uid),
            'body_text' => $this->confirmationBody('010860961000-1702EXv2018C-122025.xml'),
            'body_html' => '<p>Receipt</p>',
            'attachments' => [],
        ], $overrides);
    }

    private function makeServiceWithClient(FakeEmailSyncClient $client, int &$autoMatchCalls): EmailSyncService
    {
        $autoMatchCalls = 0;
        $autoMatchService = \Mockery::mock(BirReceiptAutoMatchService::class);
        $autoMatchService->shouldReceive('syncEmail')
            ->andReturnUsing(function () use (&$autoMatchCalls): void {
                $autoMatchCalls++;
            });

        return new class($client, $autoMatchService, new BirReceiptEmailParser) extends EmailSyncService
        {
            public function __construct(
                private readonly FakeEmailSyncClient $client,
                BirReceiptAutoMatchService $birReceiptAutoMatchService,
                BirReceiptEmailParser $birReceiptEmailParser,
            ) {
                parent::__construct($birReceiptAutoMatchService, $birReceiptEmailParser);
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
     * @var list<array<int, mixed>>
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
     * @param  list<mixed>  $arguments
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
