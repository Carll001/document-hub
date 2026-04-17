<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\GenerateDocumentBatchItemJob;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentGeneratorSignature;
use App\Models\User;
use App\Services\ExcelExtractionService;
use App\Services\PdfSignatureStampService;
use App\Services\SignatureImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class DocumentGeneratorSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_generator_routes_require_authentication(): void
    {
        $this->get(route('document-generator.index'))->assertRedirect(route('login'));
        $this->post(route('document-generator.batches.store'))->assertRedirect(route('login'));
        $this->get(route('document-generator.signature.show'))->assertRedirect(route('login'));
    }

    public function test_staff_can_view_document_generator_page(): void
    {
        $this->withoutVite();
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($staff)
            ->get(route('document-generator.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocumentGenerator')
                ->has('initialItems.data')
                ->has('initialSignature')
                ->where('signatureEnabled', true));
    }

    public function test_generated_files_pages_include_signature_feature_flag(): void
    {
        $this->withoutVite();
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $batch = DocumentBatch::factory()->create(['user_id' => $staff->id]);

        config()->set('services.document_generator.signature_enabled', false);

        $this->actingAs($staff)
            ->get(route('document-generator.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocumentGenerator')
                ->where('signatureEnabled', false));

        $this->actingAs($staff)
            ->get(route('generated-files.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('GeneratedFiles')
                ->where('signatureEnabled', false));

        $this->actingAs($staff)
            ->get(route('generated-files.show', ['batch' => $batch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('GeneratedBatchItems')
                ->where('signatureEnabled', false));
    }

    public function test_store_batch_dispatches_generation_jobs(): void
    {
        Storage::fake('local');
        Queue::fake();

        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $excelService = Mockery::mock(ExcelExtractionService::class);
        $excelService->shouldReceive('extract')
            ->once()
            ->andReturn([
                'headers' => ['COMPANY', 'TIN'],
                'rows' => [
                    ['COMPANY' => 'Acme Inc', 'TIN' => '123'],
                    ['COMPANY' => 'Globex LLC', 'TIN' => '456'],
                ],
            ]);
        $this->app->instance(ExcelExtractionService::class, $excelService);

        $response = $this->actingAs($staff)->postJson(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 64),
            'default_template_file' => UploadedFile::fake()->create('template.docx', 32),
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('total_items', 2);

        Queue::assertPushed(GenerateDocumentBatchItemJob::class, 2);
    }

    public function test_staff_can_store_show_and_delete_signature(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $tmpProcessedPath = tempnam(sys_get_temp_dir(), 'processed-signature-');
        if ($tmpProcessedPath === false) {
            $this->fail('Failed to create temporary processed signature file.');
        }
        file_put_contents($tmpProcessedPath, 'png-bytes');

        $signatureService = Mockery::mock(SignatureImageService::class);
        $signatureService->shouldReceive('processToTransparentPng')
            ->once()
            ->andReturn($tmpProcessedPath);
        $this->app->instance(SignatureImageService::class, $signatureService);

        $payload = [
            'signature_file' => UploadedFile::fake()->image('signature.png', 200, 80),
            'page2_anchor' => 'bottom_right',
            'page2_offset_x' => 0,
            'page2_offset_y' => 0,
            'page2_width' => 40,
            'page2_height' => 16,
            'page3_anchor' => 'bottom_right',
            'page3_offset_x' => 0,
            'page3_offset_y' => 0,
            'page3_width' => 40,
            'page3_height' => 16,
        ];

        $this->actingAs($staff)
            ->postJson(route('document-generator.signature.store'), $payload)
            ->assertOk()
            ->assertJsonPath('signature.page2.anchor', 'bottom_right');

        $this->actingAs($staff)
            ->getJson(route('document-generator.signature.show'))
            ->assertOk()
            ->assertJsonPath('signature.page3.anchor', 'bottom_right');

        $this->actingAs($staff)
            ->deleteJson(route('document-generator.signature.destroy'))
            ->assertOk()
            ->assertJsonPath('signature', null);
    }

    public function test_sign_item_requires_president_signature_file(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $batch = DocumentBatch::factory()->create(['user_id' => $staff->id]);
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-2.pdf",
        ]);
        Storage::disk('local')->put((string) $item->pdf_path, 'fake-pdf');

        DocumentGeneratorSignature::query()->create([
            'user_id' => $staff->id,
            'processed_signature_path' => "document-generator/{$staff->id}/signature/processed-getor.png",
            'original_signature_path' => "document-generator/{$staff->id}/signature/original-getor.png",
            'anchor' => 'bottom_right',
            'offset_x' => 0,
            'offset_y' => 0,
            'width' => 40,
            'height' => 16,
            'page2_anchor' => 'bottom_right',
            'page2_offset_x' => 0,
            'page2_offset_y' => 0,
            'page2_width' => 40,
            'page2_height' => 16,
            'page3_anchor' => 'bottom_right',
            'page3_offset_x' => 0,
            'page3_offset_y' => 0,
            'page3_width' => 40,
            'page3_height' => 16,
            'page4_anchor' => 'bottom_right',
            'page4_offset_x' => 0,
            'page4_offset_y' => 0,
            'page4_width' => 40,
            'page4_height' => 16,
            'page8_anchor' => 'bottom_right',
            'page8_offset_x' => 0,
            'page8_offset_y' => 0,
            'page8_width' => 40,
            'page8_height' => 16,
        ]);
        Storage::disk('local')->put("document-generator/{$staff->id}/signature/processed-getor.png", 'getor-signature');

        $this->actingAs($staff)
            ->postJson(route('document-generator.batches.items.signature', [$batch, $item]), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['president_signature_file']);
    }

    public function test_sign_item_applies_with_president_signature_and_default_getor_signature(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $batch = DocumentBatch::factory()->create(['user_id' => $staff->id]);
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-2.pdf",
        ]);
        Storage::disk('local')->put((string) $item->pdf_path, 'fake-pdf');

        DocumentGeneratorSignature::query()->create([
            'user_id' => $staff->id,
            'processed_signature_path' => "document-generator/{$staff->id}/signature/processed-getor.png",
            'original_signature_path' => "document-generator/{$staff->id}/signature/original-getor.png",
            'anchor' => 'bottom_right',
            'offset_x' => 0,
            'offset_y' => 0,
            'width' => 40,
            'height' => 16,
            'page2_anchor' => 'bottom_right',
            'page2_offset_x' => 0,
            'page2_offset_y' => 0,
            'page2_width' => 40,
            'page2_height' => 16,
            'page3_anchor' => 'bottom_right',
            'page3_offset_x' => 0,
            'page3_offset_y' => 0,
            'page3_width' => 40,
            'page3_height' => 16,
            'page4_anchor' => 'bottom_right',
            'page4_offset_x' => 0,
            'page4_offset_y' => 0,
            'page4_width' => 40,
            'page4_height' => 16,
            'page8_anchor' => 'bottom_right',
            'page8_offset_x' => 0,
            'page8_offset_y' => 0,
            'page8_width' => 40,
            'page8_height' => 16,
        ]);
        Storage::disk('local')->put("document-generator/{$staff->id}/signature/processed-getor.png", 'getor-signature');

        $stampService = Mockery::mock(PdfSignatureStampService::class);
        $stampService->shouldReceive('stampFileWithPageLayouts')
            ->twice()
            ->withArgs(function (string $pdfPath, string $signaturePath, array $layouts): bool {
                if (array_keys($layouts) === [2, 3]) {
                    return file_exists($signaturePath);
                }

                if (array_keys($layouts) === [4, 8]) {
                    return str_contains($signaturePath, 'processed-getor.png');
                }

                return false;
            })
            ->andReturnNull();
        $this->app->instance(PdfSignatureStampService::class, $stampService);

        $this->actingAs($staff)
            ->post(route('document-generator.batches.items.signature', [$batch, $item]), [
                'president_signature_file' => UploadedFile::fake()->image('president.png', 240, 90),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Signature applied.');
    }

    public function test_bulk_signing_requires_president_signature_file_and_supports_one_upload_for_many_items(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $batch = DocumentBatch::factory()->create(['user_id' => $staff->id]);
        $firstItem = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-2.pdf",
        ]);
        $secondItem = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 3,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-3.pdf",
        ]);
        Storage::disk('local')->put((string) $firstItem->pdf_path, 'fake-pdf-1');
        Storage::disk('local')->put((string) $secondItem->pdf_path, 'fake-pdf-2');

        DocumentGeneratorSignature::query()->create([
            'user_id' => $staff->id,
            'processed_signature_path' => "document-generator/{$staff->id}/signature/processed-getor.png",
            'original_signature_path' => "document-generator/{$staff->id}/signature/original-getor.png",
            'anchor' => 'bottom_right',
            'offset_x' => 0,
            'offset_y' => 0,
            'width' => 40,
            'height' => 16,
            'page2_anchor' => 'bottom_right',
            'page2_offset_x' => 0,
            'page2_offset_y' => 0,
            'page2_width' => 40,
            'page2_height' => 16,
            'page3_anchor' => 'bottom_right',
            'page3_offset_x' => 0,
            'page3_offset_y' => 0,
            'page3_width' => 40,
            'page3_height' => 16,
            'page4_anchor' => 'bottom_right',
            'page4_offset_x' => 0,
            'page4_offset_y' => 0,
            'page4_width' => 40,
            'page4_height' => 16,
            'page8_anchor' => 'bottom_right',
            'page8_offset_x' => 0,
            'page8_offset_y' => 0,
            'page8_width' => 40,
            'page8_height' => 16,
        ]);
        Storage::disk('local')->put("document-generator/{$staff->id}/signature/processed-getor.png", 'getor-signature');

        $this->actingAs($staff)
            ->postJson(route('document-generator.items.signature.bulk'), [
                'targets' => [
                    ['batch_id' => $batch->id, 'item_id' => $firstItem->id],
                    ['batch_id' => $batch->id, 'item_id' => $secondItem->id],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['president_signature_file']);

        $stampService = Mockery::mock(PdfSignatureStampService::class);
        $stampService->shouldReceive('stampFileWithPageLayouts')
            ->times(4)
            ->andReturnNull();
        $this->app->instance(PdfSignatureStampService::class, $stampService);

        $this->actingAs($staff)
            ->post(route('document-generator.items.signature.bulk'), [
                'president_signature_file' => UploadedFile::fake()->image('president.png', 240, 90),
                'targets' => [
                    ['batch_id' => $batch->id, 'item_id' => $firstItem->id],
                    ['batch_id' => $batch->id, 'item_id' => $secondItem->id],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'results');
    }

    public function test_signature_endpoints_return_404_when_signature_feature_is_disabled(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $batch = DocumentBatch::factory()->create(['user_id' => $staff->id]);
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-2.pdf",
        ]);
        Storage::disk('local')->put((string) $item->pdf_path, 'fake-pdf');

        config()->set('services.document_generator.signature_enabled', false);

        $this->actingAs($staff)
            ->getJson(route('document-generator.signature.show'))
            ->assertNotFound();

        $this->actingAs($staff)
            ->postJson(route('document-generator.signature.store'), [])
            ->assertNotFound();

        $this->actingAs($staff)
            ->deleteJson(route('document-generator.signature.destroy'))
            ->assertNotFound();

        $this->actingAs($staff)
            ->get(route('document-generator.signature.preview'))
            ->assertNotFound();

        $this->actingAs($staff)
            ->postJson(route('document-generator.batches.items.signature', [$batch, $item]), [])
            ->assertNotFound();

        $this->actingAs($staff)
            ->postJson(route('document-generator.items.signature.bulk'), [])
            ->assertNotFound();
    }
}
