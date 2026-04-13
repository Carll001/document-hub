<?php

namespace App\Services\EmailSync;

use App\Models\EmailSyncAccount;
use App\Models\SyncedEmail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class EmailSyncService
{
    public const INITIAL_SYNC_LIMIT = 25;

    public function __construct(
        private readonly BirReceiptAutoMatchService $birReceiptAutoMatchService,
    ) {
    }

    /**
     * @return array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, mailbox: string, skipped: bool}
     */
    public function syncAccount(EmailSyncAccount $account): array
    {
        $config = $this->configuration($account);
        $mailbox = $config['mailbox'];
        $newestSyncedUid = $this->newestSyncedUid($account, $mailbox);
        $client = $this->makeClient($config);

        try {
            $client->connect();
            $client->selectMailbox($mailbox);

            $uids = $newestSyncedUid === null
                ? $client->latestUids(self::INITIAL_SYNC_LIMIT)
                : $client->uidsNewerThan($newestSyncedUid);

            return $this->syncUids($account, $mailbox, $client, $uids);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, mailbox: string, skipped: bool}
     */
    public function backfillAccount(EmailSyncAccount $account, CarbonImmutable $startDate): array
    {
        $config = $this->configuration($account);
        $mailbox = $config['mailbox'];
        $client = $this->makeClient($config);

        try {
            $client->connect();
            $client->selectMailbox($mailbox);

            $uids = $client->uidsReceivedSince($startDate->startOfDay());

            return $this->syncUids($account, $mailbox, $client, $uids);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Create the IMAP client used for inbox sync operations.
     *
     * @param  array{
     *     host: string,
     *     port: int,
     *     username: string,
     *     password: string,
     *     encryption: string,
     *     mailbox: string,
     *     validate_certificate: bool
     * }  $config
     */
    protected function makeClient(array $config): EmailSyncClient
    {
        return new GmailImapClient(
            host: $config['host'],
            port: $config['port'],
            username: $config['username'],
            password: $config['password'],
            encryption: $config['encryption'],
            validateCertificate: $config['validate_certificate'],
        );
    }

    /**
     * @param  list<int>  $uids
     * @return array{accountId: int, accountLabel: string, fetched: int, created: int, updated: int, mailbox: string, skipped: bool}
     */
    private function syncUids(
        EmailSyncAccount $account,
        string $mailbox,
        EmailSyncClient $client,
        array $uids,
    ): array {
        $created = 0;
        $updated = 0;
        $fetched = 0;
        $syncedAt = now();

        foreach ($uids as $uid) {
            $message = $client->fetchMessage($uid);
            $fetched++;

            $email = SyncedEmail::query()->updateOrCreate(
                [
                    'email_sync_account_id' => $account->getKey(),
                    'mailbox' => $mailbox,
                    'imap_uid' => $message['imap_uid'],
                ],
                [
                    'message_id' => $message['message_id'],
                    'from_name' => $message['from_name'],
                    'from_email' => $message['from_email'],
                    'subject' => $message['subject'],
                    'received_at' => $message['received_at'],
                    'body_text' => $message['body_text'],
                    'body_html' => $message['body_html'],
                    'body_preview' => $this->previewFromBody($message['body_text']),
                    'synced_at' => $syncedAt,
                ],
            );

            if ($email->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }

            $this->syncAttachments($email, $message['attachments']);
            $this->birReceiptAutoMatchService->syncEmail($email);
        }

        return [
            'accountId' => (int) $account->getKey(),
            'accountLabel' => $account->label(),
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'mailbox' => $mailbox,
            'skipped' => false,
        ];
    }

    private function newestSyncedUid(EmailSyncAccount $account, string $mailbox): ?int
    {
        $imapUid = $this->mailboxQuery($account, $mailbox)
            ->orderByRaw('CAST(imap_uid AS BIGINT) DESC')
            ->value('imap_uid');

        return $imapUid !== null ? (int) $imapUid : null;
    }

    private function mailboxQuery(EmailSyncAccount $account, string $mailbox): Builder
    {
        return SyncedEmail::query()
            ->where('email_sync_account_id', $account->getKey())
            ->where('mailbox', $mailbox);
    }

    private function previewFromBody(?string $bodyText): ?string
    {
        $bodyText = preg_replace('/\s+/u', ' ', trim((string) $bodyText)) ?? '';

        if ($bodyText === '') {
            return null;
        }

        return Str::limit($bodyText, 240);
    }

    /**
     * @param  list<array{file_name: string, content_type: string|null, content: string, size: int, content_id: string|null, is_inline: bool}>  $attachments
     */
    private function syncAttachments(SyncedEmail $email, array $attachments): void
    {
        $disk = Storage::disk('local');
        $directory = "email-sync/shared/{$email->id}";

        $disk->deleteDirectory($directory);
        $email->attachments()->delete();

        foreach ($attachments as $index => $attachment) {
            $fileName = $this->safeAttachmentFilename($attachment['file_name'], $index + 1);
            $storagePath = "{$directory}/".sprintf('%02d-%s', $index + 1, $fileName);

            $disk->put($storagePath, $attachment['content']);

            $email->attachments()->create([
                'file_name' => $attachment['file_name'],
                'storage_path' => $storagePath,
                'content_type' => $attachment['content_type'],
                'content_id' => $attachment['content_id'] ?? null,
                'is_inline' => $attachment['is_inline'] ?? false,
                'file_size' => $attachment['size'],
            ]);
        }
    }

    private function safeAttachmentFilename(string $fileName, int $position): string
    {
        $extension = Str::of(pathinfo($fileName, PATHINFO_EXTENSION))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();

        $baseName = Str::of(pathinfo($fileName, PATHINFO_FILENAME))
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-._')
            ->value();

        if ($baseName === '') {
            $baseName = "attachment-{$position}";
        }

        return $extension !== ''
            ? "{$baseName}.{$extension}"
            : $baseName;
    }

    /**
     * @return array{
     *     host: string,
     *     port: int,
     *     username: string,
     *     password: string,
     *     encryption: string,
     *     mailbox: string,
     *     validate_certificate: bool
     * }
     */
    private function configuration(EmailSyncAccount $account): array
    {
        $username = trim((string) $account->username);
        $password = trim((string) $account->password);

        if ($username === '') {
            throw new RuntimeException("Email sync account {$account->label()} is missing a username.");
        }

        if ($password === '') {
            throw new RuntimeException("Email sync account {$account->label()} is missing a password.");
        }

        return [
            'host' => trim((string) $account->host),
            'port' => (int) $account->port,
            'username' => $username,
            'password' => $password,
            'encryption' => $account->encryption === 'none'
                ? ''
                : trim((string) $account->encryption),
            'mailbox' => trim((string) $account->mailbox),
            'validate_certificate' => (bool) $account->validate_certificate,
        ];
    }
}
