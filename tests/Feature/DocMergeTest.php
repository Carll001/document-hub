<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateMergedPdfReceipt;
use App\Mail\MergedPdfEmail;
use App\Models\ConfirmationTemplate;
use App\Models\DocMergeBatch;
use App\Models\MergedPdf;
use App\Models\User;
use App\Services\ConfirmationDocxService;
use App\Services\PdfTextExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;
use ZipArchive;

class DocMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('doc-merge.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_doc_merge_page()
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'combined-report.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/combined-report.pdf',
            'file_size' => 10240,
            'source_count' => 2,
            'source_file_names' => ['chapter-1.pdf', 'chapter-2.pdf'],
            'tin_number' => '123-456-789-000',
            'footer_text' => 'Prepared for filing',
        ]);

        MergedPdf::query()->create([
            'user_id' => $otherUser->id,
            'file_name' => 'other-user.pdf',
            'storage_path' => 'doc-merge/'.$otherUser->id.'/other-user.pdf',
            'file_size' => 2048,
            'source_count' => 2,
            'source_file_names' => ['secret-a.pdf', 'secret-b.pdf'],
        ]);

        $this->actingAs($user)
            ->get(route('doc-merge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMerge')
                ->where('confirmationTemplate.hasTemplate', false)
                ->where('confirmationTemplate.fileName', null)
                ->where('confirmationTemplate.fileSize', null)
                ->where('confirmationTemplate.placeholders', [])
                ->where('confirmationTemplate.downloadUrl', null)
                ->where('batchPagination.currentPage', 1)
                ->where('batchPagination.lastPage', 1)
                ->missing('mergeHistory'),
            );
    }

    public function test_authenticated_users_can_upload_a_receipt_template_and_view_detected_placeholders()
    {
        Storage::fake('local');
        $this->withoutVite();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.confirmation-template.store'), [
                'template' => $this->makeUploadedDocxTemplate(
                    'confirmation-template.docx',
                    ['client_name', 'tin_number'],
                ),
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Shared receipt template saved.');

        $sharedTemplate = ConfirmationTemplate::shared();

        $this->assertInstanceOf(ConfirmationTemplate::class, $sharedTemplate);
        $this->assertSame(
            'confirmation-template.docx',
            $sharedTemplate->file_name,
        );
        $this->assertNotNull($sharedTemplate->storage_path);
        $this->assertNotNull($sharedTemplate->file_size);
        $this->assertSame($user->id, $sharedTemplate->uploaded_by_user_id);
        Storage::disk('s3')->assertExists($sharedTemplate->storage_path);

        $this->actingAs($user)
            ->get(route('doc-merge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMerge')
                ->where('confirmationTemplate.hasTemplate', true)
                ->where('confirmationTemplate.fileName', 'confirmation-template.docx')
                ->where('confirmationTemplate.placeholders', [
                    'client_name',
                    'tin_number',
                ])
                ->where(
                    'confirmationTemplate.downloadUrl',
                    route('doc-merge.confirmation-template.download'),
                ),
            );
    }

    public function test_uploaded_receipt_templates_are_shared_across_users()
    {
        Storage::fake('local');
        Queue::fake();
        $this->withoutVite();

        $uploader = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherMergedPdf = MergedPdf::query()->create([
            'user_id' => $otherUser->id,
            'file_name' => 'other-user-packet.pdf',
            'storage_path' => 'doc-merge/'.$otherUser->id.'/other-user-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['quote.pdf', 'invoice.pdf'],
        ]);

        Storage::disk('s3')->put(
            $otherMergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0]]),
        );

        $this->actingAs($uploader)
            ->post(route('doc-merge.confirmation-template.store'), [
                'template' => $this->makeUploadedDocxTemplate(
                    'shared-template.docx',
                    ['client_name'],
                ),
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Shared receipt template saved.');

        $this->actingAs($otherUser)
            ->get(route('doc-merge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMerge')
                ->where('confirmationTemplate.hasTemplate', true)
                ->where('confirmationTemplate.fileName', 'shared-template.docx')
                ->where('confirmationTemplate.placeholders', ['client_name'])
                ->where(
                    'confirmationTemplate.downloadUrl',
                    route('doc-merge.confirmation-template.download'),
                ),
            );

        $this->actingAs($otherUser)
            ->get(route('doc-merge.confirmation-template.download'))
            ->assertOk()
            ->assertDownload('shared-template.docx');

        $this->actingAs($otherUser)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $otherMergedPdf]), [
                'placeholders' => [
                    'client_name' => 'Globex',
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt queued for other-user-packet.pdf.');

        $otherMergedPdf->refresh();

        $this->assertSame(MergedPdf::RECEIPT_JOB_STATUS_QUEUED, $otherMergedPdf->receipt_job_status);
        $this->assertNull($otherMergedPdf->receipt_job_error);
        $this->assertNull($otherMergedPdf->receipt_storage_path);
        Queue::assertPushed(GenerateMergedPdfReceipt::class, function (GenerateMergedPdfReceipt $job) use ($otherMergedPdf): bool {
            return $job->mergedPdfId === $otherMergedPdf->id;
        });
    }

    public function test_receipt_template_detection_ignores_word_document_ids_in_settings_xml()
    {
        Storage::fake('local');
        $this->withoutVite();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.confirmation-template.store'), [
                'template' => $this->makeUploadedDocxTemplate(
                    'confirmation-template.docx',
                    ['file_name', 'date_received', 'time_received'],
                    '{CFB6B461-5DA7-44A8-B26B-CAED9CB2248C}',
                ),
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Shared receipt template saved.');

        $this->actingAs($user)
            ->get(route('doc-merge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMerge')
                ->where('confirmationTemplate.placeholders', [
                    'date_received',
                    'file_name',
                    'time_received',
                ]),
            );
    }

    public function test_authenticated_users_can_generate_a_templated_receipt_pdf_and_append_it_to_a_saved_merge()
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'expense-packet.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/expense-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['quote.pdf', 'invoice.pdf'],
            'tin_number' => '123-456-789-000',
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0]]),
        );

        $this->actingAs($user)
            ->post(route('doc-merge.confirmation-template.store'), [
                'template' => $this->makeUploadedDocxTemplate(
                    'confirmation-template.docx',
                    ['client_name', 'tin_number', 'saved_at'],
                ),
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Shared receipt template saved.');

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'placeholders' => [
                    'client_name' => 'Acme Corp',
                    'tin_number' => '123-456-789-000',
                    'saved_at' => 'March 30, 2026 9:00 AM',
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt queued for expense-packet.pdf.');

        $mergedPdf->refresh();

        $this->assertSame(MergedPdf::RECEIPT_JOB_STATUS_QUEUED, $mergedPdf->receipt_job_status);
        $this->assertNull($mergedPdf->receipt_job_error);
        $this->assertNull($mergedPdf->receipt_storage_path);

        $queuedJob = $this->assertReceiptJobQueuedFor($mergedPdf);

        $queuedJob->handle(app(\App\Services\MergedPdfReceiptService::class));

        $mergedPdf->refresh();

        $this->assertSame('expense-packet-receipt.pdf', $mergedPdf->receipt_file_name);
        $this->assertNotNull($mergedPdf->receipt_storage_path);
        $this->assertNotNull($mergedPdf->receipt_file_size);
        $this->assertNull($mergedPdf->receipt_job_status);
        $this->assertNull($mergedPdf->receipt_job_error);
        $this->assertSame(3, $mergedPdf->source_count);
        $this->assertSame([
            'quote.pdf',
            'invoice.pdf',
            'Receipt: expense-packet-receipt.pdf',
        ], $mergedPdf->source_file_names);
        Storage::disk('s3')->assertExists($mergedPdf->receipt_storage_path);
        $this->assertCount(
            2,
            $this->mergedPdfDimensions(
                Storage::disk('s3')->path($mergedPdf->storage_path),
            ),
        );

        $receiptText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('s3')->path($mergedPdf->receipt_storage_path),
        );
        $mergedText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('s3')->path($mergedPdf->storage_path),
        );

        $this->assertStringContainsString('Acme Corp', $receiptText);
        $this->assertStringContainsString('123-456-789-000', $receiptText);
        $this->assertStringContainsString('March 30, 2026 9:00 AM', $receiptText);
        $this->assertStringNotContainsString('{client_name}', $receiptText);
        $this->assertStringContainsString('Acme Corp', $mergedText);
        $this->assertStringContainsString('123-456-789-000', $mergedText);

        $this->actingAs($user)
            ->get(route('doc-merge.receipt.download', ['mergedPdf' => $mergedPdf]))
            ->assertOk()
            ->assertDownload('expense-packet-receipt.pdf');
    }

    public function test_receipt_generation_requires_each_detected_placeholder_to_be_present()
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'expense-packet.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/expense-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['quote.pdf', 'invoice.pdf'],
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0]]),
        );

        $this->actingAs($user)
            ->post(route('doc-merge.confirmation-template.store'), [
                'template' => $this->makeUploadedDocxTemplate(
                    'confirmation-template.docx',
                    ['client_name'],
                ),
            ])
            ->assertRedirect(route('doc-merge.index'));

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'placeholders' => [],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('placeholders.client_name');

        $mergedPdf->refresh();

        $this->assertNull($mergedPdf->receipt_storage_path);
        $this->assertNull($mergedPdf->receipt_file_name);
        $this->assertNull($mergedPdf->receipt_job_status);
        Queue::assertNothingPushed();
    }

    public function test_authenticated_users_can_merge_two_uploaded_pdfs_and_save_output()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.store'), [
                'outputName' => 'custom-merged',
                'sources' => [
                    ['type' => 'upload'],
                    ['type' => 'upload'],
                ],
                'files' => [
                    $this->makeUploadedPdf('intro.pdf'),
                    $this->makeUploadedPdf('appendix.pdf'),
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Merged PDF saved as custom-merged.pdf.');

        $mergedPdf = MergedPdf::query()->firstOrFail();

        $this->assertSame('custom-merged.pdf', $mergedPdf->file_name);
        $this->assertSame(2, $mergedPdf->source_count);
        $this->assertSame(['intro.pdf', 'appendix.pdf'], $mergedPdf->source_file_names);
        Storage::disk('s3')->assertExists($mergedPdf->storage_path);
    }

    public function test_authenticated_users_can_extract_a_tin_number_and_apply_footer_text()
    {
        Storage::fake('local');
        $this->withoutVite();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.store'), [
                'outputName' => 'tin-footer-output',
                'footerText' => 'Prepared for internal filing',
                'sources' => [
                    ['type' => 'upload'],
                    ['type' => 'upload'],
                ],
                'files' => [
                    $this->makeUploadedTextPdf('invoice.pdf', [[
                        'Taxpayer Identification Number (TIN): 123-456-789-000',
                        'Invoice details',
                    ]]),
                    $this->makeUploadedTextPdf('receipt.pdf', [[
                        'Receipt details',
                    ]]),
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Merged PDF saved as tin-footer-output.pdf.');

        $mergedPdf = MergedPdf::query()->firstOrFail();

        $this->assertSame('123-456-789-000', $mergedPdf->tin_number);
        $this->assertSame('Prepared for internal filing', $mergedPdf->footer_text);

        $pdfText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('s3')->path($mergedPdf->storage_path),
        );

        $this->assertStringContainsString('123-456-789-000', $pdfText);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($pdfText, 'Prepared for internal filing'),
        );

        $this->actingAs($user)
            ->get(route('doc-merge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMerge')
                ->missing('mergeHistory'),
            );
    }

    public function test_authenticated_users_can_merge_three_or_more_uploaded_pdfs_in_selected_order()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.store'), [
                'outputName' => 'ordered-output.pdf',
                'sources' => [
                    ['type' => 'upload'],
                    ['type' => 'upload'],
                    ['type' => 'upload'],
                ],
                'files' => [
                    $this->makeUploadedPdf('landscape.pdf', [[320, 180]]),
                    $this->makeUploadedPdf('portrait.pdf', [[180, 320]]),
                    $this->makeUploadedPdf('two-pages.pdf', [[210, 210], [148, 210]]),
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Merged PDF saved as ordered-output.pdf.');

        $mergedPdf = MergedPdf::query()->firstOrFail();
        $dimensions = $this->mergedPdfDimensions(Storage::disk('s3')->path($mergedPdf->storage_path));

        $this->assertCount(4, $dimensions);
        $this->assertGreaterThan($dimensions[0]['height'], $dimensions[0]['width']);
        $this->assertLessThan($dimensions[1]['height'], $dimensions[1]['width']);
        $this->assertEqualsWithDelta(210.0, $dimensions[2]['width'], 1.0);
        $this->assertEqualsWithDelta(210.0, $dimensions[2]['height'], 1.0);
        $this->assertEqualsWithDelta(148.0, $dimensions[3]['width'], 1.0);
        $this->assertEqualsWithDelta(210.0, $dimensions[3]['height'], 1.0);
    }

    public function test_authenticated_users_can_append_files_to_a_saved_merge_without_replacing_the_original()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $original = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'original-merge.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/original-merge.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['part-a.pdf', 'part-b.pdf'],
        ]);

        Storage::disk('s3')->put(
            $original->storage_path,
            $this->makePdfContents([[210.0, 297.0], [210.0, 297.0]]),
        );

        $original->update([
            'file_size' => Storage::disk('s3')->size($original->storage_path),
        ]);

        $this->actingAs($user)
            ->post(route('doc-merge.store'), [
                'outputName' => 'original-merge.pdf',
                'sources' => [
                    ['type' => 'merged_pdf', 'id' => $original->id],
                    ['type' => 'upload'],
                ],
                'files' => [
                    $this->makeUploadedPdf('appendix.pdf'),
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Merged PDF saved as original-merge.pdf.');

        $this->assertDatabaseCount('merged_pdfs', 2);

        $appendedMerge = MergedPdf::query()
            ->whereKeyNot($original->id)
            ->firstOrFail();

        $this->assertSame('original-merge.pdf', $appendedMerge->file_name);
        $this->assertSame(2, $appendedMerge->source_count);
        $this->assertSame(
            ['original-merge.pdf', 'appendix.pdf'],
            $appendedMerge->source_file_names,
        );
        $this->assertSame(['part-a.pdf', 'part-b.pdf'], $original->fresh()->source_file_names);
    }

    public function test_doc_merge_validation_requires_at_least_two_sources()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.store'), [
                'sources' => [
                    ['type' => 'upload'],
                ],
                'files' => [$this->makeUploadedPdf('single.pdf')],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('sources');
    }

    public function test_doc_merge_validation_requires_uploaded_files_to_be_pdfs()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.store'), [
                'sources' => [
                    ['type' => 'upload'],
                    ['type' => 'upload'],
                ],
                'files' => [
                    UploadedFile::fake()->create('photo.png', 12, 'image/png'),
                    UploadedFile::fake()->create('another-photo.jpg', 12, 'image/jpeg'),
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('files.0');
    }

    public function test_doc_merge_validation_requires_the_upload_queue_to_match_the_uploaded_files()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.store'), [
                'sources' => [
                    ['type' => 'upload'],
                    ['type' => 'upload'],
                ],
                'files' => [
                    $this->makeUploadedPdf('only-one.pdf'),
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('files');
    }

    public function test_doc_merge_validation_rejects_foreign_saved_pdf_sources()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $foreignMergedPdf = MergedPdf::query()->create([
            'user_id' => $otherUser->id,
            'file_name' => 'foreign.pdf',
            'storage_path' => 'doc-merge/'.$otherUser->id.'/foreign.pdf',
            'file_size' => 0,
            'source_count' => 1,
            'source_file_names' => ['foreign.pdf'],
        ]);

        Storage::disk('s3')->put(
            $foreignMergedPdf->storage_path,
            $this->makePdfContents(),
        );

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.store'), [
                'sources' => [
                    ['type' => 'merged_pdf', 'id' => $foreignMergedPdf->id],
                    ['type' => 'upload'],
                ],
                'files' => [
                    $this->makeUploadedPdf('appendix.pdf'),
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('sources.0.id');
    }

    public function test_merged_pdf_preview_is_authorized_and_streamed_inline()
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $owner->id,
            'file_name' => 'secure-merge.pdf',
            'storage_path' => 'doc-merge/'.$owner->id.'/secure-merge.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['a.pdf', 'b.pdf'],
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents(),
        );

        $this->actingAs($owner)
            ->get(route('doc-merge.preview', ['mergedPdf' => $mergedPdf]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($intruder)
            ->get(route('doc-merge.preview', ['mergedPdf' => $mergedPdf]))
            ->assertNotFound();
    }

    public function test_merged_pdf_downloads_are_authorized()
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $owner->id,
            'file_name' => 'secure-merge.pdf',
            'storage_path' => 'doc-merge/'.$owner->id.'/secure-merge.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['a.pdf', 'b.pdf'],
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents(),
        );

        $this->actingAs($owner)
            ->get(route('doc-merge.download', ['mergedPdf' => $mergedPdf]))
            ->assertOk()
            ->assertDownload('secure-merge.pdf');

        $this->actingAs($intruder)
            ->get(route('doc-merge.download', ['mergedPdf' => $mergedPdf]))
            ->assertNotFound();
    }

    public function test_authenticated_users_can_delete_a_single_saved_merged_pdf()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'mistake.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/mistake.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['first.pdf', 'second.pdf'],
            'receipt_file_name' => 'receipt.pdf',
            'receipt_storage_path' => 'doc-merge/'.$user->id.'/receipts/'.$user->id.'/receipt.pdf',
            'receipt_file_size' => 1234,
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents(),
        );
        Storage::disk('s3')->put(
            $mergedPdf->receipt_storage_path,
            'receipt contents',
        );

        $this->actingAs($user)
            ->delete(route('doc-merge.destroy-many'), [
                'items' => [
                    ['type' => 'merged_pdf', 'id' => $mergedPdf->uuid],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Deleted mistake.pdf.');

        $this->assertDatabaseMissing('merged_pdfs', [
            'id' => $mergedPdf->id,
        ]);
        Storage::disk('s3')->assertMissing($mergedPdf->storage_path);
        Storage::disk('s3')->assertMissing($mergedPdf->receipt_storage_path);
    }

    public function test_authenticated_users_can_bulk_delete_saved_merged_pdfs()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $firstMergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'first.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/first.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['a.pdf', 'b.pdf'],
        ]);
        $secondMergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'second.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/second.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['c.pdf', 'd.pdf'],
        ]);

        Storage::disk('s3')->put(
            $firstMergedPdf->storage_path,
            $this->makePdfContents(),
        );
        Storage::disk('s3')->put(
            $secondMergedPdf->storage_path,
            $this->makePdfContents(),
        );

        $this->actingAs($user)
            ->delete(route('doc-merge.destroy-many'), [
                'items' => [
                    ['type' => 'merged_pdf', 'id' => $firstMergedPdf->uuid],
                    ['type' => 'merged_pdf', 'id' => $secondMergedPdf->uuid],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Deleted 2 merge results.');

        $this->assertDatabaseMissing('merged_pdfs', [
            'id' => $firstMergedPdf->id,
        ]);
        $this->assertDatabaseMissing('merged_pdfs', [
            'id' => $secondMergedPdf->id,
        ]);
    }

    public function test_doc_merge_bulk_delete_rejects_non_owned_saved_pdfs()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownedMergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'mine.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/mine.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['a.pdf', 'b.pdf'],
        ]);
        $foreignMergedPdf = MergedPdf::query()->create([
            'user_id' => $otherUser->id,
            'file_name' => 'theirs.pdf',
            'storage_path' => 'doc-merge/'.$otherUser->id.'/theirs.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['c.pdf', 'd.pdf'],
        ]);

        Storage::disk('s3')->put(
            $ownedMergedPdf->storage_path,
            $this->makePdfContents(),
        );
        Storage::disk('s3')->put(
            $foreignMergedPdf->storage_path,
            $this->makePdfContents(),
        );

        $this->actingAs($user)
            ->delete(route('doc-merge.destroy-many'), [
                'items' => [
                    ['type' => 'merged_pdf', 'id' => $ownedMergedPdf->uuid],
                    ['type' => 'merged_pdf', 'id' => $foreignMergedPdf->uuid],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas(
                'error',
                'One or more selected merge results could not be deleted.',
            );

        $this->assertDatabaseHas('merged_pdfs', [
            'id' => $ownedMergedPdf->id,
        ]);
        $this->assertDatabaseHas('merged_pdfs', [
            'id' => $foreignMergedPdf->id,
        ]);
    }

    public function test_authenticated_users_can_add_and_replace_receipts_for_saved_merged_pdfs()
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'expense-packet.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/expense-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['quote.pdf', 'invoice.pdf'],
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0]]),
        );

        $this->actingAs($user)
            ->post(route('doc-merge.confirmation-template.store'), [
                'template' => $this->makeUploadedDocxTemplate(
                    'confirmation-template.docx',
                    ['client_name', 'receipt_amount'],
                ),
            ])
            ->assertRedirect(route('doc-merge.index'));

        $this->actingAs($user)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'placeholders' => [
                    'client_name' => 'Acme Corp',
                    'receipt_amount' => 'PHP 1,250.00',
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt queued for expense-packet.pdf.');

        $this->assertReceiptJobQueuedFor($mergedPdf)
            ->handle(app(\App\Services\MergedPdfReceiptService::class));

        $mergedPdf->refresh();

        $this->assertSame('expense-packet-receipt.pdf', $mergedPdf->receipt_file_name);
        $this->assertNotNull($mergedPdf->receipt_storage_path);
        $this->assertNotNull($mergedPdf->receipt_file_size);
        $this->assertSame(3, $mergedPdf->source_count);
        $this->assertSame(
            ['quote.pdf', 'invoice.pdf', 'Receipt: expense-packet-receipt.pdf'],
            $mergedPdf->source_file_names,
        );
        Storage::disk('s3')->assertExists($mergedPdf->receipt_storage_path);
        $this->assertCount(
            2,
            $this->mergedPdfDimensions(
                Storage::disk('s3')->path($mergedPdf->storage_path),
            ),
        );

        $firstReceiptPath = $mergedPdf->receipt_storage_path;
        $this->assertStringContainsString(
            'Acme Corp',
            app(PdfTextExtractionService::class)->extractText(
                Storage::disk('s3')->path($mergedPdf->receipt_storage_path),
            ),
        );

        $this->actingAs($user)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'placeholders' => [
                    'client_name' => 'Globex',
                    'receipt_amount' => 'PHP 2,500.00',
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt queued for expense-packet.pdf.');

        $mergedPdf->refresh();
        $this->assertSame(MergedPdf::RECEIPT_JOB_STATUS_QUEUED, $mergedPdf->receipt_job_status);

        $this->assertReceiptJobQueuedFor($mergedPdf)
            ->handle(app(\App\Services\MergedPdfReceiptService::class));

        $mergedPdf->refresh();

        $this->assertSame('expense-packet-receipt.pdf', $mergedPdf->receipt_file_name);
        $this->assertNotSame($firstReceiptPath, $mergedPdf->receipt_storage_path);
        $this->assertNull($mergedPdf->receipt_job_status);
        $this->assertNull($mergedPdf->receipt_job_error);
        $this->assertSame(3, $mergedPdf->source_count);
        $this->assertSame(
            ['quote.pdf', 'invoice.pdf', 'Receipt: expense-packet-receipt.pdf'],
            $mergedPdf->source_file_names,
        );
        Storage::disk('s3')->assertMissing($firstReceiptPath);
        Storage::disk('s3')->assertExists($mergedPdf->receipt_storage_path);
        $this->assertCount(
            2,
            $this->mergedPdfDimensions(
                Storage::disk('s3')->path($mergedPdf->storage_path),
            ),
        );
        $updatedReceiptText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('s3')->path($mergedPdf->receipt_storage_path),
        );

        $this->assertStringContainsString('Globex', $updatedReceiptText);
        $this->assertStringContainsString('PHP 2,500.00', $updatedReceiptText);
        $this->assertStringNotContainsString('Acme Corp', $updatedReceiptText);
    }

    public function test_receipt_updates_keep_the_saved_tin_number_and_reapply_the_footer()
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.store'), [
                'outputName' => 'footered-packet',
                'footerText' => 'Prepared for internal filing',
                'sources' => [
                    ['type' => 'upload'],
                    ['type' => 'upload'],
                ],
                'files' => [
                    $this->makeUploadedTextPdf('quote.pdf', [[
                        'TIN: 123-456-789-000',
                        'Quote page',
                    ]]),
                    $this->makeUploadedTextPdf('invoice.pdf', [[
                        'Invoice page',
                    ]]),
                ],
            ])
            ->assertRedirect(route('doc-merge.index'));

        $mergedPdf = MergedPdf::query()->firstOrFail();

        $this->assertSame('123-456-789-000', $mergedPdf->tin_number);

        $this->actingAs($user)
            ->post(route('doc-merge.confirmation-template.store'), [
                'template' => $this->makeUploadedDocxTemplate(
                    'confirmation-template.docx',
                    ['receipt_note'],
                ),
            ])
            ->assertRedirect(route('doc-merge.index'));

        $this->actingAs($user)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'placeholders' => [
                    'receipt_note' => 'Receipt page',
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt queued for footered-packet.pdf.');

        $this->assertReceiptJobQueuedFor($mergedPdf)
            ->handle(app(\App\Services\MergedPdfReceiptService::class));

        $mergedPdf->refresh();

        $withReceiptText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('s3')->path($mergedPdf->storage_path),
        );

        $this->assertSame('123-456-789-000', $mergedPdf->tin_number);
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($withReceiptText, 'Prepared for internal filing'),
        );

        $this->actingAs($user)
            ->delete(route('doc-merge.receipt.destroy', ['mergedPdf' => $mergedPdf]))
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt removed from footered-packet.pdf.');

        $mergedPdf->refresh();

        $restoredText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('s3')->path($mergedPdf->storage_path),
        );

        $this->assertSame('123-456-789-000', $mergedPdf->tin_number);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($restoredText, 'Prepared for internal filing'),
        );
    }

    public function test_receipt_generation_requires_a_saved_template()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'expense-packet.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/expense-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['quote.pdf', 'invoice.pdf'],
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0]]),
        );

        $this->actingAs($user)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'placeholders' => [
                    'client_name' => 'Acme Corp',
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas(
                'error',
                'Upload the shared receipt template before generating a receipt.',
            );
    }

    public function test_receipt_downloads_are_authorized_for_the_owner_only()
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $owner->id,
            'file_name' => 'expense-packet.pdf',
            'storage_path' => 'doc-merge/'.$owner->id.'/expense-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['quote.pdf', 'invoice.pdf'],
            'receipt_file_name' => 'official-receipt.pdf',
            'receipt_storage_path' => 'doc-merge/'.$owner->id.'/receipts/'.$owner->id.'/official-receipt.pdf',
            'receipt_file_size' => 12345,
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents(),
        );
        Storage::disk('s3')->put(
            $mergedPdf->receipt_storage_path,
            'receipt contents',
        );

        $this->actingAs($owner)
            ->get(route('doc-merge.receipt.download', ['mergedPdf' => $mergedPdf]))
            ->assertOk()
            ->assertDownload('official-receipt.pdf');

        $this->actingAs($intruder)
            ->get(route('doc-merge.receipt.download', ['mergedPdf' => $mergedPdf]))
            ->assertNotFound();
    }

    public function test_authenticated_users_can_remove_receipts_and_restore_the_original_merged_pdf()
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'expense-packet.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/expense-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['quote.pdf', 'invoice.pdf'],
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0]]),
        );

        $this->actingAs($user)
            ->post(route('doc-merge.confirmation-template.store'), [
                'template' => $this->makeUploadedDocxTemplate(
                    'confirmation-template.docx',
                    ['receipt_note'],
                ),
            ])
            ->assertRedirect(route('doc-merge.index'));

        $this->actingAs($user)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'placeholders' => [
                    'receipt_note' => 'Official receipt',
                ],
            ])
            ->assertRedirect(route('doc-merge.index'));

        $this->assertReceiptJobQueuedFor($mergedPdf)
            ->handle(app(\App\Services\MergedPdfReceiptService::class));

        $mergedPdf->refresh();
        $receiptStoragePath = $mergedPdf->receipt_storage_path;

        $this->assertSame(3, $mergedPdf->source_count);
        $this->assertCount(
            2,
            $this->mergedPdfDimensions(
                Storage::disk('s3')->path($mergedPdf->storage_path),
            ),
        );

        $this->actingAs($user)
            ->delete(route('doc-merge.receipt.destroy', ['mergedPdf' => $mergedPdf]))
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt removed from expense-packet.pdf.');

        $mergedPdf->refresh();

        $this->assertNull($mergedPdf->receipt_file_name);
        $this->assertNull($mergedPdf->receipt_storage_path);
        $this->assertNull($mergedPdf->receipt_file_size);
        $this->assertSame(2, $mergedPdf->source_count);
        $this->assertSame(['quote.pdf', 'invoice.pdf'], $mergedPdf->source_file_names);
        $this->assertCount(
            1,
            $this->mergedPdfDimensions(
                Storage::disk('s3')->path($mergedPdf->storage_path),
            ),
        );
        Storage::disk('s3')->assertMissing((string) $receiptStoragePath);
    }

    public function test_receipt_generation_returns_a_friendly_error_when_pdf_conversion_fails()
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'expense-packet.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/expense-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['quote.pdf', 'invoice.pdf'],
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0]]),
        );

        $this->actingAs($user)
            ->post(route('doc-merge.confirmation-template.store'), [
                'template' => $this->makeUploadedDocxTemplate(
                    'confirmation-template.docx',
                    ['client_name'],
                ),
            ])
            ->assertRedirect(route('doc-merge.index'));

        $this->mock(ConfirmationDocxService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractPlaceholders')
                ->once()
                ->andReturn(['client_name']);
            $mock->shouldReceive('renderPdf')
                ->once()
                ->andThrow(new \RuntimeException('The receipt PDF could not be created. LibreOffice failed.'));
        });

        $this->actingAs($user)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'placeholders' => [
                    'client_name' => 'Acme Corp',
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt queued for expense-packet.pdf.');

        $queuedJob = $this->assertReceiptJobQueuedFor($mergedPdf);

        try {
            $queuedJob->handle(app(\App\Services\MergedPdfReceiptService::class));
            $this->fail('Expected the queued receipt job to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The receipt PDF could not be created. LibreOffice failed.',
                $exception->getMessage(),
            );
        }

        $mergedPdf->refresh();

        $this->assertNull($mergedPdf->receipt_file_name);
        $this->assertNull($mergedPdf->receipt_storage_path);
        $this->assertNull($mergedPdf->receipt_file_size);
        $this->assertSame(MergedPdf::RECEIPT_JOB_STATUS_FAILED, $mergedPdf->receipt_job_status);
        $this->assertSame(
            'The receipt PDF could not be created. LibreOffice failed.',
            $mergedPdf->receipt_job_error,
        );
    }

    public function test_authenticated_users_can_email_saved_merged_pdfs_with_optional_subject_and_message()
    {
        Storage::fake('local');
        Mail::fake();

        $user = User::factory()->create();

        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'shared-packet.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/shared-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['chapter-1.pdf', 'chapter-2.pdf'],
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0], [210.0, 297.0]]),
        );

        $mergedPdf->update([
            'file_size' => Storage::disk('s3')->size($mergedPdf->storage_path),
        ]);

        $cases = [
            [
                'recipient' => 'provided@example.com',
                'subject' => 'Monthly packet',
                'message' => 'Please review the attached PDF.',
                'expectedSubject' => 'Monthly packet',
                'expectedMessage' => 'Please review the attached PDF.',
            ],
            [
                'recipient' => 'null-subject@example.com',
                'subject' => null,
                'message' => 'Message stays present.',
                'expectedSubject' => null,
                'expectedMessage' => 'Message stays present.',
            ],
            [
                'recipient' => 'blank-subject@example.com',
                'subject' => '   ',
                'message' => 'Trim blank subject to null.',
                'expectedSubject' => null,
                'expectedMessage' => 'Trim blank subject to null.',
            ],
            [
                'recipient' => 'null-message@example.com',
                'subject' => 'Attachment only',
                'message' => null,
                'expectedSubject' => 'Attachment only',
                'expectedMessage' => null,
            ],
            [
                'recipient' => 'blank-both@example.com',
                'subject' => " \n\t ",
                'message' => "   \n\t ",
                'expectedSubject' => null,
                'expectedMessage' => null,
            ],
        ];

        foreach ($cases as $case) {
            $this->actingAs($user)
                ->post(route('doc-merge.send-email', ['mergedPdf' => $mergedPdf]), [
                    'recipientEmail' => $case['recipient'],
                    'subject' => $case['subject'],
                    'message' => $case['message'],
                ])
                ->assertRedirect(route('doc-merge.index'))
                ->assertSessionHas('success', "Email queued to {$case['recipient']}.");
        }

        Mail::assertQueued(MergedPdfEmail::class, count($cases));

        foreach ($cases as $case) {
            Mail::assertQueued(MergedPdfEmail::class, function (MergedPdfEmail $mail) use ($case, $mergedPdf): bool {
                if (! $mail->hasTo($case['recipient'])) {
                    return false;
                }

                $mail->assertHasTo($case['recipient']);
                $mail->assertHasAttachment(
                    Attachment::fromStorageDisk('local', $mergedPdf->storage_path)
                        ->as($mergedPdf->file_name)
                        ->withMime('application/pdf'),
                );

                $this->assertSame($case['expectedSubject'], $mail->subjectLine);
                $this->assertSame($case['expectedMessage'], $mail->messageBody);
                $this->assertSame($case['expectedSubject'], $mail->envelope()->subject);

                if ($case['expectedSubject'] !== null) {
                    $mail->assertHasSubject($case['expectedSubject']);
                }

                if ($case['expectedMessage'] !== null) {
                    $mail->assertSeeInText($case['expectedMessage']);
                }

                return true;
            });
        }
    }

    public function test_doc_merge_send_email_validation_requires_a_valid_recipient_email()
    {
        Mail::fake();

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'shared-packet.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/shared-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['chapter-1.pdf', 'chapter-2.pdf'],
        ]);

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.send-email', ['mergedPdf' => $mergedPdf]), [
                'recipientEmail' => 'not-an-email',
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('recipientEmail');

        Mail::assertNothingQueued();
    }

    public function test_doc_merge_send_email_rejects_non_owned_saved_pdfs()
    {
        Mail::fake();

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $owner->id,
            'file_name' => 'shared-packet.pdf',
            'storage_path' => 'doc-merge/'.$owner->id.'/shared-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['chapter-1.pdf', 'chapter-2.pdf'],
        ]);

        $this->actingAs($intruder)
            ->post(route('doc-merge.send-email', ['mergedPdf' => $mergedPdf]), [
                'recipientEmail' => 'recipient@example.com',
            ])
            ->assertNotFound();

        Mail::assertNothingQueued();
    }

    public function test_doc_merge_send_email_returns_a_friendly_error_when_the_saved_pdf_is_missing()
    {
        Storage::fake('local');
        Mail::fake();

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'missing.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/missing.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['chapter-1.pdf', 'chapter-2.pdf'],
        ]);

        $this->actingAs($user)
            ->post(route('doc-merge.send-email', ['mergedPdf' => $mergedPdf]), [
                'recipientEmail' => 'recipient@example.com',
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('error', 'The saved PDF missing.pdf is no longer available.');

        Mail::assertNothingQueued();
    }

    public function test_busy_batches_block_receipt_queueing_and_email_queueing(): void
    {
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $user = User::factory()->create();
        $batch = DocMergeBatch::query()->create([
            'user_id' => $user->id,
            'name' => 'Busy Batch',
            'processing_status' => DocMergeBatch::PROCESSING_STATUS_QUEUED,
        ]);
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'doc_merge_batch_id' => $batch->id,
            'file_name' => 'busy-batch.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/busy-batch.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['page-1.pdf', 'page-2.pdf'],
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents(),
        );

        $this->actingAs($user)
            ->post(route('doc-merge.confirmation-template.store'), [
                'template' => $this->makeUploadedDocxTemplate(
                    'confirmation-template.docx',
                    ['client_name'],
                ),
            ])
            ->assertRedirect(route('doc-merge.index'));

        $busyMessage = 'This batch is already queued or processing. Wait for it to finish before making more changes.';

        $this->actingAs($user)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'placeholders' => [
                    'client_name' => 'Acme Corp',
                ],
            ])
            ->assertRedirect(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertSessionHas('error', $busyMessage);

        $this->actingAs($user)
            ->post(route('doc-merge.send-email', ['mergedPdf' => $mergedPdf]), [
                'recipientEmail' => 'recipient@example.com',
            ])
            ->assertRedirect(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertSessionHas('error', $busyMessage);

        Queue::assertNothingPushed();
        Mail::assertNothingQueued();
    }

    private function assertReceiptJobQueuedFor(MergedPdf $mergedPdf): GenerateMergedPdfReceipt
    {
        $queuedJob = null;

        Queue::assertPushed(GenerateMergedPdfReceipt::class, function (GenerateMergedPdfReceipt $job) use ($mergedPdf, &$queuedJob): bool {
            if ($job->mergedPdfId !== $mergedPdf->id) {
                return false;
            }

            $queuedJob = $job;

            return true;
        });

        $this->assertInstanceOf(GenerateMergedPdfReceipt::class, $queuedJob);

        return $queuedJob;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $pages
     */
    private function makeUploadedPdf(string $fileName, array $pages = [[210.0, 297.0]]): UploadedFile
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-merge-test-');
        $pdfPath = $temporaryPath.'.pdf';
        rename($temporaryPath, $pdfPath);

        $pdf = new \FPDF;

        foreach ($pages as [$width, $height]) {
            $orientation = $width > $height ? 'L' : 'P';

            $pdf->AddPage($orientation, [$width, $height]);
        }

        $pdf->Output('F', $pdfPath);

        return new UploadedFile(
            $pdfPath,
            $fileName,
            'application/pdf',
            null,
            true,
        );
    }

    /**
     * @param  list<list<string>>  $pages
     */
    private function makeUploadedTextPdf(string $fileName, array $pages): UploadedFile
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-merge-text-');
        $pdfPath = $temporaryPath.'.pdf';
        rename($temporaryPath, $pdfPath);

        $pdf = new \FPDF;

        foreach ($pages as $lines) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', '', 12);

            foreach ($lines as $line) {
                $pdf->Cell(0, 10, $line, 0, 1);
            }
        }

        $pdf->Output('F', $pdfPath);

        return new UploadedFile(
            $pdfPath,
            $fileName,
            'application/pdf',
            null,
            true,
        );
    }

    /**
     * @param  list<array{0: float, 1: float}>  $pages
     */
    private function makePdfContents(array $pages = [[210.0, 297.0]]): string
    {
        $pdf = new \FPDF;

        foreach ($pages as [$width, $height]) {
            $orientation = $width > $height ? 'L' : 'P';

            $pdf->AddPage($orientation, [$width, $height]);
        }

        return $pdf->Output('S');
    }

    /**
     * @return list<array{width: float, height: float}>
     */
    private function mergedPdfDimensions(string $path): array
    {
        $pdf = new Fpdi;
        $pageCount = $pdf->setSourceFile($path);
        $dimensions = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);

            $dimensions[] = [
                'width' => (float) $size['width'],
                'height' => (float) $size['height'],
            ];
        }

        return $dimensions;
    }

    /**
     * @param  list<string>  $placeholders
     */
    private function makeUploadedDocxTemplate(
        string $fileName,
        array $placeholders,
        ?string $wordDocumentId = null,
    ): UploadedFile {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-merge-docx-');
        $docxPath = $temporaryPath.'.docx';
        rename($temporaryPath, $docxPath);

        $archive = new ZipArchive;

        if ($archive->open($docxPath, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('The DOCX test archive could not be created.');
        }

        $bodyText = collect($placeholders)
            ->map(
                fn (string $placeholder): string => sprintf(
                    '<w:p><w:r><w:t>{%s}</w:t></w:r></w:p>',
                    $placeholder,
                ),
            )
            ->implode('');

        $archive->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>
XML);
        $archive->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML);
        $archive->addFromString('docProps/app.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
    xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>Microsoft Office Word</Application>
</Properties>
XML);
        $archive->addFromString('docProps/core.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:dcterms="http://purl.org/dc/terms/"
    xmlns:dcmitype="http://purl.org/dc/dcmitype/"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:title>Doc Merge Test Template</dc:title>
    <dc:creator>Codex</dc:creator>
</cp:coreProperties>
XML);
        $archive->addFromString('word/_rels/document.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
</Relationships>
XML);
        if ($wordDocumentId !== null) {
            $archive->addFromString(
                'word/settings.xml',
                <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
    xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml">
    <w15:docId w15:val="{$wordDocumentId}"/>
</w:settings>
XML,
            );
        }
        $archive->addFromString('word/styles.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:default="1" w:styleId="Normal">
        <w:name w:val="Normal"/>
    </w:style>
</w:styles>
XML);
        $archive->addFromString(
            'word/document.xml',
            <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        {$bodyText}
        <w:sectPr>
            <w:pgSz w:w="11906" w:h="16838"/>
            <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/>
        </w:sectPr>
    </w:body>
</w:document>
XML,
        );
        $archive->close();

        return new UploadedFile(
            $docxPath,
            $fileName,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );
    }

    private function previewVersion(MergedPdf $mergedPdf): string
    {
        return sha1(json_encode([
            'updated_at' => $mergedPdf->updated_at?->toIso8601String(),
            'file_size' => $mergedPdf->file_size,
            'source_count' => $mergedPdf->source_count,
            'source_file_names' => $mergedPdf->source_file_names,
            'tin_number' => $mergedPdf->tin_number,
            'footer_text' => $mergedPdf->footer_text,
            'receipt_file_name' => $mergedPdf->receipt_file_name,
            'receipt_file_size' => $mergedPdf->receipt_file_size,
        ], JSON_THROW_ON_ERROR));
    }
}
