<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateForm1702ExRowReceipt;
use App\Jobs\ProcessForm1702ExBatchRows;
use App\Mail\Form1702ExCompletedRowEmail;
use App\Mail\Form1702ExCompletedRowsEmail;
use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Models\SyncedEmail;
use App\Models\User;
use App\Services\EmailSync\BirReceiptAutoMatchService;
use App\Services\Form1702ExCompletedEmailService;
use App\Services\Form1702ExRowReceiptService;
use App\Services\Form1702ExService;
use App\Services\PdfTextExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class Form1702ExReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_users_can_view_and_generate_the_receipt_template_alignment_page(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.receipt-template.show'))
            ->assertOk()
            ->assertSee('Receipt Template Alignment')
            ->assertSee('receipt.schema.json');

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.receipt-template.generate'))
            ->assertRedirect(route('forms.1702-ex.receipt-template.show'))
            ->assertSessionHas('success', 'The 1702-EX receipt PDF was generated.');

        $latestReceiptTemplate = app(Form1702ExService::class)
            ->latestReceiptTemplatePdf($staff->id);

        $this->assertNotNull($latestReceiptTemplate);
        Storage::disk('local')->assertExists($latestReceiptTemplate['storagePath']);

        $receiptText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('local')->path($latestReceiptTemplate['storagePath']),
        );

        $this->assertStringContainsStringIgnoringCase(
            'Tax Return Receipt Confirmation',
            $receiptText,
        );
        $this->assertStringContainsString(
            '1702-EX-2025-FOUNDATION-FOR-COMMUNITY-GROWTH.xml',
            $receiptText,
        );
        $this->assertStringContainsString('March 23, 2026', $receiptText);
        $this->assertStringContainsString('3:01 PM', $receiptText);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.receipt-template.preview', [
                'v' => $latestReceiptTemplate['version'],
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.receipt-template.download'))
            ->assertOk()
            ->assertDownload('1702-ex-receipt-template.pdf');
    }

    public function test_staff_users_can_queue_and_generate_receipts_for_generated_rows(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Receipt Batch',
        ]);
        $row = $this->generateRowPdf($this->createBatchRow($batch));

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.rows.receipt.store', [
                'form1702ExBatchRow' => $row,
            ]), [
                'values' => [
                    'file_name' => 'receipt-source.xml',
                    'date_received_by_bir' => 'April 10, 2026',
                    'time_received_by_bir' => '9:45 AM',
                ],
            ])
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success', 'Receipt queued for the selected row.');

        $row->refresh();

        $this->assertSame(
            Form1702ExBatchRow::RECEIPT_JOB_STATUS_QUEUED,
            $row->receipt_job_status,
        );
        $this->assertNull($row->receipt_storage_path);
        $this->assertNull($row->receipt_file_name);

        $queuedJob = $this->assertReceiptJobQueuedFor($row);
        $queuedJob->handle(
            app(Form1702ExRowReceiptService::class),
            app(BirReceiptAutoMatchService::class),
            app(Form1702ExCompletedEmailService::class),
        );

        $row->refresh();
        $expectedReceiptFileName = pathinfo(
            (string) $row->generated_pdf_file_name,
            PATHINFO_FILENAME,
        ).'-receipt.pdf';

        $this->assertNull($row->receipt_job_status);
        $this->assertNull($row->receipt_job_error);
        $this->assertSame($expectedReceiptFileName, $row->receipt_file_name);
        $this->assertNotNull($row->receipt_storage_path);
        $this->assertNotNull($row->receipt_file_size);
        Storage::disk('local')->assertExists((string) $row->receipt_storage_path);

        $receiptText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('local')->path((string) $row->receipt_storage_path),
        );
        $mergedText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('local')->path((string) $row->generated_pdf_storage_path),
        );

        $this->assertStringContainsStringIgnoringCase(
            'Tax Return Receipt Confirmation',
            $receiptText,
        );
        $this->assertStringContainsString('receipt-source.xml', $receiptText);
        $this->assertStringContainsString('April 10, 2026', $receiptText);
        $this->assertStringContainsString('9:45 AM', $receiptText);
        $this->assertStringContainsStringIgnoringCase(
            'Tax Return Receipt Confirmation',
            $mergedText,
        );
        $this->assertStringContainsString('FOUNDATION FOR COMMUNITY GROWTH, INC.', $mergedText);
        $this->assertStringContainsString('receipt-source.xml', $mergedText);

        Mail::assertQueued(Form1702ExCompletedRowEmail::class, function (Form1702ExCompletedRowEmail $mail) use ($row): bool {
            return $mail->hasTo('finance@communitygrowth.org')
                && $mail->row->is($row);
        });
        Mail::assertNotQueued(Form1702ExCompletedRowsEmail::class);

        $row->refresh();
        $this->assertNotNull($row->completed_email_auto_hash);
        $this->assertSame('finance@communitygrowth.org', $row->completed_email_auto_recipient);
        $this->assertNotNull($row->completed_email_auto_queued_at);
    }

    public function test_staff_users_can_upload_temporary_receipts_for_generated_rows(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Temporary Receipt Batch',
        ]);
        $row = $this->generateRowPdf($this->createBatchRow($batch));

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.rows.receipt.temporary.store', [
                'form1702ExBatchRow' => $row,
            ]), [
                'temporaryReceipt' => UploadedFile::fake()->create('temp-receipt.pdf', 12, 'application/pdf'),
                'recipientEmail' => 'replacement@example.com',
            ])
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success', 'Temporary receipt added for the selected row.');

        $row->refresh();

        $this->assertTrue($row->receipt_is_temporary);
        $this->assertSame('replacement@example.com', $row->completed_email_recipient);
        $this->assertNotNull($row->receipt_file_name);
        $this->assertNotNull($row->receipt_storage_path);
        $this->assertNotNull($row->receipt_file_size);
        Storage::disk('local')->assertExists((string) $row->receipt_storage_path);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('forms/1702-ex/Index')
                ->where('pagination.total', 1)
                ->where('completedCount', 0)
                ->where('rows.0.id', $row->uuid));

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.completed.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('forms/1702-ex/Completed')
                ->where('pagination.total', 0));
    }

    public function test_auto_matched_official_receipt_replaces_temporary_receipt_and_moves_row_to_completed(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Temporary To Official Batch',
        ]);
        $row = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'tin' => '010803043000',
            ]),
        ]));

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.rows.receipt.temporary.store', [
                'form1702ExBatchRow' => $row,
            ]), [
                'temporaryReceipt' => UploadedFile::fake()->create('temp-receipt.pdf', 12, 'application/pdf'),
                'recipientEmail' => 'finance@communitygrowth.org',
            ])
            ->assertRedirect(route('forms.1702-ex.index'));

        $row->refresh();
        $this->assertTrue($row->receipt_is_temporary);

        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $staff->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9901',
            'message_id' => '<message-9901@example.com>',
            'subject' => 'BIR receipt confirmation',
            'body_text' => implode("\n", [
                'File name: 010803043000-1702EXv2018C-122025.xml',
                'Date received by BIR: 10 April 2026',
                'Time received by BIR: 02:49 PM',
            ]),
            'synced_at' => now(),
        ]);

        app(BirReceiptAutoMatchService::class)->syncEmail($email);

        $email->refresh();
        $row->refresh();

        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_QUEUED, $email->bir_receipt_match_status);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_QUEUED, $row->auto_receipt_status);
        $this->assertTrue($row->receipt_is_temporary);

        $queuedJob = $this->assertReceiptJobQueuedFor($row);
        $queuedJob->handle(
            app(Form1702ExRowReceiptService::class),
            app(BirReceiptAutoMatchService::class),
            app(Form1702ExCompletedEmailService::class),
        );

        $email->refresh();
        $row->refresh();

        $this->assertFalse($row->receipt_is_temporary);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_APPLIED, $email->bir_receipt_match_status);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_APPLIED, $row->auto_receipt_status);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('forms/1702-ex/Index')
                ->where('pagination.total', 0)
                ->where('completedCount', 1));

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.completed.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('forms/1702-ex/Completed')
                ->where('pagination.total', 1));
    }

    public function test_bir_receipt_email_details_auto_match_and_queue_for_generated_rows(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Auto Match Batch',
        ]);
        $row = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'tin' => '010803043000',
            ]),
        ]));
        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $staff->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9001',
            'message_id' => '<message-9001@example.com>',
            'subject' => 'BIR receipt confirmation',
            'body_text' => implode("\n", [
                'File name: 010803043000-1702EXv2018C-122025.xml',
                'Date received by BIR: 10 April 2026',
                'Time received by BIR: 02:49 PM',
            ]),
            'synced_at' => now(),
        ]);

        app(BirReceiptAutoMatchService::class)->syncEmail($email);

        $email->refresh();
        $row->refresh();

        $this->assertSame((string) $row->id, (string) $email->matched_form_1702_ex_batch_row_id);
        $this->assertSame('010803043000', $email->bir_receipt_tin);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_QUEUED, $email->bir_receipt_match_status);
        $this->assertSame(Form1702ExBatchRow::RECEIPT_JOB_STATUS_QUEUED, $row->receipt_job_status);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_QUEUED, $row->auto_receipt_status);

        $queuedJob = $this->assertReceiptJobQueuedFor($row);
        $this->assertSame($email->id, $queuedJob->syncedEmailId);

        $queuedJob->handle(
            app(Form1702ExRowReceiptService::class),
            app(BirReceiptAutoMatchService::class),
            app(Form1702ExCompletedEmailService::class),
        );

        $email->refresh();
        $row->refresh();

        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_APPLIED, $email->bir_receipt_match_status);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_APPLIED, $row->auto_receipt_status);
        $this->assertNotNull($row->receipt_storage_path);
        Mail::assertQueued(Form1702ExCompletedRowEmail::class, function (Form1702ExCompletedRowEmail $mail) use ($row): bool {
            return $mail->hasTo('finance@communitygrowth.org')
                && $mail->row->is($row);
        });
        Mail::assertNotQueued(Form1702ExCompletedRowsEmail::class);

        $receiptText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('local')->path((string) $row->receipt_storage_path),
        );

        $this->assertStringContainsString('010803043000-1702EXv2018C-122025.xml', $receiptText);
        $this->assertStringContainsString('10 April 2026', $receiptText);
        $this->assertStringContainsString('02:49 PM', $receiptText);
    }

    public function test_bir_receipt_email_matches_wait_until_the_row_pdf_is_generated(): void
    {
        Storage::fake('local');
        Queue::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Pending Auto Match Batch',
        ]);
        $row = $this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'tin' => '010803043000',
            ]),
        ]);
        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $staff->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9002',
            'message_id' => '<message-9002@example.com>',
            'subject' => 'BIR receipt confirmation',
            'body_text' => implode("\n", [
                'File name: 010803043000-1702EXv2018C-122025.xml',
                'Date received by BIR: 10 April 2026',
                'Time received by BIR: 02:49 PM',
            ]),
            'synced_at' => now(),
        ]);

        app(BirReceiptAutoMatchService::class)->syncEmail($email);

        $email->refresh();
        $row->refresh();

        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_PENDING_PDF, $email->bir_receipt_match_status);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_PENDING_PDF, $row->auto_receipt_status);
        Queue::assertNotPushed(GenerateForm1702ExRowReceipt::class);

        (new ProcessForm1702ExBatchRows([$row->id]))->handle(
            app(Form1702ExService::class),
            app(BirReceiptAutoMatchService::class),
        );

        $email->refresh();
        $row->refresh();

        $this->assertSame(Form1702ExBatchRow::PDF_STATUS_GENERATED, $row->pdf_status);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_QUEUED, $email->bir_receipt_match_status);
        Queue::assertPushed(GenerateForm1702ExRowReceipt::class, function (GenerateForm1702ExRowReceipt $job) use ($email, $row): bool {
            return $job->rowId === $row->id
                && $job->syncedEmailId === $email->id;
        });
    }

    public function test_bir_receipt_auto_match_skips_rows_when_bir_receipt_date_is_before_the_acceptance_start_date(): void
    {
        Storage::fake('local');
        Queue::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Receipt Gate Batch',
            'receipt_acceptance_start_date' => '2026-04-10',
        ]);
        $row = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'tin' => '010803043000',
                'receipt_acceptance_start_date' => '2026-04-10',
            ]),
        ]));
        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $staff->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9004',
            'message_id' => '<message-9004@example.com>',
            'subject' => 'Too early BIR receipt confirmation',
            'body_text' => implode("\n", [
                'File name: 010803043000-1702EXv2018C-122025.xml',
                'Date received by BIR: 09 April 2026',
                'Time received by BIR: 02:49 PM',
            ]),
            'synced_at' => now(),
        ]);

        app(BirReceiptAutoMatchService::class)->syncEmail($email);

        $email->refresh();
        $row->refresh();

        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_UNMATCHED, $email->bir_receipt_match_status);
        $this->assertNull($email->matched_form_1702_ex_batch_row_id);
        $this->assertNull($row->auto_receipt_status);
        Queue::assertNotPushed(GenerateForm1702ExRowReceipt::class);
    }

    public function test_existing_stored_emails_only_reconcile_when_the_bir_receipt_date_passes_the_acceptance_start_date(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Stored Email Gate Batch',
            'receipt_acceptance_start_date' => '2026-04-10',
        ]);
        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $staff->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9013',
            'message_id' => '<message-9013@example.com>',
            'subject' => 'Earlier BIR receipt',
            'body_text' => implode("\n", [
                'File name: 010803043000-1702EXv2018C-122025.xml',
                'Date received by BIR: 09 April 2026',
                'Time received by BIR: 02:49 PM',
            ]),
            'synced_at' => now(),
        ]);

        app(BirReceiptAutoMatchService::class)->syncEmail($email);
        $email->refresh();
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_UNMATCHED, $email->bir_receipt_match_status);

        $row = app(\App\Services\Form1702ExBatchService::class)->storeImport($batch, [
            'sourceName' => 'match.csv',
            'sourceType' => 'csv',
            'importedAt' => now()->toIso8601String(),
            'rows' => [[
                'rowNumber' => 2,
                'payload' => $this->validPayload([
                    'tin' => '010803043000',
                ]),
            ]],
        ], false)->sole();

        $email->refresh();
        $row->refresh();

        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_UNMATCHED, $email->bir_receipt_match_status);
        $this->assertNull($email->matched_form_1702_ex_batch_row_id);
        $this->assertNull($row->auto_receipt_status);
    }

    public function test_existing_bir_receipt_email_is_auto_reconciled_when_a_matching_row_is_uploaded(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Uploaded Later Batch',
        ]);
        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $staff->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9010',
            'message_id' => '<message-9010@example.com>',
            'subject' => 'Earlier BIR receipt',
            'body_text' => implode("\n", [
                'File name: 010803043000-1702EXv2018C-122025.xml',
                'Date received by BIR: 10 April 2026',
                'Time received by BIR: 02:49 PM',
            ]),
            'synced_at' => now(),
        ]);

        app(BirReceiptAutoMatchService::class)->syncEmail($email);

        $email->refresh();
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_UNMATCHED, $email->bir_receipt_match_status);

        $row = app(\App\Services\Form1702ExBatchService::class)->storeImport($batch, [
            'sourceName' => 'match.csv',
            'sourceType' => 'csv',
            'importedAt' => now()->toIso8601String(),
            'rows' => [[
                'rowNumber' => 2,
                'payload' => $this->validPayload([
                    'tin' => '010803043000',
                ]),
            ]],
        ], false)->sole();

        $email->refresh();
        $row->refresh();

        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_PENDING_PDF, $email->bir_receipt_match_status);
        $this->assertSame($row->id, $email->matched_form_1702_ex_batch_row_id);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_PENDING_PDF, $row->auto_receipt_status);
        $this->assertSame($email->id, $row->auto_receipt_synced_email_id);
    }

    public function test_duplicate_tin_rows_use_fifo_and_hide_later_duplicates_after_the_first_receipt_is_applied(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        $this->withoutVite();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'FIFO Duplicate Batch',
        ]);
        $uploadedAt = now()->subHour();
        $oldestRow = $this->generateRowPdf($this->createBatchRow($batch, [
            'uploaded_at' => $uploadedAt,
            'payload' => $this->validPayload([
                'tin' => '010803043000',
                'taxpayer_name' => 'Oldest Foundation',
            ]),
            'source_row_number' => 2,
        ]));
        $secondRow = $this->generateRowPdf($this->createBatchRow($batch, [
            'uploaded_at' => $uploadedAt,
            'payload' => $this->validPayload([
                'tin' => '010803043000',
                'taxpayer_name' => 'Second Foundation',
            ]),
            'source_row_number' => 3,
        ]));
        $thirdRow = $this->generateRowPdf($this->createBatchRow($batch, [
            'uploaded_at' => $uploadedAt,
            'payload' => $this->validPayload([
                'tin' => '010803043000',
                'taxpayer_name' => 'Third Foundation',
            ]),
            'source_row_number' => 4,
        ]));
        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $staff->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9011',
            'message_id' => '<message-9011@example.com>',
            'subject' => 'FIFO receipt confirmation',
            'body_text' => implode("\n", [
                'File name: 010803043000-1702EXv2018C-122025.xml',
                'Date received by BIR: 10 April 2026',
                'Time received by BIR: 02:49 PM',
            ]),
            'synced_at' => now(),
        ]);

        app(BirReceiptAutoMatchService::class)->syncEmail($email);

        $queuedJob = $this->assertReceiptJobQueuedFor($oldestRow);
        $this->assertSame($email->id, $queuedJob->syncedEmailId);
        Queue::assertNotPushed(GenerateForm1702ExRowReceipt::class, function (GenerateForm1702ExRowReceipt $job) use ($secondRow, $thirdRow): bool {
            return in_array($job->rowId, [$secondRow->id, $thirdRow->id], true);
        });

        $queuedJob->handle(
            app(Form1702ExRowReceiptService::class),
            app(BirReceiptAutoMatchService::class),
            app(Form1702ExCompletedEmailService::class),
        );

        $email->refresh();
        $oldestRow->refresh();
        $secondRow->refresh();
        $thirdRow->refresh();

        $this->assertSame($oldestRow->id, $email->matched_form_1702_ex_batch_row_id);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_APPLIED, $email->bir_receipt_match_status);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_APPLIED, $oldestRow->auto_receipt_status);
        $this->assertSame(Form1702ExBatchRow::DUPLICATE_RESOLUTION_SKIPPED, $secondRow->duplicate_resolution_status);
        $this->assertSame($oldestRow->id, $secondRow->duplicate_of_form_1702_ex_batch_row_id);
        $this->assertSame(Form1702ExBatchRow::DUPLICATE_RESOLUTION_SKIPPED, $thirdRow->duplicate_resolution_status);
        $this->assertSame($oldestRow->id, $thirdRow->duplicate_of_form_1702_ex_batch_row_id);
        $this->assertNull($secondRow->auto_receipt_status);
        $this->assertNull($thirdRow->auto_receipt_status);

        Mail::assertQueued(Form1702ExCompletedRowEmail::class, 1);
        Mail::assertNotQueued(Form1702ExCompletedRowsEmail::class);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('forms/1702-ex/Index')
                ->where('pagination.total', 0)
                ->where('completedCount', 1));

        $this->assertSame(3, Form1702ExBatchRow::query()->count());
    }

    public function test_completed_duplicate_tin_rows_are_not_reselected_ahead_of_the_next_fifo_candidate(): void
    {
        Storage::fake('local');
        Queue::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Completed Duplicate FIFO Batch',
        ]);
        $uploadedAt = now()->subHour();
        $completedOldestRow = $this->attachReceipt($this->generateRowPdf($this->createBatchRow($batch, [
            'uploaded_at' => $uploadedAt,
            'payload' => $this->validPayload([
                'tin' => '010803043000',
            ]),
            'source_row_number' => 2,
        ])));
        $nextEligibleRow = $this->generateRowPdf($this->createBatchRow($batch, [
            'uploaded_at' => $uploadedAt,
            'payload' => $this->validPayload([
                'tin' => '010803043000',
                'taxpayer_name' => 'Next Eligible Foundation',
            ]),
            'source_row_number' => 3,
        ]));
        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $staff->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9012',
            'message_id' => '<message-9012@example.com>',
            'subject' => 'Second FIFO receipt confirmation',
            'body_text' => implode("\n", [
                'File name: 010803043000-1702EXv2018C-122025.xml',
                'Date received by BIR: 11 April 2026',
                'Time received by BIR: 08:30 AM',
            ]),
            'synced_at' => now(),
        ]);

        app(BirReceiptAutoMatchService::class)->syncEmail($email);

        $email->refresh();
        $nextEligibleRow->refresh();

        $this->assertSame($nextEligibleRow->id, $email->matched_form_1702_ex_batch_row_id);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_QUEUED, $email->bir_receipt_match_status);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_QUEUED, $nextEligibleRow->auto_receipt_status);
    }

    public function test_completed_rows_without_recipient_do_not_auto_queue_email(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Missing Recipient Batch',
        ]);
        $row = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'email_address' => '',
            ]),
        ]));

        $job = new GenerateForm1702ExRowReceipt($row->id, [
            'file_name' => 'receipt-source.xml',
            'date_received_by_bir' => 'April 10, 2026',
            'time_received_by_bir' => '9:45 AM',
        ]);
        $job->handle(
            app(Form1702ExRowReceiptService::class),
            app(BirReceiptAutoMatchService::class),
            app(Form1702ExCompletedEmailService::class),
        );

        Mail::assertNothingQueued();
        $row->refresh();
        $this->assertNull($row->completed_email_auto_hash);
        $this->assertNull($row->completed_email_auto_queued_at);
    }

    public function test_completed_rows_auto_email_only_once_for_the_same_completed_artifact(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Auto Email Once Batch',
        ]);
        $row = $this->generateRowPdf($this->createBatchRow($batch));

        $job = new GenerateForm1702ExRowReceipt($row->id, [
            'file_name' => 'receipt-source.xml',
            'date_received_by_bir' => 'April 10, 2026',
            'time_received_by_bir' => '9:45 AM',
        ]);
        $job->handle(
            app(Form1702ExRowReceiptService::class),
            app(BirReceiptAutoMatchService::class),
            app(Form1702ExCompletedEmailService::class),
        );
        $job->handle(
            app(Form1702ExRowReceiptService::class),
            app(BirReceiptAutoMatchService::class),
            app(Form1702ExCompletedEmailService::class),
        );

        Mail::assertQueued(Form1702ExCompletedRowEmail::class, 1);
        Mail::assertNotQueued(Form1702ExCompletedRowsEmail::class);
    }

    public function test_two_completed_rows_for_the_same_recipient_queue_two_individual_emails_only(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Grouped Recipient Batch',
        ]);
        $rowOne = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'email_address' => 'shared@example.com',
                'tin' => '0101112220000',
            ]),
            'source_row_number' => 2,
        ]));
        $rowTwo = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'email_address' => 'shared@example.com',
                'tin' => '0101112220001',
            ]),
            'source_row_number' => 3,
        ]));

        $this->runReceiptJob($rowOne);
        $this->runReceiptJob($rowTwo);

        Mail::assertQueued(Form1702ExCompletedRowEmail::class, 2);
        Mail::assertNotQueued(Form1702ExCompletedRowsEmail::class);
    }

    public function test_a_new_later_completed_row_for_the_same_recipient_still_queues_only_individual_emails(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Growing Group Batch',
        ]);
        $rowOne = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'email_address' => 'shared@example.com',
                'tin' => '0101112220000',
            ]),
            'source_row_number' => 2,
        ]));
        $rowTwo = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'email_address' => 'shared@example.com',
                'tin' => '0101112220001',
            ]),
            'source_row_number' => 3,
        ]));
        $rowThree = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'email_address' => 'shared@example.com',
                'tin' => '0101112220002',
            ]),
            'source_row_number' => 4,
        ]));

        $this->runReceiptJob($rowOne);
        $this->runReceiptJob($rowTwo);
        $this->runReceiptJob($rowThree);

        Mail::assertQueued(Form1702ExCompletedRowEmail::class, 3);
        Mail::assertNotQueued(Form1702ExCompletedRowsEmail::class);
    }

    public function test_duplicate_reprocessing_does_not_resend_any_grouped_email(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Duplicate Group Batch',
        ]);
        $rowOne = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'email_address' => 'shared@example.com',
                'tin' => '0101112220000',
            ]),
            'source_row_number' => 2,
        ]));
        $rowTwo = $this->generateRowPdf($this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'email_address' => 'shared@example.com',
                'tin' => '0101112220001',
            ]),
            'source_row_number' => 3,
        ]));

        $this->runReceiptJob($rowOne);
        $this->runReceiptJob($rowTwo);
        $this->runReceiptJob($rowTwo);

        Mail::assertQueued(Form1702ExCompletedRowEmail::class, 2);
        Mail::assertNotQueued(Form1702ExCompletedRowsEmail::class);
    }

    public function test_unmatched_bir_receipt_email_is_stored_without_queueing_a_receipt(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $staff->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9003',
            'message_id' => '<message-9003@example.com>',
            'subject' => 'BIR receipt confirmation',
            'body_text' => implode("\n", [
                'File name: 010803043000-1702EXv2018C-122025.xml',
                'Date received by BIR: 10 April 2026',
                'Time received by BIR: 02:49 PM',
            ]),
            'synced_at' => now(),
        ]);

        app(BirReceiptAutoMatchService::class)->syncEmail($email);

        $email->refresh();

        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_UNMATCHED, $email->bir_receipt_match_status);
        $this->assertSame('010803043000', $email->bir_receipt_tin);
        $this->assertNull($email->matched_form_1702_ex_batch_row_id);
        Queue::assertNotPushed(GenerateForm1702ExRowReceipt::class);
    }

    public function test_staff_users_can_remove_attached_receipts_and_restore_the_saved_row_pdf(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Receipt Removal Batch',
        ]);
        $row = $this->attachReceipt($this->generateRowPdf($this->createBatchRow($batch)));
        $receiptStoragePath = (string) $row->receipt_storage_path;
        $receiptBasePath = $row->receiptBaseStoragePath();

        Storage::disk('local')->assertExists($receiptStoragePath);
        Storage::disk('local')->assertExists($receiptBasePath);

        $this->actingAs($staff)
            ->delete(route('forms.1702-ex.rows.receipt.destroy', [
                'form1702ExBatchRow' => $row,
            ]))
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success', 'Receipt removed from the selected row.');

        $row->refresh();

        $this->assertNull($row->receipt_file_name);
        $this->assertNull($row->receipt_storage_path);
        $this->assertNull($row->receipt_file_size);
        $this->assertNull($row->receipt_job_status);
        $this->assertNull($row->receipt_job_error);
        Storage::disk('local')->assertMissing($receiptStoragePath);
        Storage::disk('local')->assertMissing($receiptBasePath);

        $restoredText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('local')->path((string) $row->generated_pdf_storage_path),
        );

        $this->assertStringContainsString('FOUNDATION FOR COMMUNITY GROWTH, INC.', $restoredText);
        $this->assertStringNotContainsString('receipt-source.xml', $restoredText);
        $this->assertStringNotContainsStringIgnoringCase(
            'Tax Return Receipt Confirmation',
            $restoredText,
        );
    }

    public function test_regenerating_a_row_clears_existing_receipt_state_and_queues_pdf_regeneration(): void
    {
        Storage::fake('local');
        Queue::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Receipt Regenerate Batch',
        ]);
        $row = $this->attachReceipt($this->generateRowPdf($this->createBatchRow($batch)));
        $generatedPdfPath = (string) $row->generated_pdf_storage_path;
        $receiptStoragePath = (string) $row->receipt_storage_path;
        $receiptBasePath = $row->receiptBaseStoragePath();

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.rows.regenerate', [
                'form1702ExBatchRow' => $row,
            ]), [
                'footerSourcePath' => 'file:///tmp/updated-footer.pdf',
                'footerPrintedDate' => '10/04/2026',
            ])
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success', 'PDF regeneration queued for the selected row.');

        $row->refresh();

        $this->assertSame(Form1702ExBatchRow::PDF_STATUS_QUEUED, $row->pdf_status);
        $this->assertNull($row->generated_pdf_file_name);
        $this->assertNull($row->generated_pdf_storage_path);
        $this->assertNull($row->generated_at);
        $this->assertNull($row->receipt_file_name);
        $this->assertNull($row->receipt_storage_path);
        $this->assertNull($row->receipt_file_size);
        $this->assertNull($row->receipt_job_status);
        $this->assertNull($row->receipt_job_error);
        Storage::disk('local')->assertMissing($generatedPdfPath);
        Storage::disk('local')->assertMissing($receiptStoragePath);
        Storage::disk('local')->assertMissing($receiptBasePath);

        Queue::assertPushed(ProcessForm1702ExBatchRows::class, function (ProcessForm1702ExBatchRows $job) use ($row): bool {
            return $job->rowIds === [$row->id];
        });
    }

    public function test_receipt_downloads_are_authorized_for_the_owner_only(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $otherStaff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $owner->id,
            'name' => 'Receipt Owner Batch',
        ]);
        $row = $this->attachReceipt($this->generateRowPdf($this->createBatchRow($batch)));

        $this->actingAs($owner)
            ->get(route('forms.1702-ex.rows.receipt.download', [
                'form1702ExBatchRow' => $row,
            ]))
            ->assertOk()
            ->assertDownload((string) $row->receipt_file_name);

        $this->actingAs($otherStaff)
            ->get(route('forms.1702-ex.rows.receipt.download', [
                'form1702ExBatchRow' => $row,
            ]))
            ->assertNotFound();
    }

    private function assertReceiptJobQueuedFor(Form1702ExBatchRow $row): GenerateForm1702ExRowReceipt
    {
        $queuedJob = null;

        Queue::assertPushed(GenerateForm1702ExRowReceipt::class, function (GenerateForm1702ExRowReceipt $job) use ($row, &$queuedJob): bool {
            if ($job->rowId !== $row->id) {
                return false;
            }

            $queuedJob = $job;

            return true;
        });

        $this->assertInstanceOf(GenerateForm1702ExRowReceipt::class, $queuedJob);

        return $queuedJob;
    }

    private function generateRowPdf(Form1702ExBatchRow $row): Form1702ExBatchRow
    {
        return app(Form1702ExService::class)->generateBatchRowPdf($row);
    }

    private function attachReceipt(Form1702ExBatchRow $row): Form1702ExBatchRow
    {
        app(Form1702ExRowReceiptService::class)->generateAndAttachReceipt($row, [
            'file_name' => 'receipt-source.xml',
            'date_received_by_bir' => 'April 10, 2026',
            'time_received_by_bir' => '9:45 AM',
        ]);

        return $row->fresh() ?? $row;
    }

    private function runReceiptJob(Form1702ExBatchRow $row): void
    {
        $job = new GenerateForm1702ExRowReceipt($row->id, [
            'file_name' => 'receipt-source.xml',
            'date_received_by_bir' => 'April 10, 2026',
            'time_received_by_bir' => '9:45 AM',
        ]);
        $job->handle(
            app(Form1702ExRowReceiptService::class),
            app(BirReceiptAutoMatchService::class),
            app(Form1702ExCompletedEmailService::class),
        );
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
            'receipt_is_temporary' => false,
            'receipt_job_status' => null,
            'receipt_job_error' => null,
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
