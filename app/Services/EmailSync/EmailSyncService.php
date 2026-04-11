<?php

namespace App\Services\EmailSync;

use App\Models\SyncedEmail;
use App\Models\User;
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
     * Incrementally sync newer Gmail inbox messages into the local database.
     *
     * @return array{fetched: int, created: int, updated: int, mailbox: string}
     */
    public function sync(User $user): array
    {
        $config = $this->configuration();
        $mailbox = $config['mailbox'];
        $newestSyncedUid = $this->newestSyncedUid($user, $mailbox);

        $client = $this->makeClient($config);

        try {
            $client->connect();
            $client->selectMailbox($mailbox);

            $uids = $newestSyncedUid === null
                ? $client->latestUids(self::INITIAL_SYNC_LIMIT)
                : $client->uidsNewerThan($newestSyncedUid);

            return $this->syncUids($user, $mailbox, $client, $uids);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Backfill older Gmail inbox messages into the local database.
     *
     * @return array{fetched: int, created: int, updated: int, mailbox: string}
     */
    public function backfill(User $user, CarbonImmutable $startDate): array
    {
        $config = $this->configuration();
        $mailbox = $config['mailbox'];
        $client = $this->makeClient($config);

        try {
            $client->connect();
            $client->selectMailbox($mailbox);

            $uids = $client->uidsReceivedSince($startDate->startOfDay());

            return $this->syncUids($user, $mailbox, $client, $uids);
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
     * Persist the fetched IMAP UIDs for a mailbox.
     *
     * @param  list<int>  $uids
     * @return array{fetched: int, created: int, updated: int, mailbox: string}
     */
    private function syncUids(User $user, string $mailbox, EmailSyncClient $client, array $uids): array
    {
        $created = 0;
        $updated = 0;
        $fetched = 0;
        $syncedAt = now();

        foreach ($uids as $uid) {
            $message = $client->fetchMessage($uid);
            $fetched++;

            $email = SyncedEmail::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
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
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'mailbox' => $mailbox,
        ];
    }

    /**
     * Get the newest synced IMAP UID for the user and mailbox.
     */
    private function newestSyncedUid(User $user, string $mailbox): ?int
    {
        $imapUid = $this->mailboxQuery($user, $mailbox)
            ->orderByRaw('CAST(imap_uid AS BIGINT) DESC')
            ->value('imap_uid');

        return $imapUid !== null ? (int) $imapUid : null;
    }

    /**
     * Build the base query used for synced email mailbox lookups.
     */
    private function mailboxQuery(User $user, string $mailbox): Builder
    {
        return SyncedEmail::query()
            ->whereBelongsTo($user)
            ->where('mailbox', $mailbox);
    }

    /**
     * Generate a short single-line preview from the extracted message body.
     */
    private function previewFromBody(?string $bodyText): ?string
    {
        $bodyText = preg_replace('/\s+/u', ' ', trim((string) $bodyText)) ?? '';

        if ($bodyText === '') {
            return null;
        }

        return Str::limit($bodyText, 240);
    }

    /**
     * Store the latest attachment set for a synced email.
     *
     * @param  list<array{file_name: string, content_type: string|null, content: string, size: int, content_id: string|null, is_inline: bool}>  $attachments
     */
    private function syncAttachments(SyncedEmail $email, array $attachments): void
    {
        $disk = Storage::disk('local');
        $directory = "email-sync/{$email->user_id}/{$email->id}";

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

    /**
     * Prevent unsafe or empty attachment filenames from being written directly.
     */
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
     * Get the validated email sync configuration.
     *
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
    private function configuration(): array
    {
        $config = config('services.email_sync');

        $username = trim((string) ($config['username'] ?? ''));
        $password = trim((string) ($config['password'] ?? ''));

        if ($username === '' || $username === 'your-google-account@gmail.com') {
            throw new RuntimeException('Email sync is not configured yet. Set your Gmail address in MAIL_USERNAME first.');
        }

        if ($password === '') {
            throw new RuntimeException('Email sync is not configured yet. Set your Gmail app password in MAIL_PASSWORD first.');
        }

        return [
            'host' => trim((string) ($config['host'] ?? 'imap.gmail.com')),
            'port' => (int) ($config['port'] ?? 993),
            'username' => $username,
            'password' => $password,
            'encryption' => trim((string) ($config['encryption'] ?? 'ssl')),
            'mailbox' => trim((string) ($config['mailbox'] ?? 'INBOX')),
            'validate_certificate' => (bool) ($config['validate_certificate'] ?? true),
        ];
    }
}
