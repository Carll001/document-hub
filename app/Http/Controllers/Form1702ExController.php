<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateForm1702ExRowReceipt;
use App\Jobs\ProcessForm1702ExBatchRows;
use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Services\Form1702ExBatchService;
use App\Services\Form1702ExCompletedEmailService;
use App\Services\Form1702ExImportService;
use App\Services\Form1702ExRowReceiptService;
use App\Services\Form1702ExService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Form1702ExController extends Controller
{
    private const ROWS_PER_PAGE = 25;

    public function __construct(
        private readonly Form1702ExBatchService $form1702ExBatchService,
        private readonly Form1702ExCompletedEmailService $form1702ExCompletedEmailService,
        private readonly Form1702ExImportService $form1702ExImportService,
        private readonly Form1702ExService $form1702ExService,
    ) {
    }

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'in:uploadedAt,generatedAt,pdfStatus,sourceRowNumber'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);
        $user = $request->user();
        $rowPage = $this->rowPage(
            $user,
            (int) ($validated['page'] ?? 1),
            isset($validated['search']) ? (string) $validated['search'] : '',
            isset($validated['sort']) ? (string) $validated['sort'] : 'uploadedAt',
            isset($validated['direction']) ? (string) $validated['direction'] : 'desc',
        );

        return Inertia::render('forms/1702-ex/Index', [
            'flash' => $this->flash($request),
            'indexUrl' => route('forms.1702-ex.index'),
            'completedFilesUrl' => route('forms.1702-ex.completed.index'),
            'completedCount' => $this->completedCount($user),
            'importUrl' => route('forms.1702-ex.import.store'),
            'bulkDeleteUrl' => route('forms.1702-ex.rows.destroy'),
            'settingsUpdateUrl' => route('forms.1702-ex.settings.update'),
            'templateSpreadsheetUrl' => asset('form-assets/1702-ex/1702-ex-import-template.xlsx'),
            'receiptTemplateUrl' => route('forms.1702-ex.receipt-template.show'),
            'receiptTemplate' => [
                'fields' => $this->form1702ExService->receiptInputFields(),
            ],
            'settings' => [
                'fileNamePrefix' => $this->form1702ExService->resolveFileNamePrefix(
                    $user->form_1702_ex_file_name_prefix,
                ),
                'footerSourcePath' => $this->form1702ExService->resolveFooterSourcePath(
                    $user->form_1702_ex_footer_source_path,
                ),
                'footerPrintedDate' => $this->form1702ExService->resolveFooterPrintedDate(
                    $user->form_1702_ex_footer_printed_date,
                ),
                'receiptAcceptanceStartDate' => null,
            ],
            'rows' => $this->transformRows(collect($rowPage->items()), true),
            'pagination' => [
                'currentPage' => $rowPage->currentPage(),
                'lastPage' => $rowPage->lastPage(),
                'perPage' => $rowPage->perPage(),
                'total' => $rowPage->total(),
                'from' => $rowPage->firstItem(),
                'to' => $rowPage->lastItem(),
            ],
            'filters' => [
                'search' => isset($validated['search']) ? trim((string) $validated['search']) : '',
                'sort' => isset($validated['sort']) ? (string) $validated['sort'] : 'uploadedAt',
                'direction' => isset($validated['direction']) ? (string) $validated['direction'] : 'desc',
            ],
            'hasActiveJobs' => $this->userHasActiveJobs($user),
        ]);
    }

    public function completed(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'in:uploadedAt,generatedAt,pdfStatus,sourceRowNumber'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);
        $user = $request->user();
        $rowPage = $this->rowPage(
            $user,
            (int) ($validated['page'] ?? 1),
            isset($validated['search']) ? (string) $validated['search'] : '',
            isset($validated['sort']) ? (string) $validated['sort'] : 'generatedAt',
            isset($validated['direction']) ? (string) $validated['direction'] : 'desc',
            true,
        );

        return Inertia::render('forms/1702-ex/Completed', [
            'flash' => $this->flash($request),
            'indexUrl' => route('forms.1702-ex.index'),
            'completedFilesUrl' => route('forms.1702-ex.completed.index'),
            'completedBulkSendUrl' => route('forms.1702-ex.completed.send.bulk'),
            'rows' => $this->transformRows(collect($rowPage->items()), true),
            'pagination' => [
                'currentPage' => $rowPage->currentPage(),
                'lastPage' => $rowPage->lastPage(),
                'perPage' => $rowPage->perPage(),
                'total' => $rowPage->total(),
                'from' => $rowPage->firstItem(),
                'to' => $rowPage->lastItem(),
            ],
            'filters' => [
                'search' => isset($validated['search']) ? trim((string) $validated['search']) : '',
                'sort' => isset($validated['sort']) ? (string) $validated['sort'] : 'generatedAt',
                'direction' => isset($validated['direction']) ? (string) $validated['direction'] : 'desc',
            ],
        ]);
    }

    public function show(Request $request, Form1702ExBatch $form1702ExBatch): Response
    {
        $this->ensureAccessibleBatch($request, $form1702ExBatch);

        $rows = $form1702ExBatch->rows()
            ->with(['client', 'company'])
            ->latest('uploaded_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('forms/1702-ex/Show', [
            'flash' => $this->flash($request),
            'batch' => [
                'id' => $form1702ExBatch->uuid,
                'name' => $form1702ExBatch->name,
                'showUrl' => route('forms.1702-ex.batches.show', [
                    'form1702ExBatch' => $form1702ExBatch,
                ]),
                'importUrl' => route('forms.1702-ex.batches.import.store', [
                    'form1702ExBatch' => $form1702ExBatch,
                ]),
                'bulkDeleteUrl' => route('forms.1702-ex.batches.rows.destroy', [
                    'form1702ExBatch' => $form1702ExBatch,
                ]),
                'prefixUpdateUrl' => route('forms.1702-ex.batches.prefix.update', [
                    'form1702ExBatch' => $form1702ExBatch,
                ]),
                'footerUpdateUrl' => route('forms.1702-ex.batches.footer.update', [
                    'form1702ExBatch' => $form1702ExBatch,
                ]),
                'receiptTemplateUrl' => route('forms.1702-ex.receipt-template.show'),
                'receiptTemplate' => [
                    'fields' => $this->form1702ExService->receiptInputFields(),
                ],
                'templateSpreadsheetUrl' => asset('form-assets/1702-ex/1702-ex-import-template.xlsx'),
                'fileNamePrefix' => $this->form1702ExService->resolveFileNamePrefix(
                    $form1702ExBatch->file_name_prefix,
                ),
                'footerSourcePath' => $this->form1702ExService->resolveFooterSourcePath(
                    $form1702ExBatch->footer_source_path,
                ),
                'footerPrintedDate' => $this->form1702ExService->resolveFooterPrintedDate(
                    $form1702ExBatch->footer_printed_date,
                ),
                'receiptAcceptanceStartDate' => $form1702ExBatch->receipt_acceptance_start_date?->toDateString(),
                'rows' => $rows->map(fn (Form1702ExBatchRow $row): array => $this->transformRow(
                    $form1702ExBatch,
                    $row,
                ))->all(),
                'isProcessing' => $rows->contains(
                    fn (Form1702ExBatchRow $row): bool => $row->isProcessing(),
                ),
                'hasActiveReceiptJobs' => $rows->contains(
                    fn (Form1702ExBatchRow $row): bool => $row->receiptJobIsBusy(),
                ),
            ],
            'indexUrl' => route('forms.1702-ex.index'),
        ]);
    }

    public function alignment(): RedirectResponse
    {
        return to_route('forms.1702-ex.index');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'fileNamePrefix' => ['nullable', 'string', 'max:120'],
            'footerSourcePath' => ['nullable', 'string', 'max:500'],
            'footerPrintedDate' => ['nullable', 'string', 'max:64'],
        ]);

        $request->user()->forceFill([
            'form_1702_ex_file_name_prefix' => $this->form1702ExService->resolveFileNamePrefix(
                is_string($validated['fileNamePrefix'] ?? null) ? $validated['fileNamePrefix'] : null,
            ),
            'form_1702_ex_footer_source_path' => $this->form1702ExService->resolveFooterSourcePath(
                is_string($validated['footerSourcePath'] ?? null) ? $validated['footerSourcePath'] : null,
            ),
            'form_1702_ex_footer_printed_date' => $this->form1702ExService->resolveFooterPrintedDate(
                is_string($validated['footerPrintedDate'] ?? null) ? $validated['footerPrintedDate'] : null,
            ),
        ])->save();

        return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
            ->with('success', '1702-EX defaults updated.');
    }

    public function storeImportDirect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'spreadsheet' => ['required', 'file', 'max:15360'],
            'receiptAcceptanceStartDate' => ['required', 'date'],
        ]);

        /** @var UploadedFile $spreadsheet */
        $spreadsheet = $validated['spreadsheet'];
        $user = $request->user();
        $batch = $this->form1702ExBatchService->createBatch(
            $user,
            $this->generatedBatchName($spreadsheet->getClientOriginalName()),
        );
        $batch->forceFill([
            'file_name_prefix' => $this->form1702ExService->resolveFileNamePrefix(
                $user->form_1702_ex_file_name_prefix,
            ),
            'footer_source_path' => $this->form1702ExService->resolveFooterSourcePath(
                $user->form_1702_ex_footer_source_path,
            ),
            'footer_printed_date' => $this->form1702ExService->resolveFooterPrintedDate(
                $user->form_1702_ex_footer_printed_date,
            ),
            'receipt_acceptance_start_date' => Carbon::parse((string) $validated['receiptAcceptanceStartDate'])->toDateString(),
        ])->save();

        $basePayload = $this->form1702ExService->batchPayloadDefaults();
        $basePayload['file_name_prefix'] = $batch->file_name_prefix;
        $basePayload['footer_source_path'] = $batch->footer_source_path;
        $basePayload['footer_printed_date'] = $batch->footer_printed_date;

        try {
            $import = $this->form1702ExImportService->import(
                $spreadsheet,
                $basePayload,
            );

            $rows = $this->form1702ExBatchService->storeImport(
                $batch,
                $import,
                false,
            );
            $processableRows = $rows->filter(fn (Form1702ExBatchRow $row): bool => ! $row->isSkippedDuplicate())->values();
            $skippedCount = $rows->filter(fn (Form1702ExBatchRow $row): bool => $row->isSkippedDuplicate())->count();

            if ($rows->isEmpty()) {
                $batch->delete();

                return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                    ->with('error', 'The uploaded file does not contain any importable rows.');
            }

            if ($processableRows->isNotEmpty()) {
                ProcessForm1702ExBatchRows::dispatch(
                    $processableRows
                        ->pluck('id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all(),
                );
            }

            $successMessage = "Imported {$processableRows->count()} row(s) from {$import['sourceName']}.";

            if ($processableRows->isNotEmpty()) {
                $successMessage .= ' PDFs are being generated.';
            }

            if ($skippedCount > 0) {
                $successMessage .= " Skipped {$skippedCount} duplicate TIN row(s) because a receipt already exists.";
            }

            if ($processableRows->isEmpty() && $skippedCount > 0) {
                $successMessage = "Skipped {$skippedCount} duplicate TIN row(s) from {$import['sourceName']} because a receipt already exists.";
            }

            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('success', $successMessage);
        } catch (ValidationException $exception) {
            $batch->delete();

            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $batch->delete();

            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'The bulk 1702-EX file could not be imported right now.');
        }
    }

    public function destroyRowsDirect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rowIds' => ['required', 'array', 'min:1'],
            'rowIds.*' => ['required', 'string', 'uuid'],
        ]);

        $rowUuids = collect($validated['rowIds'])
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        $rows = $this->rowOwnershipQuery($request)
            ->whereIn('uuid', $rowUuids)
            ->get();

        if ($rows->isEmpty()) {
            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'Select at least one imported row to delete.');
        }

        if ($rows->contains(fn (Form1702ExBatchRow $row): bool => $row->isProcessing() || $row->receiptJobIsBusy())) {
            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'Wait for queued or processing rows to finish before deleting them.');
        }

        $deletedCount = 0;

        foreach ($rows as $row) {
            $row->delete();
            $deletedCount++;
        }

        return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
            ->with('success', "Deleted {$deletedCount} imported row(s).");
    }

    public function sendCompletedEmail(
        Request $request,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): RedirectResponse {
        $this->ensureAccessibleStandaloneRow($request, $form1702ExBatchRow);

        if (! $this->form1702ExCompletedEmailService->isCompleted($form1702ExBatchRow)) {
            return to_route('forms.1702-ex.completed.index', $this->completedRouteParameters($request))
                ->with('error', 'Only completed rows can be emailed from this page.');
        }

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:5000'],
            'extraAttachment' => ['nullable', 'file', 'max:10240'],
        ]);

        $recipientEmail = $this->form1702ExCompletedEmailService->recipientEmail($form1702ExBatchRow);

        if ($recipientEmail === null) {
            return to_route('forms.1702-ex.completed.index', $this->completedRouteParameters($request))
                ->with('error', 'This completed row has no recipients.');
        }

        $disk = Storage::disk('local');

        /** @var UploadedFile|null $extraAttachment */
        $extraAttachment = $validated['extraAttachment'] ?? null;
        $extraAttachmentStoragePath = null;
        $extraAttachmentFileName = null;
        $extraAttachmentMimeType = null;

        if ($extraAttachment instanceof UploadedFile) {
            $extraAttachmentFileName = $extraAttachment->getClientOriginalName();
            $extraAttachmentMimeType = $extraAttachment->getMimeType() ?: 'application/octet-stream';
            $extraAttachmentStoragePath = sprintf(
                'forms/%d/%s/email-attachments/%d/%s-%s',
                $form1702ExBatchRow->batch->user_id,
                Form1702ExService::FORM_KEY,
                $form1702ExBatchRow->id,
                Str::uuid(),
                $this->safeAttachmentFilename($extraAttachmentFileName, 'attachment'),
            );

            $stored = $disk->putFileAs(
                dirname($extraAttachmentStoragePath),
                $extraAttachment,
                basename($extraAttachmentStoragePath),
            );

            if ($stored === false || ! $disk->exists($extraAttachmentStoragePath)) {
                return to_route('forms.1702-ex.completed.index', $this->completedRouteParameters($request))
                    ->with('error', 'The extra email attachment could not be stored.');
            }
        }

        try {
            $queuedRecipient = $this->form1702ExCompletedEmailService->queueManual(
                $form1702ExBatchRow,
                $this->normalizeOptionalText($validated['subject'] ?? null),
                $this->normalizeOptionalText($validated['message'] ?? null),
                $extraAttachmentStoragePath,
                $extraAttachmentFileName,
                $extraAttachmentMimeType,
            );

            if ($queuedRecipient === null) {
                return to_route('forms.1702-ex.completed.index', $this->completedRouteParameters($request))
                    ->with('error', 'The final completed PDF is no longer available.');
            }

            return to_route('forms.1702-ex.completed.index', $this->completedRouteParameters($request))
                ->with('success', "Email queued to {$queuedRecipient}.");
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('forms.1702-ex.completed.index', $this->completedRouteParameters($request))
                ->with('error', 'The email could not be queued right now. Please verify your mail settings and try again.');
        }
    }

    public function sendCompletedEmailsBulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rowIds' => ['required', 'array', 'min:1'],
            'rowIds.*' => ['required', 'string', 'uuid'],
        ]);

        $rowUuids = collect($validated['rowIds'])
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        $rows = $this->rowOwnershipQuery($request)
            ->with('batch')
            ->whereIn('uuid', $rowUuids)
            ->get();

        if ($rows->isEmpty()) {
            return to_route('forms.1702-ex.completed.index', $this->completedRouteParameters($request))
                ->with('error', 'Select at least one completed row to email.');
        }

        $sent = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (! $this->form1702ExCompletedEmailService->isCompleted($row)) {
                $skipped++;

                continue;
            }

            if ($this->form1702ExCompletedEmailService->recipientEmail($row) === null) {
                $skipped++;

                continue;
            }

            try {
                if ($this->form1702ExCompletedEmailService->queueManual($row) !== null) {
                    $sent++;

                    continue;
                }

                $skipped++;
            } catch (\Throwable $exception) {
                report($exception);
                $skipped++;
            }
        }

        if ($sent === 0) {
            return to_route('forms.1702-ex.completed.index', $this->completedRouteParameters($request))
                ->with('error', "No completed emails were queued. Skipped {$skipped} row(s).");
        }

        return to_route('forms.1702-ex.completed.index', $this->completedRouteParameters($request))
            ->with('success', "Queued {$sent} completed email(s). Skipped {$skipped} row(s).");
    }

    public function storeBatch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ], [
            'name.required' => 'Enter a batch name.',
        ]);

        $batch = $this->form1702ExBatchService->createBatch(
            $request->user(),
            $validated['name'],
        );

        return to_route('forms.1702-ex.batches.show', [
            'form1702ExBatch' => $batch,
        ])->with('success', "Batch {$batch->name} created.");
    }

    public function updatePrefix(Request $request, Form1702ExBatch $form1702ExBatch): RedirectResponse
    {
        $this->ensureAccessibleBatch($request, $form1702ExBatch);

        if ($form1702ExBatch->isProcessing()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'Wait for the current batch generation to finish before updating the prefix.');
        }

        $validated = $request->validate([
            'fileNamePrefix' => ['nullable', 'string', 'max:120'],
        ]);

        $form1702ExBatch->forceFill([
            'file_name_prefix' => $this->form1702ExService->resolveFileNamePrefix(
                is_string($validated['fileNamePrefix'] ?? null) ? $validated['fileNamePrefix'] : null,
            ),
        ])->save();

        return to_route('forms.1702-ex.batches.show', [
            'form1702ExBatch' => $form1702ExBatch,
        ])->with('success', 'Batch prefix updated.');
    }

    public function updateFooter(Request $request, Form1702ExBatch $form1702ExBatch): RedirectResponse
    {
        $this->ensureAccessibleBatch($request, $form1702ExBatch);

        if ($form1702ExBatch->isProcessing()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'Wait for the current batch generation to finish before updating the footer.');
        }

        $validated = $request->validate([
            'footerSourcePath' => ['nullable', 'string', 'max:500'],
            'footerPrintedDate' => ['nullable', 'string', 'max:64'],
        ]);

        $form1702ExBatch->forceFill([
            'footer_source_path' => $this->form1702ExService->resolveFooterSourcePath(
                is_string($validated['footerSourcePath'] ?? null) ? $validated['footerSourcePath'] : null,
            ),
            'footer_printed_date' => $this->form1702ExService->resolveFooterPrintedDate(
                is_string($validated['footerPrintedDate'] ?? null) ? $validated['footerPrintedDate'] : null,
            ),
        ])->save();

        return to_route('forms.1702-ex.batches.show', [
            'form1702ExBatch' => $form1702ExBatch,
        ])->with('success', 'Batch footer updated.');
    }

    public function storeImport(Request $request, Form1702ExBatch $form1702ExBatch): RedirectResponse
    {
        $this->ensureAccessibleBatch($request, $form1702ExBatch);

        if ($form1702ExBatch->isProcessing()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'Wait for the current batch generation to finish before uploading another file.');
        }

        $validated = $request->validate([
            'spreadsheet' => ['required', 'file', 'max:15360'],
            'receiptAcceptanceStartDate' => ['required', 'date'],
        ]);

        /** @var UploadedFile $spreadsheet */
        $spreadsheet = $validated['spreadsheet'];
        $basePayload = $this->form1702ExService->batchPayloadDefaults();
        $basePayload['file_name_prefix'] = $this->form1702ExService->resolveFileNamePrefix(
            $form1702ExBatch->file_name_prefix,
        );
        $basePayload['footer_source_path'] = $this->form1702ExService->resolveFooterSourcePath(
            $form1702ExBatch->footer_source_path,
        );
        $basePayload['footer_printed_date'] = $this->form1702ExService->resolveFooterPrintedDate(
            $form1702ExBatch->footer_printed_date,
        );
        $form1702ExBatch->forceFill([
            'receipt_acceptance_start_date' => Carbon::parse((string) $validated['receiptAcceptanceStartDate'])->toDateString(),
        ])->save();

        try {
            $import = $this->form1702ExImportService->import(
                $spreadsheet,
                $basePayload,
            );

            $rows = $this->form1702ExBatchService->storeImport(
                $form1702ExBatch,
                $import,
                false,
            );
            $processableRows = $rows->filter(fn (Form1702ExBatchRow $row): bool => ! $row->isSkippedDuplicate())->values();
            $skippedCount = $rows->filter(fn (Form1702ExBatchRow $row): bool => $row->isSkippedDuplicate())->count();

            if ($rows->isEmpty()) {
                return to_route('forms.1702-ex.batches.show', [
                    'form1702ExBatch' => $form1702ExBatch,
                ])->with('error', 'The uploaded file does not contain any importable rows.');
            }

            if ($processableRows->isNotEmpty()) {
                ProcessForm1702ExBatchRows::dispatch(
                    $processableRows
                        ->pluck('id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all(),
                );
            }

            $successMessage = "Imported {$processableRows->count()} row(s) from {$import['sourceName']}.";

            if ($processableRows->isNotEmpty()) {
                $successMessage .= ' PDFs are being generated.';
            }

            if ($skippedCount > 0) {
                $successMessage .= " Skipped {$skippedCount} duplicate TIN row(s) because a receipt already exists.";
            }

            if ($processableRows->isEmpty() && $skippedCount > 0) {
                $successMessage = "Skipped {$skippedCount} duplicate TIN row(s) from {$import['sourceName']} because a receipt already exists.";
            }

            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('success', $successMessage);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'The bulk 1702-EX file could not be imported right now.');
        }
    }

    public function destroyRows(Request $request, Form1702ExBatch $form1702ExBatch): RedirectResponse
    {
        $this->ensureAccessibleBatch($request, $form1702ExBatch);

        if ($form1702ExBatch->isProcessing()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'Wait for the current batch generation to finish before deleting rows.');
        }

        $validated = $request->validate([
            'rowIds' => ['required', 'array', 'min:1'],
            'rowIds.*' => ['required', 'string', 'uuid'],
        ]);

        $rowUuids = collect($validated['rowIds'])
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        $rows = $form1702ExBatch->rows()
            ->whereIn('uuid', $rowUuids)
            ->get();

        if ($rows->isEmpty()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'Select at least one imported row to delete.');
        }

        $deletedCount = 0;

        foreach ($rows as $row) {
            $row->delete();
            $deletedCount++;
        }

        return to_route('forms.1702-ex.batches.show', [
            'form1702ExBatch' => $form1702ExBatch,
        ])->with(
            'success',
            "Deleted {$deletedCount} imported row(s).",
        );
    }

    public function previewRow(
        Request $request,
        Form1702ExBatch $form1702ExBatch,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): StreamedResponse {
        $this->ensureAccessibleRow($request, $form1702ExBatch, $form1702ExBatchRow);
        abort_unless(filled($form1702ExBatchRow->generated_pdf_storage_path), 404);

        return Storage::disk('local')->response(
            $form1702ExBatchRow->generated_pdf_storage_path,
            $form1702ExBatchRow->generated_pdf_file_name ?? '1702-ex.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function previewRowDirect(
        Request $request,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): StreamedResponse {
        $this->ensureAccessibleStandaloneRow($request, $form1702ExBatchRow);
        abort_unless(filled($form1702ExBatchRow->generated_pdf_storage_path), 404);

        return Storage::disk('local')->response(
            $form1702ExBatchRow->generated_pdf_storage_path,
            $form1702ExBatchRow->generated_pdf_file_name ?? '1702-ex.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function downloadRow(
        Request $request,
        Form1702ExBatch $form1702ExBatch,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): StreamedResponse {
        $this->ensureAccessibleRow($request, $form1702ExBatch, $form1702ExBatchRow);
        abort_unless(filled($form1702ExBatchRow->generated_pdf_storage_path), 404);

        return Storage::disk('local')->download(
            $form1702ExBatchRow->generated_pdf_storage_path,
            $form1702ExBatchRow->generated_pdf_file_name ?? '1702-ex.pdf',
        );
    }

    public function downloadRowDirect(
        Request $request,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): StreamedResponse {
        $this->ensureAccessibleStandaloneRow($request, $form1702ExBatchRow);
        abort_unless(filled($form1702ExBatchRow->generated_pdf_storage_path), 404);

        return Storage::disk('local')->download(
            $form1702ExBatchRow->generated_pdf_storage_path,
            $form1702ExBatchRow->generated_pdf_file_name ?? '1702-ex.pdf',
        );
    }

    public function storeReceipt(
        Request $request,
        Form1702ExBatch $form1702ExBatch,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): RedirectResponse {
        $this->ensureAccessibleRow($request, $form1702ExBatch, $form1702ExBatchRow);

        if ($form1702ExBatchRow->isProcessing()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'Wait for this row PDF to finish generating before adding a receipt.');
        }

        if ($form1702ExBatchRow->receiptJobIsBusy()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'A receipt update is already queued for this row.');
        }

        if (
            $form1702ExBatchRow->pdf_status !== Form1702ExBatchRow::PDF_STATUS_GENERATED
            || ! filled($form1702ExBatchRow->generated_pdf_storage_path)
        ) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'Generate the 1702-EX PDF before adding a receipt.');
        }

        $validator = Validator::make($request->all(), [
            'values' => ['nullable', 'array'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $submittedValues = $request->input('values', []);

            if (! is_array($submittedValues)) {
                $validator->errors()->add(
                    'values',
                    'Receipt values must be sent as a field map.',
                );

                return;
            }

            foreach ($this->form1702ExService->receiptInputFields() as $field) {
                $key = $field['key'];

                if (! array_key_exists($key, $submittedValues)) {
                    continue;
                }

                $value = $submittedValues[$key];

                if (! is_scalar($value) && $value !== null) {
                    $validator->errors()->add(
                        "values.{$key}",
                        "{$field['label']} must be plain text.",
                    );

                    continue;
                }

                if (mb_strlen((string) $value) > 500) {
                    $validator->errors()->add(
                        "values.{$key}",
                        "{$field['label']} must be 500 characters or fewer.",
                    );
                }
            }
        });

        $validated = $validator->validate();

        try {
            $form1702ExBatchRow->forceFill([
                'receipt_job_status' => Form1702ExBatchRow::RECEIPT_JOB_STATUS_QUEUED,
                'receipt_job_error' => null,
            ])->save();

            GenerateForm1702ExRowReceipt::dispatch(
                $form1702ExBatchRow->getKey(),
                is_array($validated['values'] ?? null)
                    ? $validated['values']
                    : [],
            )->afterCommit();

            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('success', 'Receipt queued for the selected row.');
        } catch (\Throwable $exception) {
            $form1702ExBatchRow->forceFill([
                'receipt_job_status' => null,
                'receipt_job_error' => null,
            ])->save();

            report($exception);

            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'The receipt could not be queued right now. Please try again.');
        }
    }

    public function storeReceiptDirect(
        Request $request,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): RedirectResponse {
        $this->ensureAccessibleStandaloneRow($request, $form1702ExBatchRow);

        if ($form1702ExBatchRow->isProcessing()) {
            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'Wait for this row PDF to finish generating before adding a receipt.');
        }

        if ($form1702ExBatchRow->receiptJobIsBusy()) {
            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'A receipt update is already queued for this row.');
        }

        if (
            $form1702ExBatchRow->pdf_status !== Form1702ExBatchRow::PDF_STATUS_GENERATED
            || ! filled($form1702ExBatchRow->generated_pdf_storage_path)
        ) {
            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'Generate the 1702-EX PDF before adding a receipt.');
        }

        $validator = Validator::make($request->all(), [
            'values' => ['nullable', 'array'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            $submittedValues = $request->input('values', []);

            if (! is_array($submittedValues)) {
                $validator->errors()->add(
                    'values',
                    'Receipt values must be sent as a field map.',
                );

                return;
            }

            foreach ($this->form1702ExService->receiptInputFields() as $field) {
                $key = $field['key'];

                if (! array_key_exists($key, $submittedValues)) {
                    continue;
                }

                $value = $submittedValues[$key];

                if (! is_scalar($value) && $value !== null) {
                    $validator->errors()->add(
                        "values.{$key}",
                        "{$field['label']} must be plain text.",
                    );

                    continue;
                }

                if (mb_strlen((string) $value) > 500) {
                    $validator->errors()->add(
                        "values.{$key}",
                        "{$field['label']} must be 500 characters or fewer.",
                    );
                }
            }
        });

        $validated = $validator->validate();

        try {
            $form1702ExBatchRow->forceFill([
                'receipt_job_status' => Form1702ExBatchRow::RECEIPT_JOB_STATUS_QUEUED,
                'receipt_job_error' => null,
            ])->save();

            GenerateForm1702ExRowReceipt::dispatch(
                $form1702ExBatchRow->getKey(),
                is_array($validated['values'] ?? null)
                    ? $validated['values']
                    : [],
            )->afterCommit();

            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('success', 'Receipt queued for the selected row.');
        } catch (\Throwable $exception) {
            $form1702ExBatchRow->forceFill([
                'receipt_job_status' => null,
                'receipt_job_error' => null,
            ])->save();

            report($exception);

            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'The receipt could not be queued right now. Please try again.');
        }
    }

    public function downloadReceipt(
        Request $request,
        Form1702ExBatch $form1702ExBatch,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): StreamedResponse {
        $this->ensureAccessibleRow($request, $form1702ExBatch, $form1702ExBatchRow);
        abort_unless(
            filled($form1702ExBatchRow->receipt_storage_path)
                && filled($form1702ExBatchRow->receipt_file_name)
                && Storage::disk('local')->exists($form1702ExBatchRow->receipt_storage_path),
            404,
        );

        return Storage::disk('local')->download(
            $form1702ExBatchRow->receipt_storage_path,
            $form1702ExBatchRow->receipt_file_name,
        );
    }

    public function downloadReceiptDirect(
        Request $request,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): StreamedResponse {
        $this->ensureAccessibleStandaloneRow($request, $form1702ExBatchRow);
        abort_unless(
            filled($form1702ExBatchRow->receipt_storage_path)
                && filled($form1702ExBatchRow->receipt_file_name)
                && Storage::disk('local')->exists($form1702ExBatchRow->receipt_storage_path),
            404,
        );

        return Storage::disk('local')->download(
            $form1702ExBatchRow->receipt_storage_path,
            $form1702ExBatchRow->receipt_file_name,
        );
    }

    public function destroyReceipt(
        Request $request,
        Form1702ExBatch $form1702ExBatch,
        Form1702ExBatchRow $form1702ExBatchRow,
        Form1702ExRowReceiptService $form1702ExRowReceiptService,
    ): RedirectResponse {
        $this->ensureAccessibleRow($request, $form1702ExBatch, $form1702ExBatchRow);

        if ($form1702ExBatchRow->isProcessing()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'Wait for this row PDF to finish generating before removing a receipt.');
        }

        if ($form1702ExBatchRow->receiptJobIsBusy()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'A receipt update is already queued for this row.');
        }

        if (! filled($form1702ExBatchRow->receipt_storage_path) || ! filled($form1702ExBatchRow->receipt_file_name)) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'There is no receipt attached to this row.');
        }

        try {
            $form1702ExRowReceiptService->removeReceipt($form1702ExBatchRow);

            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('success', 'Receipt removed from the selected row.');
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'The receipt could not be removed right now. Please try again.');
        }
    }

    public function destroyReceiptDirect(
        Request $request,
        Form1702ExBatchRow $form1702ExBatchRow,
        Form1702ExRowReceiptService $form1702ExRowReceiptService,
    ): RedirectResponse {
        $this->ensureAccessibleStandaloneRow($request, $form1702ExBatchRow);

        if ($form1702ExBatchRow->isProcessing()) {
            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'Wait for this row PDF to finish generating before removing a receipt.');
        }

        if ($form1702ExBatchRow->receiptJobIsBusy()) {
            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'A receipt update is already queued for this row.');
        }

        if (! filled($form1702ExBatchRow->receipt_storage_path) || ! filled($form1702ExBatchRow->receipt_file_name)) {
            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'There is no receipt attached to this row.');
        }

        try {
            $form1702ExRowReceiptService->removeReceipt($form1702ExBatchRow);

            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('success', 'Receipt removed from the selected row.');
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'The receipt could not be removed right now. Please try again.');
        }
    }

    public function regenerateRow(
        Request $request,
        Form1702ExBatch $form1702ExBatch,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): RedirectResponse {
        $this->ensureAccessibleRow($request, $form1702ExBatch, $form1702ExBatchRow);

        if ($form1702ExBatchRow->isProcessing()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'This row is already queued or processing.');
        }

        if ($form1702ExBatchRow->receiptJobIsBusy()) {
            return to_route('forms.1702-ex.batches.show', [
                'form1702ExBatch' => $form1702ExBatch,
            ])->with('error', 'Wait for the current receipt update to finish before regenerating this row.');
        }

        $validated = $request->validate([
            'footerSourcePath' => ['nullable', 'string', 'max:500'],
            'footerPrintedDate' => ['nullable', 'string', 'max:64'],
        ]);

        $form1702ExBatchRow = $this->form1702ExService->clearReceipt($form1702ExBatchRow);
        $form1702ExBatchRow = $this->form1702ExService->clearGeneratedPdf($form1702ExBatchRow);

        /** @var array<string, mixed> $payload */
        $payload = is_array($form1702ExBatchRow->payload) ? $form1702ExBatchRow->payload : [];
        $footerFallbackDate = $form1702ExBatchRow->uploaded_at instanceof Carbon
            ? $form1702ExBatchRow->uploaded_at
            : null;

        $payload['footer_source_path'] = $this->form1702ExService->resolveFooterSourcePath(
            is_string($validated['footerSourcePath'] ?? null)
                ? $validated['footerSourcePath']
                : $form1702ExBatch->footer_source_path,
        );
        $payload['footer_printed_date'] = $this->form1702ExService->resolveFooterPrintedDate(
            is_string($validated['footerPrintedDate'] ?? null)
                ? $validated['footerPrintedDate']
                : $form1702ExBatch->footer_printed_date,
            $footerFallbackDate,
        );
        $payload['file_name_prefix'] = $this->form1702ExService->resolveFileNamePrefix(
            $form1702ExBatch->file_name_prefix,
        );

        $form1702ExBatchRow->forceFill([
            'payload' => $payload,
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_QUEUED,
            'pdf_error' => null,
        ])->save();

        ProcessForm1702ExBatchRows::dispatch([$form1702ExBatchRow->id]);

        return to_route('forms.1702-ex.batches.show', [
            'form1702ExBatch' => $form1702ExBatch,
        ])->with('success', 'PDF regeneration queued for the selected row.');
    }

    public function regenerateRowDirect(
        Request $request,
        Form1702ExBatchRow $form1702ExBatchRow,
    ): RedirectResponse {
        $this->ensureAccessibleStandaloneRow($request, $form1702ExBatchRow);

        if ($form1702ExBatchRow->isProcessing()) {
            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'This row is already queued or processing.');
        }

        if ($form1702ExBatchRow->receiptJobIsBusy()) {
            return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
                ->with('error', 'Wait for the current receipt update to finish before regenerating this row.');
        }

        $validated = $request->validate([
            'footerSourcePath' => ['nullable', 'string', 'max:500'],
            'footerPrintedDate' => ['nullable', 'string', 'max:64'],
        ]);

        $form1702ExBatchRow = $this->form1702ExService->clearReceipt($form1702ExBatchRow);
        $form1702ExBatchRow = $this->form1702ExService->clearGeneratedPdf($form1702ExBatchRow);

        /** @var array<string, mixed> $payload */
        $payload = is_array($form1702ExBatchRow->payload) ? $form1702ExBatchRow->payload : [];
        $footerFallbackDate = $form1702ExBatchRow->uploaded_at instanceof Carbon
            ? $form1702ExBatchRow->uploaded_at
            : null;
        $user = $request->user();

        $payload['footer_source_path'] = $this->form1702ExService->resolveFooterSourcePath(
            is_string($validated['footerSourcePath'] ?? null)
                ? $validated['footerSourcePath']
                : $user->form_1702_ex_footer_source_path,
        );
        $payload['footer_printed_date'] = $this->form1702ExService->resolveFooterPrintedDate(
            is_string($validated['footerPrintedDate'] ?? null)
                ? $validated['footerPrintedDate']
                : $user->form_1702_ex_footer_printed_date,
            $footerFallbackDate,
        );
        $payload['file_name_prefix'] = $this->form1702ExService->resolveFileNamePrefix(
            $user->form_1702_ex_file_name_prefix,
        );

        $form1702ExBatchRow->forceFill([
            'payload' => $payload,
            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_QUEUED,
            'pdf_error' => null,
        ])->save();

        ProcessForm1702ExBatchRows::dispatch([$form1702ExBatchRow->id]);

        return to_route('forms.1702-ex.index', $this->indexRouteParameters($request))
            ->with('success', 'PDF regeneration queued for the selected row.');
    }

    /**
     * @return array{success: string|null, error: string|null}
     */
    private function flash(Request $request): array
    {
        return [
            'success' => $request->session()->get('success'),
            'error' => $request->session()->get('error'),
        ];
    }

    private function ensureAccessibleBatch(Request $request, Form1702ExBatch $batch): void
    {
        abort_unless($batch->user->is($request->user()), 404);
    }

    private function ensureAccessibleRow(
        Request $request,
        Form1702ExBatch $batch,
        Form1702ExBatchRow $row,
    ): void {
        abort_unless(
            $row->form_1702_ex_batch_id === $batch->id
            && $batch->user->is($request->user()),
            404,
        );
    }

    private function ensureAccessibleStandaloneRow(
        Request $request,
        Form1702ExBatchRow $row,
    ): void {
        $row->loadMissing('batch.user');

        abort_unless($row->batch->user->is($request->user()), 404);
    }

    private function rowOwnershipQuery(Request $request)
    {
        return Form1702ExBatchRow::query()
            ->whereHas('batch', fn ($query) => $query->whereBelongsTo($request->user()));
    }

    private function rowPage(
        $user,
        int $page,
        string $search,
        string $sort,
        string $direction,
        bool $completed = false,
    ): LengthAwarePaginator {
        $query = Form1702ExBatchRow::query()
            ->with(['batch', 'client', 'company'])
            ->whereHas('batch', fn ($batchQuery) => $batchQuery->whereBelongsTo($user));

        $this->applyVisibleRowScope($query);

        $this->applyCompletedScope($query, $completed);

        $search = trim($search);

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $like = '%'.$search.'%';

                $searchQuery
                    ->where('generated_pdf_file_name', 'like', $like)
                    ->orWhere('source_name', 'like', $like)
                    ->orWhere('pdf_status', 'like', $like)
                    ->orWhere('payload->taxpayer_name', 'like', $like)
                    ->orWhere('payload->registered_name', 'like', $like)
                    ->orWhere('payload->client_name', 'like', $like)
                    ->orWhere('payload->tin', 'like', $like)
                    ->orWhere('payload->email_address', 'like', $like)
                    ->orWhere('receipt_file_name', 'like', $like)
                    ->orWhere('receipt_job_status', 'like', $like)
                    ->orWhere('receipt_job_error', 'like', $like)
                    ->orWhere('pdf_error', 'like', $like)
                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('name', 'like', $like))
                    ->orWhereHas('company', function ($companyQuery) use ($like): void {
                        $companyQuery
                            ->where('name', 'like', $like)
                            ->orWhere('tin', 'like', $like);
                    });
            });
        }

        $sortColumn = match ($sort) {
            'generatedAt' => 'generated_at',
            'pdfStatus' => 'pdf_status',
            'sourceRowNumber' => 'source_row_number',
            default => 'uploaded_at',
        };
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderByDesc('id')
            ->paginate(self::ROWS_PER_PAGE, ['*'], 'page', $page)
            ->withQueryString();
    }

    /**
     * @param  Collection<int, Form1702ExBatchRow>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function transformRows(Collection $rows, bool $useDirectRoutes): array
    {
        return $rows
            ->map(fn (Form1702ExBatchRow $row): array => $this->transformRow($row->batch, $row, $useDirectRoutes))
            ->all();
    }

    private function completedCount($user): int
    {
        $query = Form1702ExBatchRow::query()
            ->whereHas('batch', fn ($batchQuery) => $batchQuery->whereBelongsTo($user));

        $this->applyVisibleRowScope($query);

        $this->applyCompletedScope($query, true);

        return $query->count();
    }

    private function userHasActiveJobs($user): bool
    {
        return Form1702ExBatchRow::query()
            ->whereHas('batch', fn ($batchQuery) => $batchQuery->whereBelongsTo($user))
            ->whereNull('duplicate_resolution_status')
            ->where(function ($query): void {
                $query
                    ->whereIn('pdf_status', [
                        Form1702ExBatchRow::PDF_STATUS_QUEUED,
                        Form1702ExBatchRow::PDF_STATUS_PROCESSING,
                    ])
                    ->orWhereIn('receipt_job_status', [
                        Form1702ExBatchRow::RECEIPT_JOB_STATUS_QUEUED,
                        Form1702ExBatchRow::RECEIPT_JOB_STATUS_PROCESSING,
                    ]);
            })
            ->exists();
    }

    private function applyVisibleRowScope($query): void
    {
        $query->whereNull('duplicate_resolution_status');
    }

    /**
     * @return array{page?: int, search?: string, sort?: string, direction?: string}
     */
    private function indexRouteParameters(Request $request): array
    {
        $parameters = [];

        if ($request->filled('page')) {
            $parameters['page'] = max(1, (int) $request->input('page'));
        }

        if ($request->filled('search')) {
            $parameters['search'] = trim((string) $request->input('search'));
        }

        if ($request->filled('sort')) {
            $parameters['sort'] = (string) $request->input('sort');
        }

        if ($request->filled('direction')) {
            $parameters['direction'] = (string) $request->input('direction');
        }

        return $parameters;
    }

    /**
     * @return array{page?: int, search?: string, sort?: string, direction?: string}
     */
    private function completedRouteParameters(Request $request): array
    {
        return $this->indexRouteParameters($request);
    }

    private function generatedBatchName(string $sourceName): string
    {
        $baseName = pathinfo($sourceName, PATHINFO_FILENAME);
        $token = trim(Str::limit((string) $baseName, 40, ''));

        if ($token === '') {
            $token = 'upload';
        }

        return sprintf(
            'Internal %s %s',
            now()->format('Y-m-d H:i:s'),
            $token,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     fileName: string,
     *     taxpayerName: string,
     *     clientName: string|null,
     *     companyName: string|null,
     *     tin: string,
     *     sourceRowNumber: int,
     *     sourceName: string,
     *     uploadedAt: string|null,
     *     pdfStatus: string,
     *     generatedAt: string|null,
     *     previewUrl: string|null,
     *     downloadUrl: string|null,
     *     hasReceipt: bool,
     *     receiptFileName: string|null,
     *     receiptFileSize: int|null,
     *     receiptJobStatus: string|null,
     *     receiptJobError: string|null,
     *     receiptStoreUrl: string,
     *     receiptRemoveUrl: string|null,
     *     receiptDownloadUrl: string|null,
     *     regenerateUrl: string,
     *     pdfError: string|null,
     *     autoReceiptStatus: string|null,
     *     autoReceiptError: string|null,
     *     recipientEmail: string|null,
     *     sendEmailUrl: string|null,
     *     footerSourcePath: string,
     *     footerPrintedDate: string
     * }
     */
    private function transformRow(Form1702ExBatch $batch, Form1702ExBatchRow $row, bool $useDirectRoutes = false): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($row->payload) ? $row->payload : [];
        $footerFallbackDate = $row->uploaded_at instanceof Carbon ? $row->uploaded_at : null;
        $previewUrl = null;
        $downloadUrl = null;
        $hasReceipt = filled($row->receipt_file_name) && filled($row->receipt_storage_path);

        if (
            $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_GENERATED
            && filled($row->generated_pdf_storage_path)
        ) {
            $previewRoute = $useDirectRoutes
                ? 'forms.1702-ex.rows.preview'
                : 'forms.1702-ex.batches.rows.preview';
            $downloadRoute = $useDirectRoutes
                ? 'forms.1702-ex.rows.download'
                : 'forms.1702-ex.batches.rows.download';

            $previewUrl = route($previewRoute, $useDirectRoutes
                ? [
                    'form1702ExBatchRow' => $row,
                    'v' => $this->form1702ExService->previewVersion($row),
                ]
                : [
                    'form1702ExBatch' => $batch,
                    'form1702ExBatchRow' => $row,
                    'v' => $this->form1702ExService->previewVersion($row),
                ]);
            $downloadUrl = route($downloadRoute, $useDirectRoutes
                ? [
                    'form1702ExBatchRow' => $row,
                ]
                : [
                    'form1702ExBatch' => $batch,
                    'form1702ExBatchRow' => $row,
                ]);
        }

        return [
            'id' => $row->uuid,
            'fileName' => filled($row->generated_pdf_file_name)
                ? (string) $row->generated_pdf_file_name
                : 'Not generated yet',
            'taxpayerName' => (string) ($payload['taxpayer_name'] ?? $payload['registered_name'] ?? 'Row '.$row->source_row_number),
            'clientName' => $row->client?->name
                ?? (is_scalar($payload['client_name'] ?? null) ? (string) $payload['client_name'] : null),
            'companyName' => $row->company?->name
                ?? (string) ($payload['taxpayer_name'] ?? $payload['registered_name'] ?? ''),
            'tin' => (string) ($payload['tin'] ?? ''),
            'sourceRowNumber' => $row->source_row_number,
            'sourceName' => $row->source_name,
            'uploadedAt' => $row->uploaded_at?->toIso8601String(),
            'receiptAcceptanceStartDate' => is_scalar($payload['receipt_acceptance_start_date'] ?? null)
                ? (string) $payload['receipt_acceptance_start_date']
                : $batch->receipt_acceptance_start_date?->toDateString(),
            'pdfStatus' => $row->pdf_status,
            'generatedAt' => $row->generated_at?->toIso8601String(),
            'previewUrl' => $previewUrl,
            'downloadUrl' => $downloadUrl,
            'hasReceipt' => $hasReceipt,
            'receiptFileName' => $hasReceipt ? (string) $row->receipt_file_name : null,
            'receiptFileSize' => $row->receipt_file_size,
            'receiptJobStatus' => $row->receipt_job_status,
            'receiptJobError' => $row->receipt_job_error,
            'receiptStoreUrl' => route($useDirectRoutes ? 'forms.1702-ex.rows.receipt.store' : 'forms.1702-ex.batches.rows.receipt.store', $useDirectRoutes
                ? [
                    'form1702ExBatchRow' => $row,
                ]
                : [
                    'form1702ExBatch' => $batch,
                    'form1702ExBatchRow' => $row,
                ]),
            'receiptRemoveUrl' => $hasReceipt
                ? route($useDirectRoutes ? 'forms.1702-ex.rows.receipt.destroy' : 'forms.1702-ex.batches.rows.receipt.destroy', $useDirectRoutes
                    ? [
                        'form1702ExBatchRow' => $row,
                    ]
                    : [
                        'form1702ExBatch' => $batch,
                        'form1702ExBatchRow' => $row,
                    ])
                : null,
            'receiptDownloadUrl' => $hasReceipt
                ? route($useDirectRoutes ? 'forms.1702-ex.rows.receipt.download' : 'forms.1702-ex.batches.rows.receipt.download', $useDirectRoutes
                    ? [
                        'form1702ExBatchRow' => $row,
                    ]
                    : [
                        'form1702ExBatch' => $batch,
                        'form1702ExBatchRow' => $row,
                    ])
                : null,
            'regenerateUrl' => route($useDirectRoutes ? 'forms.1702-ex.rows.regenerate' : 'forms.1702-ex.batches.rows.regenerate', $useDirectRoutes
                ? [
                    'form1702ExBatchRow' => $row,
                ]
                : [
                    'form1702ExBatch' => $batch,
                    'form1702ExBatchRow' => $row,
                ]),
            'pdfError' => $row->pdf_error,
            'autoReceiptStatus' => $row->auto_receipt_status,
            'autoReceiptError' => $row->auto_receipt_error,
            'recipientEmail' => $this->form1702ExCompletedEmailService->recipientEmail($row),
            'sendEmailUrl' => $this->form1702ExCompletedEmailService->isCompleted($row)
                ? route('forms.1702-ex.completed.send', [
                    'form1702ExBatchRow' => $row,
                ])
                : null,
            'footerSourcePath' => $this->form1702ExService->resolveFooterSourcePath(
                is_scalar($payload['footer_source_path'] ?? null)
                    ? (string) $payload['footer_source_path']
                    : null,
            ),
            'footerPrintedDate' => $this->form1702ExService->resolveFooterPrintedDate(
                is_scalar($payload['footer_printed_date'] ?? null)
                    ? (string) $payload['footer_printed_date']
                    : null,
                $footerFallbackDate,
            ),
        ];
    }

    private function applyCompletedScope($query, bool $completed): void
    {
        if ($completed) {
            $query
                ->where('pdf_status', Form1702ExBatchRow::PDF_STATUS_GENERATED)
                ->whereNotNull('generated_pdf_storage_path')
                ->whereNotNull('receipt_storage_path')
                ->whereNotNull('receipt_file_name');

            return;
        }

        $query->where(function ($rowQuery): void {
            $rowQuery
                ->where('pdf_status', '!=', Form1702ExBatchRow::PDF_STATUS_GENERATED)
                ->orWhereNull('generated_pdf_storage_path')
                ->orWhereNull('receipt_storage_path')
                ->orWhereNull('receipt_file_name');
        });
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function safeAttachmentFilename(string $fileName, string $fallbackBaseName): string
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

        return $extension !== ''
            ? "{$baseName}.{$extension}"
            : $baseName;
    }
}
