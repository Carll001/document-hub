<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessDocMergeBatch;
use App\Models\BulkMergeFailure;
use App\Models\ConfirmationTemplate;
use App\Models\DocMergeBatch;
use App\Models\DocMergeBatchSourceFile;
use App\Models\MergedPdf;
use App\Models\User;
use App\Services\ConfirmationDocxService;
use App\Services\DocMergeBatchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocMergeBatchController extends Controller
{
    private const BATCHES_PER_PAGE = 9;

    private const RESULTS_PER_PAGE = 20;

    /**
     * Return the next page of saved batch folders.
     */
    public function batches(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(
            $this->batchPagePayload(
                $this->batchPage($request->user(), (int) ($validated['cursor'] ?? 1)),
            ),
        );
    }

    /**
     * Show the saved batch workspace.
     */
    public function show(
        Request $request,
        DocMergeBatch $docMergeBatch,
        ConfirmationDocxService $confirmationDocxService,
    ): Response {
        abort_unless($docMergeBatch->user->is($request->user()), 404);
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $docMergeBatch->loadCount(['mergedPdfs', 'bulkMergeFailures']);
        $requestedPage = (int) ($validated['page'] ?? 1);
        $resultsPage = $this->resultsPage($docMergeBatch, $requestedPage);

        if ($requestedPage > 1 && $requestedPage > $resultsPage->lastPage()) {
            $resultsPage = $this->resultsPage($docMergeBatch, $resultsPage->lastPage());
        }

        return Inertia::render('DocMergeBatch', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'batch' => [
                ...$this->transformBatch($docMergeBatch),
                ...$this->resultsPagePayload($resultsPage),
            ],
            'confirmationTemplate' => $this->transformConfirmationTemplate($confirmationDocxService),
        ]);
    }

    /**
     * Return the next page of merged results for one batch.
     */
    public function results(Request $request, DocMergeBatch $docMergeBatch): JsonResponse
    {
        abort_unless($docMergeBatch->user->is($request->user()), 404);

        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(
            $this->resultsPagePayload(
                $this->resultsPage($docMergeBatch, (int) ($validated['cursor'] ?? 1)),
            ),
        );
    }

    /**
     * Create a new saved batch and open its workspace.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ], [
            'name.required' => 'Enter a batch name.',
        ]);

        $normalizedName = $this->normalizeBatchName($validated['name']);

        if ($normalizedName === '') {
            throw ValidationException::withMessages([
                'name' => 'Enter a batch name.',
            ]);
        }

        $batch = DocMergeBatch::query()->create([
            'user_id' => $request->user()->id,
            'name' => $normalizedName,
        ]);

        return to_route('doc-merge.batches.show', ['docMergeBatch' => $batch])
            ->with('success', "Batch {$batch->name} created.");
    }

    /**
     * Upload page folders into a saved batch.
     */
    public function storePageFolders(
        Request $request,
        DocMergeBatch $docMergeBatch,
        DocMergeBatchService $docMergeBatchService,
    ): RedirectResponse {
        abort_unless($docMergeBatch->user->is($request->user()), 404);

        if ($docMergeBatch->isBusy()) {
            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('error', $this->busyBatchErrorMessage());
        }

        $validated = $request->validate([
            'outputPrefix' => ['nullable', 'string', 'max:120'],
            'pageFolders' => ['required', 'array', 'min:2'],
            'pageFolders.*.name' => ['required', 'string', 'max:120'],
            'pageFolders.*.number' => ['required', 'integer', 'min:1'],
            'pageFolders.*.hasNestedEntries' => ['nullable', 'boolean'],
            'pageFolders.*.hasInvalidFiles' => ['nullable', 'boolean'],
            'pageFolders.*.files' => ['required', 'array', 'min:1'],
            'pageFolders.*.files.*' => ['required', 'file', 'mimes:pdf'],
        ], [
            'pageFolders.required' => 'Add at least two page folders like PAGE 1 and PAGE 2.',
            'pageFolders.min' => 'Add at least two page folders like PAGE 1 and PAGE 2.',
            'pageFolders.*.files.min' => 'Each page folder must contain at least one direct PDF file.',
            'pageFolders.*.files.*.mimes' => 'Only PDF files can be uploaded into a batch.',
        ]);

        try {
            $docMergeBatchService->storePageFolders(
                $docMergeBatch,
                $validated['pageFolders'],
            );
            $this->queueBatchProcessing(
                $docMergeBatch,
                $validated['outputPrefix'] ?? null,
            );

            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('success', 'Batch processing queued. Results will refresh automatically.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'The page folders could not be uploaded right now.');
        }
    }

    /**
     * Upload a ZIP into a saved batch.
     */
    public function storeZip(
        Request $request,
        DocMergeBatch $docMergeBatch,
        DocMergeBatchService $docMergeBatchService,
    ): RedirectResponse {
        abort_unless($docMergeBatch->user->is($request->user()), 404);

        if ($docMergeBatch->isBusy()) {
            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('error', $this->busyBatchErrorMessage());
        }

        $validated = $request->validate([
            'outputPrefix' => ['nullable', 'string', 'max:120'],
            'zip' => ['required', 'file', 'mimes:zip'],
        ], [
            'zip.required' => 'Choose a ZIP file to import.',
            'zip.mimes' => 'Only ZIP files are supported for batch import.',
        ]);

        /** @var UploadedFile $zip */
        $zip = $validated['zip'];

        try {
            $docMergeBatchService->storeZip(
                $docMergeBatch,
                $zip,
            );
            $this->queueBatchProcessing(
                $docMergeBatch,
                $validated['outputPrefix'] ?? null,
            );

            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('success', 'Batch processing queued. Results will refresh automatically.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'The ZIP could not be imported right now.');
        }
    }

    /**
     * Remove one source file from a saved batch.
     */
    public function destroySourceFile(
        Request $request,
        DocMergeBatch $docMergeBatch,
        DocMergeBatchSourceFile $sourceFile,
        DocMergeBatchService $docMergeBatchService,
    ): RedirectResponse {
        abort_unless($docMergeBatch->user->is($request->user()), 404);
        abort_unless($sourceFile->doc_merge_batch_id === $docMergeBatch->id, 404);

        if ($docMergeBatch->isBusy()) {
            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('error', $this->busyBatchErrorMessage());
        }

        $displayName = $sourceFile->display_name;

        $docMergeBatchService->removeSourceFile($sourceFile);

        return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
            ->with('success', "Removed {$displayName} from the batch.");
    }

    /**
     * Remove one page folder from a saved batch.
     */
    public function destroyPageFolder(
        Request $request,
        DocMergeBatch $docMergeBatch,
        int $pageFolderNumber,
        DocMergeBatchService $docMergeBatchService,
    ): RedirectResponse {
        abort_unless($docMergeBatch->user->is($request->user()), 404);

        if ($docMergeBatch->isBusy()) {
            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('error', $this->busyBatchErrorMessage());
        }

        $pageFolderName = $docMergeBatch->sourceFiles()
            ->where('page_folder_number', $pageFolderNumber)
            ->value('page_folder_name');

        abort_unless(is_string($pageFolderName), 404);

        $docMergeBatchService->removePageFolder($docMergeBatch, $pageFolderNumber);

        return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
            ->with('success', "Removed {$pageFolderName} from the batch.");
    }

    /**
     * Run the saved batch merge.
     */
    public function process(
        Request $request,
        DocMergeBatch $docMergeBatch,
    ): RedirectResponse {
        abort_unless($docMergeBatch->user->is($request->user()), 404);

        if ($docMergeBatch->isBusy()) {
            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('error', $this->busyBatchErrorMessage());
        }

        try {
            $this->queueBatchProcessing($docMergeBatch, null);

            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('success', 'Batch processing queued. Results will refresh automatically.');
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('error', $exception->getMessage() !== '' ? $exception->getMessage() : 'The batch could not be processed right now.');
        }
    }

    /**
     * Download the batch ZIP.
     */
    public function download(
        Request $request,
        DocMergeBatch $docMergeBatch,
        DocMergeBatchService $docMergeBatchService,
    ): BinaryFileResponse {
        abort_unless($docMergeBatch->user->is($request->user()), 404);

        return $docMergeBatchService->downloadBatch($docMergeBatch);
    }

    /**
     * Delete a saved batch.
     */
    public function destroy(Request $request, DocMergeBatch $docMergeBatch): RedirectResponse
    {
        abort_unless($docMergeBatch->user->is($request->user()), 404);

        if ($docMergeBatch->isBusy()) {
            return to_route('doc-merge.batches.show', ['docMergeBatch' => $docMergeBatch])
                ->with('error', $this->busyBatchErrorMessage());
        }

        $batchName = $docMergeBatch->name;
        $docMergeBatch->delete();

        return to_route('doc-merge.index')
            ->with('success', "Deleted batch {$batchName}.");
    }

    private function batchPage(User $user, int $page): LengthAwarePaginator
    {
        return DocMergeBatch::query()
            ->whereBelongsTo($user)
            ->withCount(['mergedPdfs', 'bulkMergeFailures'])
            ->latest()
            ->paginate(self::BATCHES_PER_PAGE, ['*'], 'page', $page);
    }

    /**
     * @return array{
     *     batches: array<int, array<string, mixed>>,
     *     hasMoreBatches: bool,
     *     nextBatchesCursor: string|null
     * }
     */
    private function batchPagePayload(LengthAwarePaginator $batchPage): array
    {
        return [
            'batches' => collect($batchPage->items())
                ->map(fn (DocMergeBatch $batch): array => $this->transformBatch($batch))
                ->all(),
            'hasMoreBatches' => $batchPage->hasMorePages(),
            'nextBatchesCursor' => $batchPage->hasMorePages()
                ? (string) ($batchPage->currentPage() + 1)
                : null,
        ];
    }

    private function resultsPage(DocMergeBatch $batch, int $page): LengthAwarePaginator
    {
        $mergedRows = DB::table('merged_pdfs')
            ->where('doc_merge_batch_id', $batch->id)
            ->selectRaw("'merged_pdf' as record_type")
            ->addSelect(DB::raw('id as numeric_id'))
            ->addSelect('uuid', 'created_at');
        $failureRows = DB::table('bulk_merge_failures')
            ->where('doc_merge_batch_id', $batch->id)
            ->selectRaw("'merge_failure' as record_type")
            ->addSelect(DB::raw('id as numeric_id'))
            ->addSelect('uuid', 'created_at');

        return DB::query()
            ->fromSub($mergedRows->unionAll($failureRows), 'batch_results')
            ->orderByDesc('created_at')
            ->orderByDesc('numeric_id')
            ->paginate(self::RESULTS_PER_PAGE, ['*'], 'page', $page);
    }

    /**
     * @return array{
     *     results: array<int, array<string, mixed>>,
     *     resultsPagination: array{
     *         currentPage: int,
     *         lastPage: int
     *     }
     * }
     */
    private function resultsPagePayload(LengthAwarePaginator $resultsPage): array
    {
        $rows = collect($resultsPage->items());
        $mergedIds = $rows
            ->where('record_type', 'merged_pdf')
            ->pluck('numeric_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $failureIds = $rows
            ->where('record_type', 'merge_failure')
            ->pluck('numeric_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $mergedPdfs = MergedPdf::query()
            ->whereKey($mergedIds)
            ->get()
            ->keyBy('id');
        $bulkMergeFailures = BulkMergeFailure::query()
            ->whereKey($failureIds)
            ->get()
            ->keyBy('id');

        return [
            'results' => $rows
                ->map(function (object $row) use ($mergedPdfs, $bulkMergeFailures): ?array {
                    if ($row->record_type === 'merged_pdf') {
                        $mergedPdf = $mergedPdfs->get((int) $row->numeric_id);

                        return $mergedPdf instanceof MergedPdf
                            ? $this->transformMergedPdfResult($mergedPdf)
                            : null;
                    }

                    $failure = $bulkMergeFailures->get((int) $row->numeric_id);

                    return $failure instanceof BulkMergeFailure
                        ? $this->transformFailureResult($failure)
                        : null;
                })
                ->filter()
                ->values()
                ->all(),
            'resultsPagination' => [
                'currentPage' => $resultsPage->currentPage(),
                'lastPage' => $resultsPage->lastPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBatch(DocMergeBatch $batch): array
    {
        return [
            'id' => $batch->uuid,
            'name' => $batch->name,
            'mergedCount' => (int) ($batch->merged_pdfs_count ?? $batch->mergedPdfs()->count()),
            'failedCount' => (int) ($batch->bulk_merge_failures_count ?? $batch->bulkMergeFailures()->count()),
            'lastProcessedAt' => $batch->last_processed_at?->toIso8601String(),
            'processingStatus' => $batch->processing_status,
            'processingError' => $batch->processing_error,
            'showUrl' => route('doc-merge.batches.show', ['docMergeBatch' => $batch]),
            'downloadUrl' => route('doc-merge.batches.download', ['docMergeBatch' => $batch]),
            'deleteUrl' => route('doc-merge.batches.destroy', ['docMergeBatch' => $batch]),
            'uploadPageFoldersUrl' => route('doc-merge.batches.page-folders.store', ['docMergeBatch' => $batch]),
            'uploadZipUrl' => route('doc-merge.batches.zip.store', ['docMergeBatch' => $batch]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformMergedPdfResult(MergedPdf $mergedPdf): array
    {
        return [
            'recordType' => 'merged_pdf',
            'id' => $mergedPdf->uuid,
            'fileName' => $mergedPdf->file_name,
            'fileSize' => $mergedPdf->file_size,
            'sourceCount' => $mergedPdf->source_count,
            'sourceFileNames' => $mergedPdf->source_file_names,
            'tinNumber' => $mergedPdf->tin_number,
            'footerText' => $mergedPdf->footer_text,
            'hasReceipt' => filled($mergedPdf->receipt_storage_path),
            'receiptFileName' => $mergedPdf->receipt_file_name,
            'receiptFileSize' => $mergedPdf->receipt_file_size,
            'receiptJobStatus' => $mergedPdf->receipt_job_status,
            'receiptJobError' => $mergedPdf->receipt_job_error,
            'createdAt' => $mergedPdf->created_at?->toIso8601String(),
            'downloadUrl' => route('doc-merge.download', ['mergedPdf' => $mergedPdf]),
            'previewUrl' => route('doc-merge.preview', [
                'mergedPdf' => $mergedPdf,
                'v' => $this->previewVersion($mergedPdf),
            ]),
            'receiptStoreUrl' => route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]),
            'receiptRemoveUrl' => filled($mergedPdf->receipt_storage_path)
                ? route('doc-merge.receipt.destroy', ['mergedPdf' => $mergedPdf])
                : null,
            'sendEmailUrl' => route('doc-merge.send-email', ['mergedPdf' => $mergedPdf]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformFailureResult(BulkMergeFailure $failure): array
    {
        return [
            'recordType' => 'merge_failure',
            'id' => $failure->uuid,
            'fileName' => $failure->output_file_name,
            'fileSize' => null,
            'sourceCount' => null,
            'sourceFileNames' => [],
            'tinNumber' => null,
            'footerText' => null,
            'hasReceipt' => false,
            'receiptFileName' => null,
            'receiptFileSize' => null,
            'createdAt' => $failure->created_at?->toIso8601String(),
            'downloadUrl' => null,
            'previewUrl' => null,
            'receiptStoreUrl' => null,
            'receiptRemoveUrl' => null,
            'sendEmailUrl' => null,
            'inputMode' => $failure->input_mode,
            'inputLabel' => $failure->input_label,
            'groupLabel' => $failure->group_label,
            'errorMessage' => $failure->error_message,
        ];
    }

    private function normalizeBatchName(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->replaceMatches('/\s+/u', ' ')
            ->value();
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

    /**
     * Build frontend metadata for the shared confirmation template.
     *
     * @return array{
     *     hasTemplate: bool,
     *     fileName: ?string,
     *     fileSize: ?int,
     *     placeholders: list<string>,
     *     downloadUrl: ?string
     * }
     */
    private function transformConfirmationTemplate(ConfirmationDocxService $confirmationDocxService): array
    {
        $template = ConfirmationTemplate::shared();
        $storagePath = $template?->storage_path;
        $disk = Storage::disk('local');

        if (! filled($storagePath) || ! $disk->exists($storagePath)) {
            return [
                'hasTemplate' => false,
                'fileName' => null,
                'fileSize' => null,
                'placeholders' => [],
                'downloadUrl' => null,
            ];
        }

        try {
            $placeholders = $confirmationDocxService->extractPlaceholders(
                $disk->path($storagePath),
            );
        } catch (\Throwable $exception) {
            report($exception);
            $placeholders = [];
        }

        return [
            'hasTemplate' => true,
            'fileName' => $template?->file_name,
            'fileSize' => $template?->file_size,
            'placeholders' => $placeholders,
            'downloadUrl' => route('doc-merge.confirmation-template.download'),
        ];
    }

    private function bulkMergeSummaryMessage(int $mergedCount, int $failedCount): string
    {
        return sprintf(
            '%d %s merged, %d %s failed.',
            $mergedCount,
            $mergedCount === 1 ? 'PDF' : 'PDFs',
            $failedCount,
            $failedCount === 1 ? 'PDF' : 'PDFs',
        );
    }

    private function queueBatchProcessing(
        DocMergeBatch $docMergeBatch,
        ?string $outputPrefix,
    ): void {
        $docMergeBatch->forceFill([
            'processing_status' => DocMergeBatch::PROCESSING_STATUS_QUEUED,
            'processing_error' => null,
        ])->save();

        try {
            ProcessDocMergeBatch::dispatch(
                $docMergeBatch->getKey(),
                $outputPrefix,
            )->afterCommit();
        } catch (\Throwable $exception) {
            $docMergeBatch->forceFill([
                'processing_status' => null,
                'processing_error' => null,
            ])->save();

            throw $exception;
        }
    }

    private function busyBatchErrorMessage(): string
    {
        return 'This batch is already queued or processing. Wait for it to finish before making more changes.';
    }
}
