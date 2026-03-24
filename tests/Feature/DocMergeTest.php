<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MergedPdf;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
                ->has('mergedPdfs', 1)
                ->where('mergedPdfs.0.fileName', 'combined-report.pdf')
                ->where('mergedPdfs.0.fileSize', 10240)
                ->where('mergedPdfs.0.sourceCount', 2)
                ->where('mergedPdfs.0.sourceFileNames', ['chapter-1.pdf', 'chapter-2.pdf'])
                ->where('mergedPdfs.0.downloadUrl', route('doc-merge.download', ['mergedPdf' => $ownedMergedPdf])),
            );
    }

    public function test_authenticated_users_can_merge_two_pdfs_and_save_output()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.store'), [
                'outputName' => 'custom-merged',
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

    public function test_authenticated_users_can_merge_three_or_more_pdfs_in_selected_order()
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('doc-merge.store'), [
                'outputName' => 'ordered-output.pdf',
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

    public function test_doc_merge_validation_requires_at_least_two_files()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.store'), [
                'files' => [$this->makeUploadedPdf('single.pdf')],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('files');
    }

    public function test_doc_merge_validation_requires_files_to_be_pdfs()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.store'), [
                'files' => [
                    UploadedFile::fake()->create('photo.png', 12, 'image/png'),
                    UploadedFile::fake()->create('another-photo.jpg', 12, 'image/jpeg'),
                ],
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('files.0');
    }

    public function test_doc_merge_validation_requires_files_to_be_present()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.store'), [])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('files');
    }

    public function test_saved_merged_pdfs_are_scoped_to_the_current_user()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'mine.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/mine.pdf',
            'file_size' => 99,
            'source_count' => 2,
            'source_file_names' => ['a.pdf', 'b.pdf'],
        ]);

        Storage::disk('local')->put($mergedPdf->storage_path, 'merged-body');

        $foreignMergedPdf = MergedPdf::query()->create([
            'user_id' => $otherUser->id,
            'file_name' => 'theirs.pdf',
            'storage_path' => 'doc-merge/'.$otherUser->id.'/theirs.pdf',
            'file_size' => 99,
            'source_count' => 2,
            'source_file_names' => ['c.pdf', 'd.pdf'],
        ]);

        Storage::disk('local')->put($foreignMergedPdf->storage_path, 'merged-body');

        $this->withoutVite();

        $this->actingAs($user)
            ->get(route('doc-merge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMerge')
                ->has('mergedPdfs', 1)
                ->where('mergedPdfs.0.fileName', 'mine.pdf'),
            );
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
            'file_size' => 99,
            'source_count' => 2,
            'source_file_names' => ['a.pdf', 'b.pdf'],
        ]);

        Storage::disk('local')->put($mergedPdf->storage_path, 'merged-body');

        $this->actingAs($owner)
            ->get(route('doc-merge.download', ['mergedPdf' => $mergedPdf]))
            ->assertOk()
            ->assertDownload('secure-merge.pdf');

        $this->actingAs($intruder)
            ->get(route('doc-merge.download', ['mergedPdf' => $mergedPdf]))
            ->assertNotFound();
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
