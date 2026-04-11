<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EmailSync\BirReceiptEmailParser;
use PHPUnit\Framework\TestCase;

class BirReceiptEmailParserTest extends TestCase
{
    public function test_it_extracts_file_name_date_time_and_tin_from_bir_receipt_email_text(): void
    {
        $parser = new BirReceiptEmailParser();

        $parsed = $parser->parse(implode("\n", [
            'File name: 010803043000-1702EXv2018C-122025.xml',
            'Date received by BIR: 10 April 2026',
            'Time received by BIR: 02:49 PM',
        ]));

        $this->assertSame([
            'file_name' => '010803043000-1702EXv2018C-122025.xml',
            'date_received_by_bir' => '10 April 2026',
            'time_received_by_bir' => '02:49 PM',
            'tin' => '010803043000',
            'form_type' => '1702EXV2018C',
        ], $parsed);
    }

    public function test_it_does_not_create_false_matches_for_irrelevant_or_partial_body_text(): void
    {
        $parser = new BirReceiptEmailParser();

        $this->assertNull($parser->parse('Hello there, this is just a normal email.'));

        $parsed = $parser->parse('File name: no-leading-tin.xml');

        $this->assertSame([
            'file_name' => 'no-leading-tin.xml',
            'date_received_by_bir' => null,
            'time_received_by_bir' => null,
            'tin' => null,
            'form_type' => null,
        ], $parsed);
    }
}
