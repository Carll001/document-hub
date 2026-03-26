<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\MergedPdfEmail;
use App\Models\MergedPdf;
use App\Models\User;
use App\Services\PdfTextExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

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

        $ownedMergedPdf = MergedPdf::query()->create([
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
                ->has('mergeHistory', 1)
                ->where('mergeHistory.0.recordType', 'merged_pdf')
                ->where('mergeHistory.0.fileName', 'combined-report.pdf')
                ->where('mergeHistory.0.fileSize', 10240)
                ->where('mergeHistory.0.sourceCount', 2)
                ->where('mergeHistory.0.sourceFileNames', ['chapter-1.pdf', 'chapter-2.pdf'])
                ->where('mergeHistory.0.tinNumber', '123-456-789-000')
                ->where('mergeHistory.0.footerText', 'Prepared for filing')
                ->where('mergeHistory.0.hasReceipt', false)
                ->where('mergeHistory.0.receiptFileName', null)
                ->where('mergeHistory.0.receiptFileSize', null)
                ->where('mergeHistory.0.downloadUrl', route('doc-merge.download', ['mergedPdf' => $ownedMergedPdf]))
                ->where('mergeHistory.0.previewUrl', route('doc-merge.preview', [
                    'mergedPdf' => $ownedMergedPdf,
                    'v' => $this->previewVersion($ownedMergedPdf),
                ]))
                ->where('mergeHistory.0.receiptUploadUrl', route('doc-merge.receipt.store', ['mergedPdf' => $ownedMergedPdf]))
                ->where('mergeHistory.0.receiptRemoveUrl', null)
                ->where('mergeHistory.0.receiptDownloadUrl', null)
                ->where('mergeHistory.0.sendEmailUrl', route('doc-merge.send-email', ['mergedPdf' => $ownedMergedPdf])),
            );
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
        Storage::disk('local')->assertExists($mergedPdf->storage_path);
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
            Storage::disk('local')->path($mergedPdf->storage_path),
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
                ->where('mergeHistory.0.tinNumber', '123-456-789-000'),
            );
    }

    public function test_authenticated_users_can_merge_pdfs_and_attach_a_receipt_in_one_step()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.store'), [
                'outputName' => 'expense-packet',
                'sources' => [
                    ['type' => 'upload'],
                    ['type' => 'upload'],
                ],
                'files' => [
                    $this->makeUploadedPdf('quote.pdf', [[210.0, 297.0]]),
                    $this->makeUploadedPdf('invoice.pdf', [[210.0, 297.0]]),
                ],
                'receipt' => $this->makeUploadedPdf(
                    'official-receipt.pdf',
                    [[148.0, 210.0]],
                ),
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas(
                'success',
                'Merged PDF saved as expense-packet.pdf with receipt attached.',
            );

        $mergedPdf = MergedPdf::query()->firstOrFail();

        $this->assertSame('expense-packet.pdf', $mergedPdf->file_name);
        $this->assertSame('official-receipt.pdf', $mergedPdf->receipt_file_name);
        $this->assertNotNull($mergedPdf->receipt_storage_path);
        $this->assertNotNull($mergedPdf->receipt_file_size);
        $this->assertSame(3, $mergedPdf->source_count);
        $this->assertSame(
            ['quote.pdf', 'invoice.pdf', 'Receipt: official-receipt.pdf'],
            $mergedPdf->source_file_names,
        );
        Storage::disk('local')->assertExists($mergedPdf->storage_path);
        Storage::disk('local')->assertExists($mergedPdf->receipt_storage_path);
        $this->assertCount(
            3,
            $this->mergedPdfDimensions(
                Storage::disk('local')->path($mergedPdf->storage_path),
            ),
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
        $dimensions = $this->mergedPdfDimensions(Storage::disk('local')->path($mergedPdf->storage_path));

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

        Storage::disk('local')->put(
            $original->storage_path,
            $this->makePdfContents([[210.0, 297.0], [210.0, 297.0]]),
        );

        $original->update([
            'file_size' => Storage::disk('local')->size($original->storage_path),
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

    public function test_doc_merge_validation_requires_merge_receipts_to_be_supported_files()
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
                    $this->makeUploadedPdf('chapter-a.pdf'),
                    $this->makeUploadedPdf('chapter-b.pdf'),
                ],
                'receipt' => UploadedFile::fake()->create(
                    'notes.txt',
                    5,
                    'text/plain',
                ),
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('receipt');
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

        Storage::disk('local')->put(
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

        Storage::disk('local')->put(
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

        Storage::disk('local')->put(
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

        Storage::disk('local')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents(),
        );
        Storage::disk('local')->put(
            $mergedPdf->receipt_storage_path,
            'receipt contents',
        );

        $this->actingAs($user)
            ->delete(route('doc-merge.destroy-many'), [
                'items' => [
                    ['type' => 'merged_pdf', 'id' => $mergedPdf->id],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Deleted mistake.pdf.');

        $this->assertDatabaseMissing('merged_pdfs', [
            'id' => $mergedPdf->id,
        ]);
        Storage::disk('local')->assertMissing($mergedPdf->storage_path);
        Storage::disk('local')->assertMissing($mergedPdf->receipt_storage_path);
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

        Storage::disk('local')->put(
            $firstMergedPdf->storage_path,
            $this->makePdfContents(),
        );
        Storage::disk('local')->put(
            $secondMergedPdf->storage_path,
            $this->makePdfContents(),
        );

        $this->actingAs($user)
            ->delete(route('doc-merge.destroy-many'), [
                'items' => [
                    ['type' => 'merged_pdf', 'id' => $firstMergedPdf->id],
                    ['type' => 'merged_pdf', 'id' => $secondMergedPdf->id],
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

        Storage::disk('local')->put(
            $ownedMergedPdf->storage_path,
            $this->makePdfContents(),
        );
        Storage::disk('local')->put(
            $foreignMergedPdf->storage_path,
            $this->makePdfContents(),
        );

        $this->actingAs($user)
            ->delete(route('doc-merge.destroy-many'), [
                'items' => [
                    ['type' => 'merged_pdf', 'id' => $ownedMergedPdf->id],
                    ['type' => 'merged_pdf', 'id' => $foreignMergedPdf->id],
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

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'expense-packet.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/expense-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['quote.pdf', 'invoice.pdf'],
        ]);

        Storage::disk('local')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0]]),
        );

        $firstReceipt = $this->makeUploadedPdf(
            'official-receipt.pdf',
            [[148.0, 210.0]],
        );

        $this->actingAs($user)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'receipt' => $firstReceipt,
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt added to expense-packet.pdf.');

        $mergedPdf->refresh();

        $this->assertSame('official-receipt.pdf', $mergedPdf->receipt_file_name);
        $this->assertNotNull($mergedPdf->receipt_storage_path);
        $this->assertNotNull($mergedPdf->receipt_file_size);
        $this->assertSame(3, $mergedPdf->source_count);
        $this->assertSame(
            ['quote.pdf', 'invoice.pdf', 'Receipt: official-receipt.pdf'],
            $mergedPdf->source_file_names,
        );
        Storage::disk('local')->assertExists($mergedPdf->receipt_storage_path);
        $this->assertCount(
            2,
            $this->mergedPdfDimensions(
                Storage::disk('local')->path($mergedPdf->storage_path),
            ),
        );

        $firstReceiptPath = $mergedPdf->receipt_storage_path;

        $replacementReceipt = UploadedFile::fake()->image('replacement-receipt.png');

        $this->actingAs($user)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'receipt' => $replacementReceipt,
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt updated for expense-packet.pdf.');

        $mergedPdf->refresh();

        $this->assertSame('replacement-receipt.png', $mergedPdf->receipt_file_name);
        $this->assertNotSame($firstReceiptPath, $mergedPdf->receipt_storage_path);
        $this->assertSame(3, $mergedPdf->source_count);
        $this->assertSame(
            ['quote.pdf', 'invoice.pdf', 'Receipt: replacement-receipt.png'],
            $mergedPdf->source_file_names,
        );
        Storage::disk('local')->assertMissing($firstReceiptPath);
        Storage::disk('local')->assertExists($mergedPdf->receipt_storage_path);
        $this->assertCount(
            2,
            $this->mergedPdfDimensions(
                Storage::disk('local')->path($mergedPdf->storage_path),
            ),
        );
    }

    public function test_receipt_updates_keep_the_saved_tin_number_and_reapply_the_footer()
    {
        Storage::fake('local');

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
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'receipt' => $this->makeUploadedTextPdf('official-receipt.pdf', [[
                    'Receipt page',
                ]]),
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Receipt added to footered-packet.pdf.');

        $mergedPdf->refresh();

        $withReceiptText = app(PdfTextExtractionService::class)->extractText(
            Storage::disk('local')->path($mergedPdf->storage_path),
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
            Storage::disk('local')->path($mergedPdf->storage_path),
        );

        $this->assertSame('123-456-789-000', $mergedPdf->tin_number);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($restoredText, 'Prepared for internal filing'),
        );
    }

    public function test_doc_merge_receipt_validation_requires_supported_files()
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

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'receipt' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain'),
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('receipt');
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

        Storage::disk('local')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents(),
        );
        Storage::disk('local')->put(
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

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'expense-packet.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/expense-packet.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['quote.pdf', 'invoice.pdf'],
        ]);

        Storage::disk('local')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0]]),
        );

        $this->actingAs($user)
            ->post(route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]), [
                'receipt' => $this->makeUploadedPdf('official-receipt.pdf', [[148.0, 210.0]]),
            ])
            ->assertRedirect(route('doc-merge.index'));

        $mergedPdf->refresh();
        $receiptStoragePath = $mergedPdf->receipt_storage_path;

        $this->assertSame(3, $mergedPdf->source_count);
        $this->assertCount(
            2,
            $this->mergedPdfDimensions(
                Storage::disk('local')->path($mergedPdf->storage_path),
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
                Storage::disk('local')->path($mergedPdf->storage_path),
            ),
        );
        Storage::disk('local')->assertMissing((string) $receiptStoragePath);
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

        Storage::disk('local')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents([[210.0, 297.0], [210.0, 297.0]]),
        );

        $mergedPdf->update([
            'file_size' => Storage::disk('local')->size($mergedPdf->storage_path),
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
                ->assertSessionHas('success', "Email sent to {$case['recipient']}.");
        }

        Mail::assertSent(MergedPdfEmail::class, count($cases));

        foreach ($cases as $case) {
            Mail::assertSent(MergedPdfEmail::class, function (MergedPdfEmail $mail) use ($case, $mergedPdf): bool {
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

        Mail::assertNothingSent();
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

        Mail::assertNothingSent();
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

        Mail::assertNothingSent();
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
