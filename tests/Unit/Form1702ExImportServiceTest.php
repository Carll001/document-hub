<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Form1702ExImportService;
use App\Services\Form1702ExService;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class Form1702ExImportServiceTest extends TestCase
{
    public function test_import_stored_file_reads_csv_from_default(): void
    {
        Storage::fake('rustfs');
        config()->set('filesystems.default', 'rustfs');

        $storagePath = 'tmp/form-form1702ex-imports/test-import.csv';
        Storage::disk('rustfs')->put($storagePath, implode("\n", [
            'registered_name,tin,recipient',
            'Alpha Ventures OPC,0101112220000,alpha@example.com',
        ]));

        $importService = app(Form1702ExImportService::class);
        $basePayload = app(Form1702ExService::class)->batchPayloadDefaults();

        $import = $importService->importStoredFile($storagePath, 'test-import.csv', $basePayload);

        $this->assertSame('csv', $import['sourceType']);
        $this->assertCount(1, $import['rows']);
        $this->assertSame('Alpha Ventures OPC', $import['rows'][0]['payload']['registered_name'] ?? null);
        $this->assertSame('0101112220000', $import['rows'][0]['payload']['tin'] ?? null);
    }

    public function test_import_stored_file_reads_xlsx_from_default(): void
    {
        Storage::fake('rustfs');
        config()->set('filesystems.default', 'rustfs');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'registered_name');
        $sheet->setCellValue('B1', 'tin');
        $sheet->setCellValue('C1', 'recipient');
        $sheet->setCellValue('A2', 'Beta Ventures OPC');
        $sheet->setCellValue('B2', '0103334440000');
        $sheet->setCellValue('C2', 'beta@example.com');

        $temporaryPath = tempnam(sys_get_temp_dir(), 'form1702ex-xlsx-');
        $this->assertIsString($temporaryPath);
        $xlsxPath = $temporaryPath.'.xlsx';
        @unlink($temporaryPath);

        $writer = new Xlsx($spreadsheet);
        $writer->save($xlsxPath);

        $storagePath = 'tmp/form-form1702ex-imports/test-import.xlsx';
        Storage::disk('rustfs')->put($storagePath, (string) file_get_contents($xlsxPath));
        @unlink($xlsxPath);

        $importService = app(Form1702ExImportService::class);
        $basePayload = app(Form1702ExService::class)->batchPayloadDefaults();

        $import = $importService->importStoredFile($storagePath, 'test-import.xlsx', $basePayload);

        $this->assertSame('xlsx', $import['sourceType']);
        $this->assertCount(1, $import['rows']);
        $this->assertSame('Beta Ventures OPC', $import['rows'][0]['payload']['registered_name'] ?? null);
        $this->assertSame('0103334440000', $import['rows'][0]['payload']['tin'] ?? null);
    }
}
