<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ExcelExtractionService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExcelExtractionServiceTest extends TestCase
{
    public function test_extract_from_document_storage_reads_tabular_rows(): void
    {
        Storage::fake('rustfs');
        config()->set('filesystems.default', 'rustfs');

        $storagePath = 'document-generator/2/uploads/sample.csv';
        Storage::disk('rustfs')->put($storagePath, implode("\n", [
            'Company,TIN',
            'Alpha Ventures,0101112220000',
        ]));

        $service = app(ExcelExtractionService::class);
        $extracted = $service->extractFromDocumentStorage($storagePath, 0);

        $this->assertSame(['Company', 'TIN'], $extracted['headers']);
        $this->assertCount(1, $extracted['rows']);
        $this->assertSame('Alpha Ventures', $extracted['rows'][0]['Company'] ?? null);
        $this->assertSame('0101112220000', $extracted['rows'][0]['TIN'] ?? null);
    }
}
