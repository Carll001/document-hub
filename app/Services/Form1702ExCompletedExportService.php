<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Form1702ExBatchRow;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ZipArchive;

class Form1702ExCompletedExportService
{
    private const CACHE_TTL_SECONDS = 21600;
    private const ACTIVE_STATE_MAX_AGE_SECONDS = 7200;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    public const STATUS_READY = 'ready';

    public function cacheKey(int $userId): string
    {
        return "forms:1702-ex:completed-export:{$userId}";
    }

    public function getState(int $userId): array
    {
        $state = Cache::get($this->cacheKey($userId));

        if (! is_array($state)) {
            return $this->emptyState();
        }

        $status = $state['status'] ?? null;

        if (! in_array($status, [
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
            self::STATUS_FAILED,
            self::STATUS_READY,
        ], true)) {
            return $this->emptyState();
        }

        if (in_array($status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true)) {
            $batchId = is_string($state['batchId'] ?? null) ? trim((string) $state['batchId']) : '';

            if ($batchId !== '') {
                $batch = Bus::findBatch($batchId);

                if ($batch === null) {
                    if ($this->isStateStale($state)) {
                        $this->forgetState($userId);

                        return $this->emptyState();
                    }
                } elseif ($batch->cancelled() || ($batch->finished() && $batch->hasFailures())) {
                    $this->putState($userId, [
                        'status' => self::STATUS_FAILED,
                        'error' => 'The completed files ZIP could not be prepared right now.',
                        'rowCount' => null,
                        'downloadUrl' => null,
                        'storagePath' => null,
                        'batchId' => $batch->id,
                    ]);

                    return [
                        'status' => self::STATUS_FAILED,
                        'error' => 'The completed files ZIP could not be prepared right now.',
                        'rowCount' => null,
                        'downloadUrl' => null,
                    ];
                }
            } elseif ($this->isStateStale($state)) {
                $this->forgetState($userId);

                return $this->emptyState();
            }
        }

        if ($status === self::STATUS_READY) {
            $storagePath = is_string($state['storagePath'] ?? null) ? $state['storagePath'] : null;

            if ($storagePath === null || ! \App\Support\DocumentStorage::exists($storagePath)) {
                $this->forgetState($userId);

                return $this->emptyState();
            }
        }

        return [
            'status' => $status,
            'error' => is_string($state['error'] ?? null) ? $state['error'] : null,
            'rowCount' => is_numeric($state['rowCount'] ?? null) ? (int) $state['rowCount'] : null,
            'downloadUrl' => is_string($state['downloadUrl'] ?? null) ? $state['downloadUrl'] : null,
        ];
    }

    /**
     * @param  array{status: string, error?: string|null, rowCount?: int|null, downloadUrl?: string|null, storagePath?: string|null}  $state
     */
    public function putState(int $userId, array $state): void
    {
        $state['updatedAt'] = now()->toIso8601String();

        Cache::put($this->cacheKey($userId), $state, now()->addSeconds(self::CACHE_TTL_SECONDS));
    }

    public function forgetState(int $userId): void
    {
        $cached = Cache::get($this->cacheKey($userId));

        if (is_array($cached)) {
            $storagePath = $cached['storagePath'] ?? null;

            if (is_string($storagePath) && $storagePath !== '') {
                \App\Support\DocumentStorage::disk()->delete($storagePath);
            }
        }

        Cache::forget($this->cacheKey($userId));
    }

    public function currentBatchId(int $userId): ?string
    {
        $state = Cache::get($this->cacheKey($userId));
        if (! is_array($state)) {
            return null;
        }

        $batchId = $state['batchId'] ?? null;

        return is_string($batchId) && $batchId !== '' ? $batchId : null;
    }

    /**
     * @param  list<int>  $rowIds
     * @return array{storagePath: string, rowCount: int}
     */
    public function buildChunkZipFromRowIds(int $userId, string $exportRunId, int $chunkIndex, array $rowIds): array
    {
        $directory = $this->chunkDirectory($userId, $exportRunId);
        $disk = \App\Support\DocumentStorage::disk();
        $disk->makeDirectory($directory);

        $chunkStoragePath = sprintf('%s/chunk-%05d.zip', $directory, $chunkIndex + 1);
        $temporaryZipPath = storage_path('app/tmp/form-1702-ex-completed-chunk-'.Str::uuid().'.zip');

        if (! is_dir(dirname($temporaryZipPath))) {
            mkdir(dirname($temporaryZipPath), 0777, true);
        }

        $archive = new ZipArchive;
        if ($archive->open($temporaryZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The completed files ZIP could not be created.');
        }

        $rows = Form1702ExBatchRow::query()
            ->whereIn('id', $rowIds)
            ->where('pdf_status', Form1702ExBatchRow::PDF_STATUS_GENERATED)
            ->whereNotNull('generated_pdf_storage_path')
            ->get(['id', 'uuid', 'generated_pdf_storage_path', 'generated_pdf_file_name']);

        $usedPaths = [];
        $includedRows = 0;
        $temporaryEntryPaths = [];

        foreach ($rows as $row) {
            $pdfStoragePath = (string) ($row->generated_pdf_storage_path ?? '');
            if ($pdfStoragePath === '' || ! \App\Support\DocumentStorage::exists($pdfStoragePath)) {
                continue;
            }

            $zipPath = $this->uniqueZipPath(
                (string) ($row->generated_pdf_file_name ?? "completed-row-{$row->uuid}.pdf"),
                $usedPaths,
            );

            $stream = $disk->readStream($pdfStoragePath);
            if (! is_resource($stream)) {
                continue;
            }

            $localEntryPath = tempnam(sys_get_temp_dir(), 'f1702chunk-');
            if ($localEntryPath === false) {
                fclose($stream);
                continue;
            }

            $target = @fopen($localEntryPath, 'wb');
            if (! is_resource($target)) {
                fclose($stream);
                @unlink($localEntryPath);
                continue;
            }

            try {
                stream_copy_to_stream($stream, $target);
            } finally {
                fclose($stream);
                fclose($target);
            }

            if ((@filesize($localEntryPath) ?: 0) <= 0) {
                @unlink($localEntryPath);
                continue;
            }

            if (! $archive->addFile($localEntryPath, $zipPath)) {
                @unlink($localEntryPath);
                continue;
            }

            $temporaryEntryPaths[] = $localEntryPath;
            $includedRows++;
        }

        $archive->close();

        foreach ($temporaryEntryPaths as $temporaryEntryPath) {
            if (is_string($temporaryEntryPath) && is_file($temporaryEntryPath)) {
                @unlink($temporaryEntryPath);
            }
        }

        if ($includedRows <= 0) {
            @unlink($temporaryZipPath);

            return ['storagePath' => $chunkStoragePath, 'rowCount' => 0];
        }

        try {
            $stream = fopen($temporaryZipPath, 'rb');
            if (! is_resource($stream)) {
                throw new \RuntimeException('The completed files ZIP could not be created.');
            }

            try {
                if (! $disk->put($chunkStoragePath, $stream)) {
                    throw new \RuntimeException('The completed files ZIP could not be stored.');
                }
            } finally {
                fclose($stream);
            }
        } finally {
            if (is_file($temporaryZipPath)) {
                @unlink($temporaryZipPath);
            }
        }

        return ['storagePath' => $chunkStoragePath, 'rowCount' => $includedRows];
    }

    /**
     * @return array{storagePath: string, downloadFileName: string, rowCount: int}
     */
    public function buildZipFromChunkArchives(int $userId, string $exportRunId): array
    {
        $directory = "tmp/form-1702-ex-completed-exports/user-{$userId}";
        $chunkDirectory = $this->chunkDirectory($userId, $exportRunId);
        $disk = \App\Support\DocumentStorage::disk();
        $disk->makeDirectory($directory);

        $chunkPaths = collect($disk->files($chunkDirectory))
            ->filter(static fn (string $path): bool => str_ends_with($path, '.zip'))
            ->sort()
            ->values()
            ->all();

        if ($chunkPaths === []) {
            throw new \RuntimeException('No completed files matched this export request.');
        }

        $fileName = '1702-ex-completed-files-'.Str::uuid().'.zip';
        $storagePath = "{$directory}/{$fileName}";
        $temporaryZipPath = storage_path('app/tmp/form-1702-ex-completed-files-'.Str::uuid().'.zip');
        if (! is_dir(dirname($temporaryZipPath))) {
            mkdir(dirname($temporaryZipPath), 0777, true);
        }

        $archive = new ZipArchive;
        if ($archive->open($temporaryZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The completed files ZIP could not be created.');
        }

        $usedPaths = [];
        $includedRows = 0;
        $temporaryEntryPaths = [];

        foreach ($chunkPaths as $chunkPath) {
            $chunkStream = $disk->readStream($chunkPath);
            if (! is_resource($chunkStream)) {
                continue;
            }

            $localChunkPath = tempnam(sys_get_temp_dir(), 'f1702chunkzip-');
            if ($localChunkPath === false) {
                fclose($chunkStream);
                continue;
            }

            $target = @fopen($localChunkPath, 'wb');
            if (! is_resource($target)) {
                fclose($chunkStream);
                @unlink($localChunkPath);
                continue;
            }

            try {
                stream_copy_to_stream($chunkStream, $target);
            } finally {
                fclose($chunkStream);
                fclose($target);
            }

            $chunkArchive = new ZipArchive;
            if ($chunkArchive->open($localChunkPath) !== true) {
                @unlink($localChunkPath);
                continue;
            }

            try {
                for ($index = 0; $index < $chunkArchive->numFiles; $index++) {
                    $stat = $chunkArchive->statIndex($index);
                    if (! is_array($stat) || ! is_string($stat['name'] ?? null) || str_ends_with((string) $stat['name'], '/')) {
                        continue;
                    }

                    $entryStream = $chunkArchive->getStream((string) $stat['name']);
                    if (! is_resource($entryStream)) {
                        continue;
                    }

                    $localEntryPath = tempnam(sys_get_temp_dir(), 'f1702merge-');
                    if ($localEntryPath === false) {
                        fclose($entryStream);
                        continue;
                    }

                    $entryTarget = @fopen($localEntryPath, 'wb');
                    if (! is_resource($entryTarget)) {
                        fclose($entryStream);
                        @unlink($localEntryPath);
                        continue;
                    }

                    try {
                        stream_copy_to_stream($entryStream, $entryTarget);
                    } finally {
                        fclose($entryStream);
                        fclose($entryTarget);
                    }

                    if ((@filesize($localEntryPath) ?: 0) <= 0) {
                        @unlink($localEntryPath);
                        continue;
                    }

                    $zipPath = $this->uniqueZipPath((string) $stat['name'], $usedPaths);
                    if (! $archive->addFile($localEntryPath, $zipPath)) {
                        @unlink($localEntryPath);
                        continue;
                    }

                    $temporaryEntryPaths[] = $localEntryPath;
                    $includedRows++;
                }
            } finally {
                $chunkArchive->close();
                @unlink($localChunkPath);
            }
        }

        $archive->close();

        foreach ($temporaryEntryPaths as $temporaryEntryPath) {
            if (is_string($temporaryEntryPath) && is_file($temporaryEntryPath)) {
                @unlink($temporaryEntryPath);
            }
        }

        if ($includedRows === 0) {
            @unlink($temporaryZipPath);
            throw new \RuntimeException('No completed files were available to add to the ZIP.');
        }

        try {
            $stream = fopen($temporaryZipPath, 'rb');
            if (! is_resource($stream)) {
                throw new \RuntimeException('The completed files ZIP could not be created.');
            }

            try {
                if (! $disk->put($storagePath, $stream)) {
                    throw new \RuntimeException('The completed files ZIP could not be stored.');
                }
            } finally {
                fclose($stream);
            }
        } finally {
            if (is_file($temporaryZipPath)) {
                @unlink($temporaryZipPath);
            }
        }

        return [
            'storagePath' => $storagePath,
            'downloadFileName' => '1702-ex-completed-files.zip',
            'rowCount' => $includedRows,
        ];
    }

    public function cleanupChunkArchives(int $userId, string $exportRunId): void
    {
        \App\Support\DocumentStorage::disk()->deleteDirectory($this->chunkDirectory($userId, $exportRunId));
    }

    /**
     * @param  Collection<int, Form1702ExBatchRow>  $rows
     * @return array{storagePath: string, downloadFileName: string, rowCount: int}
     */
    public function buildZip(Collection $rows, int $userId): array
    {
        $directory = "tmp/form-1702-ex-completed-exports/user-{$userId}";
        $disk = \App\Support\DocumentStorage::disk();
        $disk->makeDirectory($directory);

        $fileName = '1702-ex-completed-files-'.Str::uuid().'.zip';
        $storagePath = "{$directory}/{$fileName}";
        $temporaryZipPath = storage_path('app/tmp/form-1702-ex-completed-files-'.Str::uuid().'.zip');

        if (! is_dir(dirname($temporaryZipPath))) {
            mkdir(dirname($temporaryZipPath), 0777, true);
        }

        $archive = new ZipArchive;

        if ($archive->open($temporaryZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The completed files ZIP could not be created.');
        }

        $usedPaths = [];
        $includedRows = 0;
        $temporaryEntryPaths = [];

        foreach ($rows as $row) {
            $pdfStoragePath = (string) ($row->generated_pdf_storage_path ?? '');

            if ($pdfStoragePath === '' || ! \App\Support\DocumentStorage::exists($pdfStoragePath)) {
                continue;
            }

            $zipPath = $this->uniqueZipPath(
                (string) ($row->generated_pdf_file_name ?? "completed-row-{$row->uuid}.pdf"),
                $usedPaths,
            );

            $stream = $disk->readStream($pdfStoragePath);
            if (! is_resource($stream)) {
                continue;
            }

            $localEntryPath = tempnam(sys_get_temp_dir(), 'f1702completed-');
            if ($localEntryPath === false) {
                fclose($stream);
                continue;
            }

            $target = @fopen($localEntryPath, 'wb');
            if (! is_resource($target)) {
                fclose($stream);
                @unlink($localEntryPath);
                continue;
            }

            try {
                stream_copy_to_stream($stream, $target);
            } finally {
                fclose($stream);
                fclose($target);
            }

            if ((@filesize($localEntryPath) ?: 0) <= 0) {
                @unlink($localEntryPath);
                continue;
            }

            if (! $archive->addFile($localEntryPath, $zipPath)) {
                @unlink($localEntryPath);
                continue;
            }

            $temporaryEntryPaths[] = $localEntryPath;
            $includedRows++;
        }

        $archive->close();

        foreach ($temporaryEntryPaths as $temporaryEntryPath) {
            if (is_string($temporaryEntryPath) && is_file($temporaryEntryPath)) {
                @unlink($temporaryEntryPath);
            }
        }

        if ($includedRows === 0) {
            @unlink($temporaryZipPath);

            throw new \RuntimeException('No completed files were available to add to the ZIP.');
        }

        try {
            $stream = fopen($temporaryZipPath, 'rb');

            if (! is_resource($stream)) {
                throw new \RuntimeException('The completed files ZIP could not be created.');
            }

            try {
                if (! $disk->put($storagePath, $stream)) {
                    throw new \RuntimeException('The completed files ZIP could not be stored.');
                }
            } finally {
                fclose($stream);
            }
        } finally {
            if (is_file($temporaryZipPath)) {
                @unlink($temporaryZipPath);
            }
        }

        return [
            'storagePath' => $storagePath,
            'downloadFileName' => '1702-ex-completed-files.zip',
            'rowCount' => $includedRows,
        ];
    }

    /**
     * @return array{status: null, error: null, rowCount: null, downloadUrl: null}
     */
    private function emptyState(): array
    {
        return [
            'status' => null,
            'error' => null,
            'rowCount' => null,
            'downloadUrl' => null,
        ];
    }

    private function uniqueZipPath(string $fileName, array &$usedPaths): string
    {
        $name = Str::of($fileName)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-._')
            ->value();

        if ($name === '') {
            $name = 'completed-file.pdf';
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $baseName = pathinfo($name, PATHINFO_FILENAME);
        $candidate = $name;
        $suffix = 2;

        while (isset($usedPaths[mb_strtolower($candidate)])) {
            $candidate = $extension !== ''
                ? "{$baseName}-{$suffix}.{$extension}"
                : "{$baseName}-{$suffix}";
            $suffix++;
        }

        $usedPaths[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    private function chunkDirectory(int $userId, string $exportRunId): string
    {
        return "tmp/form-1702-ex-completed-exports/user-{$userId}/chunks/{$exportRunId}";
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function isStateStale(array $state): bool
    {
        $updatedAtRaw = $state['updatedAt'] ?? null;
        $updatedAt = is_string($updatedAtRaw) ? strtotime($updatedAtRaw) : false;

        if (! is_int($updatedAt) || $updatedAt <= 0) {
            return true;
        }

        return (time() - $updatedAt) > self::ACTIVE_STATE_MAX_AGE_SECONDS;
    }
}
