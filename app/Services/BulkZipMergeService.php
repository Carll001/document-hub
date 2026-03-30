<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BulkMergeFailure;
use App\Models\DocMergeBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class BulkZipMergeService
{
    public function __construct(
        private readonly PdfMergeService $pdfMergeService,
    ) {
    }

    /**
     * Process a ZIP upload that contains page folders such as PAGE 1 and PAGE 2.
     *
     * @return array{mergedCount: int, failedCount: int}
     */
    public function processZip(
        User $user,
        UploadedFile $zipFile,
        ?string $outputPrefix = null,
        ?string $footerText = null,
        ?DocMergeBatch $batch = null,
    ): array {
        $archivePath = $zipFile->getRealPath();

        if ($archivePath === false || ! is_file($archivePath)) {
            throw ValidationException::withMessages([
                'zip' => 'The uploaded ZIP file is no longer available.',
            ]);
        }

        $inspection = $this->inspectArchive(
            $archivePath,
            $zipFile->getClientOriginalName(),
        );

        return $this->processDocumentGroups(
            $user,
            $this->planDocumentGroups(
                $inspection['pageFolders'],
                $outputPrefix,
            ),
            $inspection['inputMode'],
            $inspection['inputLabel'],
            $archivePath,
            $footerText,
            $batch,
        );
    }

    /**
     * Process uploaded page folders collected from the browser folder picker.
     *
     * @param  list<array{
     *     name: string,
     *     number: int|string|null,
     *     files?: list<UploadedFile|array{displayName: string, entryName?: string, path?: string}>,
     *     filesByKey?: array<string, array{
     *         matchKey: string,
     *         groupLabel: string,
     *         displayName: string,
     *         entryName?: string,
     *         path?: string
     *     }>,
     *     hasNestedEntries?: bool|int|string|null,
     *     hasInvalidFiles?: bool|int|string|null
     * }>  $pageFolders
     * @return array{mergedCount: int, failedCount: int}
     */
    public function processPageFolders(
        User $user,
        array $pageFolders,
        ?string $outputPrefix = null,
        ?string $footerText = null,
        ?DocMergeBatch $batch = null,
        ?string $inputLabel = null,
        string $inputMode = 'folder',
    ): array {
        $inspection = $this->inspectPageFolders(
            $pageFolders,
            $inputLabel,
        );

        return $this->processDocumentGroups(
            $user,
            $this->planDocumentGroups(
                $inspection['pageFolders'],
                $outputPrefix,
            ),
            $inputMode,
            $inspection['inputLabel'],
            footerText: $footerText,
            batch: $batch,
        );
    }

    /**
     * Inspect a ZIP archive and normalize it into ordered page folders.
     *
     * @return array{
     *     inputMode: 'zip',
     *     inputLabel: string,
     *     pageFolders: list<array{
     *         name: string,
     *         number: int,
     *         filesByKey: array<string, array{
     *             matchKey: string,
     *             groupLabel: string,
     *             displayName: string,
     *             entryName?: string,
     *             path?: string
     *         }>
     *     }>
     * }
     */
    public function inspectArchive(
        string $archivePath,
        string $inputLabel,
        int $minPageFolderCount = 2,
    ): array
    {
        $zip = new ZipArchive;
        $openResult = $zip->open($archivePath);

        if ($openResult !== true) {
            throw ValidationException::withMessages([
                'zip' => 'The ZIP file could not be opened.',
            ]);
        }

        $entries = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if (! is_array($stat) || ! isset($stat['name']) || ! is_string($stat['name'])) {
                    continue;
                }

                $entryName = str_replace('\\', '/', $stat['name']);
                $normalizedPath = trim($entryName, '/');

                if ($normalizedPath === '') {
                    continue;
                }

                $segments = array_values(
                    array_filter(
                        explode('/', $normalizedPath),
                        static fn (string $segment): bool => $segment !== '',
                    ),
                );

                if ($segments === []) {
                    continue;
                }

                if ($this->hasUnsafeSegments($segments)) {
                    throw ValidationException::withMessages([
                        'zip' => 'The ZIP file contains an unsupported path.',
                    ]);
                }

                $entries[] = [
                    'normalizedPath' => $normalizedPath,
                    'segments' => $segments,
                    'isDirectory' => str_ends_with($entryName, '/'),
                ];
            }
        } finally {
            $zip->close();
        }

        if ($entries === []) {
            throw ValidationException::withMessages([
                'zip' => $minPageFolderCount <= 1
                    ? 'The ZIP must contain at least one page folder like PAGE 1.'
                    : 'The ZIP must contain at least two page folders like PAGE 1 and PAGE 2.',
            ]);
        }

        $pageFolders = $this->detectArchiveLayout($entries) === 'wrapped'
            ? $this->collectWrappedPageFoldersFromArchive($entries)
            : $this->collectRootPageFoldersFromArchive($entries);

        return [
            'inputMode' => 'zip',
            'inputLabel' => $inputLabel,
            'pageFolders' => $this->validatePageFolders(
                $pageFolders,
                'zip',
                $minPageFolderCount,
            ),
        ];
    }

    /**
     * Inspect uploaded browser folders and normalize them into ordered page folders.
     *
     * @param  list<array{
     *     name: string,
     *     number: int|string|null,
     *     files?: list<UploadedFile|array{displayName: string, entryName?: string, path?: string}>,
     *     filesByKey?: array<string, array{
     *         matchKey: string,
     *         groupLabel: string,
     *         displayName: string,
     *         entryName?: string,
     *         path?: string
     *     }>,
     *     hasNestedEntries?: bool|int|string|null,
     *     hasInvalidFiles?: bool|int|string|null
     * }>  $pageFolders
     * @return array{
     *     inputMode: 'folder',
     *     inputLabel: string,
     *     pageFolders: list<array{
     *         name: string,
     *         number: int,
     *         filesByKey: array<string, array{
     *             matchKey: string,
     *             groupLabel: string,
     *             displayName: string,
     *             entryName?: string,
     *             path?: string
     *         }>
     *     }>
     * }
     */
    public function inspectPageFolders(
        array $pageFolders,
        ?string $inputLabel = null,
        int $minPageFolderCount = 2,
    ): array {
        $normalizedPageFolders = $this->validatePageFolders(
            $pageFolders,
            'pageFolders',
            $minPageFolderCount,
        );

        return [
            'inputMode' => 'folder',
            'inputLabel' => $inputLabel ?? implode(
                ', ',
                array_map(
                    static fn (array $pageFolder): string => $pageFolder['name'],
                    $normalizedPageFolders,
                ),
            ),
            'pageFolders' => $normalizedPageFolders,
        ];
    }

    /**
     * Build output groups by matching PDF filenames across all page folders.
     *
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
     * @return list<array{
     *     groupLabel: string,
     *     outputFileName: string,
     *     missingFolderNames: list<string>,
     *     sources: list<array{
     *         matchKey: string,
     *         groupLabel: string,
     *         displayName: string,
     *         entryName?: string,
     *         path?: string
     *     }>
     * }>
     */
    public function planDocumentGroups(
        array $pageFolders,
        ?string $outputPrefix = null,
    ): array {
        $documentKeys = [];

        foreach ($pageFolders as $pageFolder) {
            foreach ($pageFolder['filesByKey'] as $matchKey => $file) {
                $documentKeys[$matchKey] ??= $file['groupLabel'];
            }
        }

        $documentGroups = [];

        foreach (array_keys($documentKeys) as $matchKey) {
            $sources = [];
            $missingFolderNames = [];
            $groupLabel = null;

            foreach ($pageFolders as $pageFolder) {
                $source = $pageFolder['filesByKey'][$matchKey] ?? null;

                if ($source === null) {
                    $missingFolderNames[] = $pageFolder['name'];

                    continue;
                }

                if ($groupLabel === null) {
                    $groupLabel = $source['groupLabel'];
                }

                $sources[] = $source;
            }

            if ($groupLabel === null) {
                continue;
            }

            $documentGroups[] = [
                'groupLabel' => $groupLabel,
                'outputFileName' => $this->prefixedOutputFileName(
                    $groupLabel,
                    $outputPrefix,
                ),
                'missingFolderNames' => $missingFolderNames,
                'sources' => $sources,
            ];
        }

        usort(
            $documentGroups,
            static fn (array $left, array $right): int => [
                Str::lower($left['groupLabel']),
                $left['groupLabel'],
            ] <=> [
                Str::lower($right['groupLabel']),
                $right['groupLabel'],
            ],
        );

        return $documentGroups;
    }

    /**
     * @param  list<array{
     *     groupLabel: string,
     *     outputFileName: string,
     *     missingFolderNames: list<string>,
     *     sources: list<array{
     *         matchKey: string,
     *         groupLabel: string,
     *         displayName: string,
     *         entryName?: string,
     *         path?: string
     *     }>
     * }>  $documentGroups
     * @return array{mergedCount: int, failedCount: int}
     */
    private function processDocumentGroups(
        User $user,
        array $documentGroups,
        string $inputMode,
        string $inputLabel,
        ?string $archivePath = null,
        ?string $footerText = null,
        ?DocMergeBatch $batch = null,
    ): array {
        $temporaryRootPath = storage_path('app/tmp/doc-merge-bulk-'.Str::uuid());
        $zip = null;
        $mergedCount = 0;
        $failedCount = 0;

        File::ensureDirectoryExists($temporaryRootPath);

        if ($archivePath !== null) {
            $zip = new ZipArchive;
            $openResult = $zip->open($archivePath);

            if ($openResult !== true) {
                File::deleteDirectory($temporaryRootPath);

                throw ValidationException::withMessages([
                    'zip' => 'The ZIP file could not be opened.',
                ]);
            }
        }

        try {
            foreach ($documentGroups as $documentGroup) {
                if ($documentGroup['missingFolderNames'] !== []) {
                    $this->createFailure(
                        $user,
                        $inputMode,
                        $inputLabel,
                        $documentGroup['groupLabel'],
                        $documentGroup['outputFileName'],
                        $this->missingPageFolderMessage(
                            $documentGroup['groupLabel'],
                            $documentGroup['missingFolderNames'],
                        ),
                        $batch,
                    );
                    $failedCount++;

                    continue;
                }

                $groupDirectory = $temporaryRootPath.DIRECTORY_SEPARATOR.Str::uuid();
                File::ensureDirectoryExists($groupDirectory);

                try {
                    $sources = $this->materializeSources(
                        $documentGroup['sources'],
                        $groupDirectory,
                        $zip,
                    );

                    $this->pdfMergeService->merge(
                        $user,
                        $sources,
                        $documentGroup['outputFileName'],
                        $footerText,
                        $batch,
                    );

                    $mergedCount++;
                } catch (\Throwable $exception) {
                    $this->createFailure(
                        $user,
                        $inputMode,
                        $inputLabel,
                        $documentGroup['groupLabel'],
                        $documentGroup['outputFileName'],
                        $this->groupMergeFailureMessage($exception),
                        $batch,
                    );
                    $failedCount++;
                } finally {
                    File::deleteDirectory($groupDirectory);
                }
            }
        } finally {
            if ($zip instanceof ZipArchive) {
                $zip->close();
            }

            File::deleteDirectory($temporaryRootPath);
        }

        return [
            'mergedCount' => $mergedCount,
            'failedCount' => $failedCount,
        ];
    }

    /**
     * @param  list<array{
     *     normalizedPath: string,
     *     segments: list<string>,
     *     isDirectory: bool
     * }>  $entries
     */
    private function detectArchiveLayout(array $entries): string
    {
        $rootSegments = [];
        $secondSegments = [];
        $hasRootPageFiles = false;

        foreach ($entries as $entry) {
            $segments = $entry['segments'];
            $depth = count($segments);

            $rootSegments[$segments[0]] = true;

            if ($depth === 2 && ! $entry['isDirectory']) {
                $hasRootPageFiles = true;
            }

            if ($depth >= 2) {
                $secondSegments[$segments[1]] = true;
            }
        }

        if (count($rootSegments) !== 1 || $hasRootPageFiles) {
            return 'root';
        }

        return count($secondSegments) >= 2 ? 'wrapped' : 'root';
    }

    /**
     * @param  list<array{
     *     normalizedPath: string,
     *     segments: list<string>,
     *     isDirectory: bool
     * }>  $entries
     * @return list<array{
     *     name: string,
     *     number: int|string|null,
     *     files: list<array{displayName: string, entryName: string}>
     * }>
     */
    private function collectRootPageFoldersFromArchive(array $entries): array
    {
        $pageFolders = [];

        foreach ($entries as $entry) {
            $segments = $entry['segments'];
            $depth = count($segments);
            $isDirectory = $entry['isDirectory'];

            if ($depth === 1) {
                if (! $isDirectory) {
                    throw ValidationException::withMessages([
                        'zip' => 'The ZIP root must contain only page folders like PAGE 1 and PAGE 2.',
                    ]);
                }

                $pageFolders[$segments[0]] ??= $this->makePageFolderDescriptor($segments[0]);

                continue;
            }

            $pageFolderName = $segments[0];
            $pageFolders[$pageFolderName] ??= $this->makePageFolderDescriptor($pageFolderName);

            if ($depth > 2 || $isDirectory) {
                throw ValidationException::withMessages([
                    'zip' => "Page folder {$pageFolderName} contains nested folders. Only direct PDF files are allowed.",
                ]);
            }

            $fileName = $segments[1];

            if (! $this->isPdfFileName($fileName)) {
                throw ValidationException::withMessages([
                    'zip' => "Page folder {$pageFolderName} contains a non-PDF file: {$fileName}. Only direct PDF files are allowed.",
                ]);
            }

            $pageFolders[$pageFolderName]['files'][] = [
                'displayName' => $fileName,
                'entryName' => $entry['normalizedPath'],
            ];
        }

        return array_values($pageFolders);
    }

    /**
     * @param  list<array{
     *     normalizedPath: string,
     *     segments: list<string>,
     *     isDirectory: bool
     * }>  $entries
     * @return list<array{
     *     name: string,
     *     number: int|string|null,
     *     files: list<array{displayName: string, entryName: string}>
     * }>
     */
    private function collectWrappedPageFoldersFromArchive(array $entries): array
    {
        $pageFolders = [];

        foreach ($entries as $entry) {
            $segments = $entry['segments'];
            $depth = count($segments);
            $isDirectory = $entry['isDirectory'];

            if ($depth === 1) {
                if (! $isDirectory) {
                    throw ValidationException::withMessages([
                        'zip' => 'The ZIP root must contain only page folders like PAGE 1 and PAGE 2.',
                    ]);
                }

                continue;
            }

            if ($depth === 2) {
                if (! $isDirectory) {
                    throw ValidationException::withMessages([
                        'zip' => 'The ZIP wrapper folder must contain only page folders.',
                    ]);
                }

                $pageFolders[$segments[1]] ??= $this->makePageFolderDescriptor($segments[1]);

                continue;
            }

            $pageFolderName = $segments[1];
            $pageFolders[$pageFolderName] ??= $this->makePageFolderDescriptor($pageFolderName);

            if ($depth > 3 || $isDirectory) {
                throw ValidationException::withMessages([
                    'zip' => "Page folder {$pageFolderName} contains nested folders. Only direct PDF files are allowed.",
                ]);
            }

            $fileName = $segments[2];

            if (! $this->isPdfFileName($fileName)) {
                throw ValidationException::withMessages([
                    'zip' => "Page folder {$pageFolderName} contains a non-PDF file: {$fileName}. Only direct PDF files are allowed.",
                ]);
            }

            $pageFolders[$pageFolderName]['files'][] = [
                'displayName' => $fileName,
                'entryName' => $entry['normalizedPath'],
            ];
        }

        return array_values($pageFolders);
    }

    /**
     * @param  list<array{
     *     name: string,
     *     number: int|string|null,
     *     files: list<UploadedFile|array{displayName: string, entryName?: string, path?: string}>,
     *     hasNestedEntries?: bool|int|string|null,
     *     hasInvalidFiles?: bool|int|string|null
     * }>  $pageFolders
     * @return list<array{
     *     name: string,
     *     number: int,
     *     filesByKey: array<string, array{
     *         matchKey: string,
     *         groupLabel: string,
     *         displayName: string,
     *         entryName?: string,
     *         path?: string
     *     }>
     * }>
     */
    private function validatePageFolders(
        array $pageFolders,
        string $errorField,
        int $minPageFolderCount = 2,
    ): array
    {
        if (count($pageFolders) < $minPageFolderCount) {
            throw ValidationException::withMessages([
                $errorField => $minPageFolderCount <= 1
                    ? 'Select at least one page folder like PAGE 1.'
                    : 'Select at least two page folders like PAGE 1 and PAGE 2.',
            ]);
        }

        $normalizedPageFolders = [];
        $pageNumberNames = [];

        foreach ($pageFolders as $pageFolder) {
            $pageFolderName = trim((string) ($pageFolder['name'] ?? ''));

            if ($pageFolderName === '') {
                throw ValidationException::withMessages([
                    $errorField => 'Each page folder needs a name like PAGE 1.',
                ]);
            }

            $pageNumber = $this->pageFolderNumberFromName($pageFolderName);

            if ($pageNumber === null) {
                throw ValidationException::withMessages([
                    $errorField => "Page folder {$pageFolderName} must end with a positive number, like PAGE 1.",
                ]);
            }

            if (
                array_key_exists('number', $pageFolder)
                && $pageFolder['number'] !== null
                && (int) $pageFolder['number'] !== $pageNumber
            ) {
                throw ValidationException::withMessages([
                    $errorField => "Page folder {$pageFolderName} has an invalid page number.",
                ]);
            }

            if ($this->isTruthy($pageFolder['hasNestedEntries'] ?? false)) {
                throw ValidationException::withMessages([
                    $errorField => "Page folder {$pageFolderName} contains nested folders. Only direct PDF files are allowed.",
                ]);
            }

            if ($this->isTruthy($pageFolder['hasInvalidFiles'] ?? false)) {
                throw ValidationException::withMessages([
                    $errorField => "Page folder {$pageFolderName} contains non-PDF files. Only direct PDF files are allowed.",
                ]);
            }

            $rawFiles = array_values(
                array_filter(
                    is_array($pageFolder['files'] ?? null) ? $pageFolder['files'] : [],
                    static fn (mixed $file): bool => $file instanceof UploadedFile || is_array($file),
                ),
            );

            if (
                $rawFiles === []
                && is_array($pageFolder['filesByKey'] ?? null)
            ) {
                $rawFiles = array_values(
                    array_filter(
                        $pageFolder['filesByKey'],
                        static fn (mixed $file): bool => is_array($file),
                    ),
                );
            }

            if ($rawFiles === []) {
                throw ValidationException::withMessages([
                    $errorField => "Page folder {$pageFolderName} must contain at least one direct PDF file.",
                ]);
            }

            $filesByKey = [];

            foreach ($rawFiles as $rawFile) {
                $normalizedFile = $this->normalizePageFolderFile(
                    $rawFile,
                    $pageFolderName,
                    $pageNumber,
                    $errorField,
                );
                $matchKey = $normalizedFile['matchKey'];

                if (isset($filesByKey[$matchKey])) {
                    throw ValidationException::withMessages([
                        $errorField => "Page folder {$pageFolderName} contains duplicate PDF files. Both {$filesByKey[$matchKey]['displayName']} and {$normalizedFile['displayName']} match the same document name.",
                    ]);
                }

                $filesByKey[$matchKey] = $normalizedFile;
            }

            if (isset($pageNumberNames[$pageNumber])) {
                throw ValidationException::withMessages([
                    $errorField => "Page folder numbers must be unique. {$pageNumberNames[$pageNumber]} and {$pageFolderName} both use {$pageNumber}.",
                ]);
            }

            $pageNumberNames[$pageNumber] = $pageFolderName;
            $normalizedPageFolders[] = [
                'name' => $pageFolderName,
                'number' => $pageNumber,
                'filesByKey' => $filesByKey,
            ];
        }

        usort(
            $normalizedPageFolders,
            static fn (array $left, array $right): int => [
                $left['number'],
                Str::lower($left['name']),
                $left['name'],
            ] <=> [
                $right['number'],
                Str::lower($right['name']),
                $right['name'],
            ],
        );

        return $normalizedPageFolders;
    }

    /**
     * @param  UploadedFile|array{displayName: string, entryName?: string, path?: string}  $rawFile
     * @return array{
     *     matchKey: string,
     *     groupLabel: string,
     *     displayName: string,
     *     entryName?: string,
     *     path?: string
     * }
     */
    private function normalizePageFolderFile(
        UploadedFile|array $rawFile,
        string $pageFolderName,
        int $pageNumber,
        string $errorField,
    ): array {
        if ($rawFile instanceof UploadedFile) {
            $path = $rawFile->getRealPath();

            if ($path === false || ! is_file($path)) {
                throw ValidationException::withMessages([
                    $errorField => "One of the PDFs in {$pageFolderName} is no longer available.",
                ]);
            }

            $displayName = $rawFile->getClientOriginalName();

            if (! $this->isPdfFileName($displayName)) {
                throw ValidationException::withMessages([
                    $errorField => "Page folder {$pageFolderName} contains non-PDF files. Only direct PDF files are allowed.",
                ]);
            }

            $this->ensureFilePageNumberMatchesFolder(
                $displayName,
                $pageFolderName,
                $pageNumber,
                $errorField,
            );

            return [
                'matchKey' => $this->fileMatchKey($displayName),
                'groupLabel' => $this->groupLabelFromFileName($displayName),
                'displayName' => $displayName,
                'path' => $path,
            ];
        }

        $displayName = $rawFile['displayName'] ?? null;
        $entryName = $rawFile['entryName'] ?? null;
        $path = $rawFile['path'] ?? null;

        if (! is_string($displayName) || ! $this->isPdfFileName($displayName)) {
            throw ValidationException::withMessages([
                $errorField => "Page folder {$pageFolderName} contains non-PDF files. Only direct PDF files are allowed.",
            ]);
        }

        $this->ensureFilePageNumberMatchesFolder(
            $displayName,
            $pageFolderName,
            $pageNumber,
            $errorField,
        );

        if ($entryName !== null && ! is_string($entryName)) {
            throw ValidationException::withMessages([
                $errorField => "One of the PDFs in {$pageFolderName} could not be read from the ZIP file.",
            ]);
        }

        if ($path !== null && (! is_string($path) || ! is_file($path))) {
            throw ValidationException::withMessages([
                $errorField => "One of the PDFs in {$pageFolderName} is no longer available.",
            ]);
        }

        return array_filter([
            'matchKey' => $this->fileMatchKey($displayName),
            'groupLabel' => $this->groupLabelFromFileName($displayName),
            'displayName' => $displayName,
            'entryName' => $entryName,
            'path' => $path,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<array{
     *     matchKey: string,
     *     groupLabel: string,
     *     displayName: string,
     *     entryName?: string,
     *     path?: string
     * }>  $sources
     * @return list<array{path: string, displayName: string}>
     */
    private function materializeSources(
        array $sources,
        string $destinationDirectory,
        ?ZipArchive $zip,
    ): array {
        $materializedSources = [];

        foreach ($sources as $source) {
            if (isset($source['path'])) {
                $materializedSources[] = [
                    'path' => $source['path'],
                    'displayName' => $source['displayName'],
                ];

                continue;
            }

            if (! isset($source['entryName']) || ! $zip instanceof ZipArchive) {
                throw new RuntimeException('One of the selected PDF sources is no longer available.');
            }

            $destinationPath = $destinationDirectory.DIRECTORY_SEPARATOR.Str::uuid().'.pdf';
            $this->copyEntryToPath($zip, $source['entryName'], $destinationPath);

            $materializedSources[] = [
                'path' => $destinationPath,
                'displayName' => $source['displayName'],
            ];
        }

        return $materializedSources;
    }

    private function copyEntryToPath(
        ZipArchive $zip,
        string $entryName,
        string $destinationPath,
    ): void {
        $sourceStream = $zip->getStream($entryName);

        if ($sourceStream === false) {
            throw new RuntimeException("The ZIP file entry {$entryName} could not be read.");
        }

        $destinationStream = fopen($destinationPath, 'wb');

        if ($destinationStream === false) {
            fclose($sourceStream);

            throw new RuntimeException('A temporary PDF file could not be created.');
        }

        try {
            if (stream_copy_to_stream($sourceStream, $destinationStream) === false) {
                throw new RuntimeException("The ZIP file entry {$entryName} could not be extracted.");
            }
        } finally {
            fclose($sourceStream);
            fclose($destinationStream);
        }
    }

    /**
     * @return array{
     *     name: string,
     *     number: int|string|null,
     *     files: list<array{displayName: string, entryName: string}>
     * }
     */
    private function makePageFolderDescriptor(string $pageFolderName): array
    {
        return [
            'name' => $pageFolderName,
            'number' => null,
            'files' => [],
        ];
    }

    private function prefixedOutputFileName(
        string $baseFileName,
        ?string $outputPrefix,
    ): string {
        $prefix = (string) $outputPrefix;

        return $prefix !== '' ? $prefix.$baseFileName : $baseFileName;
    }

    private function pageFolderNumberFromName(string $pageFolderName): ?int
    {
        $trimmedName = trim($pageFolderName);

        if (! preg_match('/(\d+)$/', $trimmedName, $matches)) {
            return null;
        }

        $pageNumber = (int) $matches[1];

        return $pageNumber > 0 ? $pageNumber : null;
    }

    private function fileMatchKey(string $fileName): string
    {
        return Str::lower($this->groupLabelFromFileName($fileName));
    }

    private function isPdfFileName(string $fileName): bool
    {
        return Str::of($fileName)->lower()->endsWith('.pdf');
    }

    private function ensureFilePageNumberMatchesFolder(
        string $fileName,
        string $pageFolderName,
        int $pageNumber,
        string $errorField,
    ): void {
        $filePageNumber = $this->filePageNumberFromName($fileName);

        if ($filePageNumber === null || $filePageNumber === $pageNumber) {
            return;
        }

        throw ValidationException::withMessages([
            $errorField => "PDF {$fileName} in {$pageFolderName} must end with {$pageNumber} to match its page folder.",
        ]);
    }

    private function groupLabelFromFileName(string $fileName): string
    {
        $extension = Str::of(pathinfo($fileName, PATHINFO_EXTENSION))
            ->lower()
            ->value();
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);

        if (
            preg_match('/^(?<label>.*\D)(?<number>\d+)$/u', $baseName, $matches) === 1
            && ($matches['number'] ?? '') !== ''
        ) {
            $label = rtrim((string) $matches['label'], " \t\n\r\0\x0B._-");

            if ($label !== '') {
                return "{$label}.{$extension}";
            }
        }

        return $fileName;
    }

    private function filePageNumberFromName(string $fileName): ?int
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);

        if (
            preg_match('/^(?<label>.*\D)(?<number>\d+)$/u', $baseName, $matches) !== 1
            || ($matches['number'] ?? '') === ''
        ) {
            return null;
        }

        $pageNumber = (int) $matches['number'];

        return $pageNumber > 0 ? $pageNumber : null;
    }

    /**
     * @param  list<string>  $segments
     */
    private function hasUnsafeSegments(array $segments): bool
    {
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function isTruthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * @param  list<string>  $missingFolderNames
     */
    private function missingPageFolderMessage(
        string $groupLabel,
        array $missingFolderNames,
    ): string {
        return sprintf(
            'The PDF %s is missing from %s.',
            $groupLabel,
            $this->humanReadableList($missingFolderNames),
        );
    }

    /**
     * @param  list<string>  $values
     */
    private function humanReadableList(array $values): string
    {
        $values = array_values($values);

        if (count($values) <= 1) {
            return $values[0] ?? 'the selected page folders';
        }

        if (count($values) === 2) {
            return "{$values[0]} and {$values[1]}";
        }

        $lastValue = array_pop($values);

        return implode(', ', $values).", and {$lastValue}";
    }

    private function groupMergeFailureMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message !== ''
            ? $message
            : 'This PDF could not be merged right now.';
    }

    private function createFailure(
        User $user,
        string $inputMode,
        string $inputLabel,
        string $groupLabel,
        string $outputFileName,
        string $errorMessage,
        ?DocMergeBatch $batch = null,
    ): BulkMergeFailure {
        return BulkMergeFailure::query()->create([
            'user_id' => $user->id,
            'doc_merge_batch_id' => $batch?->id,
            'input_mode' => $inputMode,
            'input_label' => $inputLabel,
            'group_label' => $groupLabel,
            'output_file_name' => $outputFileName,
            'error_message' => $errorMessage,
        ]);
    }
}
