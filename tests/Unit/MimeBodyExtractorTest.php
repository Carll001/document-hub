<?php

namespace Tests\Unit;

use App\Services\EmailSync\MimeBodyExtractor;
use PHPUnit\Framework\TestCase;

class MimeBodyExtractorTest extends TestCase
{
    public function test_extracts_plain_text_body_from_a_raw_message()
    {
        $message = <<<'EOT'
From: Support Team <support@example.com>
Subject: Plain text example
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

Hello there,

This is a plain text message body.
EOT;

        $extractor = new MimeBodyExtractor;

        $this->assertSame(
            "Hello there,\n\nThis is a plain text message body.",
            $extractor->extractTextFromRawMessage($message),
        );
    }

    public function test_falls_back_to_html_when_plain_text_is_unavailable()
    {
        $message = <<<'EOT'
From: Google <no-reply@example.com>
Subject: HTML example
Content-Type: multipart/alternative; boundary="boundary-123"

--boundary-123
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

<div><h1>Security alert</h1><p>A new sign-in on Windows</p><p>If this was you, no action is needed.</p></div>
--boundary-123--
EOT;

        $extractor = new MimeBodyExtractor;
        $body = $extractor->extractTextFromRawMessage($message);

        $this->assertNotNull($body);
        $this->assertStringContainsString('Security alert', $body);
        $this->assertStringContainsString('A new sign-in on Windows', $body);
        $this->assertStringContainsString('If this was you, no action is needed.', $body);
    }

    public function test_extracts_attachment_payloads_from_a_raw_message()
    {
        $message = <<<'EOT'
From: Support Team <support@example.com>
Subject: Attachment example
Content-Type: multipart/mixed; boundary="mixed-123"

--mixed-123
Content-Type: text/plain; charset=UTF-8

Please review the attached file.
--mixed-123
Content-Type: text/plain; name="notes.txt"
Content-Disposition: attachment; filename="notes.txt"
Content-Transfer-Encoding: base64

QXR0YWNobWVudCBib2R5IHRleHQ=
--mixed-123--
EOT;

        $extractor = new MimeBodyExtractor;
        $payload = $extractor->extractPayloadFromRawMessage($message);

        $this->assertSame('Please review the attached file.', $payload['body_text']);
        $this->assertCount(1, $payload['attachments']);
        $this->assertSame('notes.txt', $payload['attachments'][0]['file_name']);
        $this->assertSame('text/plain', $payload['attachments'][0]['content_type']);
        $this->assertSame('Attachment body text', $payload['attachments'][0]['content']);
        $this->assertSame(20, $payload['attachments'][0]['size']);
    }
}
