<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MergedPdf;
use App\Services\PdfMergeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
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
     * Merge the uploaded PDFs and save the result.
     */
    public function store(Request $request, PdfMergeService $service): RedirectResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:2'],
            'files.*' => ['required', 'file', 'mimes:pdf'],
            'outputName' => ['nullable', 'string', 'max:120'],
        ], [
            'files.min' => 'Select at least two PDF files to merge.',
            'files.*.mimes' => 'Only PDF files can be merged right now.',
        ]);

        /** @var list<UploadedFile> $files */
        $files = $validated['files'];

        try {
            $mergedPdf = $service->merge(
                $request->user(),
                $files,
                $validated['outputName'] ?? null,
            );

            return to_route('doc-merge.index')
                ->with('success', "Merged PDF saved as {$mergedPdf->file_name}.");
        } catch (RuntimeException $exception) {
            report($exception);

            return to_route('doc-merge.index')
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('doc-merge.index')
                ->with('error', 'The PDF merge failed. Try again with standard, unlocked PDF files.');
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
            'createdAt' => $mergedPdf->created_at?->toIso8601String(),
            'downloadUrl' => route('doc-merge.download', ['mergedPdf' => $mergedPdf]),
        ])->all();
    }
}
