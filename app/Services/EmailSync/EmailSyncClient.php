<?php

namespace App\Services\EmailSync;

use Carbon\CarbonImmutable;

interface EmailSyncClient
{
    /**
     * Open the IMAP connection and authenticate the configured user.
     */
    public function connect(): void;

    /**
     * Select the mailbox to read from.
     */
    public function selectMailbox(string $mailbox): void;

    /**
     * Return the most recent IMAP UIDs from the selected mailbox.
     *
     * @return list<int>
     */
    public function latestUids(int $limit): array;

    /**
     * Return UIDs newer than the given IMAP UID.
     *
     * @return list<int>
     */
    public function uidsNewerThan(int $uid): array;

    /**
     * Return UIDs older than the given IMAP UID.
     *
     * @return list<int>
     */
    public function olderUidsBefore(int $uid, int $limit = 0): array;

    /**
     * Fetch message metadata and content for the given IMAP UID.
     *
     * @return array{
     *     imap_uid: string,
     *     message_id: string|null,
     *     from_name: string|null,
     *     from_email: string|null,
     *     subject: string|null,
     *     received_at: CarbonImmutable|null,
     *     body_text: string|null,
     *     attachments: list<array{
     *         file_name: string,
     *         content_type: string|null,
     *         content: string,
     *         size: int
     *     }>
     * }
     */
    public function fetchMessage(int $uid): array;

    /**
     * Close the connection to the IMAP server.
     */
    public function disconnect(): void;
}
