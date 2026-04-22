<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateMergedPdfReceipt;
use App\Mail\MergedPdfEmail;
use App\Models\BulkMergeFailure;
use App\Models\ConfirmationTemplate;
use App\Models\DocMergeBatch;
use App\Models\MergedPdf;
use App\Models\User;
use App\Services\BulkZipMergeService;
use App\Services\ConfirmationDocxService;
use App\Services\PdfMergeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocMergeController extends Controller
{
    /**
     * Show the document merge page.
     */
    public function index(
        Request $request,
        ConfirmationDocxService $confirmationDocxService,
    ): Response {
        $user = $request->user();
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $requestedPage = (int) ($validated['page'] ?? 1);
        $batchPage = $this->batchPage($user, $requestedPage);

        if ($requestedPage > 1 && $requestedPage > $batchPage->lastPage()) {
            $batchPage = $this->batchPage($user, $batchPage->lastPage());
        }

        return Inertia::render('DocMerge', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'confirmationTemplate' => $this->transformConfirmationTemplate($confirmationDocxService),
            'batchCreateUrl' => route('doc-merge.batches.store'),
            ...$this->batchPagePayload($batchPage),
        ]);
    }

    /**
     * Merge the selected PDF sources and save the result.
     */
    public function store(Request $request, PdfMergeService $service): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'sources' => ['required', 'array', 'min:2'],
            'sources.*.type' => ['required', 'string', Rule::in(['upload', 'merged_pdf'])],
            'sources.*.id' => ['nullable', 'integer'],
            'files' => ['nullable', 'array'],
            'files.*' => ['required', 'file', 'mimes:pdf'],
            'outputName' => ['nullable', 'string', 'max:120'],
            'footerText' => ['nullable', 'string', 'max:160'],
        ], [
            'sources.required' => 'Select at least two PDF sources to merge.',
            'sources.min' => 'Select at least two PDF sources to merge.',
            'files.*.mimes' => 'Only PDF files can be merged right now.',
            'footerText.max' => 'Footer text must be 160 characters or fewer.',
        ])->validate();
        $mergedPdf = null;

        try {
            $mergedPdf = $service->merge(
                $request->user(),
                $this->resolveMergeSources(
                    $request->user(),
                    $validated,
                    $this->resolveUploadedFiles($request),
                ),
                $validated['outputName'] ?? null,
                $this->normalizeFooterText($validated['footerText'] ?? null),
            );

            return to_route('doc-merge.index')
                ->with('success', "Merged PDF saved as {$mergedPdf->file_name}.");
        } catch (ValidationException $exception) {
            if ($mergedPdf instanceof MergedPdf) {
                $mergedPdf->delete();
            }

            throw $exception;
        } catch (RuntimeException $exception) {
            if ($mergedPdf instanceof MergedPdf) {
                $mergedPdf->delete();
            }

            report($exception);

            return to_route('doc-merge.index')
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            if ($mergedPdf instanceof MergedPdf) {
                $mergedPdf->delete();
            }

            report($exception);

            return to_route('doc-merge.index')
                ->with('error', 'The PDF merge failed. Try again with standard, unlocked PDF files.');
        }
    }

    /**
     * Merge a ZIP archive into many saved PDFs while recording per-folder failures.
     */
    public function storeBulk(
        Request $request,
        BulkZipMergeService $service,
    ): RedirectResponse {
        $validated = $request->validate([
            'zip' => ['required', 'file', 'mimes:zip'],
            'outputPrefix' => ['nullable', 'string', 'max:120'],
            'footerText' => ['nullable', 'string', 'max:160'],
        ], [
            'zip.required' => 'Choose a ZIP file to bulk merge.',
            'zip.mimes' => 'Only ZIP files are supported for bulk merge.',
            'footerText.max' => 'Footer text must be 160 characters or fewer.',
        ]);

        /** @var UploadedFile $zip */
        $zip = $validated['zip'];

        try {
            $result = $service->processZip(
                $request->user(),
                $zip,
                $validated['outputPrefix'] ?? null,
                $this->normalizeFooterText($validated['footerText'] ?? null),
            );

            return to_route('doc-merge.index')->with(
                'success',
                $this->bulkMergeSummaryMessage(
                    $result['mergedCount'],
                    $result['failedCount'],
                ),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('doc-merge.index')
                ->with('error', 'The bulk ZIP merge failed. Please try again.');
        }
    }

    /**
     * Merge uploaded page folders into many saved PDFs.
     */
    public function storeBulkFolders(
        Request $request,
        BulkZipMergeService $service,
    ): RedirectResponse {
        $validated = $request->validate([
            'outputPrefix' => ['nullable', 'string', 'max:120'],
            'footerText' => ['nullable', 'string', 'max:160'],
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
            'pageFolders.*.files.*.mimes' => 'Only PDF files can be merged right now.',
            'footerText.max' => 'Footer text must be 160 characters or fewer.',
        ]);

        try {
            $result = $service->processPageFolders(
                $request->user(),
                $validated['pageFolders'],
                $validated['outputPrefix'] ?? null,
                $this->normalizeFooterText($validated['footerText'] ?? null),
            );

            return to_route('doc-merge.index')->with(
                'success',
                $this->bulkMergeSummaryMessage(
                    $result['mergedCount'],
                    $result['failedCount'],
                ),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('doc-merge.index')
                ->with('error', 'The bulk folder merge failed. Please try again.');
        }
    }

    /**
     * Delete one or more saved merged PDFs that belong to the current user.
     */
    public function destroyMany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'string', Rule::in(['merged_pdf', 'merge_failure'])],
            'items.*.id' => ['required', 'string', 'max:36'],
        ], [
            'items.required' => 'Select at least one merge result to delete.',
            'items.min' => 'Select at least one merge result to delete.',
        ]);

        $requestedItems = collect($validated['items'])
            ->map(fn (array $item): array => [
                'type' => (string) $item['type'],
                'id' => (string) $item['id'],
            ])
            ->unique(fn (array $item): string => "{$item['type']}:{$item['id']}")
            ->values();
        $mergedPdfIds = $requestedItems
            ->where('type', 'merged_pdf')
            ->pluck('id')
            ->all();
        $failureIds = $requestedItems
            ->where('type', 'merge_failure')
            ->pluck('id')
            ->all();

        /** @var Collection<int, MergedPdf> $mergedPdfs */
        $mergedPdfs = MergedPdf::query()
            ->whereBelongsTo($request->user())
            ->whereIn('uuid', $mergedPdfIds)
            ->get();
        /** @var Collection<int, BulkMergeFailure> $bulkMergeFailures */
        $bulkMergeFailures = BulkMergeFailure::query()
            ->whereBelongsTo($request->user())
            ->whereIn('uuid', $failureIds)
            ->get();

        if (
            $mergedPdfs->count() + $bulkMergeFailures->count()
            !== $requestedItems->count()
        ) {
            return to_route('doc-merge.index')
                ->with('error', 'One or more selected merge results could not be deleted.');
        }

        $busyBatch = $this->firstBusyBatch($mergedPdfs, $bulkMergeFailures);

        if ($busyBatch instanceof DocMergeBatch) {
            return $this->redirectToDeletedItemsContext($mergedPdfs, $bulkMergeFailures)
                ->with('error', $this->busyBatchErrorMessage());
        }

        try {
            $deletedCount = $mergedPdfs->count() + $bulkMergeFailures->count();
            $deletedLabel = $deletedCount === 1
                ? $this->singleDeleteLabel(
                    $mergedPdfs->first(),
                    $bulkMergeFailures->first(),
                )
                : null;

            $mergedPdfs->each->delete();
            $bulkMergeFailures->each->delete();

            $message = $deletedCount === 1 && $deletedLabel !== null
                ? "Deleted {$deletedLabel}."
                : "Deleted {$deletedCount} merge results.";

            return $this->redirectToDeletedItemsContext($mergedPdfs, $bulkMergeFailures)
                ->with('success', $message);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->redirectToDeletedItemsContext($mergedPdfs, $bulkMergeFailures)
                ->with('error', 'The selected merge results could not be deleted right now. Please try again.');
        }
    }

    /**
     * Store or replace the shared confirmation DOCX template.
     */
    public function storeConfirmationTemplate(
        Request $request,
        ConfirmationDocxService $confirmationDocxService,
    ): RedirectResponse {
        $validated = $request->validate([
            'template' => ['required', 'file', 'mimes:docx', 'max:10240'],
        ], [
            'template.required' => 'Choose a DOCX file to use as the receipt template.',
            'template.mimes' => 'Only DOCX files can be used as receipt templates.',
            'template.max' => 'Receipt templates must be 10 MB or smaller.',
        ]);

        /** @var UploadedFile $template */
        $template = $validated['template'];
        $user = $request->user();
        $disk = Storage::disk('s3');
        $sharedTemplate = ConfirmationTemplate::query()
            ->firstOrNew(['key' => ConfirmationTemplate::SHARED_KEY]);
        $safeFileName = $this->safeDocxFilename(
            $template->getClientOriginalName(),
            'receipt-template',
        );
        $storagePath = $this->confirmationTemplateStoragePath($safeFileName);
        $previousStoragePath = $sharedTemplate->storage_path;

        try {
            $templateRealPath = $template->getRealPath();

            if ($templateRealPath === false || ! is_file($templateRealPath)) {
                throw new RuntimeException('The uploaded receipt template is no longer available.');
            }

            $confirmationDocxService->extractPlaceholders($templateRealPath);

            $stored = $disk->putFileAs(
                dirname($storagePath),
                $template,
                basename($storagePath),
            );

            if ($stored === false || ! $disk->exists($storagePath)) {
                throw new RuntimeException('The receipt template could not be stored.');
            }

            $sharedTemplate->forceFill([
                'file_name' => $template->getClientOriginalName(),
                'storage_path' => $storagePath,
                'file_size' => $disk->size($storagePath),
                'uploaded_by_user_id' => $user->id,
            ])->save();

            if (
                filled($previousStoragePath)
                && $previousStoragePath !== $storagePath
                && $disk->exists($previousStoragePath)
            ) {
                $disk->delete($previousStoragePath);
            }

            return to_route('doc-merge.index')
                ->with('success', 'Shared receipt template saved.');
        } catch (RuntimeException $exception) {
            if ($disk->exists($storagePath)) {
                $disk->delete($storagePath);
            }

            report($exception);

            return to_route('doc-merge.index')
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            if ($disk->exists($storagePath)) {
                $disk->delete($storagePath);
            }

            report($exception);

            return to_route('doc-merge.index')
                ->with('error', 'The receipt template could not be uploaded right now. Please try again.');
        }
    }

    /**
     * Download the shared confirmation template.
     */
    public function downloadConfirmationTemplate(Request $request): StreamedResponse
    {
        $disk = Storage::disk('s3');
        $template = ConfirmationTemplate::shared();

        abort_unless(
            $template instanceof ConfirmationTemplate
                && filled($template->storage_path)
                && filled($template->file_name)
                && $disk->exists($template->storage_path),
            404,
        );

        return $disk->download(
            $template->storage_path,
            $template->file_name,
        );
    }

    /**
     * Download a saved merged PDF.
     */
    public function download(Request $request, MergedPdf $mergedPdf): StreamedResponse
    {
        abort_unless($mergedPdf->user->is($request->user()), 404);

        return Storage::disk('s3')->download(
            $mergedPdf->storage_path,
            $mergedPdf->file_name,
        );
    }

    /**
     * Stream a saved merged PDF inline for previewing.
     */
    public function preview(Request $request, MergedPdf $mergedPdf): StreamedResponse
    {
        abort_unless($mergedPdf->user->is($request->user()), 404);

        return Storage::disk('s3')->response(
            $mergedPdf->storage_path,
            $mergedPdf->file_name,
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Store or replace a receipt file for a saved merged PDF.
     */
    public function storeReceipt(
        Request $request,
        MergedPdf $mergedPdf,
        ConfirmationDocxService $confirmationDocxService,
    ): RedirectResponse {
        abort_unless($mergedPdf->user->is($request->user()), 404);

        if ($this->mergedPdfBatchIsBusy($mergedPdf)) {
            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', $this->busyBatchErrorMessage());
        }

        if ($mergedPdf->receiptJobIsBusy()) {
            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', "A receipt update is already queued for {$mergedPdf->file_name}.");
        }

        $templateStoragePath = $this->storedConfirmationTemplateStoragePath();
        $templatePath = $this->storedConfirmationTemplatePath();

        if ($templateStoragePath === null || $templatePath === null) {
            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', 'Upload the shared receipt template before generating a receipt.');
        }

        try {
            $placeholders = $confirmationDocxService->extractPlaceholders($templatePath);
        } catch (RuntimeException $exception) {
            report($exception);

            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', $exception->getMessage());
        }

        $validator = Validator::make($request->all(), [
            'placeholders' => ['nullable', 'array'],
        ]);

        $validator->after(function ($validator) use ($placeholders, $request): void {
            $submittedPlaceholders = $request->input('placeholders', []);

            if ($placeholders === []) {
                return;
            }

            if (! is_array($submittedPlaceholders)) {
                $validator->errors()->add(
                    'placeholders',
                    'Receipt placeholder values must be sent as a field map.',
                );

                return;
            }

            foreach ($placeholders as $placeholder) {
                if (! array_key_exists($placeholder, $submittedPlaceholders)) {
                    $validator->errors()->add(
                        "placeholders.{$placeholder}",
                        "Provide a value for {{$placeholder}}.",
                    );

                    continue;
                }

                $value = $submittedPlaceholders[$placeholder];

                if (! is_scalar($value) && $value !== null) {
                    $validator->errors()->add(
                        "placeholders.{$placeholder}",
                        "{{$placeholder}} must be plain text.",
                    );

                    continue;
                }

                if (mb_strlen((string) $value) > 500) {
                    $validator->errors()->add(
                        "placeholders.{$placeholder}",
                        "{{$placeholder}} must be 500 characters or fewer.",
                    );
                }
            }
        });

        $validated = $validator->validate();

        try {
            $mergedPdf->forceFill([
                'receipt_job_status' => MergedPdf::RECEIPT_JOB_STATUS_QUEUED,
                'receipt_job_error' => null,
            ])->save();

            GenerateMergedPdfReceipt::dispatch(
                $mergedPdf->getKey(),
                $templateStoragePath,
                $placeholders,
                is_array($validated['placeholders'] ?? null)
                    ? $validated['placeholders']
                    : [],
            )->afterCommit();

            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('success', "Receipt queued for {$mergedPdf->file_name}.");
        } catch (\Throwable $exception) {
            $mergedPdf->forceFill([
                'receipt_job_status' => null,
                'receipt_job_error' => null,
            ])->save();

            report($exception);

            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', 'The receipt could not be queued right now. Please try again.');
        }
    }

    /**
     * Download a saved receipt file for a merged PDF.
     */
    public function downloadReceipt(Request $request, MergedPdf $mergedPdf): StreamedResponse
    {
        abort_unless($mergedPdf->user->is($request->user()), 404);
        abort_unless(
            filled($mergedPdf->receipt_storage_path)
                && filled($mergedPdf->receipt_file_name)
                && Storage::disk('s3')->exists($mergedPdf->receipt_storage_path),
            404,
        );

        return Storage::disk('s3')->download(
            $mergedPdf->receipt_storage_path,
            $mergedPdf->receipt_file_name,
        );
    }

    /**
     * Remove the receipt from a saved merged PDF and restore the original file.
     */
    public function destroyReceipt(
        Request $request,
        MergedPdf $mergedPdf,
        PdfMergeService $service,
    ): RedirectResponse {
        abort_unless($mergedPdf->user->is($request->user()), 404);

        if ($this->mergedPdfBatchIsBusy($mergedPdf)) {
            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', $this->busyBatchErrorMessage());
        }

        if ($mergedPdf->receiptJobIsBusy()) {
            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', "A receipt update is already queued for {$mergedPdf->file_name}.");
        }

        if (! filled($mergedPdf->receipt_storage_path) || ! filled($mergedPdf->receipt_file_name)) {
            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', "There is no receipt attached to {$mergedPdf->file_name}.");
        }

        $disk = Storage::disk('s3');
        $previousReceiptPath = $mergedPdf->receipt_storage_path;

        try {
            $service->removeReceipt($mergedPdf);

            $sourceFileNames = $this->sourceFileNamesWithoutReceipt(
                $mergedPdf->source_file_names,
                $mergedPdf->receipt_file_name,
            );

            $mergedPdf->forceFill([
                'file_size' => $disk->size($mergedPdf->storage_path),
                'source_count' => count($sourceFileNames),
                'source_file_names' => $sourceFileNames,
                'receipt_file_name' => null,
                'receipt_storage_path' => null,
                'receipt_file_size' => null,
            ])->save();

            if (filled($previousReceiptPath) && $disk->exists($previousReceiptPath)) {
                $disk->delete($previousReceiptPath);
            }

            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('success', "Receipt removed from {$mergedPdf->file_name}.");
        } catch (\Throwable $exception) {
            report($exception);

            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', 'The receipt could not be removed right now. Please try again.');
        }
    }

    /**
     * Email a saved merged PDF to a recipient.
     */
    public function sendEmail(Request $request, MergedPdf $mergedPdf): RedirectResponse
    {
        abort_unless($mergedPdf->user->is($request->user()), 404);

        if ($this->mergedPdfBatchIsBusy($mergedPdf)) {
            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', $this->busyBatchErrorMessage());
        }

        $validated = $request->validate([
            'recipientEmail' => ['required', 'email', 'max:254'],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $recipientEmail = trim((string) $validated['recipientEmail']);
        $subject = $this->normalizeOptionalText($validated['subject'] ?? null);
        $message = $this->normalizeOptionalText($validated['message'] ?? null);
        $disk = Storage::disk('s3');

        if (! $disk->exists($mergedPdf->storage_path)) {
            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', "The saved PDF {$mergedPdf->file_name} is no longer available.");
        }

        try {
            Mail::to($recipientEmail)->queue(
                (new MergedPdfEmail($mergedPdf, $subject, $message))
                    ->afterCommit(),
            );

            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('success', "Email queued to {$recipientEmail}.");
        } catch (\Throwable $exception) {
            report($exception);

            return $this->redirectToMergedPdfContext($mergedPdf)
                ->with('error', 'The email could not be queued right now. Please verify your mail settings and try again.');
        }
    }

    /**
     * Convert merged PDF and failed bulk-merge records into one frontend feed.
     *
     * @param  Collection<int, MergedPdf>  $mergedPdfs
     * @param  Collection<int, BulkMergeFailure>  $bulkMergeFailures
     * @return array<int, array<string, mixed>>
     */
    private function transformMergeHistory(
        Collection $mergedPdfs,
        Collection $bulkMergeFailures,
    ): array {
        $mergedHistory = $mergedPdfs->map(fn (MergedPdf $mergedPdf): array => [
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
            'receiptDownloadUrl' => filled($mergedPdf->receipt_storage_path)
                ? route('doc-merge.receipt.download', ['mergedPdf' => $mergedPdf])
                : null,
            'sendEmailUrl' => route('doc-merge.send-email', ['mergedPdf' => $mergedPdf]),
            'batchName' => $mergedPdf->docMergeBatch?->name,
            'batchShowUrl' => $mergedPdf->docMergeBatch instanceof DocMergeBatch
                ? route('doc-merge.batches.show', ['docMergeBatch' => $mergedPdf->docMergeBatch])
                : null,
            'inputMode' => null,
            'inputLabel' => null,
            'groupLabel' => null,
            'errorMessage' => null,
            'sortCreatedAt' => sprintf(
                '%013d-%010d',
                $mergedPdf->created_at?->valueOf() ?? 0,
                $mergedPdf->getKey(),
            ),
        ]);
        $failureHistory = $bulkMergeFailures->map(fn (BulkMergeFailure $failure): array => [
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
            'receiptDownloadUrl' => null,
            'sendEmailUrl' => null,
            'batchName' => $failure->docMergeBatch?->name,
            'batchShowUrl' => $failure->docMergeBatch instanceof DocMergeBatch
                ? route('doc-merge.batches.show', ['docMergeBatch' => $failure->docMergeBatch])
                : null,
            'inputMode' => $failure->input_mode,
            'inputLabel' => $failure->input_label,
            'groupLabel' => $failure->group_label,
            'errorMessage' => $failure->error_message,
            'sortCreatedAt' => sprintf(
                '%013d-%010d',
                $failure->created_at?->valueOf() ?? 0,
                $failure->getKey(),
            ),
        ]);

        return $mergedHistory
            ->concat($failureHistory)
            ->sortByDesc('sortCreatedAt')
            ->values()
            ->map(function (array $item): array {
                unset($item['sortCreatedAt']);

                return $item;
            })
            ->all();
    }

    /**
     * Convert saved batches into one frontend summary list.
     *
     * @return array{
     *     batches: array<int, array<string, mixed>>,
     *     batchPagination: array{
     *         currentPage: int,
     *         lastPage: int
     *     }
     * }
     */
    private function batchPagePayload(LengthAwarePaginator $batchPage): array
    {
        return [
            'batches' => collect($batchPage->items())
                ->map(fn (DocMergeBatch $batch): array => [
                    'id' => $batch->uuid,
                    'name' => $batch->name,
                    'mergedCount' => (int) ($batch->merged_pdfs_count ?? 0),
                    'failedCount' => (int) ($batch->bulk_merge_failures_count ?? 0),
                    'lastProcessedAt' => $batch->last_processed_at?->toIso8601String(),
                    'processingStatus' => $batch->processing_status,
                    'processingError' => $batch->processing_error,
                    'showUrl' => route('doc-merge.batches.show', ['docMergeBatch' => $batch]),
                    'downloadUrl' => route('doc-merge.batches.download', ['docMergeBatch' => $batch]),
                ])
                ->all(),
            'batchPagination' => [
                'currentPage' => $batchPage->currentPage(),
                'lastPage' => $batchPage->lastPage(),
            ],
        ];
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
        $disk = Storage::disk('s3');

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

    /**
     * Normalize the requested merge queue into on-disk PDF sources.
     *
     * @param  array{
     *     sources: list<array{type: string, id?: int|string|null}>,
     *     outputName?: string|null
     * }  $validated
     * @param  list<UploadedFile>  $uploadedFiles
     * @return list<array{path: string, displayName: string}>
     */
    private function resolveMergeSources(User $user, array $validated, array $uploadedFiles): array
    {
        $resolvedSources = [];
        $uploadIndex = 0;
        $errors = [];
        $disk = Storage::disk('s3');

        foreach ($validated['sources'] as $index => $source) {
            $sourceType = $source['type'];

            if ($sourceType === 'upload') {
                $file = $uploadedFiles[$uploadIndex] ?? null;

                if (! $file instanceof UploadedFile) {
                    $errors['files'] = 'The uploaded PDF files did not match the selected merge order.';

                    continue;
                }

                $path = $file->getRealPath();

                if ($path === false || ! is_file($path)) {
                    throw new RuntimeException('One of the uploaded PDF files is no longer available.');
                }

                $resolvedSources[] = [
                    'path' => $path,
                    'displayName' => $file->getClientOriginalName(),
                ];

                $uploadIndex++;

                continue;
            }

            if (! array_key_exists('id', $source) || $source['id'] === null) {
                $errors["sources.{$index}.id"] = 'Choose a saved PDF source before merging.';

                continue;
            }

            $mergedPdf = MergedPdf::query()
                ->whereBelongsTo($user)
                ->find((int) $source['id']);

            if ($mergedPdf === null) {
                $errors["sources.{$index}.id"] = 'Choose a saved merged PDF that belongs to your account.';

                continue;
            }

            if (! $disk->exists($mergedPdf->storage_path)) {
                throw new RuntimeException("The saved PDF {$mergedPdf->file_name} is no longer available.");
            }

            $resolvedSources[] = [
                'path' => $disk->path($mergedPdf->storage_path),
                'displayName' => $mergedPdf->file_name,
            ];
        }

        if ($uploadIndex !== count($uploadedFiles)) {
            $errors['files'] = 'The uploaded PDF files did not match the selected merge order.';
        }

        if (count($resolvedSources) < 2) {
            $errors['sources'] = 'Select at least two PDF sources to merge.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolvedSources;
    }

    /**
     * Return the upload files from the request as a flat list.
     *
     * @return list<UploadedFile>
     */
    private function resolveUploadedFiles(Request $request): array
    {
        $files = $request->file('files', []);

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        return array_values(
            array_filter(
                is_array($files) ? $files : [],
                fn ($file): bool => $file instanceof UploadedFile,
            ),
        );
    }

    /**
     * Normalize optional text input while treating blank strings as null.
     */
    private function normalizeOptionalText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Build a storage-safe PDF filename.
     */
    private function safePdfFilename(string $fileName, string $fallbackBaseName): string
    {
        $extension = Str::of(pathinfo($fileName, PATHINFO_EXTENSION))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();

        $baseName = Str::of(pathinfo($fileName, PATHINFO_FILENAME))
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-._')
            ->value();

        if ($baseName === '') {
            $baseName = $fallbackBaseName;
        }

        if ($extension !== 'pdf') {
            $extension = 'pdf';
        }

        return "{$baseName}.{$extension}";
    }

    /**
     * Build a storage-safe DOCX filename.
     */
    private function safeDocxFilename(string $fileName, string $fallbackBaseName): string
    {
        $extension = Str::of(pathinfo($fileName, PATHINFO_EXTENSION))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();

        $baseName = Str::of(pathinfo($fileName, PATHINFO_FILENAME))
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-._')
            ->value();

        if ($baseName === '') {
            $baseName = $fallbackBaseName;
        }

        if ($extension !== 'docx') {
            $extension = 'docx';
        }

        return "{$baseName}.{$extension}";
    }

    /**
     * Build the stored shared confirmation template path.
     */
    private function confirmationTemplateStoragePath(string $safeFileName): string
    {
        return sprintf(
            'doc-merge/shared/confirmation-template/%s-%s',
            Str::uuid(),
            $safeFileName,
        );
    }

    /**
     * Build the stored generated receipt PDF path for a merged PDF.
     */
    private function receiptStoragePath(MergedPdf $mergedPdf, string $safeFileName): string
    {
        return sprintf(
            'doc-merge/%d/receipts/%d/%s-%s',
            $mergedPdf->user_id,
            $mergedPdf->id,
            Str::uuid(),
            $safeFileName,
        );
    }

    /**
     * Return the on-disk path for the shared confirmation template.
     */
    private function storedConfirmationTemplatePath(): ?string
    {
        $storagePath = ConfirmationTemplate::shared()?->storage_path;
        $disk = Storage::disk('s3');

        if (! filled($storagePath) || ! $disk->exists($storagePath)) {
            return null;
        }

        return $disk->path($storagePath);
    }

    /**
     * Return the stored shared confirmation template path relative to the local disk.
     */
    private function storedConfirmationTemplateStoragePath(): ?string
    {
        $storagePath = ConfirmationTemplate::shared()?->storage_path;
        $disk = Storage::disk('s3');

        if (! filled($storagePath) || ! $disk->exists($storagePath)) {
            return null;
        }

        return $storagePath;
    }

    /**
     * Build the generated receipt filename shown in merge history.
     */
    private function normalizedReceiptOutputName(MergedPdf $mergedPdf): string
    {
        return Str::of($mergedPdf->file_name)
            ->beforeLast('.')
            ->append('-receipt.pdf')
            ->value();
    }

    /**
     * Resolve template placeholder values in template order.
     *
     * @param  list<string>  $placeholders
     * @return array<string, string>
     */
    private function resolveTemplatePlaceholderValues(
        mixed $submittedValues,
        array $placeholders,
    ): array {
        $submittedValues = is_array($submittedValues) ? $submittedValues : [];
        $resolvedValues = [];

        foreach ($placeholders as $placeholder) {
            $resolvedValues[$placeholder] = (string) ($submittedValues[$placeholder] ?? '');
        }

        return $resolvedValues;
    }

    private function storeFileFromPath(
        FilesystemAdapter $disk,
        string $storagePath,
        string $sourcePath,
        string $errorMessage,
    ): void {
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException($errorMessage);
        }

        try {
            $stored = $disk->put($storagePath, $stream);
        } finally {
            fclose($stream);
        }

        if ($stored !== true || ! $disk->exists($storagePath)) {
            throw new RuntimeException($errorMessage);
        }
    }

    /**
     * Update the visible source list so the receipt appears as the final entry.
     *
     * @return list<string>
     */
    private function sourceFileNamesWithReceipt(
        mixed $sourceFileNames,
        ?string $previousReceiptFileName,
        string $nextReceiptFileName,
    ): array {
        $names = array_values(
            array_filter(
                is_array($sourceFileNames) ? $sourceFileNames : [],
                fn ($name): bool => is_string($name) && trim($name) !== '',
            ),
        );

        if ($previousReceiptFileName !== null) {
            $previousReceiptLabel = $this->receiptSourceLabel($previousReceiptFileName);

            $names = array_values(
                array_filter(
                    $names,
                    fn (string $name): bool => $name !== $previousReceiptLabel,
                ),
            );
        }

        $names[] = $this->receiptSourceLabel($nextReceiptFileName);

        return $names;
    }

    /**
     * Remove the receipt label from the stored source summary.
     *
     * @return list<string>
     */
    private function sourceFileNamesWithoutReceipt(
        mixed $sourceFileNames,
        ?string $receiptFileName,
    ): array {
        $names = array_values(
            array_filter(
                is_array($sourceFileNames) ? $sourceFileNames : [],
                fn ($name): bool => is_string($name) && trim($name) !== '',
            ),
        );

        if ($receiptFileName === null) {
            return $names;
        }

        $receiptLabel = $this->receiptSourceLabel($receiptFileName);

        return array_values(
            array_filter(
                $names,
                fn (string $name): bool => $name !== $receiptLabel,
            ),
        );
    }

    /**
     * Format a receipt file name for the merge-source summary.
     */
    private function receiptSourceLabel(string $fileName): string
    {
        return "Receipt: {$fileName}";
    }

    /**
     * Build a cache-busting preview version whenever the merged PDF content changes.
     */
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

    private function batchPage(User $user, int $page): LengthAwarePaginator
    {
        return DocMergeBatch::query()
            ->whereBelongsTo($user)
            ->withCount(['mergedPdfs', 'bulkMergeFailures'])
            ->latest()
            ->paginate(9, ['*'], 'page', $page);
    }

    private function redirectToMergedPdfContext(MergedPdf $mergedPdf): RedirectResponse
    {
        $batch = $mergedPdf->docMergeBatch;

        if ($batch instanceof DocMergeBatch) {
            return to_route('doc-merge.batches.show', ['docMergeBatch' => $batch]);
        }

        return to_route('doc-merge.index');
    }

    private function mergedPdfBatchIsBusy(MergedPdf $mergedPdf): bool
    {
        $batch = $mergedPdf->docMergeBatch;

        return $batch instanceof DocMergeBatch && $batch->isBusy();
    }

    /**
     * @param  Collection<int, MergedPdf>  $mergedPdfs
     * @param  Collection<int, BulkMergeFailure>  $bulkMergeFailures
     */
    private function redirectToDeletedItemsContext(
        Collection $mergedPdfs,
        Collection $bulkMergeFailures,
    ): RedirectResponse {
        $batchIds = $mergedPdfs
            ->pluck('doc_merge_batch_id')
            ->concat($bulkMergeFailures->pluck('doc_merge_batch_id'))
            ->filter(fn ($id): bool => $id !== null)
            ->unique()
            ->values();

        if ($batchIds->count() === 1) {
            $batch = DocMergeBatch::query()->find($batchIds->first());

            if ($batch instanceof DocMergeBatch) {
                return to_route('doc-merge.batches.show', ['docMergeBatch' => $batch]);
            }
        }

        return to_route('doc-merge.index');
    }

    private function normalizeFooterText(mixed $value): ?string
    {
        return $this->normalizeOptionalText(
            preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '',
        );
    }

    private function busyBatchErrorMessage(): string
    {
        return 'This batch is already queued or processing. Wait for it to finish before making more changes.';
    }

    /**
     * @param  Collection<int, MergedPdf>  $mergedPdfs
     * @param  Collection<int, BulkMergeFailure>  $bulkMergeFailures
     */
    private function firstBusyBatch(
        Collection $mergedPdfs,
        Collection $bulkMergeFailures,
    ): ?DocMergeBatch {
        $batchIds = $mergedPdfs
            ->pluck('doc_merge_batch_id')
            ->concat($bulkMergeFailures->pluck('doc_merge_batch_id'))
            ->filter(fn ($id): bool => $id !== null)
            ->unique()
            ->values();

        if ($batchIds->isEmpty()) {
            return null;
        }

        return DocMergeBatch::query()
            ->whereIn('id', $batchIds->all())
            ->get()
            ->first(fn (DocMergeBatch $batch): bool => $batch->isBusy());
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

    private function singleDeleteLabel(
        ?MergedPdf $mergedPdf,
        ?BulkMergeFailure $bulkMergeFailure,
    ): ?string {
        if ($mergedPdf instanceof MergedPdf) {
            return $mergedPdf->file_name;
        }

        if ($bulkMergeFailure instanceof BulkMergeFailure) {
            return $bulkMergeFailure->output_file_name;
        }

        return null;
    }
}
