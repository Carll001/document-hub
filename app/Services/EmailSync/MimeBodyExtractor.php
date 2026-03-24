<?php

namespace App\Services\EmailSync;

class MimeBodyExtractor
{
    /**
     * Extract the best-effort text body from a raw RFC822 message.
     */
    public function extractTextFromRawMessage(string $rawMessage): ?string
    {
        return $this->extractPayloadFromRawMessage($rawMessage)['body_text'];
    }

    /**
     * Extract the message body and any attachments from a raw RFC822 message.
     *
     * @return array{
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
    public function extractPayloadFromRawMessage(string $rawMessage): array
    {
        [$headerBlock, $body] = $this->splitMessage($rawMessage);
        $headers = $this->parseHeaders($headerBlock);

        return $this->extractPayloadFromPart($headers, $body);
    }

    /**
     * Split an RFC822 message or MIME part into headers and body.
     *
     * @return array{0: string, 1: string}
     */
    private function splitMessage(string $rawMessage): array
    {
        $parts = preg_split("/\r\n\r\n|\n\n|\r\r/", $rawMessage, 2);

        return [
            $parts[0] ?? '',
            $parts[1] ?? '',
        ];
    }

    /**
     * Parse a header block into a normalized key/value map.
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $currentHeader = null;

        foreach (preg_split("/\r\n|\n|\r/", $headerBlock) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                if ($currentHeader !== null) {
                    $headers[$currentHeader] .= ' '.trim($line);
                }

                continue;
            }

            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');

            $currentHeader = strtolower(trim($name));
            $headers[$currentHeader] = trim($value);
        }

        return $headers;
    }

    /**
     * Extract body text, HTML, and attachments from a MIME part.
     *
     * @param  array<string, string>  $headers
     * @return array{
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
    private function extractPayloadFromPart(array $headers, string $body): array
    {
        $contentType = $this->parseHeaderWithParameters($headers['content-type'] ?? 'text/plain; charset=UTF-8');
        $contentDisposition = $this->parseHeaderWithParameters($headers['content-disposition'] ?? '');

        $mediaType = $contentType['value'] !== ''
            ? $contentType['value']
            : 'text/plain';

        if (str_starts_with($mediaType, 'multipart/')) {
            return $this->extractPayloadFromMultipart(
                $body,
                (string) ($contentType['parameters']['boundary'] ?? ''),
            );
        }

        if ($this->isAttachment($contentDisposition, $contentType, $headers)) {
            $attachment = $this->buildAttachment(
                $contentType,
                $contentDisposition,
                $this->decodeTransferEncodedBody(
                    $body,
                    $headers['content-transfer-encoding'] ?? null,
                ),
                $headers['content-id'] ?? null,
            );

            return [
                'body_text' => null,
                'body_html' => null,
                'attachments' => $attachment !== null ? [$attachment] : [],
            ];
        }

        $decodedBody = $this->decodeTextBody(
            $body,
            $headers['content-transfer-encoding'] ?? null,
            $contentType['parameters']['charset'] ?? null,
        );

        return match ($mediaType) {
            'text/plain' => [
                'body_text' => $this->normalizeText($decodedBody),
                'body_html' => null,
                'attachments' => [],
            ],
            'text/html' => [
                'body_text' => $this->htmlToText($decodedBody),
                'body_html' => $this->normalizeHtml($decodedBody),
                'attachments' => [],
            ],
            'message/rfc822' => $this->extractPayloadFromRawMessage($decodedBody),
            default => [
                'body_text' => null,
                'body_html' => null,
                'attachments' => [],
            ],
        };
    }

    /**
     * Prefer plain text parts and keep HTML when the message provides it.
     *
     * @return array{
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
    private function extractPayloadFromMultipart(string $body, string $boundary): array
    {
        if ($boundary === '') {
            return [
                'body_text' => null,
                'body_html' => null,
                'attachments' => [],
            ];
        }

        $plainTextBody = null;
        $fallbackBody = null;
        $htmlBody = null;
        $attachments = [];

        foreach ($this->splitMultipartBody($body, $boundary) as $part) {
            [$partHeaderBlock, $partBody] = $this->splitMessage($part);
            $partHeaders = $this->parseHeaders($partHeaderBlock);
            $partContentType = $this->parseHeaderWithParameters($partHeaders['content-type'] ?? 'text/plain');
            $payload = $this->extractPayloadFromPart($partHeaders, $partBody);

            $attachments = [...$attachments, ...$payload['attachments']];

            if ($partContentType['value'] === 'text/plain' && $payload['body_text'] !== null) {
                $plainTextBody ??= $payload['body_text'];

                continue;
            }

            if ($partContentType['value'] === 'text/html') {
                $htmlBody ??= $payload['body_html'];
            }

            $fallbackBody ??= $payload['body_text'];
            $htmlBody ??= $payload['body_html'];
        }

        return [
            'body_text' => $plainTextBody ?? $fallbackBody,
            'body_html' => $htmlBody,
            'attachments' => $attachments,
        ];
    }

    /**
     * Split a multipart body into its child parts.
     *
     * @return list<string>
     */
    private function splitMultipartBody(string $body, string $boundary): array
    {
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
        $delimiter = '--'.$boundary;
        $segments = explode($delimiter, $normalizedBody);
        $parts = [];

        foreach (array_slice($segments, 1) as $segment) {
            $segment = ltrim($segment, "\n");

            if ($segment === '' || str_starts_with($segment, '--')) {
                break;
            }

            $parts[] = rtrim($segment, "\n");
        }

        return $parts;
    }

    /**
     * Decode a transfer-encoded MIME part without altering its binary content.
     */
    private function decodeTransferEncodedBody(string $body, ?string $transferEncoding): string
    {
        $transferEncoding = strtolower(trim((string) $transferEncoding));
        $body = str_replace("\r\n", "\n", $body);

        return match ($transferEncoding) {
            'base64' => base64_decode(
                preg_replace('/\s+/', '', $body) ?? '',
                true,
            ) ?: $body,
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /**
     * Decode a MIME text body into UTF-8.
     */
    private function decodeTextBody(string $body, ?string $transferEncoding, ?string $charset): string
    {
        return $this->convertToUtf8(
            $this->decodeTransferEncodedBody($body, $transferEncoding),
            $charset,
        );
    }

    /**
     * Convert decoded text into UTF-8 when a charset is available.
     */
    private function convertToUtf8(string $text, ?string $charset): string
    {
        $charset = trim((string) $charset);

        if ($charset === '' || preg_match('/^(utf-8|us-ascii|ascii)$/i', $charset)) {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            try {
                return mb_convert_encoding($text, 'UTF-8', $charset);
            } catch (\Throwable) {
                // Fall through to iconv.
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);

            if ($converted !== false) {
                return $converted;
            }
        }

        return $text;
    }

    /**
     * Convert HTML markup into readable plain text.
     */
    private function htmlToText(string $html): ?string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(p|div|section|article|tr|li|h[1-6])>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<(p|div|section|article|table|ul|ol|li|h[1-6])\b[^>]*>/i', "\n", $html) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return $this->normalizeText($text);
    }

    /**
     * Keep the decoded HTML body when the message contains markup.
     */
    private function normalizeHtml(?string $html): ?string
    {
        $html = trim((string) $html);

        return $html === '' ? null : $html;
    }

    /**
     * Normalize whitespace while keeping paragraph breaks readable.
     */
    private function normalizeText(?string $text): ?string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * Parse a structured header value and parameters.
     *
     * @return array{value: string, parameters: array<string, string>}
     */
    private function parseHeaderWithParameters(string $value): array
    {
        $segments = str_getcsv($value, ';', '"', '\\');
        $mediaType = strtolower(trim(array_shift($segments) ?? ''));
        $parameters = [];

        foreach ($segments as $segment) {
            [$name, $parameterValue] = array_pad(explode('=', $segment, 2), 2, '');

            $name = strtolower(trim($name));

            if ($name === '') {
                continue;
            }

            $parameters[$name] = trim($parameterValue, " \t\n\r\0\x0B\"'");
        }

        return [
            'value' => $mediaType,
            'parameters' => $parameters,
        ];
    }

    /**
     * Determine whether the MIME part should be stored as an attachment.
     *
     * @param  array{value: string, parameters: array<string, string>}  $contentDisposition
     * @param  array{value: string, parameters: array<string, string>}  $contentType
     * @param  array<string, string>  $headers
     */
    private function isAttachment(array $contentDisposition, array $contentType, array $headers): bool
    {
        $mediaType = strtolower($contentType['value']);
        $hasContentId = $this->normalizeContentId($headers['content-id'] ?? null) !== null;

        return $contentDisposition['value'] === 'attachment'
            || $contentDisposition['value'] === 'inline'
                && (
                    array_key_exists('filename', $contentDisposition['parameters'])
                    || array_key_exists('filename*', $contentDisposition['parameters'])
                )
            || array_key_exists('name', $contentType['parameters'])
            || array_key_exists('name*', $contentType['parameters'])
            || $hasContentId
                && ! in_array($mediaType, ['text/plain', 'text/html'], true);
    }

    /**
     * Build a normalized attachment payload.
     *
     * @param  array{value: string, parameters: array<string, string>}  $contentType
     * @param  array{value: string, parameters: array<string, string>}  $contentDisposition
     * @return array{
     *     file_name: string,
     *     content_type: string|null,
     *     content: string,
     *     size: int,
     *     content_id: string|null,
     *     is_inline: bool
     * }|null
     */
    private function buildAttachment(
        array $contentType,
        array $contentDisposition,
        string $content,
        ?string $contentId = null,
    ): ?array {
        $fileName = $this->resolveAttachmentFilename($contentDisposition, $contentType)
            ?? $this->fallbackAttachmentFilename($contentType['value']);
        $normalizedContentId = $this->normalizeContentId($contentId);

        if ($fileName === null || trim($fileName) === '') {
            return null;
        }

        return [
            'file_name' => $fileName,
            'content_type' => $contentType['value'] !== '' ? $contentType['value'] : null,
            'content' => $content,
            'size' => strlen($content),
            'content_id' => $normalizedContentId,
            'is_inline' => $this->shouldTreatAsInlineAsset(
                $contentDisposition['value'],
                $contentType['value'],
                $normalizedContentId,
            ),
        ];
    }

    /**
     * Hide only likely embedded assets from the file list.
     */
    private function shouldTreatAsInlineAsset(
        string $contentDisposition,
        string $mediaType,
        ?string $contentId,
    ): bool {
        $mediaType = strtolower(trim($mediaType));
        $isEmbeddableImage = str_starts_with($mediaType, 'image/');

        return $isEmbeddableImage
            && (
                strtolower(trim($contentDisposition)) === 'inline'
                || $contentId !== null
            );
    }

    /**
     * Resolve an attachment filename from content-disposition or content-type parameters.
     *
     * @param  array{value: string, parameters: array<string, string>}  $contentDisposition
     * @param  array{value: string, parameters: array<string, string>}  $contentType
     */
    private function resolveAttachmentFilename(array $contentDisposition, array $contentType): ?string
    {
        foreach (['filename', 'filename*'] as $parameter) {
            if (isset($contentDisposition['parameters'][$parameter])) {
                return $this->decodeHeaderValue($contentDisposition['parameters'][$parameter]);
            }
        }

        foreach (['name', 'name*'] as $parameter) {
            if (isset($contentType['parameters'][$parameter])) {
                return $this->decodeHeaderValue($contentType['parameters'][$parameter]);
            }
        }

        return null;
    }

    /**
     * Decode encoded header-style values used in attachment names.
     */
    private function decodeHeaderValue(string $value): string
    {
        $value = preg_replace("/^[^']*''/", '', $value) ?? $value;
        $value = rawurldecode($value);

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

            if ($decoded !== false) {
                $value = $decoded;
            }
        }

        return trim($value);
    }

    /**
     * Build a fallback filename when the message omits one.
     */
    private function fallbackAttachmentFilename(string $mediaType): ?string
    {
        $extension = match (strtolower($mediaType)) {
            'text/plain' => 'txt',
            'text/html' => 'html',
            'text/csv' => 'csv',
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };

        return $extension !== null
            ? "attachment.{$extension}"
            : 'attachment.bin';
    }

    /**
     * Normalize content IDs so stored attachments can be matched by CID URLs.
     */
    private function normalizeContentId(?string $contentId): ?string
    {
        $contentId = trim((string) $contentId, " \t\n\r\0\x0B<>");

        return $contentId === '' ? null : $contentId;
    }
}
