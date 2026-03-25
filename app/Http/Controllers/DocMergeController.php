<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\MergedPdfEmail;
use App\Models\MergedPdf;
use App\Models\User;
use App\Services\PdfMergeService;
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
    public function index(Request $request): Response
    {
        return Inertia::render('DocMerge', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'mergedPdfs' => $this->transformMergedPdfs(
                MergedPdf::query()
                    ->whereBelongsTo($request->user())
                    ->latest()
                    ->get(),
            ),
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
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'sources.required' => 'Select at least two PDF sources to merge.',
            'sources.min' => 'Select at least two PDF sources to merge.',
            'files.*.mimes' => 'Only PDF files can be merged right now.',
            'receipt.mimes' => 'Receipts must be a PDF or image file.',
            'receipt.max' => 'Receipts must be 10 MB or smaller.',
        ])->validate();

        $receipt = $this->resolveReceiptFile($request);
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
            );

            if ($receipt instanceof UploadedFile) {
                $this->attachUploadedReceipt($mergedPdf, $receipt, $service);
            }

            return to_route('doc-merge.index')
                ->with(
                    'success',
                    $receipt instanceof UploadedFile
                        ? "Merged PDF saved as {$mergedPdf->file_name} with receipt attached."
                        : "Merged PDF saved as {$mergedPdf->file_name}.",
                );
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
     * Delete one or more saved merged PDFs that belong to the current user.
     */
    public function destroyMany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ], [
            'ids.required' => 'Select at least one merged PDF to delete.',
            'ids.min' => 'Select at least one merged PDF to delete.',
        ]);

        $requestedIds = array_values(
            array_map('intval', $validated['ids']),
        );

        /** @var Collection<int, MergedPdf> $mergedPdfs */
        $mergedPdfs = MergedPdf::query()
            ->whereBelongsTo($request->user())
            ->whereKey($requestedIds)
            ->get();

        if ($mergedPdfs->count() !== count($requestedIds)) {
            return to_route('doc-merge.index')
                ->with('error', 'One or more selected merged PDFs could not be deleted.');
        }

        try {
            $deletedCount = $mergedPdfs->count();
            $deletedFileName = $deletedCount === 1
                ? $mergedPdfs->first()?->file_name
                : null;

            $mergedPdfs->each->delete();

            $message = $deletedCount === 1 && $deletedFileName !== null
                ? "Deleted {$deletedFileName}."
                : "Deleted {$deletedCount} merged PDFs.";

            return to_route('doc-merge.index')->with('success', $message);
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('doc-merge.index')
                ->with('error', 'The selected merged PDFs could not be deleted right now. Please try again.');
        }
    }

    /**
     * Download a saved merged PDF.
     */
    public function download(Request $request, MergedPdf $mergedPdf): StreamedResponse
    {
        abort_unless($mergedPdf->user->is($request->user()), 404);

        return Storage::disk('local')->download(
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

        return Storage::disk('local')->response(
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
        PdfMergeService $service,
    ): RedirectResponse {
        abort_unless($mergedPdf->user->is($request->user()), 404);

        $validated = $request->validate([
            'receipt' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], $this->receiptValidationMessages([
            'receipt.required' => 'Choose a receipt file to upload.',
        ]));

        /** @var UploadedFile $receipt */
        $receipt = $validated['receipt'];
        $hadReceipt = filled($mergedPdf->receipt_storage_path);

        try {
            $this->attachUploadedReceipt($mergedPdf, $receipt, $service);

            $message = $hadReceipt
                ? "Receipt updated for {$mergedPdf->file_name}."
                : "Receipt added to {$mergedPdf->file_name}.";

            return to_route('doc-merge.index')->with('success', $message);
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('doc-merge.index')
                ->with('error', 'The receipt could not be uploaded right now. Please try again.');
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
                && Storage::disk('local')->exists($mergedPdf->receipt_storage_path),
            404,
        );

        return Storage::disk('local')->download(
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

        if (! filled($mergedPdf->receipt_storage_path) || ! filled($mergedPdf->receipt_file_name)) {
            return to_route('doc-merge.index')
                ->with('error', "There is no receipt attached to {$mergedPdf->file_name}.");
        }

        $disk = Storage::disk('local');
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

            return to_route('doc-merge.index')
                ->with('success', "Receipt removed from {$mergedPdf->file_name}.");
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('doc-merge.index')
                ->with('error', 'The receipt could not be removed right now. Please try again.');
        }
    }

    /**
     * Email a saved merged PDF to a recipient.
     */
    public function sendEmail(Request $request, MergedPdf $mergedPdf): RedirectResponse
    {
        abort_unless($mergedPdf->user->is($request->user()), 404);

        $validated = $request->validate([
            'recipientEmail' => ['required', 'email', 'max:254'],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $recipientEmail = trim((string) $validated['recipientEmail']);
        $subject = $this->normalizeOptionalText($validated['subject'] ?? null);
        $message = $this->normalizeOptionalText($validated['message'] ?? null);
        $disk = Storage::disk('local');

        if (! $disk->exists($mergedPdf->storage_path)) {
            return to_route('doc-merge.index')
                ->with('error', "The saved PDF {$mergedPdf->file_name} is no longer available.");
        }

        try {
            Mail::to($recipientEmail)->send(
                new MergedPdfEmail($mergedPdf, $subject, $message),
            );

            return to_route('doc-merge.index')
                ->with('success', "Email sent to {$recipientEmail}.");
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('doc-merge.index')
                ->with('error', 'The email could not be sent right now. Please verify your mail settings and try again.');
        }
    }

    /**
     * Convert merged PDF records into frontend payloads.
     *
     * @param  Collection<int, MergedPdf>  $mergedPdfs
     * @return array<int, array<string, mixed>>
     */
    private function transformMergedPdfs(Collection $mergedPdfs): array
    {
        return $mergedPdfs->map(fn (MergedPdf $mergedPdf): array => [
            'id' => $mergedPdf->id,
            'fileName' => $mergedPdf->file_name,
            'fileSize' => $mergedPdf->file_size,
            'sourceCount' => $mergedPdf->source_count,
            'sourceFileNames' => $mergedPdf->source_file_names,
            'hasReceipt' => filled($mergedPdf->receipt_storage_path),
            'receiptFileName' => $mergedPdf->receipt_file_name,
            'receiptFileSize' => $mergedPdf->receipt_file_size,
            'createdAt' => $mergedPdf->created_at?->toIso8601String(),
            'downloadUrl' => route('doc-merge.download', ['mergedPdf' => $mergedPdf]),
            'previewUrl' => route('doc-merge.preview', [
                'mergedPdf' => $mergedPdf,
                'v' => $this->previewVersion($mergedPdf),
            ]),
            'receiptUploadUrl' => route('doc-merge.receipt.store', ['mergedPdf' => $mergedPdf]),
            'receiptRemoveUrl' => filled($mergedPdf->receipt_storage_path)
                ? route('doc-merge.receipt.destroy', ['mergedPdf' => $mergedPdf])
                : null,
            'receiptDownloadUrl' => filled($mergedPdf->receipt_storage_path)
                ? route('doc-merge.receipt.download', ['mergedPdf' => $mergedPdf])
                : null,
            'sendEmailUrl' => route('doc-merge.send-email', ['mergedPdf' => $mergedPdf]),
        ])->all();
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
        $disk = Storage::disk('local');

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
     * Return the uploaded receipt file from the request.
     */
    private function resolveReceiptFile(Request $request): ?UploadedFile
    {
        $receipt = $request->file('receipt');

        return $receipt instanceof UploadedFile ? $receipt : null;
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
     * Build a storage-safe filename for uploaded receipts.
     */
    private function safeReceiptFilename(string $fileName): string
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
            $baseName = 'receipt';
        }

        return $extension !== ''
            ? "{$baseName}.{$extension}"
            : $baseName;
    }

    /**
     * Store a receipt file and append it to the saved merged PDF.
     */
    private function attachUploadedReceipt(
        MergedPdf $mergedPdf,
        UploadedFile $receipt,
        PdfMergeService $service,
    ): void {
        $disk = Storage::disk('local');
        $safeFileName = $this->safeReceiptFilename($receipt->getClientOriginalName());
        $storagePath = sprintf(
            'doc-merge/%d/receipts/%d/%s-%s',
            $mergedPdf->user_id,
            $mergedPdf->id,
            Str::uuid(),
            $safeFileName,
        );
        $previousReceiptPath = $mergedPdf->receipt_storage_path;
        $sourceFileNames = $this->sourceFileNamesWithReceipt(
            $mergedPdf->source_file_names,
            $mergedPdf->receipt_file_name,
            $receipt->getClientOriginalName(),
        );

        try {
            $stored = $disk->putFileAs(
                dirname($storagePath),
                $receipt,
                basename($storagePath),
            );

            if ($stored === false || ! $disk->exists($storagePath)) {
                throw new RuntimeException('The receipt could not be stored.');
            }

            $service->attachReceipt($mergedPdf, $disk->path($storagePath));

            $mergedPdf->forceFill([
                'file_size' => $disk->size($mergedPdf->storage_path),
                'source_count' => count($sourceFileNames),
                'source_file_names' => $sourceFileNames,
                'receipt_file_name' => $receipt->getClientOriginalName(),
                'receipt_storage_path' => $storagePath,
                'receipt_file_size' => $disk->size($storagePath),
            ])->save();

            if (
                filled($previousReceiptPath)
                && $previousReceiptPath !== $storagePath
                && $disk->exists($previousReceiptPath)
            ) {
                $disk->delete($previousReceiptPath);
            }
        } catch (\Throwable $exception) {
            if ($disk->exists($storagePath)) {
                $disk->delete($storagePath);
            }

            throw $exception;
        }
    }

    /**
     * Shared receipt validation messages.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function receiptValidationMessages(array $overrides = []): array
    {
        return array_merge([
            'receipt.mimes' => 'Receipts must be a PDF or image file.',
            'receipt.max' => 'Receipts must be 10 MB or smaller.',
        ], $overrides);
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
            'receipt_file_name' => $mergedPdf->receipt_file_name,
            'receipt_file_size' => $mergedPdf->receipt_file_size,
        ], JSON_THROW_ON_ERROR));
    }
}
