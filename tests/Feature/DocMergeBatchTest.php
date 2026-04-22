<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessDocMergeBatch;
use App\Models\BulkMergeFailure;
use App\Models\ConfirmationTemplate;
use App\Models\DocMergeBatch;
use App\Models\MergedPdf;
use App\Models\User;
use App\Services\DocMergeBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

class DocMergeBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_create_saved_batches_and_see_them_on_doc_merge(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        DocMergeBatch::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Other user batch',
        ]);

        $this->actingAs($user)
            ->post(route('doc-merge.batches.store'), [
                'name' => '  March Filing Batch  ',
            ])
            ->assertRedirect();

        $batch = DocMergeBatch::query()->whereBelongsTo($user)->firstOrFail();

        $this->assertSame('March Filing Batch', $batch->name);

        $this->actingAs($user)
            ->get(route('doc-merge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMerge')
                ->where('batchCreateUrl', route('doc-merge.batches.store'))
                ->where('batchPagination.currentPage', 1)
                ->where('batchPagination.lastPage', 1)
                ->has('batches', 1)
                ->where('batches.0.name', 'March Filing Batch')
                ->where('batches.0.mergedCount', 0)
                ->where('batches.0.failedCount', 0)
                ->where('batches.0.processingStatus', null)
                ->where('batches.0.processingError', null)
                ->where(
                    'batches.0.showUrl',
                    route('doc-merge.batches.show', ['docMergeBatch' => $batch]),
                ),
            );
    }

    public function test_doc_merge_batches_use_numbered_pagination(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();

        foreach (range(1, 10) as $number) {
            DocMergeBatch::query()->create([
                'user_id' => $user->id,
                'name' => "Batch {$number}",
            ]);
        }

        $this->actingAs($user)
            ->get(route('doc-merge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMerge')
                ->where('batchPagination.currentPage', 1)
                ->where('batchPagination.lastPage', 2)
                ->has('batches', 9),
            );

        $this->actingAs($user)
            ->get(route('doc-merge.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMerge')
                ->where('batchPagination.currentPage', 2)
                ->where('batchPagination.lastPage', 2)
                ->has('batches', 1)
                ->where('batches.0.name', 'Batch 1'),
            );
    }

    public function test_batch_creation_requires_a_non_blank_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('doc-merge.index'))
            ->post(route('doc-merge.batches.store'), [
                'name' => " \n\t ",
            ])
            ->assertRedirect(route('doc-merge.index'))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('doc_merge_batches', 0);
    }

    public function test_batch_pages_show_the_shared_receipt_template_uploaded_by_another_user(): void
    {
        Storage::fake('local');
        $this->withoutVite();

        $uploader = User::factory()->create();
        $viewer = User::factory()->create();
        $batch = DocMergeBatch::query()->create([
            'user_id' => $viewer->id,
            'name' => 'Shared Template Batch',
        ]);
        $storagePath = 'doc-merge/shared/confirmation-template/shared-template.docx';

        Storage::disk('s3')->put($storagePath, 'placeholder');

        ConfirmationTemplate::query()->create([
            'key' => ConfirmationTemplate::SHARED_KEY,
            'file_name' => 'shared-template.docx',
            'storage_path' => $storagePath,
            'file_size' => Storage::disk('s3')->size($storagePath),
            'uploaded_by_user_id' => $uploader->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMergeBatch')
                ->where('confirmationTemplate.hasTemplate', true)
                ->where('confirmationTemplate.fileName', 'shared-template.docx')
                ->where(
                    'confirmationTemplate.downloadUrl',
                    route('doc-merge.confirmation-template.download'),
                ),
            );
    }

    public function test_batch_folder_merges_queue_processing_and_persist_sources_to_the_batch(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->withoutVite();

        $user = User::factory()->create();
        $batch = DocMergeBatch::query()->create([
            'user_id' => $user->id,
            'name' => 'Folder Batch',
        ]);

        $this->actingAs($user)
            ->post(route('doc-merge.batches.page-folders.store', ['docMergeBatch' => $batch]), [
                'outputPrefix' => 'BATCH-',
                'pageFolders' => [
                    [
                        'name' => 'PAGE 1',
                        'number' => 1,
                        'files' => [
                            $this->makeUploadedPdf('Alpha 1.pdf'),
                            $this->makeUploadedPdf('Beta 1.pdf'),
                        ],
                    ],
                    [
                        'name' => 'PAGE 2',
                        'number' => 2,
                        'files' => [
                            $this->makeUploadedPdf('Alpha 2.pdf'),
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertSessionHas('success', 'Batch processing queued. Results will refresh automatically.');

        $batch->refresh();
        $this->assertSame(DocMergeBatch::PROCESSING_STATUS_QUEUED, $batch->processing_status);
        $this->assertNull($batch->processing_error);
        $this->assertNull($batch->last_processed_at);
        $this->assertDatabaseHas('doc_merge_batch_source_files', [
            'doc_merge_batch_id' => $batch->id,
            'page_folder_number' => 1,
            'display_name' => 'Alpha 1.pdf',
        ]);
        $this->assertDatabaseHas('doc_merge_batch_source_files', [
            'doc_merge_batch_id' => $batch->id,
            'page_folder_number' => 1,
            'display_name' => 'Beta 1.pdf',
        ]);
        $this->assertDatabaseHas('doc_merge_batch_source_files', [
            'doc_merge_batch_id' => $batch->id,
            'page_folder_number' => 2,
            'display_name' => 'Alpha 2.pdf',
        ]);
        Queue::assertPushed(ProcessDocMergeBatch::class, function (ProcessDocMergeBatch $job) use ($batch): bool {
            return $job->batchId === $batch->id
                && $job->outputPrefix === 'BATCH-';
        });

        $this->actingAs($user)
            ->get(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMergeBatch')
                ->where('batch.name', 'Folder Batch')
                ->where('batch.processingStatus', 'queued')
                ->where('batch.processingError', null)
                ->where('batch.mergedCount', 0)
                ->where('batch.failedCount', 0)
                ->has('batch.results', 0)
                ->where('batch.resultsPagination.currentPage', 1)
                ->where('batch.resultsPagination.lastPage', 1),
            );
    }

    public function test_batch_zip_merges_queue_processing_and_persist_sources_to_the_batch(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->withoutVite();

        $user = User::factory()->create();
        $batch = DocMergeBatch::query()->create([
            'user_id' => $user->id,
            'name' => 'ZIP Batch',
        ]);

        $this->actingAs($user)
            ->post(route('doc-merge.batches.zip.store', ['docMergeBatch' => $batch]), [
                'outputPrefix' => 'ZIP-',
                'zip' => $this->makeUploadedZip('zip-batch.zip', [
                    'PAGE 1/Client A 1.pdf' => $this->makePdfContents(),
                    'PAGE 1/Client B 1.pdf' => $this->makePdfContents(),
                    'PAGE 2/Client A 2.pdf' => $this->makePdfContents(),
                    'PAGE 2/Client B 2.pdf' => $this->makePdfContents(),
                ]),
            ])
            ->assertRedirect(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertSessionHas('success', 'Batch processing queued. Results will refresh automatically.');

        $batch->refresh();

        $this->assertSame(DocMergeBatch::PROCESSING_STATUS_QUEUED, $batch->processing_status);
        $this->assertNull($batch->processing_error);
        $this->assertNull($batch->last_processed_at);
        $this->assertSame(4, $batch->sourceFiles()->count());
        $this->assertDatabaseHas('doc_merge_batch_source_files', [
            'doc_merge_batch_id' => $batch->id,
            'page_folder_number' => 1,
            'display_name' => 'Client A 1.pdf',
        ]);
        $this->assertDatabaseHas('doc_merge_batch_source_files', [
            'doc_merge_batch_id' => $batch->id,
            'page_folder_number' => 2,
            'display_name' => 'Client B 2.pdf',
        ]);
        Queue::assertPushed(ProcessDocMergeBatch::class, function (ProcessDocMergeBatch $job) use ($batch): bool {
            return $job->batchId === $batch->id
                && $job->outputPrefix === 'ZIP-';
        });

        $this->actingAs($user)
            ->get(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMergeBatch')
                ->where('batch.name', 'ZIP Batch')
                ->where('batch.processingStatus', 'queued')
                ->where('batch.processingError', null)
                ->where('batch.mergedCount', 0)
                ->where('batch.failedCount', 0)
                ->has('batch.results', 0)
                ->where('batch.resultsPagination.currentPage', 1)
                ->where('batch.resultsPagination.lastPage', 1),
            );
    }

    public function test_batch_processing_jobs_merge_persisted_sources_and_clear_processing_state(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $batch = DocMergeBatch::query()->create([
            'user_id' => $user->id,
            'name' => 'Queued Batch',
            'processing_status' => DocMergeBatch::PROCESSING_STATUS_QUEUED,
        ]);

        app(DocMergeBatchService::class)->storePageFolders($batch, [
            [
                'name' => 'PAGE 1',
                'number' => 1,
                'files' => [
                    $this->makeUploadedPdf('Alpha 1.pdf'),
                    $this->makeUploadedPdf('Beta 1.pdf'),
                ],
            ],
            [
                'name' => 'PAGE 2',
                'number' => 2,
                'files' => [
                    $this->makeUploadedPdf('Alpha 2.pdf'),
                ],
            ],
        ]);

        $job = new ProcessDocMergeBatch($batch->id, 'BATCH-');

        $job->handle(app(DocMergeBatchService::class));

        $batch->refresh();
        $mergedPdf = MergedPdf::query()
            ->where('doc_merge_batch_id', $batch->id)
            ->firstOrFail();
        $failure = BulkMergeFailure::query()
            ->where('doc_merge_batch_id', $batch->id)
            ->firstOrFail();

        $this->assertSame('BATCH-Alpha.pdf', $mergedPdf->file_name);
        $this->assertSame('BATCH-Beta.pdf', $failure->output_file_name);
        $this->assertNull($batch->processing_status);
        $this->assertNull($batch->processing_error);
        $this->assertNotNull($batch->last_processed_at);
        Storage::disk('s3')->assertExists($mergedPdf->storage_path);
    }

    public function test_batch_processing_jobs_record_failures_and_leave_last_processed_at_unchanged(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $batch = DocMergeBatch::query()->create([
            'user_id' => $user->id,
            'name' => 'Broken Batch',
            'processing_status' => DocMergeBatch::PROCESSING_STATUS_QUEUED,
        ]);

        app(DocMergeBatchService::class)->storePageFolders($batch, [
            [
                'name' => 'PAGE 1',
                'number' => 1,
                'files' => [
                    $this->makeUploadedPdf('Only Page 1.pdf'),
                ],
            ],
        ]);

        $job = new ProcessDocMergeBatch($batch->id);

        try {
            $job->handle(app(DocMergeBatchService::class));
            $this->fail('Expected the batch job to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Add at least two page folders like PAGE 1 and PAGE 2 before running merge.',
                $exception->errors()['batch'][0] ?? null,
            );
        }

        $batch->refresh();

        $this->assertSame(DocMergeBatch::PROCESSING_STATUS_FAILED, $batch->processing_status);
        $this->assertSame(
            'Add at least two page folders like PAGE 1 and PAGE 2 before running merge.',
            $batch->processing_error,
        );
        $this->assertNull($batch->last_processed_at);
        $this->assertSame(0, $batch->mergedPdfs()->count());
        $this->assertSame(0, $batch->bulkMergeFailures()->count());
    }

    public function test_batch_results_use_numbered_pagination(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $batch = DocMergeBatch::query()->create([
            'user_id' => $user->id,
            'name' => 'Paginated Batch',
        ]);

        foreach (range(1, 26) as $number) {
            MergedPdf::query()->create([
                'user_id' => $user->id,
                'doc_merge_batch_id' => $batch->id,
                'file_name' => "Result {$number}.pdf",
                'storage_path' => "doc-merge/{$user->id}/result-{$number}.pdf",
                'file_size' => 1024,
                'source_count' => 2,
                'source_file_names' => ['page-1.pdf', 'page-2.pdf'],
            ]);
        }

        $this->actingAs($user)
            ->get(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMergeBatch')
                ->where('batch.resultsPagination.currentPage', 1)
                ->where('batch.resultsPagination.lastPage', 2)
                ->has('batch.results', 20)
                ->where('batch.results.0.fileName', 'Result 26.pdf'),
            );

        $this->actingAs($user)
            ->get(route('doc-merge.batches.show', [
                'docMergeBatch' => $batch,
                'page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DocMergeBatch')
                ->where('batch.resultsPagination.currentPage', 2)
                ->where('batch.resultsPagination.lastPage', 2)
                ->has('batch.results', 6)
                ->where('batch.results.0.fileName', 'Result 6.pdf'),
            );
    }

    public function test_batch_zip_download_contains_only_batch_merged_pdfs(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $batch = DocMergeBatch::query()->create([
            'user_id' => $user->id,
            'name' => 'Download Batch',
        ]);
        $mergedPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'doc_merge_batch_id' => $batch->id,
            'file_name' => 'Client A.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/client-a.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['Client A 1.pdf', 'Client A 2.pdf'],
        ]);
        $outsideBatchPdf = MergedPdf::query()->create([
            'user_id' => $user->id,
            'file_name' => 'Outside Batch.pdf',
            'storage_path' => 'doc-merge/'.$user->id.'/outside-batch.pdf',
            'file_size' => 0,
            'source_count' => 2,
            'source_file_names' => ['Outside 1.pdf', 'Outside 2.pdf'],
        ]);

        Storage::disk('s3')->put(
            $mergedPdf->storage_path,
            $this->makePdfContents(),
        );
        Storage::disk('s3')->put(
            $outsideBatchPdf->storage_path,
            $this->makePdfContents(),
        );

        $response = $this->actingAs($user)
            ->get(route('doc-merge.batches.download', ['docMergeBatch' => $batch]));

        $response->assertOk()
            ->assertDownload('Download-Batch.zip');

        /** @var BinaryFileResponse $binaryResponse */
        $binaryResponse = $response->baseResponse;
        $archivePath = $binaryResponse->getFile()->getPathname();
        $archive = new ZipArchive;

        $this->assertSame(true, $archive->open($archivePath));

        $entries = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);

            if (is_array($stat) && isset($stat['name']) && is_string($stat['name'])) {
                $entries[] = $stat['name'];
            }
        }

        $archive->close();

        $this->assertContains('Client A.pdf', $entries);
        $this->assertFalse(
            collect($entries)->contains(
                fn (string $entry): bool => str_starts_with($entry, 'merged/'),
            ),
        );
        $this->assertFalse(
            collect($entries)->contains(
                fn (string $entry): bool => str_starts_with($entry, 'source/'),
            ),
        );
    }

    public function test_busy_batches_reject_new_uploads_and_deletion_requests(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $batch = DocMergeBatch::query()->create([
            'user_id' => $user->id,
            'name' => 'Busy Batch',
            'processing_status' => DocMergeBatch::PROCESSING_STATUS_PROCESSING,
        ]);

        $busyMessage = 'This batch is already queued or processing. Wait for it to finish before making more changes.';

        $this->actingAs($user)
            ->post(route('doc-merge.batches.page-folders.store', ['docMergeBatch' => $batch]), [
                'pageFolders' => [
                    [
                        'name' => 'PAGE 1',
                        'number' => 1,
                        'files' => [$this->makeUploadedPdf('Alpha 1.pdf')],
                    ],
                    [
                        'name' => 'PAGE 2',
                        'number' => 2,
                        'files' => [$this->makeUploadedPdf('Alpha 2.pdf')],
                    ],
                ],
            ])
            ->assertRedirect(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertSessionHas('error', $busyMessage);

        $this->actingAs($user)
            ->post(route('doc-merge.batches.zip.store', ['docMergeBatch' => $batch]), [
                'zip' => $this->makeUploadedZip('busy-batch.zip', [
                    'PAGE 1/Client A 1.pdf' => $this->makePdfContents(),
                    'PAGE 2/Client A 2.pdf' => $this->makePdfContents(),
                ]),
            ])
            ->assertRedirect(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertSessionHas('error', $busyMessage);

        $this->actingAs($user)
            ->delete(route('doc-merge.batches.destroy', ['docMergeBatch' => $batch]))
            ->assertRedirect(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertSessionHas('error', $busyMessage);
    }

    public function test_batch_routes_are_scoped_to_the_current_user(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $batch = DocMergeBatch::query()->create([
            'user_id' => $owner->id,
            'name' => 'Owner Batch',
        ]);

        $this->actingAs($intruder)
            ->get(route('doc-merge.batches.show', ['docMergeBatch' => $batch]))
            ->assertNotFound();

        $this->actingAs($intruder)
            ->post(route('doc-merge.batches.page-folders.store', ['docMergeBatch' => $batch]), [
                'pageFolders' => [
                    [
                        'name' => 'PAGE 1',
                        'number' => 1,
                        'files' => [$this->makeUploadedPdf('Owner 1.pdf')],
                    ],
                    [
                        'name' => 'PAGE 2',
                        'number' => 2,
                        'files' => [$this->makeUploadedPdf('Owner 2.pdf')],
                    ],
                ],
            ])
            ->assertNotFound();

        $this->actingAs($intruder)
            ->get(route('doc-merge.batches.download', ['docMergeBatch' => $batch]))
            ->assertNotFound();

        $this->actingAs($intruder)
            ->delete(route('doc-merge.batches.destroy', ['docMergeBatch' => $batch]))
            ->assertNotFound();
    }

    /**
     * @param  list<array{0: float, 1: float}>  $pages
     */
    private function makeUploadedPdf(string $fileName, array $pages = [[210.0, 297.0]]): UploadedFile
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-merge-batch-test-');
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
     * @param  array<string, string>  $entries
     */
    private function makeUploadedZip(string $fileName, array $entries): UploadedFile
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-merge-batch-zip-');
        $zipPath = $temporaryPath.'.zip';
        rename($temporaryPath, $zipPath);

        $archive = new ZipArchive;

        if ($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The ZIP test archive could not be created.');
        }

        foreach ($entries as $entryName => $contents) {
            $archive->addFromString($entryName, $contents);
        }

        $archive->close();

        return new UploadedFile(
            $zipPath,
            $fileName,
            'application/zip',
            null,
            true,
        );
    }
}
