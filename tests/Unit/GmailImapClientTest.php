<?php

namespace Tests\Unit;

use App\Services\EmailSync\GmailImapClient;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class GmailImapClientTest extends TestCase
{
    public function test_message_dates_are_normalized_to_utc_before_storage()
    {
        $client = new GmailImapClient(
            host: 'imap.gmail.com',
            port: 993,
            username: 'mailbox@example.com',
            password: 'app-password',
        );

        $parsedDate = \Closure::bind(
            fn (?string $value): ?CarbonImmutable => $this->parseDate($value),
            $client,
            $client,
        )('Tue, 24 Mar 2026 13:53:00 +0800');

        $this->assertInstanceOf(CarbonImmutable::class, $parsedDate);
        $this->assertSame('UTC', $parsedDate->getTimezone()->getName());
        $this->assertSame('2026-03-24 05:53:00', $parsedDate->format('Y-m-d H:i:s'));
    }
}
