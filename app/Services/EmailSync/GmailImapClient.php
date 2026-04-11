<?php

namespace App\Services\EmailSync;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

class GmailImapClient implements EmailSyncClient
{
    /**
     * @var resource|null
     */
    private $stream = null;

    private int $sequence = 0;

    private readonly MimeBodyExtractor $mimeBodyExtractor;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption = 'ssl',
        private readonly bool $validateCertificate = true,
        private readonly int $timeout = 15,
        ?MimeBodyExtractor $mimeBodyExtractor = null,
    ) {
        $this->mimeBodyExtractor = $mimeBodyExtractor ?? new MimeBodyExtractor;
    }

    /**
     * Open the IMAP connection and authenticate the configured user.
     */
    public function connect(): void
    {
        if (is_resource($this->stream)) {
            return;
        }

        $scheme = $this->encryption === '' ? 'tcp' : $this->encryption;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->validateCertificate,
                'verify_peer_name' => $this->validateCertificate,
                'allow_self_signed' => ! $this->validateCertificate,
            ],
        ]);

        $stream = @stream_socket_client(
            sprintf('%s://%s:%d', $scheme, $this->host, $this->port),
            $errorCode,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! is_resource($stream)) {
            throw new RuntimeException(
                sprintf(
                    'Unable to connect to %s:%d for IMAP sync. %s',
                    $this->host,
                    $this->port,
                    $errorMessage ?: 'Check your network connection and Gmail IMAP settings.',
                ),
            );
        }

        stream_set_timeout($stream, $this->timeout);

        $this->stream = $stream;

        $greeting = $this->readLine();

        if (! str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('The Gmail IMAP server did not accept the connection.');
        }

        $this->command(sprintf(
            'LOGIN "%s" "%s"',
            $this->escape($this->username),
            $this->escape($this->password),
        ));
    }

    /**
     * Select the mailbox to read from.
     */
    public function selectMailbox(string $mailbox): void
    {
        $this->command(sprintf('SELECT "%s"', $this->escape($mailbox)));
    }

    /**
     * Return the most recent IMAP UIDs from the selected mailbox.
     *
     * @return list<int>
     */
    public function latestUids(int $limit): array
    {
        $uids = $this->searchUids('ALL');

        if ($limit <= 0) {
            return $uids;
        }

        return array_slice($uids, $limit * -1);
    }

    /**
     * Return UIDs newer than the given IMAP UID.
     *
     * @return list<int>
     */
    public function uidsNewerThan(int $uid): array
    {
        return $this->searchUids(sprintf('UID %d:*', $uid + 1));
    }

    /**
     * Return UIDs older than the given IMAP UID.
     *
     * @return list<int>
     */
    public function olderUidsBefore(int $uid, int $limit = 0): array
    {
        if ($uid <= 1) {
            return [];
        }

        $uids = $this->searchUids(sprintf('UID 1:%d', $uid - 1));

        if ($limit <= 0) {
            return $uids;
        }

        return array_slice($uids, $limit * -1);
    }

    /**
     * Return UIDs with received dates on or after the given date.
     *
     * @return list<int>
     */
    public function uidsReceivedSince(CarbonImmutable $date): array
    {
        return $this->searchUids(sprintf(
            'SINCE %s',
            $this->imapDate($date),
        ));
    }

    /**
     * Fetch message metadata and text body for the given IMAP UID.
     *
     * @return array{
     *     imap_uid: string,
     *     message_id: string|null,
     *     from_name: string|null,
     *     from_email: string|null,
     *     subject: string|null,
     *     received_at: CarbonImmutable|null,
     *     body_text: string|null,
     *     body_html: string|null,
     *     attachments: list<array{
     *         file_name: string,
     *         content_type: string|null,
     *         content: string,
     *         size: int,
     *         content_id: string|null,
     *         is_inline: bool
     *     }>
     * }
     */
    public function fetchMessage(int $uid): array
    {
        $response = $this->command(sprintf(
            'UID FETCH %d (UID BODY.PEEK[])',
            $uid,
        ));

        $rawMessage = $this->extractLiteral($response, '/BODY\[\] \{(\d+)\}\r\n/i');

        if ($rawMessage === null) {
            throw new RuntimeException("Unable to read the Gmail message for UID {$uid}.");
        }

        [$headerBlock] = $this->splitRawMessage($rawMessage);
        $headers = $this->decodeHeaders($headerBlock);
        $payload = $this->mimeBodyExtractor->extractPayloadFromRawMessage($rawMessage);
        [$fromName, $fromEmail] = $this->parseAddress($this->headerValue($headers, 'From'));

        return [
            'imap_uid' => (string) $uid,
            'message_id' => $this->trimHeader($this->headerValue($headers, 'Message-ID')),
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'subject' => $this->trimHeader($this->headerValue($headers, 'Subject')),
            'received_at' => $this->parseDate($this->headerValue($headers, 'Date')),
            'body_text' => $payload['body_text'],
            'body_html' => $payload['body_html'],
            'attachments' => $payload['attachments'],
        ];
    }

    /**
     * Close the connection to the IMAP server.
     */
    public function disconnect(): void
    {
        if (! is_resource($this->stream)) {
            return;
        }

        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
            // Ignore logout failures while closing the socket.
        }

        fclose($this->stream);
        $this->stream = null;
    }

    /**
     * Send a command and return the full tagged response.
     */
    private function command(string $command): string
    {
        $this->ensureConnected();

        $tag = sprintf('A%04d', ++$this->sequence);

        $this->write("{$tag} {$command}\r\n");

        $response = $this->readResponse($tag);

        if (! preg_match('/^'.preg_quote($tag, '/').' OK\b/m', $response)) {
            $message = trim($this->lastResponseLine($response));

            throw new RuntimeException(
                $message !== ''
                    ? $message
                    : 'The Gmail IMAP server rejected the request.',
            );
        }

        return $response;
    }

    /**
     * Run a UID SEARCH query and return the matched UIDs in ascending order.
     *
     * @return list<int>
     */
    private function searchUids(string $criteria): array
    {
        $response = $this->command(sprintf('UID SEARCH %s', $criteria));

        if (! preg_match_all('/^\* SEARCH(.*)$/mi', $response, $matches)) {
            return [];
        }

        $uids = [];

        foreach ($matches[1] as $line) {
            foreach (preg_split('/\s+/', trim($line)) ?: [] as $value) {
                if ($value !== '' && ctype_digit($value)) {
                    $uids[] = (int) $value;
                }
            }
        }

        $uids = array_values(array_unique($uids));
        sort($uids);

        return $uids;
    }

    /**
     * Read the server response for a tagged command, including literal blocks.
     */
    private function readResponse(string $tag): string
    {
        $response = '';

        while (true) {
            $line = $this->readLine();
            $response .= $line;

            while (preg_match('/\{(\d+)\}\r\n$/', $line, $matches)) {
                $response .= $this->readBytes((int) $matches[1]);

                $line = $this->readLine();
                $response .= $line;
            }

            if (str_starts_with($line, "{$tag} ")) {
                return $response;
            }
        }
    }

    /**
     * Read a single CRLF-terminated line from the socket.
     */
    private function readLine(): string
    {
        $this->ensureConnected();

        $line = fgets($this->stream);

        if ($line === false) {
            throw new RuntimeException('The Gmail IMAP connection closed unexpectedly.');
        }

        return $line;
    }

    /**
     * Read an exact number of bytes from the socket.
     */
    private function readBytes(int $length): string
    {
        $this->ensureConnected();

        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('The Gmail IMAP connection closed while reading a message.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Write the command payload to the socket.
     */
    private function write(string $payload): void
    {
        $this->ensureConnected();

        $offset = 0;

        while ($offset < strlen($payload)) {
            $written = fwrite($this->stream, substr($payload, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write to the Gmail IMAP connection.');
            }

            $offset += $written;
        }
    }

    /**
     * Extract a literal block from an IMAP response.
     */
    private function extractLiteral(string $response, string $pattern): ?string
    {
        if (! preg_match($pattern, $response, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $literalLength = (int) $matches[1][0];
        $start = $matches[0][1] + strlen($matches[0][0]);

        return substr($response, $start, $literalLength);
    }

    private function imapDate(CarbonInterface $date): string
    {
        return $date->utc()->format('d-M-Y');
    }

    /**
     * Decode the raw RFC822 header string into an associative array.
     *
     * @return array<string, string|array<int, string>>
     */
    private function decodeHeaders(string $headers): array
    {
        $decoded = iconv_mime_decode_headers(
            $headers,
            ICONV_MIME_DECODE_CONTINUE_ON_ERROR,
            'UTF-8',
        );

        if (is_array($decoded)) {
            return $decoded;
        }

        $parsed = [];
        $current = null;

        foreach (preg_split("/\r\n/", $headers) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                if ($current !== null) {
                    $parsed[$current] .= ' '.trim($line);
                }

                continue;
            }

            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $current = trim($name);
            $parsed[$current] = trim($value);
        }

        return $parsed;
    }

    /**
     * Get a header value from the decoded header set.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        if (! array_key_exists($name, $headers)) {
            return null;
        }

        $value = $headers[$name];

        if (is_array($value)) {
            return $value[0] ?? null;
        }

        return $value;
    }

    /**
     * Convert a decoded address header into a name/email pair.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseAddress(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [null, null];
        }

        $header = trim($header);

        if (preg_match('/^(?:"?([^\"]*)"?\s)?<([^>]+)>$/', $header, $matches)) {
            return [
                $this->trimHeader($matches[1]) ?: null,
                trim($matches[2]),
            ];
        }

        if (preg_match('/<([^>]+)>/', $header, $matches)) {
            $email = trim($matches[1]);
            $name = trim(str_replace($matches[0], '', $header), " \t\n\r\0\x0B\"'");

            return [$name !== '' ? $name : null, $email];
        }

        if (filter_var($header, FILTER_VALIDATE_EMAIL)) {
            return [null, $header];
        }

        return [$header, null];
    }

    /**
     * Parse the message date into an immutable Carbon instance.
     */
    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Escape characters inside an IMAP quoted string.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * Trim whitespace from header content and normalize empty strings.
     */
    private function trimHeader(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Split a raw RFC822 message into headers and body.
     *
     * @return array{0: string, 1: string}
     */
    private function splitRawMessage(string $rawMessage): array
    {
        $parts = preg_split("/\r\n\r\n|\n\n|\r\r/", $rawMessage, 2);

        return [
            $parts[0] ?? '',
            $parts[1] ?? '',
        ];
    }

    /**
     * Return the last non-empty line from the tagged IMAP response.
     */
    private function lastResponseLine(string $response): string
    {
        $lines = array_values(array_filter(
            preg_split("/\r\n/", trim($response)) ?: [],
            static fn (string $line): bool => $line !== '',
        ));

        return $lines[count($lines) - 1] ?? '';
    }

    /**
     * Ensure the stream has been opened before reading or writing.
     */
    private function ensureConnected(): void
    {
        if (! is_resource($this->stream)) {
            throw new RuntimeException('The Gmail IMAP connection has not been established.');
        }
    }
}
