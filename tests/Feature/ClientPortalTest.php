<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_user_can_only_view_completed_non_temporary_rows_for_the_mapped_client(): void
    {
        Storage::fake('local');
        $this->withoutVite();

        $staff = User::factory()->create();
        $clientLogin = User::factory()->create([
            'role' => UserRole::Client,
        ]);
        $otherClientLogin = User::factory()->create([
            'role' => UserRole::Client,
        ]);

        $client = Client::query()->create([
            'user_id' => $staff->id,
            'login_user_id' => $clientLogin->id,
            'name' => 'Mapped Client',
            'name_normalized' => 'mapped client',
        ]);
        $otherClient = Client::query()->create([
            'user_id' => $staff->id,
            'login_user_id' => $otherClientLogin->id,
            'name' => 'Other Client',
            'name_normalized' => 'other client',
        ]);
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Client Portal Batch',
        ]);

        $visibleRow = $this->createCompletedRow($batch, $client, false);
        $this->createCompletedRow($batch, $client, true);
        $this->createCompletedRow($batch, $otherClient, false);

        $this->actingAs($clientLogin)
            ->get(route('client.files'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Files')
                ->where('client.name', 'Mapped Client')
                ->has('rows', 1)
                ->where('rows.0.id', $visibleRow->uuid)
                ->where('rows.0.previewUrl', route('client.files.preview', ['form1702ExBatchRow' => $visibleRow]))
                ->where('rows.0.downloadUrl', route('client.files.download', ['form1702ExBatchRow' => $visibleRow])));
    }

    public function test_client_user_can_preview_and_download_only_mapped_completed_non_temporary_rows(): void
    {
        Storage::fake('local');

        $staff = User::factory()->create();
        $clientLogin = User::factory()->create([
            'role' => UserRole::Client,
        ]);
        $client = Client::query()->create([
            'user_id' => $staff->id,
            'login_user_id' => $clientLogin->id,
            'name' => 'Mapped Client',
            'name_normalized' => 'mapped client',
        ]);
        $batch = Form1702ExBatch::query()->create([
            'user_id' => $staff->id,
            'name' => 'Client Portal Batch',
        ]);

        $visibleRow = $this->createCompletedRow($batch, $client, false);
        $temporaryRow = $this->createCompletedRow($batch, $client, true);

        $this->actingAs($clientLogin)
            ->get(route('client.files.preview', ['form1702ExBatchRow' => $visibleRow]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($clientLogin)
            ->get(route('client.files.download', ['form1702ExBatchRow' => $visibleRow]))
            ->assertOk()
            ->assertDownload((string) $visibleRow->generated_pdf_file_name);

        $this->actingAs($clientLogin)
            ->get(route('client.files.preview', ['form1702ExBatchRow' => $temporaryRow]))
            ->assertNotFound();
    }

    public function test_client_users_are_redirected_from_staff_only_routes_to_client_files(): void
    {
        $clientLogin = User::factory()->create([
            'role' => UserRole::Client,
        ]);

        $this->actingAs($clientLogin)
            ->get(route('forms.form1702ex.index'))
            ->assertRedirect(route('client.files'));
    }

    private function createCompletedRow(
        Form1702ExBatch $batch,
        Client $client,
        bool $temporaryReceipt,
    ): Form1702ExBatchRow {
        $generatedPdfPath = 'forms/'.$batch->user_id.'/1702-ex/batches/'.$batch->id.'/'.uniqid('generated-', true).'.pdf';
        $receiptPath = 'forms/'.$batch->user_id.'/1702-ex/receipts/'.$batch->id.'/'.uniqid('receipt-', true).'.pdf';

        Storage::disk('local')->put($generatedPdfPath, 'fake generated pdf');
        Storage::disk('local')->put($receiptPath, 'fake receipt pdf');

        return Form1702ExBatchRow::query()->create([
            'form_1702_ex_batch_id' => $batch->id,
            'client_id' => $client->id,
            'source_name' => 'seed.csv',
            'source_type' => 'csv',
            'source_row_number' => 2,
            'uploaded_at' => now(),
            'payload' => [
                'taxpayer_name' => $client->name.' Taxpayer',
                'registered_name' => $client->name.' Taxpayer',
                'tin' => '0101112220000',
            ],
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_GENERATED,
            'generated_pdf_file_name' => 'completed.pdf',
            'generated_pdf_storage_path' => $generatedPdfPath,
            'generated_pdf_file_size' => Storage::disk('local')->size($generatedPdfPath),
            'generated_at' => now(),
            'receipt_file_name' => 'receipt.pdf',
            'receipt_storage_path' => $receiptPath,
            'receipt_file_size' => Storage::disk('local')->size($receiptPath),
            'receipt_is_temporary' => $temporaryReceipt,
        ]);
    }
}
