<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocMergeBatch;
use App\Models\DocMergeBatchSourceFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class DocMergeBatchService
{
    public function __construct(
        private readonly BulkZipMergeService $bulkZipMergeService,
    ) {
    }

    /**
     * Persist uploaded page folders into the batch.
     *
     * @param  list<array{
     *     name: string,
     *     number: int|string|null,
     *     files: list<UploadedFile>,
     *     hasNestedEntries?: bool|int|string|null,
     *     hasInvalidFiles?: bool|int|string|null
     * }>  $pageFolders
     */
    public function storePageFolders(DocMergeBatch $batch, array $pageFolders): int
    {
        $inspection = $this->bulkZipMergeService->inspectPageFolders(
            $pageFolders,
            minPageFolderCount: 1,
        );

        return $this->replaceTouchedPageFolders(
            $batch,
            $inspection['pageFolders'],
        );
    }

    /**
     * Persist a ZIP upload into the batch.
     */
    public function storeZip(DocMergeBatch $batch, UploadedFile $zipFile): int
    {
        $archivePath = $zipFile->getRealPath();

        if ($archivePath === false || ! is_file($archivePath)) {
            throw ValidationException::withMessages([
                'zip' => 'The uploaded ZIP file is no longer available.',
            ]);
        }

        $inspection = $this->bulkZipMergeService->inspectArchive(
            $archivePath,
            $zipFile->getClientOriginalName(),
            1,
        );
        $zip = new ZipArchive;
        $openResult = $zip->open($archivePath);

        if ($openResult !== true) {
            throw ValidationException::withMessages([
                'zip' => 'The ZIP file could not be opened.',
            ]);
        }

        try {
            return $this->replaceTouchedPageFolders(
                $batch,
                $inspection['pageFolders'],
                $zip,
            );
        } finally {
            $zip->close();
        }
    }

    /**
     * Process the persisted batch files and replace the batch's previous results.
     *
     * @return array{mergedCount: int, failedCount: int}
     */
    public function processBatch(DocMergeBatch $batch, ?string $outputPrefix = null): array
    {
        $pageFolders = $this->storedPageFolders($batch);

        if (count($pageFolders) < 2) {
            throw ValidationException::withMessages([
                'batch' => 'Add at least two page folders like PAGE 1 and PAGE 2 before running merge.',
            ]);
        }

        $batch->loadMissing(['mergedPdfs', 'bulkMergeFailures']);

        $batch->mergedPdfs->each->delete();
        $batch->bulkMergeFailures->each->delete();

        $result = $this->bulkZipMergeService->processPageFolders(
            $batch->user,
            $pageFolders,
            $outputPrefix,
            batch: $batch,
            inputLabel: $batch->name,
            inputMode: 'batch',
        );

        $batch->forceFill([
            'last_processed_at' => now(),
        ])->save();

        return $result;
    }

    /**
     * Build a ZIP download that contains the batch's merged PDFs.
     */
    public function downloadBatch(DocMergeBatch $batch): BinaryFileResponse
    {
        $batch->loadMissing(['mergedPdfs']);

        $disk = \App\Support\DocumentStorage::disk();
        $temporaryZipPath = storage_path('app/tmp/doc-merge-batch-'.Str::uuid().'.zip');

        if (! is_dir(dirname($temporaryZipPath))) {
            mkdir(dirname($temporaryZipPath), 0777, true);
        }

        $archive = new ZipArchive;

        if ($archive->open($temporaryZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The batch ZIP could not be created.');
        }

        try {
            $usedPaths = [];

            foreach ($batch->mergedPdfs->sortBy([
                ['file_name', 'asc'],
                ['id', 'asc'],
            ]) as $mergedPdf) {
                if (! $disk->exists($mergedPdf->storage_path)) {
                    continue;
                }

                $zipPath = $this->uniqueZipPath(
                    $usedPaths,
                    $this->safeZipSegment($mergedPdf->file_name, 'merged.pdf'),
                );

                $archive->addFile($disk->path($mergedPdf->storage_path), $zipPath);
            }
        } finally {
            $archive->close();
        }

        return response()->download(
            $temporaryZipPath,
            $this->downloadFileName($batch),
            ['Content-Type' => 'application/zip'],
        )->deleteFileAfterSend(true);
    }

    /**
     * Group the persisted source files into page folders ready for processing or display.
     *
     * @return list<array{
     *     name: string,
     *     number: int,
     *     filesByKey: array<string, array{
     *         matchKey: string,
     *         groupLabel: string,
     *         displayName: string,
     *         path: string
     *     }>
     * }>
     */
    public function storedPageFolders(DocMergeBatch $batch): array
    {
        $disk = \App\Support\DocumentStorage::disk();
        /** @var Collection<int, DocMergeBatchSourceFile> $sourceFiles */
        $sourceFiles = $batch->sourceFiles()
            ->orderBy('page_folder_number')
            ->orderBy('display_name')
            ->orderBy('id')
            ->get();

        $groupedFolders = [];

        foreach ($sourceFiles as $sourceFile) {
            if (! $disk->exists($sourceFile->storage_path)) {
                continue;
            }

            $groupedFolders[$sourceFile->page_folder_number] ??= [
                'name' => $sourceFile->page_folder_name,
                'number' => $sourceFile->page_folder_number,
                'filesByKey' => [],
            ];
            $groupedFolders[$sourceFile->page_folder_number]['filesByKey'][$sourceFile->match_key] = [
                'matchKey' => $sourceFile->match_key,
                'groupLabel' => $sourceFile->group_label,
                'displayName' => $sourceFile->display_name,
                'path' => $disk->path($sourceFile->storage_path),
            ];
        }

        return array_values($groupedFolders);
    }

    /**
     * Remove one persisted source file from a batch.
     */
    public function removeSourceFile(DocMergeBatchSourceFile $sourceFile): void
    {
        $sourceFile->delete();
    }

    /**
     * Remove an entire persisted page folder from a batch.
     */
    public function removePageFolder(DocMergeBatch $batch, int $pageFolderNumber): int
    {
        $sourceFiles = $batch->sourceFiles()
            ->where('page_folder_number', $pageFolderNumber)
            ->get();

        $sourceFiles->each->delete();

        return $sourceFiles->count();
    }

    /**
     * @param  list<array{
     *     name: string,
     *     number: int,
     *     filesByKey: array<string, array{
     *         matchKey: string,
     *         groupLabel: string,
     *         displayName: string,
     *         entryName?: string,
     *         path?: string
     *     }>
     * }>  $pageFolders
     */
    private function replaceTouchedPageFolders(
        DocMergeBatch $batch,
        array $pageFolders,
        ?ZipArchive $zip = null,
    ): int {
        $disk = \App\Support\DocumentStorage::disk();
        $touchedPageFolderNumbers = array_map(
            static fn (array $pageFolder): int => $pageFolder['number'],
            $pageFolders,
        );
        $existingSourceFiles = $batch->sourceFiles()
            ->whereIn('page_folder_number', $touchedPageFolderNumbers)
            ->get();
        $storedPaths = [];
        $newSourceFiles = [];

        try {
            foreach ($pageFolders as $pageFolder) {
                foreach ($pageFolder['filesByKey'] as $source) {
                    $safeFileName = $this->safePdfFilename(
                        $source['displayName'],
                        'source',
                    );
                    $storagePath = $this->sourceFileStoragePath(
                        $batch,
                        $pageFolder['number'],
                        $safeFileName,
                    );

                    if (isset($source['path'])) {
                        $this->storeFileFromPath(
                            $disk,
                            $storagePath,
                            $source['path'],
                            'One of the batch source PDFs could not be stored.',
                        );
                    } elseif (isset($source['entryName']) && $zip instanceof ZipArchive) {
                        $this->storeZipEntry(
                            $disk,
                            $zip,
                            $source['entryName'],
                            $storagePath,
                            'One of the batch ZIP source PDFs could not be stored.',
                        );
                    } else {
                        throw new RuntimeException('One of the batch source PDFs is no longer available.');
                    }

                    $storedPaths[] = $storagePath;
                    $newSourceFiles[] = [
                        'doc_merge_batch_id' => $batch->id,
                        'page_folder_name' => $pageFolder['name'],
                        'page_folder_number' => $pageFolder['number'],
                        'display_name' => $source['displayName'],
                        'storage_path' => $storagePath,
                        'file_size' => $disk->size($storagePath),
                        'match_key' => $source['matchKey'],
                        'group_label' => $source['groupLabel'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            DB::transaction(function () use ($existingSourceFiles, $newSourceFiles): void {
                if ($existingSourceFiles->isNotEmpty()) {
                    DocMergeBatchSourceFile::query()
                        ->whereKey($existingSourceFiles->modelKeys())
                        ->delete();
                }

                if ($newSourceFiles !== []) {
                    DocMergeBatchSourceFile::query()->insert($newSourceFiles);
                }
            });

            if ($existingSourceFiles->isNotEmpty()) {
                $disk = \App\Support\DocumentStorage::disk();

                foreach ($existingSourceFiles as $existingSourceFile) {
                    $disk->delete($existingSourceFile->storage_path);
                }
            }

            return count($newSourceFiles);
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $storedPath) {
                $disk->delete($storedPath);
            }

            throw $exception;
        }
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

    private function storeZipEntry(
        FilesystemAdapter $disk,
        ZipArchive $zip,
        string $entryName,
        string $storagePath,
        string $errorMessage,
    ): void {
        $stream = $zip->getStream($entryName);

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

    private function sourceFileStoragePath(
        DocMergeBatch $batch,
        int $pageFolderNumber,
        string $safeFileName,
    ): string {
        return sprintf(
            'doc-merge/%d/batches/%d/source/%d/%s-%s',
            $batch->user_id,
            $batch->id,
            $pageFolderNumber,
            Str::uuid(),
            $safeFileName,
        );
    }

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

    private function safeZipSegment(string $value, string $fallback): string
    {
        $segment = trim(str_replace('\\', '/', $value));
        $segment = trim($segment, '/');

        return $segment !== '' ? $segment : $fallback;
    }

    /**
     * @param  array<string, true>  $usedPaths
     */
    private function uniqueZipPath(array &$usedPaths, string $basePath): string
    {
        $candidate = $basePath;
        $extension = pathinfo($basePath, PATHINFO_EXTENSION);
        $baseName = pathinfo($basePath, PATHINFO_FILENAME);
        $directory = pathinfo($basePath, PATHINFO_DIRNAME);
        $suffix = 2;

        while (isset($usedPaths[$candidate])) {
            $nextBaseName = "{$baseName}-{$suffix}";
            $candidate = $directory === '.'
                ? ($extension !== '' ? "{$nextBaseName}.{$extension}" : $nextBaseName)
                : ($extension !== '' ? "{$directory}/{$nextBaseName}.{$extension}" : "{$directory}/{$nextBaseName}");
            $suffix++;
        }

        $usedPaths[$candidate] = true;

        return $candidate;
    }

    private function downloadFileName(DocMergeBatch $batch): string
    {
        return Str::of($batch->name)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-._')
            ->whenEmpty(fn () => 'doc-merge-batch')
            ->append('.zip')
            ->value();
    }
}
