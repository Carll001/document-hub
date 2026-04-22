<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_show_paginates_companies_server_side(): void
    {
        Storage::fake('local');
        $this->withoutVite();

        $staff = User::factory()->create();
        $client = Client::query()->create([
            'user_id' => $staff->id,
            'name' => 'Paginated Client',
            'name_normalized' => 'paginated client',
        ]);
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Client Batch',
        ]);

        for ($index = 1; $index <= 11; $index++) {
            $company = Company::query()->create([
                'user_id' => $staff->id,
                'client_id' => $client->id,
                'name' => sprintf('Company %02d', $index),
                'name_normalized' => sprintf('company %02d', $index),
                'tin' => sprintf('100000000%03d', $index),
                'tin_normalized' => sprintf('100000000%03d', $index),
            ]);

            $this->createCompletedRow($batch, $client, $company, [
                'generated_at' => now()->subMinutes($index),
                'uploaded_at' => now()->subMinutes($index),
                'source_row_number' => $index + 1,
                'generated_pdf_file_name' => sprintf('company-%02d.pdf', $index),
                'payload' => $this->validPayload([
                    'taxpayer_name' => $company->name,
                    'registered_name' => $company->name,
                    'tin' => $company->tin,
                    'email_address' => sprintf('company%02d@example.com', $index),
                ]),
            ]);
        }

        $this->actingAs($staff)
            ->get(route('clients.show', ['client' => $client]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClientShow')
                ->where('pageUrl', route('clients.show', ['client' => $client]))
                ->where('client.pagination.currentPage', 1)
                ->where('client.pagination.lastPage', 2)
                ->where('client.pagination.total', 11)
                ->has('client.companies', 10)
                ->where('client.companies.0.name', 'Company 01')
                ->where('client.companies.9.name', 'Company 10')
            );

        $this->actingAs($staff)
            ->get(route('clients.show', ['client' => $client, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClientShow')
                ->where('client.pagination.currentPage', 2)
                ->where('client.pagination.lastPage', 2)
                ->where('client.pagination.total', 11)
                ->has('client.companies', 1)
                ->where('client.companies.0.name', 'Company 11')
            );
    }

    public function test_client_show_header_summary_uses_all_companies_not_current_page_only(): void
    {
        Storage::fake('local');
        $this->withoutVite();

        $staff = User::factory()->create();
        $client = Client::query()->create([
            'user_id' => $staff->id,
            'name' => 'Summary Client',
            'name_normalized' => 'summary client',
        ]);
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Summary Batch',
        ]);

        for ($index = 1; $index <= 11; $index++) {
            $company = Company::query()->create([
                'user_id' => $staff->id,
                'client_id' => $client->id,
                'name' => sprintf('Summary Company %02d', $index),
                'name_normalized' => sprintf('summary company %02d', $index),
                'tin' => sprintf('200000000%03d', $index),
                'tin_normalized' => sprintf('200000000%03d', $index),
            ]);

            $this->createCompletedRow($batch, $client, $company, [
                'generated_at' => now()->subMinutes($index),
                'uploaded_at' => now()->subMinutes($index),
                'source_row_number' => $index + 1,
                'generated_pdf_file_name' => sprintf('summary-%02d.pdf', $index),
                'payload' => $this->validPayload([
                    'taxpayer_name' => $company->name,
                    'registered_name' => $company->name,
                    'tin' => $company->tin,
                    'email_address' => 'shared@example.com',
                ]),
            ]);
        }

        $this->actingAs($staff)
            ->get(route('clients.show', ['client' => $client, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClientShow')
                ->where('client.folder.companyCount', 11)
                ->where('client.folder.completedCount', 11)
                ->where('client.folder.recipientCount', 1)
                ->where('client.folder.primaryRecipient', 'shared@example.com')
                ->where('client.folder.canSend', true)
                ->where('client.folder.warning', null)
                ->where('client.pagination.currentPage', 2)
                ->has('client.companies', 1)
            );
    }

    public function test_client_show_requires_client_ownership_for_paginated_requests(): void
    {
        $owner = User::factory()->create();
        $otherStaff = User::factory()->create();
        $client = Client::query()->create([
            'user_id' => $owner->id,
            'name' => 'Protected Client',
            'name_normalized' => 'protected client',
        ]);

        $this->actingAs($otherStaff)
            ->get(route('clients.show', ['client' => $client, 'page' => 2]))
            ->assertNotFound();
    }

    public function test_client_show_and_send_exclude_temporary_receipt_rows_from_completed_counts(): void
    {
        Storage::fake('local');
        $this->withoutVite();

        $staff = User::factory()->create();
        $client = Client::query()->create([
            'user_id' => $staff->id,
            'name' => 'Temporary Scope Client',
            'name_normalized' => 'temporary scope client',
        ]);
        $company = Company::query()->create([
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'name' => 'Scoped Company',
            'name_normalized' => 'scoped company',
            'tin' => '300000000001',
            'tin_normalized' => '300000000001',
        ]);
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Scoped Batch',
        ]);

        $this->createCompletedRow($batch, $client, $company, [
            'receipt_is_temporary' => true,
        ]);

        $this->actingAs($staff)
            ->get(route('clients.show', ['client' => $client]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClientShow')
                ->where('client.folder.completedCount', 0)
                ->where('client.folder.canSend', false));

        $this->actingAs($staff)
            ->post(route('clients.forms.form1702ex.send', ['client' => $client]))
            ->assertRedirect(route('clients.show', ['client' => $client]))
            ->assertSessionHas('error', 'No completed 1702-EX files are ready for this client yet.');
    }

    private function createCompletedRow(
        Form1702ExBatch $batch,
        Client $client,
        Company $company,
        array $overrides = [],
    ): Form1702ExBatchRow {
        $generatedPdfPath = 'forms/'.$batch->user_id.'/1702-ex/batches/'.$batch->id.'/'.uniqid('generated-', true).'.pdf';
        $receiptPath = 'forms/'.$batch->user_id.'/1702-ex/receipts/'.$batch->id.'/'.uniqid('receipt-', true).'.pdf';

        Storage::disk('s3')->put($generatedPdfPath, 'fake completed pdf');
        Storage::disk('s3')->put($receiptPath, 'fake receipt pdf');

        return Form1702ExBatchRow::query()->create(array_replace([
            'form_1702_ex_batch_id' => $batch->id,
            'client_id' => $client->id,
            'company_id' => $company->id,
            'source_name' => 'seed.csv',
            'source_type' => 'csv',
            'source_row_number' => 2,
            'uploaded_at' => now(),
            'payload' => $this->validPayload([
                'taxpayer_name' => $company->name,
                'registered_name' => $company->name,
                'tin' => $company->tin,
            ]),
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_GENERATED,
            'pdf_error' => null,
            'generated_pdf_file_name' => 'completed-row.pdf',
            'generated_pdf_storage_path' => $generatedPdfPath,
            'generated_pdf_file_size' => Storage::disk('s3')->size($generatedPdfPath),
            'generated_at' => now(),
            'receipt_file_name' => 'completed-row-receipt.pdf',
            'receipt_storage_path' => $receiptPath,
            'receipt_file_size' => Storage::disk('s3')->size($receiptPath),
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
            'taxpayer_name' => 'Foundation for Community Growth, Inc.',
            'registered_name' => 'Foundation for Community Growth, Inc.',
            'email_address' => 'finance@communitygrowth.org',
        ], $overrides);
    }
}
