<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\Form1702ExCompletedRowEmail;
use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Form1702ExCompletedFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_rows_are_removed_from_the_main_table_and_listed_on_the_completed_page(): void
    {
        Storage::fake('local');
        $this->withoutVite();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Completed Batch',
        ]);

        $completedRow = $this->createCompletedRow($batch, [
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Completed Foundation',
            ]),
        ]);
        $pendingRow = $this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Pending Foundation',
                'tin' => '009999999999',
            ]),
        ]);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Index')
                ->where('completedCount', 1)
                ->where('rows.0.taxpayerName', 'Pending Foundation'),
            );

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.completed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Completed')
                ->where('pagination.total', 1)
                ->where('rows.0.taxpayerName', 'Completed Foundation')
                ->where('rows.0.sendEmailUrl', route('forms.1702-ex.completed.send', [
                    'form1702ExBatchRow' => $completedRow,
                ])),
            );
    }

    public function test_removing_a_receipt_moves_the_row_back_to_the_main_table(): void
    {
        Storage::fake('local');
        $this->withoutVite();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Receipt Removal Batch',
        ]);
        $completedRow = $this->createCompletedRow($batch);

        $this->actingAs($staff)
            ->delete(route('forms.1702-ex.rows.receipt.destroy', [
                'form1702ExBatchRow' => $completedRow,
            ]))
            ->assertRedirect(route('forms.1702-ex.index'));

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.completed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Completed')
                ->where('pagination.total', 0),
            );

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Index')
                ->where('completedCount', 0)
                ->where('rows.0.taxpayerName', 'Foundation for Community Growth, Inc.'),
            );
    }

    public function test_completed_files_page_requires_ownership(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $otherStaff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $owner->id,
            'name' => 'Owner Completed Batch',
        ]);
        $this->createCompletedRow($batch);

        $this->actingAs($otherStaff)
            ->get(route('forms.1702-ex.completed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Completed')
                ->where('pagination.total', 0),
            );
    }

    public function test_staff_users_can_queue_a_completed_row_email_with_an_optional_extra_attachment(): void
    {
        Storage::fake('local');
        Mail::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Completed Send Batch',
        ]);
        $completedRow = $this->createCompletedRow($batch);
        $extraAttachment = UploadedFile::fake()->createWithContent(
            'notes.txt',
            'extra attachment body',
        );

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.completed.send', [
                'form1702ExBatchRow' => $completedRow,
            ]), [
                'subject' => 'Custom completed file',
                'message' => 'Please find the completed file attached.',
                'extraAttachment' => $extraAttachment,
            ])
            ->assertRedirect(route('forms.1702-ex.completed.index'))
            ->assertSessionHas('success', 'Email queued to finance@communitygrowth.org.');

        Mail::assertQueued(Form1702ExCompletedRowEmail::class, function (Form1702ExCompletedRowEmail $mail) use ($completedRow): bool {
            return $mail->hasTo('finance@communitygrowth.org')
                && $mail->row->is($completedRow)
                && $mail->subjectLine === 'Custom completed file'
                && $mail->messageBody === 'Please find the completed file attached.'
                && $mail->extraAttachmentFileName === 'notes.txt';
        });
    }

    public function test_bulk_send_skips_completed_rows_without_recipient_email(): void
    {
        Storage::fake('local');
        Mail::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Completed Bulk Send Batch',
        ]);
        $sendableRow = $this->createCompletedRow($batch);
        $missingEmailRow = $this->createCompletedRow($batch, [
            'payload' => $this->validPayload([
                'taxpayer_name' => 'No Email Foundation',
                'tin' => '009999999999',
                'email_address' => '',
            ]),
            'source_row_number' => 3,
        ]);

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.completed.send.bulk'), [
                'rowIds' => [$sendableRow->uuid, $missingEmailRow->uuid],
            ])
            ->assertRedirect(route('forms.1702-ex.completed.index'))
            ->assertSessionHas('success', 'Queued 1 completed email(s). Skipped 1 row(s).');

        Mail::assertQueued(Form1702ExCompletedRowEmail::class, 1);
        Mail::assertQueued(Form1702ExCompletedRowEmail::class, function (Form1702ExCompletedRowEmail $mail) use ($sendableRow): bool {
            return $mail->hasTo('finance@communitygrowth.org')
                && $mail->row->is($sendableRow);
        });
    }

    public function test_import_uses_recipient_header_for_completed_row_recipient_email(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,recipient',
                'Alpha Ventures OPC,0101112220000,alpha@example.com',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.import.store'), [
                'spreadsheet' => $upload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.1702-ex.index'));

        $row = Form1702ExBatchRow::query()->sole();

        $this->assertSame('alpha@example.com', $row->payload['email_address'] ?? null);
        $this->assertSame('2026-04-10', $row->payload['receipt_acceptance_start_date'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createBatchRow(Form1702ExBatch $batch, array $overrides = []): Form1702ExBatchRow
    {
        return Form1702ExBatchRow::query()->create(array_replace([
            'form_1702_ex_batch_id' => $batch->id,
            'source_name' => 'seed.csv',
            'source_type' => 'csv',
            'source_row_number' => 2,
            'uploaded_at' => now(),
            'payload' => $this->validPayload(),
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_QUEUED,
            'pdf_error' => null,
            'generated_pdf_file_name' => null,
            'generated_pdf_storage_path' => null,
            'generated_pdf_file_size' => null,
            'generated_at' => null,
            'receipt_file_name' => null,
            'receipt_storage_path' => null,
            'receipt_file_size' => null,
            'receipt_job_status' => null,
            'receipt_job_error' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCompletedRow(Form1702ExBatch $batch, array $overrides = []): Form1702ExBatchRow
    {
        $generatedPdfPath = 'forms/'.$batch->user_id.'/1702-ex/batches/'.$batch->id.'/'.uniqid('generated-', true).'.pdf';
        $receiptPath = 'forms/'.$batch->user_id.'/1702-ex/receipts/'.$batch->id.'/'.uniqid('receipt-', true).'.pdf';

        Storage::disk('local')->put($generatedPdfPath, 'fake completed pdf');
        Storage::disk('local')->put($receiptPath, 'fake receipt pdf');

        return $this->createBatchRow($batch, array_replace([
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_GENERATED,
            'generated_pdf_file_name' => 'completed-row.pdf',
            'generated_pdf_storage_path' => $generatedPdfPath,
            'generated_pdf_file_size' => Storage::disk('local')->size($generatedPdfPath),
            'generated_at' => now(),
            'receipt_file_name' => 'completed-row-receipt.pdf',
            'receipt_storage_path' => $receiptPath,
            'receipt_file_size' => Storage::disk('local')->size($receiptPath),
        ], $overrides));
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
