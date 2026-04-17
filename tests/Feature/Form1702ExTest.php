<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ProcessForm1702ExBatchRows;
use App\Jobs\ProcessForm1702ExRowsExport;
use App\Mail\ClientCredentialsEmail;
use App\Models\Client;
use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Models\SyncedEmail;
use App\Models\User;
use App\Services\EmailSync\BirReceiptAutoMatchService;
use App\Services\Form1702ExRowsExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class Form1702ExTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('forms.1702-ex.index'))
            ->assertRedirect(route('login'));
    }

    public function test_guests_are_redirected_from_the_alignment_page(): void
    {
        $this->get(route('forms.1702-ex.alignment'))
            ->assertRedirect(route('login'));
    }

    public function test_staff_users_can_view_the_1702_ex_single_table_index(): void
    {
        $this->withoutVite();

        $staff = User::factory()->create();

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Index')
                ->where('indexUrl', route('forms.1702-ex.index'))
                ->where('importUrl', route('forms.1702-ex.import.store'))
                ->where('bulkDeleteUrl', route('forms.1702-ex.rows.destroy'))
                ->where('rowsExportUrl', route('forms.1702-ex.rows.export'))
                ->where('settingsUpdateUrl', route('forms.1702-ex.settings.update'))
                ->where('filters.search', '')
                ->where('filters.sort', 'uploadedAt')
                ->where('filters.direction', 'desc')
                ->where('pagination.total', 0)
                ->where('rowsExportState.status', null)
                ->has('rows', 0)
                ->missing('batches'),
            );
    }

    public function test_direct_upload_from_the_index_persists_rows_creates_an_internal_batch_and_queues_pdf_generation(): void
    {
        Queue::fake();
        $this->withoutVite();

        $staff = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,rdo_code,registered_address,zip_code,contact_number,recipient,atc,year_month,year_year,incorporation_date,deduction_method,legal_basis,investment_agency,registered_activity,effectivity_from,effectivity_to,tax_due,tax_credits,overpayment,penalty_compromise,total_amount_payable,number_of_attachments',
                'Alpha Ventures OPC,0101112220000,21B,"Address One",2003,09990000001,alpha@example.com,IC011,12,25,06/24/2024,itemized,RA 9178,DTI,REG-001,06/25/2024,06/25/2026,0,0,0,0,0,00',
                'Bravo Digital OPC,0103334440000,21B,"Address Two",2003,09990000002,bravo@example.com,IC011,12,25,06/24/2024,itemized,RA 9178,DTI,REG-002,06/25/2024,06/25/2026,0,0,0,0,0,00',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.import.store'), [
                'spreadsheet' => $upload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success');

        $batch = Form1702ExBatch::query()->whereBelongsTo($staff)->sole();
        $rows = Form1702ExBatchRow::query()
            ->where('form_1702_ex_batch_id', $batch->id)
            ->orderBy('id')
            ->get();

        $this->assertStringStartsWith('Internal ', $batch->name);
        $this->assertCount(2, $rows);
        $this->assertSame(Form1702ExBatchRow::PDF_STATUS_QUEUED, $rows[0]->pdf_status);
        $this->assertSame('1702-ex-import.csv', $rows[0]->source_name);
        $this->assertSame('Bravo Digital OPC', $rows[1]->payload['taxpayer_name'] ?? null);

        Queue::assertPushed(ProcessForm1702ExBatchRows::class, function (ProcessForm1702ExBatchRows $job) use ($rows): bool {
            $expectedIds = $rows->modelKeys();
            $actualIds = $job->rowIds;
            sort($expectedIds);
            sort($actualIds);

            return $expectedIds === $actualIds;
        });

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Index')
                ->where('hasActiveJobs', true)
                ->where('pagination.total', 2)
                ->has('rows', 2)
                ->where('rows.0.pdfStatus', 'queued'),
            );
    }

    public function test_direct_upload_preserves_effectivity_dates_exactly_as_written_in_the_spreadsheet(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,effectivity_from,effectivity_to',
                'Alpha Ventures OPC,0101112220000,09/04/2024,09/04/2026',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.import.store'), [
                'spreadsheet' => $upload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success');

        $batch = Form1702ExBatch::query()->whereBelongsTo($staff)->sole();
        $row = Form1702ExBatchRow::query()
            ->where('form_1702_ex_batch_id', $batch->id)
            ->sole();

        $this->assertSame('09/04/2024', $row->payload['effectivity_from'] ?? null);
        $this->assertSame('09/04/2026', $row->payload['effectivity_to'] ?? null);
    }

    public function test_direct_upload_uses_split_date_headers_for_target_pdf_dates(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,incorporation_date_month,incorporation_date_day,incorporation_date_year,effectivity_from_month,effectivity_from_day,effectivity_from_year,effectivity_to_month,effectivity_to_day,effectivity_to_year',
                'Alpha Ventures OPC,0101112220000,04,09,2024,04,09,2025,04,09,2026',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.import.store'), [
                'spreadsheet' => $upload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success');

        $batch = Form1702ExBatch::query()->whereBelongsTo($staff)->sole();
        $row = Form1702ExBatchRow::query()
            ->where('form_1702_ex_batch_id', $batch->id)
            ->sole();

        $this->assertSame('04/09/2024', $row->payload['incorporation_date'] ?? null);
        $this->assertSame('04/09/2025', $row->payload['effectivity_from'] ?? null);
        $this->assertSame('04/09/2026', $row->payload['effectivity_to'] ?? null);
    }

    public function test_split_date_headers_take_precedence_over_legacy_single_date_columns(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,incorporation_date,incorporation_date_month,incorporation_date_day,incorporation_date_year,effectivity_from,effectivity_from_month,effectivity_from_day,effectivity_from_year,effectivity_to,effectivity_to_month,effectivity_to_day,effectivity_to_year',
                'Alpha Ventures OPC,0101112220000,09/04/2024,04,09,2024,09/04/2025,04,09,2025,09/04/2026,04,09,2026',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.import.store'), [
                'spreadsheet' => $upload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success');

        $batch = Form1702ExBatch::query()->whereBelongsTo($staff)->sole();
        $row = Form1702ExBatchRow::query()
            ->where('form_1702_ex_batch_id', $batch->id)
            ->sole();

        $this->assertSame('04/09/2024', $row->payload['incorporation_date'] ?? null);
        $this->assertSame('04/09/2025', $row->payload['effectivity_from'] ?? null);
        $this->assertSame('04/09/2026', $row->payload['effectivity_to'] ?? null);
    }

    public function test_direct_upload_reconciles_matching_existing_synced_emails(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $email = SyncedEmail::query()->create([
            'claimed_by_user_id' => $staff->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9501',
            'message_id' => '<message-9501@example.com>',
            'subject' => 'Earlier receipt',
            'body_text' => implode("\n", [
                'File name: 0101112220000-1702EXv2018C-122025.xml',
                'Date received by BIR: 10 April 2026',
                'Time received by BIR: 02:49 PM',
            ]),
            'synced_at' => now(),
        ]);

        app(BirReceiptAutoMatchService::class)->syncEmail($email);

        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,rdo_code,registered_address,zip_code,contact_number,recipient,atc,year_month,year_year,incorporation_date,deduction_method,legal_basis,investment_agency,registered_activity,effectivity_from,effectivity_to,tax_due,tax_credits,overpayment,penalty_compromise,total_amount_payable,number_of_attachments',
                'Alpha Ventures OPC,0101112220000,21B,"Address One",2003,09990000001,alpha@example.com,IC011,12,25,06/24/2024,itemized,RA 9178,DTI,REG-001,06/25/2024,06/25/2026,0,0,0,0,0,00',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.form1702ex.import.store'), [
                'spreadsheet' => $upload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.form1702ex.index'));

        $batch = Form1702ExBatch::query()->whereBelongsTo($staff)->sole();
        $row = Form1702ExBatchRow::query()
            ->where('form_1702_ex_batch_id', $batch->id)
            ->sole();

        $email->refresh();
        $row->refresh();

        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_PENDING_PDF, $email->bir_receipt_match_status);
        $this->assertSame($row->id, $email->matched_form_1702_ex_batch_row_id);
        $this->assertSame(BirReceiptAutoMatchService::MATCH_STATUS_PENDING_PDF, $row->auto_receipt_status);
    }

    public function test_reuploading_the_same_tin_is_allowed_while_no_receipt_exists(): void
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
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success');

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.import.store'), [
                'spreadsheet' => UploadedFile::fake()->createWithContent(
                    '1702-ex-import-duplicate.csv',
                    implode("\n", [
                        'registered_name,tin,recipient',
                        'Alpha Ventures OPC duplicate,0101112220000,alpha@example.com',
                    ]),
                ),
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success', 'Imported 1 row(s) from 1702-ex-import-duplicate.csv. PDFs are being generated.');

        $this->assertSame(2, Form1702ExBatch::query()->whereBelongsTo($staff)->count());
        $this->assertSame(2, Form1702ExBatchRow::query()->count());

        Queue::assertPushed(ProcessForm1702ExBatchRows::class, 2);
    }

    public function test_reuploading_the_same_tin_is_immediately_hidden_when_a_receipt_already_exists(): void
    {
        Queue::fake();
        Storage::fake('local');

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Receipt Owner Batch',
        ]);
        $generatedPdfPath = 'forms/'.$staff->id.'/1702-ex/batches/'.$batch->id.'/completed-row.pdf';
        $receiptPath = 'forms/'.$staff->id.'/1702-ex/receipts/'.$batch->id.'/completed-row-receipt.pdf';

        Storage::disk('local')->put($generatedPdfPath, 'fake generated pdf');
        Storage::disk('local')->put($receiptPath, 'fake receipt pdf');

        $receiptOwner = $this->createBatchRow($batch, [
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_GENERATED,
            'generated_pdf_file_name' => 'completed-row.pdf',
            'generated_pdf_storage_path' => $generatedPdfPath,
            'generated_pdf_file_size' => Storage::disk('local')->size($generatedPdfPath),
            'generated_at' => now(),
            'receipt_file_name' => 'completed-row-receipt.pdf',
            'receipt_storage_path' => $receiptPath,
            'receipt_file_size' => Storage::disk('local')->size($receiptPath),
            'payload' => array_replace($this->validPayload(), [
                'tin' => '0101112220000',
            ]),
        ]);

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.import.store'), [
                'spreadsheet' => UploadedFile::fake()->createWithContent(
                    '1702-ex-import-duplicate.csv',
                    implode("\n", [
                        'registered_name,tin,recipient',
                        'Alpha Ventures OPC duplicate,0101112220000,alpha@example.com',
                    ]),
                ),
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success', 'Skipped 1 duplicate TIN row(s) from 1702-ex-import-duplicate.csv because a receipt already exists.');

        $duplicateRow = Form1702ExBatchRow::query()
            ->whereKeyNot($receiptOwner->id)
            ->sole();

        $this->assertSame(Form1702ExBatchRow::DUPLICATE_RESOLUTION_SKIPPED, $duplicateRow->duplicate_resolution_status);
        $this->assertSame($receiptOwner->id, $duplicateRow->duplicate_of_form_1702_ex_batch_row_id);
        $this->assertSame(Form1702ExBatchRow::PDF_STATUS_FAILED, $duplicateRow->pdf_status);

        Queue::assertNothingPushed();
    }

    public function test_staff_users_are_redirected_from_the_alignment_alias_to_the_batch_index(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.alignment'))
            ->assertRedirect(route('forms.1702-ex.index'));
    }

    public function test_import_auto_provisions_a_client_login_and_queues_credentials_to_all_unique_recipients(): void
    {
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,recipient,client_name',
                'Alpha Ventures OPC,0101112220000,recipient.one@example.com,Acme Client',
                'Bravo Ventures OPC,0101112220001,recipient.two@example.com,Acme Client',
                'Charlie Ventures OPC,0101112220002,recipient.one@example.com,Acme Client',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.form1702ex.import.store'), [
                'spreadsheet' => $upload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.form1702ex.index'));

        $client = Client::query()
            ->whereBelongsTo($staff)
            ->where('name_normalized', 'acme client')
            ->first();

        $this->assertNotNull($client);
        $this->assertNotNull($client?->login_user_id);
        $this->assertDatabaseHas('users', [
            'id' => $client?->login_user_id,
            'role' => UserRole::Client->value,
            'email' => 'acmeclient@analytica.ph',
        ]);

        Mail::assertQueued(ClientCredentialsEmail::class, function (ClientCredentialsEmail $mail): bool {
            return $mail->hasTo('recipient.one@example.com')
                && $mail->loginEmail === 'acmeclient@analytica.ph';
        });
        Mail::assertQueued(ClientCredentialsEmail::class, function (ClientCredentialsEmail $mail): bool {
            return $mail->hasTo('recipient.two@example.com')
                && $mail->loginEmail === 'acmeclient@analytica.ph';
        });
        Mail::assertQueued(ClientCredentialsEmail::class, 2);
    }

    public function test_import_skips_client_login_provisioning_for_unassigned_client_names(): void
    {
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,recipient,client_name',
                'Alpha Ventures OPC,0101112220000,recipient.one@example.com,',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.form1702ex.import.store'), [
                'spreadsheet' => $upload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.form1702ex.index'));

        $unassignedClient = Client::query()
            ->whereBelongsTo($staff)
            ->where('name_normalized', 'unassigned client')
            ->first();

        $this->assertNotNull($unassignedClient);
        $this->assertNull($unassignedClient?->login_user_id);
        Mail::assertNothingQueued();
    }

    public function test_reimport_does_not_recreate_or_rotate_existing_client_login_credentials(): void
    {
        Queue::fake();
        Mail::fake();

        $staff = User::factory()->create();
        $firstUpload = UploadedFile::fake()->createWithContent(
            '1702-ex-import-first.csv',
            implode("\n", [
                'registered_name,tin,recipient,client_name',
                'Alpha Ventures OPC,0101112220000,recipient.one@example.com,Acme Client',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.form1702ex.import.store'), [
                'spreadsheet' => $firstUpload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.form1702ex.index'));

        $client = Client::query()
            ->whereBelongsTo($staff)
            ->where('name_normalized', 'acme client')
            ->firstOrFail();
        $originalLoginUserId = (int) $client->login_user_id;

        Mail::assertQueued(ClientCredentialsEmail::class, 1);
        Mail::fake();

        $secondUpload = UploadedFile::fake()->createWithContent(
            '1702-ex-import-second.csv',
            implode("\n", [
                'registered_name,tin,recipient,client_name',
                'Bravo Ventures OPC,0101112220001,recipient.two@example.com,Acme Client',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.form1702ex.import.store'), [
                'spreadsheet' => $secondUpload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.form1702ex.index'));

        $client->refresh();
        $this->assertSame($originalLoginUserId, (int) $client->login_user_id);
        Mail::assertNothingQueued();
    }

    public function test_import_client_login_provisioning_is_skipped_when_base_email_already_exists(): void
    {
        Queue::fake();
        Mail::fake();

        User::factory()->create([
            'email' => 'acmeclient@analytica.ph',
        ]);

        $staff = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            '1702-ex-import.csv',
            implode("\n", [
                'registered_name,tin,recipient,client_name',
                'Alpha Ventures OPC,0101112220000,recipient.one@example.com,Acme Client',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.form1702ex.import.store'), [
                'spreadsheet' => $upload,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect(route('forms.form1702ex.index'));

        $client = Client::query()
            ->whereBelongsTo($staff)
            ->where('name_normalized', 'acme client')
            ->firstOrFail();

        $this->assertNull($client->login_user_id);
        Mail::assertNothingQueued();
    }

    public function test_index_lists_rows_from_multiple_internal_batches_without_exposing_batch_names(): void
    {
        $this->withoutVite();

        $staff = User::factory()->create();
        $olderBatch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Older hidden batch',
        ]);
        $newerBatch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Newer hidden batch',
        ]);

        $this->createBatchRow($olderBatch, [
            'uploaded_at' => Carbon::parse('2026-04-09 09:00:00'),
            'payload' => array_replace($this->validPayload(), [
                'taxpayer_name' => 'Older Foundation',
            ]),
        ]);
        $this->createBatchRow($newerBatch, [
            'uploaded_at' => Carbon::parse('2026-04-10 09:00:00'),
            'payload' => array_replace($this->validPayload(), [
                'taxpayer_name' => 'Newer Foundation',
            ]),
        ]);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Index')
                ->where('pagination.total', 2)
                ->where('rows.0.taxpayerName', 'Newer Foundation')
                ->where('rows.1.taxpayerName', 'Older Foundation')
                ->missing('rows.0.batchName')
                ->missing('rows.1.batchName'),
            );
    }

    public function test_index_paginates_imported_rows(): void
    {
        $this->withoutVite();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Pagination batch',
        ]);

        foreach (range(1, 26) as $offset) {
            $this->createBatchRow($batch, [
                'source_row_number' => $offset + 1,
                'uploaded_at' => Carbon::parse('2026-04-10 12:00:00')->subMinutes($offset),
                'payload' => array_replace($this->validPayload(), [
                    'taxpayer_name' => "Foundation {$offset}",
                ]),
            ]);
        }

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Index')
                ->where('pagination.currentPage', 1)
                ->where('pagination.lastPage', 2)
                ->where('pagination.total', 26)
                ->has('rows', 25)
                ->where('rows.0.taxpayerName', 'Foundation 1'),
            );

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Index')
                ->where('pagination.currentPage', 2)
                ->has('rows', 1)
                ->where('rows.0.taxpayerName', 'Foundation 26'),
            );
    }

    public function test_bulk_delete_from_the_index_deletes_selected_owned_rows(): void
    {
        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Delete batch',
        ]);
        $rowA = $this->createBatchRow($batch);
        $rowB = $this->createBatchRow($batch, [
            'source_row_number' => 3,
        ]);

        $this->actingAs($staff)
            ->delete(route('forms.1702-ex.rows.destroy'), [
                'rowIds' => [$rowA->uuid, $rowB->uuid],
            ])
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('success', 'Deleted 2 imported row(s).');

        $this->assertDatabaseMissing('form_1702_ex_batch_rows', [
            'id' => $rowA->id,
        ]);
        $this->assertDatabaseMissing('form_1702_ex_batch_rows', [
            'id' => $rowB->id,
        ]);
    }

    public function test_replace_existing_removes_old_rows_and_their_generated_pdfs(): void
    {
        Storage::fake('local');
        Queue::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Replace Batch',
        ]);
        $oldStoragePath = 'forms/'.$staff->id.'/1702-ex/batches/'.$batch->id.'/old-row.pdf';

        Storage::disk('local')->put($oldStoragePath, 'old pdf');

        $oldRow = $this->createBatchRow($batch, [
            'source_name' => 'old.csv',
            'source_row_number' => 2,
            'uploaded_at' => Carbon::parse('2026-04-08 09:00:00'),
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_GENERATED,
            'generated_pdf_file_name' => 'old-row.pdf',
            'generated_pdf_storage_path' => $oldStoragePath,
            'generated_pdf_file_size' => Storage::disk('local')->size($oldStoragePath),
            'generated_at' => Carbon::parse('2026-04-08 09:05:00'),
        ]);

        $upload = UploadedFile::fake()->createWithContent(
            'replacement.csv',
            implode("\n", [
                'registered_name,tin',
                'New Foundation,0101112220000',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.batches.import.store', [
                'form1702ExBatch' => $batch,
            ]), [
                'spreadsheet' => $upload,
                'replaceExisting' => true,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('form_1702_ex_batch_rows', [
            'id' => $oldRow->id,
        ]);
        Storage::disk('local')->assertMissing($oldStoragePath);
        $this->assertSame(1, $batch->rows()->count());
    }

    public function test_keep_existing_appends_rows_and_preserves_separate_upload_timestamps(): void
    {
        Queue::fake();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Append Batch',
        ]);

        $this->createBatchRow($batch, [
            'source_name' => 'older.csv',
            'source_row_number' => 2,
            'uploaded_at' => Carbon::parse('2026-04-07 09:00:00'),
            'payload' => array_replace($this->validPayload(), [
                'taxpayer_name' => 'Older Row Foundation',
            ]),
        ]);

        $upload = UploadedFile::fake()->createWithContent(
            'append.csv',
            implode("\n", [
                'registered_name,tin',
                'Newer Row Foundation,0105556660000',
            ]),
        );

        $this->actingAs($staff)
            ->post(route('forms.1702-ex.batches.import.store', [
                'form1702ExBatch' => $batch,
            ]), [
                'spreadsheet' => $upload,
                'replaceExisting' => false,
                'receiptAcceptanceStartDate' => '2026-04-10',
            ])
            ->assertRedirect();

        $rows = $batch->rows()->orderBy('uploaded_at')->get();

        $this->assertCount(2, $rows);
        $this->assertNotSame(
            $rows[0]->uploaded_at?->toIso8601String(),
            $rows[1]->uploaded_at?->toIso8601String(),
        );
    }

    public function test_batch_detail_page_no_longer_exposes_alignment_or_sample_payload_props(): void
    {
        $this->withoutVite();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Detail Batch',
        ]);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $batch,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Show')
                ->where('batch.name', 'Detail Batch')
                ->missing('payload')
                ->missing('importState')
                ->missing('fields')
                ->missing('latestExport')
                ->missing('mockExportUrl')
                ->missing('templatePdfUrl'),
            );
    }

    public function test_generated_batch_rows_cannot_be_previewed_or_downloaded_by_other_staff_users(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $otherStaff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $owner->id,
            'name' => 'Owner Batch',
        ]);
        $storagePath = 'forms/'.$owner->id.'/1702-ex/batches/'.$batch->id.'/secure-row.pdf';

        Storage::disk('local')->put($storagePath, 'secured pdf');

        $row = $this->createBatchRow($batch, [
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_GENERATED,
            'generated_pdf_file_name' => 'secure-row.pdf',
            'generated_pdf_storage_path' => $storagePath,
            'generated_pdf_file_size' => Storage::disk('local')->size($storagePath),
            'generated_at' => now(),
        ]);

        $this->actingAs($otherStaff)
            ->get(route('forms.1702-ex.rows.preview', [
                'form1702ExBatchRow' => $row,
            ]))
            ->assertNotFound();

        $this->actingAs($otherStaff)
            ->get(route('forms.1702-ex.rows.download', [
                'form1702ExBatchRow' => $row,
            ]))
            ->assertNotFound();
    }

    public function test_unmatched_rows_export_is_queued_with_the_current_filters(): void
    {
        Queue::fake();
        $this->withoutVite();

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Queued Export Batch',
        ]);

        $this->createBatchRow($batch, [
            'source_name' => 'import-alpha.csv',
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Alpha Export Foundation',
                'registered_name' => 'Alpha Export Foundation',
            ]),
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_FAILED,
        ]);

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.rows.export', [
                'search' => 'Alpha Export',
                'sort' => 'pdfStatus',
                'direction' => 'asc',
            ]))
            ->assertRedirect(route('forms.1702-ex.index', [
                'search' => 'Alpha Export',
                'sort' => 'pdfStatus',
                'direction' => 'asc',
            ]))
            ->assertSessionHas('success', 'Imported rows export queued. Your Excel file will be ready shortly.');

        Queue::assertPushed(ProcessForm1702ExRowsExport::class, function (ProcessForm1702ExRowsExport $job) use ($staff): bool {
            return $job->userId === $staff->id
                && $job->search === 'Alpha Export'
                && $job->sort === 'pdfStatus'
                && $job->direction === 'asc';
        });

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms/1702-ex/Index')
                ->where('rowsExportState.status', 'queued'),
            );
    }

    public function test_unmatched_rows_export_prepared_download_uses_current_filters_and_excludes_completed_rows(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Export Batch',
            'receipt_acceptance_start_date' => '2026-04-10',
        ]);

        $pendingRow = $this->createBatchRow($batch, [
            'source_name' => 'import-alpha.csv',
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Alpha Export Foundation',
                'registered_name' => 'Alpha Export Foundation',
                'tin' => '001111111111',
            ]),
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_FAILED,
            'pdf_error' => 'PDF build failed.',
            'receipt_job_status' => Form1702ExBatchRow::RECEIPT_JOB_STATUS_FAILED,
            'receipt_job_error' => 'Receipt parse failed.',
            'auto_receipt_status' => 'failed',
            'auto_receipt_error' => 'Email match failed.',
        ]);

        $this->createBatchRow($batch, [
            'source_name' => 'import-bravo.csv',
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Bravo Export Foundation',
                'registered_name' => 'Bravo Export Foundation',
                'tin' => '002222222222',
            ]),
        ]);

        $completedPath = 'forms/'.$staff->id.'/1702-ex/batches/'.$batch->id.'/completed-export.pdf';
        Storage::disk('local')->put($completedPath, 'completed pdf');

        $this->createBatchRow($batch, [
            'source_name' => 'import-completed.csv',
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Completed Export Foundation',
                'registered_name' => 'Completed Export Foundation',
                'tin' => '003333333333',
            ]),
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_GENERATED,
            'generated_pdf_file_name' => 'completed-export.pdf',
            'generated_pdf_storage_path' => $completedPath,
            'generated_pdf_file_size' => Storage::disk('local')->size($completedPath),
            'generated_at' => Carbon::parse('2026-04-12 09:15:00'),
            'receipt_file_name' => 'completed-receipt.pdf',
            'receipt_storage_path' => 'forms/'.$staff->id.'/1702-ex/receipts/'.$batch->id.'/completed-receipt.pdf',
            'receipt_file_size' => 1234,
        ]);

        $job = new ProcessForm1702ExRowsExport(
            $staff->id,
            'Alpha Export',
            'pdfStatus',
            'asc',
        );
        $job->handle(app(Form1702ExRowsExportService::class));

        $response = $this->actingAs($staff)
            ->get(route('forms.1702-ex.rows.export.file'));

        $response->assertOk();
        $response->assertDownload('1702-ex-unmatched-rows.xlsx');
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $downloadedXlsxPath = $response->baseResponse->getFile()->getPathname();
        $archive = new ZipArchive;
        $result = $archive->open($downloadedXlsxPath);
        $this->assertTrue($result === true, 'Expected the unmatched XLSX archive to open successfully.');

        $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
        $this->assertIsString($sheetXml);
        $archive->close();

        $this->assertStringContainsString('Alpha Export Foundation', $sheetXml);
        $this->assertStringNotContainsString('Bravo Export Foundation', $sheetXml);
        $this->assertStringNotContainsString('Completed Export Foundation', $sheetXml);
        $this->assertSame(Form1702ExBatchRow::PDF_STATUS_FAILED, $pendingRow->fresh()?->pdf_status);
    }

    public function test_unmatched_rows_export_does_not_queue_when_the_current_staff_user_has_no_matching_rows(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $otherStaff = User::factory()->create();
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $owner->id,
            'name' => 'Owner Export Batch',
        ]);

        $this->createBatchRow($batch, [
            'payload' => $this->validPayload([
                'taxpayer_name' => 'Private Export Foundation',
            ]),
        ]);

        $this->actingAs($otherStaff)
            ->get(route('forms.1702-ex.rows.export'))
            ->assertRedirect(route('forms.1702-ex.index'))
            ->assertSessionHas('error', 'No imported rows matched this export request.');

        Queue::assertNothingPushed();
    }

    public function test_unmatched_rows_export_returns_an_error_when_no_rows_match(): void
    {
        Queue::fake();

        $staff = User::factory()->create();

        $this->actingAs($staff)
            ->get(route('forms.1702-ex.rows.export', [
                'search' => 'nothing-to-export',
            ]))
            ->assertRedirect(route('forms.1702-ex.index', [
                'search' => 'nothing-to-export',
            ]))
            ->assertSessionHas('error', 'No imported rows matched this export request.');

        Queue::assertNothingPushed();
    }

    public function test_superadmins_are_redirected_to_users_from_the_staff_only_page(): void
    {
        $superadmin = User::factory()->create([
            'role' => UserRole::Superadmin,
        ]);

        $this->actingAs($superadmin)
            ->get(route('forms.1702-ex.index'))
            ->assertRedirect(route('users.index'));
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
