<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\Form1702ExCompletedRowEmail;
use App\Jobs\ProcessForm1702ExCompletedExport;
use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Models\SyncedEmail;
use App\Models\User;
use App\Services\Form1702ExCompletedExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

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
                ->where('exportState.status', null)
                ->where('pagination.total', 1)
                ->where('rows.0.taxpayerName', 'Completed Foundation')
                ->where('rows.0.sendEmailUrl', route('forms.1702-ex.completed.send', [
                    'form1702ExBatchRow' => $completedRow,
                ])),
            );
    }

    public function test_completed_files_zip_download_request_queues_an_export_for_the_current_filters(): void
    {
        Storage::fake('local');
        Queue::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Completed ZIP Batch',
        ]);
        $alphaRow = $this->createCompletedRow($batch, [
            'generated_pdf_file_name' => 'alpha.pdf',
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Alpha Foundation',
                'registered_name' => 'Alpha Foundation',
                'tin' => '001111111111',
            ]),
        ]);
        $betaRow = $this->createCompletedRow($batch, [
            'generated_pdf_file_name' => 'beta.pdf',
            'source_row_number' => 3,
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Beta Foundation',
                'registered_name' => 'Beta Foundation',
                'tin' => '002222222222',
            ]),
        ]);
        $this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Alpha Pending Foundation',
                'registered_name' => 'Alpha Pending Foundation',
                'tin' => '003333333333',
            ]),
        ]);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.completed.download', [
                'search' => 'Foundation',
                'sort' => 'generatedAt',
                'direction' => 'desc',
            ]))
            ->assertRedirect(route('forms.1702-ex.completed.index', [
                'search' => 'Foundation',
                'sort' => 'generatedAt',
                'direction' => 'desc',
            ]))
            ->assertSessionHas('success', 'Completed files export queued. Your ZIP will be ready shortly.');

        Queue::assertPushed(ProcessForm1702ExCompletedExport::class, function (ProcessForm1702ExCompletedExport $job): bool {
            return $job->search === 'Foundation'
                && $job->sort === 'generatedAt'
                && $job->direction === 'desc'
                && $job->rowUuids === [];
        });

        $this->assertNotNull($alphaRow->fresh());
        $this->assertNotNull($betaRow->fresh());
    }

    public function test_completed_files_zip_download_excludes_rows_that_are_not_completed(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Completed ZIP Filtering Batch',
        ]);

        $this->createCompletedRow($batch, [
            'generated_pdf_file_name' => 'included.pdf',
        ]);
        $incompleteRow = $this->createCompletedRow($batch, [
            'generated_pdf_file_name' => 'excluded.pdf',
            'receipt_file_name' => null,
            'receipt_storage_path' => null,
            'receipt_file_size' => null,
        ]);

        $job = new ProcessForm1702ExCompletedExport(
            $staff->id,
            '',
            'generatedAt',
            'desc',
        );
        $job->handle(app(Form1702ExCompletedExportService::class));

        $response = $this->actingAs($staff)
            ->get(route('forms.1702-ex.completed.download.file'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');

        $downloadedZipPath = $response->baseResponse->getFile()->getPathname();

        $archive = new ZipArchive;
        $result = $archive->open($downloadedZipPath);
        $this->assertTrue($result === true, 'Expected the completed ZIP archive to open successfully.');
        $this->assertSame(1, $archive->numFiles);
        $this->assertSame('included.pdf', $archive->getNameIndex(0));
        $archive->close();

        $this->assertNotNull($incompleteRow->fresh());
    }

    public function test_completed_files_zip_download_can_be_limited_to_selected_rows(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Completed ZIP Selected Batch',
        ]);

        $selectedRow = $this->createCompletedRow($batch, [
            'generated_pdf_file_name' => 'selected.pdf',
        ]);
        $this->createCompletedRow($batch, [
            'generated_pdf_file_name' => 'unselected.pdf',
            'source_row_number' => 3,
        ]);

        $job = new ProcessForm1702ExCompletedExport(
            $staff->id,
            '',
            'generatedAt',
            'desc',
            [$selectedRow->uuid],
        );
        $job->handle(app(Form1702ExCompletedExportService::class));

        $response = $this->actingAs($staff)
            ->get(route('forms.1702-ex.completed.download.file'));

        $response->assertOk();

        $downloadedZipPath = $response->baseResponse->getFile()->getPathname();

        $archive = new ZipArchive;
        $result = $archive->open($downloadedZipPath);
        $this->assertTrue($result === true, 'Expected the selected completed ZIP archive to open successfully.');
        $this->assertSame(1, $archive->numFiles);
        $this->assertSame('selected.pdf', $archive->getNameIndex(0));
        $archive->close();
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

    public function test_cancelling_a_completed_file_deletes_the_row_and_returns_its_email_to_the_available_sync_list(): void
    {
        Storage::fake('local');
        $this->withoutVite();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Completed Cancel Batch',
        ]);
        $completedRow = $this->createCompletedRow($batch);
        $syncedEmail = SyncedEmail::query()->create([
            'email_sync_account_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => 'receipt-1',
            'message_id' => '<receipt-1@example.com>',
            'from_name' => 'BIR',
            'from_email' => 'bir@example.com',
            'subject' => 'BIR Receipt',
            'body_preview' => 'Receipt email',
            'body_text' => 'Receipt email body',
            'bir_receipt_file_name' => 'completed-row-receipt.pdf',
            'bir_receipt_date_received_by_bir' => '2026-04-08',
            'bir_receipt_time_received_by_bir' => '10:00 AM',
            'bir_receipt_tin' => '008765432000',
            'bir_receipt_form_type' => '1702EX',
            'matched_form_1702_ex_batch_row_id' => $completedRow->id,
            'bir_receipt_match_status' => 'applied',
            'bir_receipt_queued_at' => now()->subMinute(),
            'bir_receipt_applied_at' => now(),
            'bir_receipt_match_error' => null,
            'received_at' => now()->subMinutes(2),
            'synced_at' => now()->subMinute(),
            'claimed_by_user_id' => $staff->id,
            'claimed_at' => now()->subMinute(),
        ]);

        $completedRow->forceFill([
            'auto_receipt_synced_email_id' => $syncedEmail->id,
            'auto_receipt_status' => 'applied',
        ])->save();

        $generatedPdfPath = (string) $completedRow->generated_pdf_storage_path;
        $receiptPath = (string) $completedRow->receipt_storage_path;

        $this->actingAs($staff)
            ->delete(route('forms.1702-ex.completed.cancel', [
                'form1702ExBatchRow' => $completedRow,
            ]))
            ->assertRedirect(route('forms.1702-ex.completed.index'))
            ->assertSessionHas('success', 'Completed file cancelled and removed.');

        $this->assertNull($completedRow->fresh());
        Storage::disk('local')->assertMissing($generatedPdfPath);
        Storage::disk('local')->assertMissing($receiptPath);

        $syncedEmail->refresh();

        $this->assertNull($syncedEmail->matched_form_1702_ex_batch_row_id);
        $this->assertSame('unmatched', $syncedEmail->bir_receipt_match_status);
        $this->assertNull($syncedEmail->bir_receipt_queued_at);
        $this->assertNull($syncedEmail->bir_receipt_applied_at);
        $this->assertNull($syncedEmail->bir_receipt_match_error);
        $this->assertNull($syncedEmail->claimed_by_user_id);
        $this->assertNull($syncedEmail->claimed_at);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.completed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Completed')
                ->where('pagination.total', 0),
            );

        $this->actingAs($staff)
            ->get(route('email-sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailSync')
                ->where('receiptCounts.applied', 0)
                ->where('receiptCounts.unmatched', 1)
                ->where('emails.0.id', $syncedEmail->id),
            );
    }

    public function test_cancelling_a_completed_file_requires_row_ownership(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $otherStaff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $owner->id,
            'name' => 'Ownership Cancel Batch',
        ]);
        $completedRow = $this->createCompletedRow($batch);

        $this->actingAs($otherStaff)
            ->delete(route('forms.1702-ex.completed.cancel', [
                'form1702ExBatchRow' => $completedRow,
            ]))
            ->assertNotFound();

        $this->assertNotNull($completedRow->fresh());
    }

    public function test_bulk_cancelling_completed_files_deletes_selected_rows_and_resets_their_synced_emails(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Bulk Cancel Batch',
        ]);
        $firstRow = $this->createCompletedRow($batch, [
            'generated_pdf_file_name' => 'first.pdf',
        ]);
        $secondRow = $this->createCompletedRow($batch, [
            'generated_pdf_file_name' => 'second.pdf',
            'source_row_number' => 3,
        ]);

        $firstEmail = SyncedEmail::query()->create([
            'email_sync_account_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => 'bulk-cancel-1',
            'message_id' => '<bulk-cancel-1@example.com>',
            'from_name' => 'BIR',
            'from_email' => 'bir@example.com',
            'subject' => 'BIR Receipt 1',
            'body_preview' => 'Receipt email',
            'body_text' => 'Receipt email body',
            'bir_receipt_file_name' => 'first-receipt.pdf',
            'bir_receipt_date_received_by_bir' => '2026-04-08',
            'bir_receipt_time_received_by_bir' => '10:00 AM',
            'bir_receipt_tin' => '008765432000',
            'bir_receipt_form_type' => '1702EX',
            'matched_form_1702_ex_batch_row_id' => $firstRow->id,
            'bir_receipt_match_status' => 'applied',
            'bir_receipt_queued_at' => now()->subMinute(),
            'bir_receipt_applied_at' => now(),
            'received_at' => now()->subMinutes(2),
            'synced_at' => now()->subMinute(),
            'claimed_by_user_id' => $staff->id,
            'claimed_at' => now()->subMinute(),
        ]);
        $secondEmail = SyncedEmail::query()->create([
            'email_sync_account_id' => null,
            'mailbox' => 'INBOX',
            'imap_uid' => 'bulk-cancel-2',
            'message_id' => '<bulk-cancel-2@example.com>',
            'from_name' => 'BIR',
            'from_email' => 'bir@example.com',
            'subject' => 'BIR Receipt 2',
            'body_preview' => 'Receipt email',
            'body_text' => 'Receipt email body',
            'bir_receipt_file_name' => 'second-receipt.pdf',
            'bir_receipt_date_received_by_bir' => '2026-04-08',
            'bir_receipt_time_received_by_bir' => '10:05 AM',
            'bir_receipt_tin' => '008765432000',
            'bir_receipt_form_type' => '1702EX',
            'matched_form_1702_ex_batch_row_id' => $secondRow->id,
            'bir_receipt_match_status' => 'applied',
            'bir_receipt_queued_at' => now()->subMinute(),
            'bir_receipt_applied_at' => now(),
            'received_at' => now()->subMinutes(2),
            'synced_at' => now()->subMinute(),
            'claimed_by_user_id' => $staff->id,
            'claimed_at' => now()->subMinute(),
        ]);

        $firstRow->forceFill([
            'auto_receipt_synced_email_id' => $firstEmail->id,
            'auto_receipt_status' => 'applied',
        ])->save();
        $secondRow->forceFill([
            'auto_receipt_synced_email_id' => $secondEmail->id,
            'auto_receipt_status' => 'applied',
        ])->save();

        $this->actingAs($staff)
            ->delete(route('forms.1702-ex.completed.cancel.bulk'), [
                'rowIds' => [$firstRow->uuid, $secondRow->uuid],
            ])
            ->assertRedirect(route('forms.1702-ex.completed.index'))
            ->assertSessionHas('success', 'Cancelled 2 completed file(s). Skipped 0 row(s).');

        $this->assertNull($firstRow->fresh());
        $this->assertNull($secondRow->fresh());

        $firstEmail->refresh();
        $secondEmail->refresh();

        $this->assertSame('unmatched', $firstEmail->bir_receipt_match_status);
        $this->assertSame('unmatched', $secondEmail->bir_receipt_match_status);
        $this->assertNull($firstEmail->claimed_by_user_id);
        $this->assertNull($secondEmail->claimed_by_user_id);
    }

    public function test_bulk_cancelling_completed_files_rejects_empty_selection(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();

        $this->actingAs($staff)
            ->delete(route('forms.1702-ex.completed.cancel.bulk'), [
                'rowIds' => [],
            ])
            ->assertSessionHasErrors('rowIds');
    }

    public function test_cancelling_a_non_completed_row_is_rejected(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Non Completed Cancel Batch',
        ]);
        $row = $this->createBatchRow($batch);

        $this->actingAs($staff)
            ->delete(route('forms.1702-ex.completed.cancel', [
                'form1702ExBatchRow' => $row,
            ]))
            ->assertRedirect(route('forms.1702-ex.completed.index'))
            ->assertSessionHas('error', 'Only completed rows can be cancelled from this page.');

        $this->assertNotNull($row->fresh());
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

    public function test_import_without_recipient_header_leaves_completed_row_recipient_email_empty(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,email',
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

        $this->assertSame('', $row->payload['email_address'] ?? null);
    }

    public function test_import_with_blank_recipient_cell_leaves_completed_row_recipient_email_empty(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,recipient',
                'Alpha Ventures OPC,0101112220000,',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.import.store'), [
                'spreadsheet' => $upload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.1702-ex.index'));

        $row = Form1702ExBatchRow::query()->sole();

        $this->assertSame('', $row->payload['email_address'] ?? null);
    }

    public function test_import_does_not_use_alias_headers_for_completed_row_recipient_email(): void
    {
        Queue::fake();
        $staff = User::factory()->create();

        foreach (['recipientemail', 'emailaddress', 'email'] as $index => $header) {
            $upload = UploadedFile::fake()->createWithContent(
                "1702-ex-import-{$header}.csv",
                implode("\n", [
                    "registered_name,tin,{$header}",
                    sprintf('Alpha Ventures OPC %d,010111222%04d,alpha@example.com', $index + 1, $index),
                ]),
            );

            $this->actingAs($staff)
                ->post(route('forms.1702-ex.import.store'), [
                    'spreadsheet' => $upload,
                    'receiptAcceptanceStartDate' => '2026-04-10',
                ])
                ->assertRedirect(route('forms.1702-ex.index'));

            $row = Form1702ExBatchRow::query()->latest('id')->firstOrFail();

            $this->assertSame('', $row->payload['email_address'] ?? null);
        }
    }

    public function test_staff_users_can_save_a_manual_recipient_email_for_a_completed_row(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Manual Recipient Batch',
        ]);
        $completedRow = $this->createCompletedRow($batch, [
            'payload' => $this->validPayload([
                'email_address' => '',
            ]),
        ]);

        $this->actingAs($staff)
            ->patch(route('forms.1702-ex.rows.recipient.update', [
                'form1702ExBatchRow' => $completedRow,
            ]), [
                'recipientEmail' => 'manual@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Recipient email saved.');

        $completedRow->refresh();

        $this->assertSame('manual@example.com', $completedRow->payload['email_address'] ?? null);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.completed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.recipientEmail', 'manual@example.com')
                ->where('rows.0.sendEmailUrl', route('forms.1702-ex.completed.send', [
                    'form1702ExBatchRow' => $completedRow,
                ]))
            );
    }

    public function test_staff_users_can_clear_a_manual_recipient_email_for_a_completed_row(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Clear Recipient Batch',
        ]);
        $completedRow = $this->createCompletedRow($batch);

        $this->actingAs($staff)
            ->patch(route('forms.1702-ex.rows.recipient.update', [
                'form1702ExBatchRow' => $completedRow,
            ]), [
                'recipientEmail' => '',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Recipient email cleared.');

        $completedRow->refresh();

        $this->assertNull($completedRow->payload['email_address'] ?? null);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.completed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.recipientEmail', null)
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
