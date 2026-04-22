<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\ProcessForm1702ExBatchRows;
use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Models\User;
use App\Services\Form1702ExService;
use App\Services\PdfTextExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class Form1702ExServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_generates_a_pdf_for_a_saved_batch_row(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $user->id,
            'name' => 'PDF Batch',
        ]);
        $row = Form1702ExBatchRow::query()->create([
            'form_1702_ex_batch_id' => $batch->id,
            'source_name' => 'import.csv',
            'source_type' => 'csv',
            'source_row_number' => 2,
            'uploaded_at' => now(),
            'payload' => $this->validPayload(),
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_PROCESSING,
        ]);

        $generatedRow = app(Form1702ExService::class)->generateBatchRowPdf($row);

        $this->assertSame(Form1702ExBatchRow::PDF_STATUS_GENERATED, $generatedRow->pdf_status);
        $this->assertNotNull($generatedRow->generated_pdf_storage_path);
        Storage::disk('s3')->assertExists((string) $generatedRow->generated_pdf_storage_path);

        $pdfText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('s3')->path((string) $generatedRow->generated_pdf_storage_path),
        );

        $this->assertStringContainsString('FOUNDATION FOR COMMUNITY GROWTH, INC.', $pdfText);
        $this->assertStringContainsString('008765432000', preg_replace('/\s+/', '', $pdfText) ?? '');
    }

    public function test_batch_row_jobs_mark_failed_rows_without_blocking_later_rows(): void
    {
        $user = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $user->id,
            'name' => 'Queued Batch',
        ]);
        $failedRow = Form1702ExBatchRow::query()->create([
            'form_1702_ex_batch_id' => $batch->id,
            'source_name' => 'failed.csv',
            'source_type' => 'csv',
            'source_row_number' => 2,
            'uploaded_at' => now(),
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Broken Row Foundation',
            ]),
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_QUEUED,
        ]);
        $successfulRow = Form1702ExBatchRow::query()->create([
            'form_1702_ex_batch_id' => $batch->id,
            'source_name' => 'success.csv',
            'source_type' => 'csv',
            'source_row_number' => 3,
            'uploaded_at' => now(),
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Healthy Row Foundation',
            ]),
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_QUEUED,
        ]);

        $service = Mockery::mock(Form1702ExService::class);
        $service->shouldReceive('generateBatchRowPdf')
            ->once()
            ->with(Mockery::on(
                fn (Form1702ExBatchRow $row): bool => $row->is($failedRow),
            ))
            ->andThrow(new RuntimeException('Broken row'));
        $service->shouldReceive('generateBatchRowPdf')
            ->once()
            ->with(Mockery::on(
                fn (Form1702ExBatchRow $row): bool => $row->is($successfulRow),
            ))
            ->andReturnUsing(function (Form1702ExBatchRow $row): Form1702ExBatchRow {
                $row->forceFill([
                    'pdf_status' => Form1702ExBatchRow::PDF_STATUS_GENERATED,
                    'pdf_error' => null,
                    'generated_pdf_file_name' => 'healthy-row.pdf',
                    'generated_pdf_storage_path' => 'forms/test/healthy-row.pdf',
                    'generated_pdf_file_size' => 1024,
                    'generated_at' => now(),
                ])->save();

                return $row->fresh() ?? $row;
            });

        $job = new ProcessForm1702ExBatchRows([
            $failedRow->id,
            $successfulRow->id,
        ]);

        $job->handle($service);

        $failedRow->refresh();
        $successfulRow->refresh();

        $this->assertSame(Form1702ExBatchRow::PDF_STATUS_FAILED, $failedRow->pdf_status);
        $this->assertSame('Broken row', $failedRow->pdf_error);
        $this->assertSame(Form1702ExBatchRow::PDF_STATUS_GENERATED, $successfulRow->pdf_status);
        $this->assertSame('healthy-row.pdf', $successfulRow->generated_pdf_file_name);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'tin' => '008765432000',
            'atc' => 'ic011',
            'filing_is_calendar' => false,
            'filing_is_fiscal' => true,
            'rdo_code' => '123',
            'amended_return_yes' => false,
            'amended_return_no' => true,
            'short_period_return_yes' => false,
            'short_period_return_no' => true,
            'taxpayer_name' => 'Foundation for Community Growth, Inc.',
            'registered_name' => 'Foundation for Community Growth, Inc.',
            'registered_address' => '25 Rizal Avenue, Makati City, Metro Manila',
            'zip_code' => '1226',
            'email_address' => 'finance@communitygrowth.org',
            'contact_number' => '(02) 8123-4567',
            'deduction_method_itemized' => true,
            'deduction_method_osd' => false,
            'line_of_business' => 'Educational and Community Development Programs',
            'exempt_under_section' => '30(E)',
            'return_period_year' => '2025',
            'calendar_year_ended' => '2025-12-31',
            'taxpayer_type_nonprofit' => true,
            'taxpayer_type_govt' => false,
            'is_amended_return' => false,
            'is_short_period_return' => false,
            'total_assets' => '25,400,000',
            'authorized_representative' => 'Maria Dela Cruz',
            'representative_tin' => '108334556000',
            'signatory_title' => 'Chief Finance Officer',
            'date_signed' => '2026-04-08',
        ], $overrides);
    }
}
