<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\GenerateDocumentBatchItemJob;
use App\Jobs\ProcessDocumentGeneratorCompletedExport;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentGeneratorSignature;
use App\Models\DocumentGeneratorTemplate;
use App\Models\User;
use App\Services\ExcelExtractionService;
use App\Services\PdfTextAnchorLocatorService;
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

    public function test_generated_files_and_batch_pages_load_with_signature_feature_flag_disabled(): void
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
                ->component('GeneratedFiles'));

        $this->actingAs($staff)
            ->get(route('generated-files.show', ['batch' => $batch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('GeneratedBatchItems')
                ->where('signatureEnabled', false));
    }

    public function test_completed_files_page_lists_only_signed_pdf_done_items(): void
    {
        $this->withoutVite();
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $batch = DocumentBatch::factory()->create(['user_id' => $staff->id]);

        $signedItem = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-2.pdf",
            'signature_applied_at' => now(),
        ]);

        DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-3.pdf",
            'signature_applied_at' => null,
        ]);

        DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'docx_done',
            'docx_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-4.docx",
            'signature_applied_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get(route('generated-files.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('GeneratedFiles')
                ->has('initialItems.data', 1)
                ->where('initialItems.data.0.id', $signedItem->id)
                ->where('initialItems.data.0.status', 'pdf_done')
                ->where('initialItems.data.0.signature_applied', true)
                ->has('initialExportState'));
    }

    public function test_document_generator_main_table_excludes_signed_rows_by_default(): void
    {
        $this->withoutVite();
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $batch = DocumentBatch::factory()->create(['user_id' => $staff->id]);

        $unsignedGenerated = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-2.pdf",
            'signature_applied_at' => null,
        ]);

        DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-3.pdf",
            'signature_applied_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get(route('document-generator.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocumentGenerator')
                ->has('initialItems.data', 1)
                ->where('initialItems.data.0.id', $unsignedGenerated->id)
                ->where('initialItems.data.0.signature_applied', false));
    }

    public function test_completed_download_queue_dispatches_export_job_for_selected_completed_rows(): void
    {
        Queue::fake();
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $batch = DocumentBatch::factory()->create(['user_id' => $staff->id]);

        $signedCompleted = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-2.pdf",
            'signature_applied_at' => now(),
        ]);

        DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-3.pdf",
            'signature_applied_at' => null,
        ]);

        $this->actingAs($staff)
            ->postJson(route('document-generator.completed.download'), [
                'item_ids' => [$signedCompleted->id],
            ])
            ->assertOk()
            ->assertJsonPath('export_state.status', 'queued');

        Queue::assertPushed(ProcessDocumentGeneratorCompletedExport::class, function (ProcessDocumentGeneratorCompletedExport $job) use ($signedCompleted): bool {
            return $job->userId > 0 && $job->itemIds === [$signedCompleted->id];
        });
    }

    public function test_bulk_delete_completed_rows_only_removes_signed_completed_rows(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $batch = DocumentBatch::factory()->create(['user_id' => $staff->id]);

        $signedCompleted = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-2.pdf",
            'signature_applied_at' => now(),
        ]);

        $unsignedGenerated = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-3.pdf",
            'signature_applied_at' => null,
        ]);

        $this->actingAs($staff)
            ->deleteJson(route('document-generator.completed.items.destroy.bulk'), [
                'item_ids' => [$signedCompleted->id, $unsignedGenerated->id],
            ])
            ->assertOk()
            ->assertJsonPath('deleted_count', 1);

        $this->assertNull($signedCompleted->fresh());
        $this->assertNotNull($unsignedGenerated->fresh());
    }

    public function test_settings_page_exposes_signature_and_default_template_sections(): void
    {
        $this->withoutVite();
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $template = DocumentGeneratorTemplate::query()->create([
            'year' => null,
            'template_name' => 'afs-default.docx',
            'template_path' => 'document-generator/global-templates/afs-default.docx',
        ]);

        $this->actingAs($staff)
            ->get(route('document-generator.template-mapping'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TemplateMapping')
                ->where('mapping.default_template.id', $template->id)
                ->where('mapping.default_template.template_name', 'afs-default.docx')
                ->where('mapping.year_templates', [])
                ->has('initialSignature')
                ->has('signatureEnabled'));
    }

    public function test_global_year_template_crud_endpoints_are_not_available(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $yearTemplate = DocumentGeneratorTemplate::query()->create([
            'year' => 2025,
            'template_name' => 'afs-2025.docx',
            'template_path' => 'document-generator/global-templates/afs-2025.docx',
        ]);

        $this->actingAs($staff)
            ->post(route('document-generator.templates.store'), [
                'year' => 2026,
                'template_file' => UploadedFile::fake()->create('template.docx', 32),
            ])
            ->assertNotFound();

        $this->actingAs($staff)
            ->post(route('document-generator.templates.update', ['template' => $yearTemplate->id]), [
                'year' => 2027,
            ])
            ->assertNotFound();

        $this->actingAs($staff)
            ->delete(route('document-generator.templates.destroy', ['template' => $yearTemplate->id]))
            ->assertNotFound();
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
        ];

        $this->actingAs($staff)
            ->postJson(route('document-generator.signature.store'), $payload)
            ->assertOk()
            ->assertJsonPath('signature.page2.anchor', 'bottom_right')
            ->assertJsonPath('signature.page2.placement_mode', 'fixed')
            ->assertJsonPath('signature.page4.placement_mode', 'fixed');

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

    public function test_sign_item_falls_back_to_fixed_layout_when_anchor_is_missing_and_fallback_is_enabled(): void
    {
        Storage::fake('local');
        config()->set('services.document_generator.signature_text_anchor_fallback_to_fixed', true);

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
            'page2_placement_mode' => 'text_anchor',
            'page2_anchor_text' => 'Missing Anchor',
            'page2_offset_x' => 0,
            'page2_offset_y' => 0,
            'page2_width' => 40,
            'page2_height' => 16,
            'page3_anchor' => 'bottom_right',
            'page3_placement_mode' => 'text_anchor',
            'page3_anchor_text' => 'Missing Anchor',
            'page3_offset_x' => 0,
            'page3_offset_y' => 0,
            'page3_width' => 40,
            'page3_height' => 16,
            'page4_anchor' => 'bottom_right',
            'page4_placement_mode' => 'text_anchor',
            'page4_anchor_text' => 'Missing Anchor',
            'page4_offset_x' => 0,
            'page4_offset_y' => 0,
            'page4_width' => 40,
            'page4_height' => 16,
            'page8_anchor' => 'bottom_right',
            'page8_placement_mode' => 'text_anchor',
            'page8_anchor_text' => 'Missing Anchor',
            'page8_offset_x' => 0,
            'page8_offset_y' => 0,
            'page8_width' => 40,
            'page8_height' => 16,
        ]);
        Storage::disk('local')->put("document-generator/{$staff->id}/signature/processed-getor.png", 'getor-signature');

        $locator = Mockery::mock(PdfTextAnchorLocatorService::class);
        $locator->shouldReceive('locateWithDiagnostics')
            ->twice()
            ->andReturn([
                'match' => null,
                'diagnostics' => [
                    'preferred_page' => 2,
                    'normalized_anchor_tokens' => ['missing', 'anchor'],
                    'searched_pages' => [2, 3],
                    'nearby_tokens' => [],
                ],
            ]);
        $this->app->instance(PdfTextAnchorLocatorService::class, $locator);

        $stampService = Mockery::mock(PdfSignatureStampService::class);
        $stampService->shouldReceive('stampFileWithPageLayouts')->twice()->andReturnNull();
        $this->app->instance(PdfSignatureStampService::class, $stampService);

        $this->actingAs($staff)
            ->post(route('document-generator.batches.items.signature', [$batch, $item]), [
                'president_signature_file' => UploadedFile::fake()->image('president.png', 240, 90),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Signature applied.');
    }

    public function test_preflight_anchor_check_returns_actionable_error_when_anchor_missing_and_fallback_disabled(): void
    {
        Storage::fake('local');
        config()->set('services.document_generator.signature_text_anchor_fallback_to_fixed', false);

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
            'page2_placement_mode' => 'text_anchor',
            'page2_anchor_text' => 'Missing Anchor',
            'page2_offset_x' => 0,
            'page2_offset_y' => 0,
            'page2_width' => 40,
            'page2_height' => 16,
            'page3_anchor' => 'bottom_right',
            'page3_placement_mode' => 'fixed',
            'page3_offset_x' => 0,
            'page3_offset_y' => 0,
            'page3_width' => 40,
            'page3_height' => 16,
            'page4_anchor' => 'bottom_right',
            'page4_placement_mode' => 'fixed',
            'page4_offset_x' => 0,
            'page4_offset_y' => 0,
            'page4_width' => 40,
            'page4_height' => 16,
            'page8_anchor' => 'bottom_right',
            'page8_placement_mode' => 'fixed',
            'page8_offset_x' => 0,
            'page8_offset_y' => 0,
            'page8_width' => 40,
            'page8_height' => 16,
        ]);
        Storage::disk('local')->put("document-generator/{$staff->id}/signature/processed-getor.png", 'getor-signature');

        $locator = Mockery::mock(PdfTextAnchorLocatorService::class);
        $locator->shouldReceive('locateWithDiagnostics')
            ->once()
            ->andReturn([
                'match' => null,
                'diagnostics' => [
                    'preferred_page' => 2,
                    'normalized_anchor_tokens' => ['missing', 'anchor'],
                    'searched_pages' => [2],
                    'nearby_tokens' => [
                        ['page' => 2, 'tokens' => ['authorized', 'signatory']],
                    ],
                ],
            ]);
        $this->app->instance(PdfTextAnchorLocatorService::class, $locator);

        $this->actingAs($staff)
            ->getJson(route('document-generator.batches.items.signature.preflight', [$batch, $item]))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['errors' => ['signature']]);
    }

    public function test_signing_keeps_both_docx_and_pdf_downloadable(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $batch = DocumentBatch::factory()->create(['user_id' => $staff->id]);
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'docx_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-2.docx",
            'pdf_path' => "document-generator/{$staff->id}/batch-{$batch->id}/row-2.pdf",
        ]);
        Storage::disk('local')->put((string) $item->docx_path, 'fake-docx');
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
        $stampService->shouldReceive('stampFileWithPageLayouts')->times(4)->andReturnNull();
        $this->app->instance(PdfSignatureStampService::class, $stampService);

        $this->actingAs($staff)
            ->post(route('document-generator.batches.items.signature', [$batch, $item]), [
                'president_signature_file' => UploadedFile::fake()->image('president.png', 240, 90),
            ])
            ->assertOk();

        $this->actingAs($staff)
            ->get(route('document-generator.batches.items.download', [$batch, $item, 'docx']))
            ->assertOk();

        $this->actingAs($staff)
            ->get(route('document-generator.batches.items.download', [$batch, $item, 'pdf']))
            ->assertOk();
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
            ->getJson(route('document-generator.batches.items.signature.preflight', [$batch, $item]))
            ->assertNotFound();

        $this->actingAs($staff)
            ->postJson(route('document-generator.items.signature.bulk'), [])
            ->assertNotFound();
    }
}
