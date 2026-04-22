<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BulkMergeFailure;
use App\Models\MergedPdf;
use App\Models\User;
use App\Services\PdfTextExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;
use ZipArchive;

class BulkDocMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_zip_merge_groups_same_name_pdfs_by_page_folder_order()
    {
        Storage::fake('local');
        $this->withoutVite();

        $user = User::factory()->create();
        $zip = $this->makeUploadedZip([
            'wrapper/PAGE 10/invoice.pdf' => $this->makePdfContents([[320.0, 180.0]]),
            'wrapper/PAGE 2/invoice.pdf' => $this->makePdfContents([[210.0, 210.0]]),
            'wrapper/PAGE 1/invoice.pdf' => $this->makePdfContents([[180.0, 320.0]]),
            'wrapper/PAGE 10/summary.pdf' => $this->makePdfContents([[240.0, 160.0]]),
            'wrapper/PAGE 2/summary.pdf' => $this->makePdfContents([[160.0, 240.0]]),
            'wrapper/PAGE 1/summary.pdf' => $this->makePdfContents([[200.0, 200.0]]),
        ]);

        $this->actingAs($user)
            ->post(route('doc-merge.bulk.store'), [
                'zip' => $zip,
                'outputPrefix' => 'Client-',
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', '2 PDFs merged, 0 PDFs failed.');

        $invoice = MergedPdf::query()
            ->where('file_name', 'Client-invoice.pdf')
            ->firstOrFail();
        $summary = MergedPdf::query()
            ->where('file_name', 'Client-summary.pdf')
            ->firstOrFail();

        $this->assertSame(3, $invoice->source_count);
        $this->assertSame(
            ['invoice.pdf', 'invoice.pdf', 'invoice.pdf'],
            $invoice->source_file_names,
        );
        $this->assertSame('Client-summary.pdf', $summary->file_name);

        $dimensions = $this->mergedPdfDimensions(
            Storage::disk('s3')->path($invoice->storage_path),
        );

        $this->assertCount(3, $dimensions);
        $this->assertLessThan($dimensions[0]['height'], $dimensions[0]['width']);
        $this->assertEqualsWithDelta(210.0, $dimensions[1]['width'], 1.0);
        $this->assertEqualsWithDelta(210.0, $dimensions[1]['height'], 1.0);
        $this->assertGreaterThan($dimensions[2]['height'], $dimensions[2]['width']);

        $this->actingAs($user)
            ->get(route('doc-merge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMerge')
                ->missing('mergeHistory'),
            );
    }

    public function test_bulk_folder_upload_matches_page_folders_and_prefixes_output_names()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.bulk-folders.store'), [
                'outputPrefix' => 'Batch-',
                'footerText' => 'Prepared for internal filing',
                'pageFolders' => [
                    [
                        'name' => 'PAGE 10',
                        'number' => 10,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [
                            $this->makeUploadedPdf('invoice.pdf'),
                            $this->makeUploadedPdf('report.pdf'),
                        ],
                    ],
                    [
                        'name' => 'PAGE 1',
                        'number' => 1,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [
                            $this->makeUploadedPdf('invoice.pdf'),
                            $this->makeUploadedPdf('report.pdf'),
                        ],
                    ],
                    [
                        'name' => 'PAGE 2',
                        'number' => 2,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [
                            $this->makeUploadedPdf('invoice.pdf'),
                            $this->makeUploadedPdf('report.pdf'),
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', '2 PDFs merged, 0 PDFs failed.');

        $this->assertDatabaseHas('merged_pdfs', [
            'user_id' => $user->id,
            'file_name' => 'Batch-invoice.pdf',
            'footer_text' => 'Prepared for internal filing',
        ]);
        $this->assertDatabaseHas('merged_pdfs', [
            'user_id' => $user->id,
            'file_name' => 'Batch-report.pdf',
        ]);
    }

    public function test_bulk_folder_upload_groups_numbered_pdf_names_by_their_base_name()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.bulk-folders.store'), [
                'outputPrefix' => 'Batch-',
                'pageFolders' => [
                    [
                        'name' => 'PAGE 1',
                        'number' => 1,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [
                            $this->makeUploadedPdf('invoice 1.pdf'),
                            $this->makeUploadedPdf('report 1.pdf'),
                        ],
                    ],
                    [
                        'name' => 'PAGE 2',
                        'number' => 2,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [
                            $this->makeUploadedPdf('invoice 2.pdf'),
                            $this->makeUploadedPdf('report 2.pdf'),
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', '2 PDFs merged, 0 PDFs failed.');

        $this->assertDatabaseHas('merged_pdfs', [
            'user_id' => $user->id,
            'file_name' => 'Batch-invoice.pdf',
        ]);
        $this->assertDatabaseHas('merged_pdfs', [
            'user_id' => $user->id,
            'file_name' => 'Batch-report.pdf',
        ]);
    }

    public function test_bulk_zip_merge_persists_tin_numbers_and_footer_text_for_successful_outputs()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $zip = $this->makeUploadedZip([
            'PAGE 1/invoice.pdf' => $this->makeTextPdfContents([[
                'size' => [210.0, 297.0],
                'lines' => ['Taxpayer Identification Number (TIN): 123-456-789-000'],
            ]]),
            'PAGE 2/invoice.pdf' => $this->makeTextPdfContents([[
                'size' => [210.0, 297.0],
                'lines' => ['Invoice continuation'],
            ]]),
        ]);

        $this->actingAs($user)
            ->post(route('doc-merge.bulk.store'), [
                'zip' => $zip,
                'outputPrefix' => 'Client-',
                'footerText' => 'Prepared for internal filing',
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', '1 PDF merged, 0 PDFs failed.');

        $mergedPdf = MergedPdf::query()
            ->where('file_name', 'Client-invoice.pdf')
            ->firstOrFail();

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
    }

    public function test_bulk_merge_creates_failure_rows_when_a_pdf_is_missing_from_a_page_folder()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.bulk-folders.store'), [
                'outputPrefix' => 'Batch-',
                'pageFolders' => [
                    [
                        'name' => 'PAGE 1',
                        'number' => 1,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [
                            $this->makeUploadedPdf('invoice.pdf'),
                            $this->makeUploadedPdf('report.pdf'),
                        ],
                    ],
                    [
                        'name' => 'PAGE 2',
                        'number' => 2,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [
                            $this->makeUploadedPdf('report.pdf'),
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', '1 PDF merged, 1 PDF failed.');

        $failure = BulkMergeFailure::query()->firstOrFail();

        $this->assertDatabaseHas('merged_pdfs', [
            'user_id' => $user->id,
            'file_name' => 'Batch-report.pdf',
        ]);
        $this->assertSame('folder', $failure->input_mode);
        $this->assertSame('invoice.pdf', $failure->group_label);
        $this->assertSame('Batch-invoice.pdf', $failure->output_file_name);
        $this->assertStringContainsString('PAGE 2', $failure->error_message);
    }

    public function test_bulk_zip_merge_rejects_invalid_page_folder_structure()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $zip = $this->makeUploadedZip([
            'PAGE 1/invoice.pdf' => $this->makePdfContents(),
            'PAGE 2/invoice.pdf' => $this->makePdfContents(),
            'notes.txt' => 'invalid root file',
        ]);

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.bulk.store'), [
                'zip' => $zip,
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('zip');

        $this->assertDatabaseCount('merged_pdfs', 0);
        $this->assertDatabaseCount('bulk_merge_failures', 0);
    }

    public function test_bulk_folder_upload_rejects_duplicate_page_numbers()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.bulk-folders.store'), [
                'pageFolders' => [
                    [
                        'name' => 'PAGE 1',
                        'number' => 1,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [$this->makeUploadedPdf('invoice.pdf')],
                    ],
                    [
                        'name' => 'PGE 1',
                        'number' => 1,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [$this->makeUploadedPdf('invoice.pdf')],
                    ],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('pageFolders');
    }

    public function test_bulk_folder_upload_rejects_pdf_numbers_that_do_not_match_the_page_folder()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.bulk-folders.store'), [
                'pageFolders' => [
                    [
                        'name' => 'PAGE 1',
                        'number' => 1,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [$this->makeUploadedPdf('invoice 1.pdf')],
                    ],
                    [
                        'name' => 'PAGE 2',
                        'number' => 2,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [$this->makeUploadedPdf('invoice 1.pdf')],
                    ],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('pageFolders');
    }

    public function test_bulk_folder_upload_rejects_duplicate_pdf_names_inside_one_page_folder()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.bulk-folders.store'), [
                'pageFolders' => [
                    [
                        'name' => 'PAGE 1',
                        'number' => 1,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [
                            $this->makeUploadedPdf('invoice.pdf'),
                            $this->makeUploadedPdf('INVOICE.pdf'),
                        ],
                    ],
                    [
                        'name' => 'PAGE 2',
                        'number' => 2,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [$this->makeUploadedPdf('invoice.pdf')],
                    ],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('pageFolders');
    }

    public function test_bulk_folder_upload_rejects_nested_page_folders()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.bulk-folders.store'), [
                'pageFolders' => [
                    [
                        'name' => 'PAGE 1',
                        'number' => 1,
                        'hasNestedEntries' => true,
                        'hasInvalidFiles' => false,
                        'files' => [$this->makeUploadedPdf('invoice.pdf')],
                    ],
                    [
                        'name' => 'PAGE 2',
                        'number' => 2,
                        'hasNestedEntries' => false,
                        'hasInvalidFiles' => false,
                        'files' => [$this->makeUploadedPdf('invoice.pdf')],
                    ],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('pageFolders');
    }

    public function test_merge_history_delete_can_remove_saved_pdfs_and_failure_rows_together()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'saved.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/saved.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['part 1.pdf', 'part 2.pdf'],
        ]);
        $failure = BulkMergeFailure::query()->create([
            'user_id' => $user->id,
            'input_mode' => 'zip',
            'input_label' => 'pages.zip',
            'group_label' => 'invoice.pdf',
            'output_file_name' => 'Batch-invoice.pdf',
            'error_message' => 'The PDF invoice.pdf is missing from PAGE 2.',
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents(),
        );

        $this->actingAs($user)
            ->delete(route('doc-merge.destroy-many'), [
                'items' => [
                    ['type' => 'merged_pdf', 'id' => $mergedPdf->uuid],
                    ['type' => 'merge_failure', 'id' => $failure->uuid],
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHas('success', 'Deleted 2 merge results.');

        $this->assertDatabaseMissing('merged_pdfs', ['id' => $mergedPdf->id]);
        $this->assertDatabaseMissing('bulk_merge_failures', ['id' => $failure->id]);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function makeUploadedZip(
        array $entries,
        string $fileName = 'bulk-merge.zip',
    ): UploadedFile {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-merge-zip-');
        $zipPath = $temporaryPath.'.zip';
        rename($temporaryPath, $zipPath);

        $zip = new ZipArchive;
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            $this->fail('The ZIP archive could not be created for the test.');
        }

        foreach ($entries as $path => $contents) {
            $zip->addFromString($path, $contents);
        }

        $zip->close();

        return new UploadedFile(
            $zipPath,
            $fileName,
            'application/zip',
            null,
            true,
        );
    }

    /**
     * @param  list<array{0: float, 1: float}>  $pages
     */
    private function makeUploadedPdf(
        string $fileName,
        array $pages = [[210.0, 297.0]],
    ): UploadedFile {
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
     * @param  list<array{size: array{0: float, 1: float}, lines: list<string>}>  $pages
     */
    private function makeTextPdfContents(array $pages): string
    {
        $pdf = new \FPDF;

        foreach ($pages as $page) {
            [$width, $height] = $page['size'];
            $orientation = $width > $height ? 'L' : 'P';

            $pdf->AddPage($orientation, [$width, $height]);
            $pdf->SetFont('Helvetica', '', 12);

            foreach ($page['lines'] as $line) {
                $pdf->Cell(0, 10, $line, 0, 1);
            }
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
}
