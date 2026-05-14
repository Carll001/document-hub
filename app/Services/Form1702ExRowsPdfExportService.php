<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Form1702ExBatchRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ZipArchive;

class Form1702ExRowsPdfExportService
{
    private const CACHE_TTL_SECONDS = 21600;
    private const ACTIVE_STATE_MAX_AGE_SECONDS = 900;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_FAILED = 'failed';

    public const STATUS_READY = 'ready';
    public const CANCEL_MESSAGE = 'Export cancelled by user.';

    public function cacheKey(int $userId): string
    {
        return "forms:1702-ex:rows-pdf-export:{$userId}";
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
            self::STATUS_CANCELLING,
            self::STATUS_FAILED,
            self::STATUS_READY,
        ], true)) {
            return $this->emptyState();
        }

        if (in_array($status, [self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_CANCELLING], true)) {
            $updatedAtRaw = $state['updatedAt'] ?? null;
            $updatedAt = is_string($updatedAtRaw) ? strtotime($updatedAtRaw) : false;

            if (! is_int($updatedAt) || $updatedAt <= 0 || (time() - $updatedAt) > self::ACTIVE_STATE_MAX_AGE_SECONDS) {
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
            'downloadScopeLabel' => is_string($state['downloadScopeLabel'] ?? null) ? $state['downloadScopeLabel'] : null,
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

    public function requestCancel(int $userId): bool
    {
        $state = Cache::get($this->cacheKey($userId));
        if (! is_array($state)) {
            return false;
        }

        $status = $state['status'] ?? null;
        if (! in_array($status, [self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_CANCELLING], true)) {
            return false;
        }

        $state['status'] = self::STATUS_CANCELLING;
        $state['cancelRequested'] = true;
        $state['updatedAt'] = now()->toIso8601String();
        Cache::put($this->cacheKey($userId), $state, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return true;
    }

    public function cancellationRequested(int $userId): bool
    {
        $state = Cache::get($this->cacheKey($userId));
        return is_array($state) && ($state['cancelRequested'] ?? false) === true;
    }

    /**
     * @return array{storagePath: string, downloadFileName: string, rowCount: int}
     */
    public function buildZipFromQuery(Builder $rowsQuery, int $userId, int $chunkSize = 10): array
    {
        $directory = "tmp/form-1702-ex-rows-pdf-exports/user-{$userId}";
        $disk = \App\Support\DocumentStorage::disk();
        $disk->makeDirectory($directory);

        $fileName = '1702-ex-imported-rows-pdfs-'.Str::uuid().'.zip';
        $storagePath = "{$directory}/{$fileName}";
        $temporaryZipPath = storage_path('app/tmp/form-1702-ex-imported-rows-pdfs-'.Str::uuid().'.zip');

        if (! is_dir(dirname($temporaryZipPath))) {
            mkdir(dirname($temporaryZipPath), 0777, true);
        }

        $archive = new ZipArchive;

        if ($archive->open($temporaryZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The imported rows PDF ZIP could not be created.');
        }

        $usedPaths = [];
        $includedRows = 0;
        $chunkSize = max(1, $chunkSize);

        $temporaryEntryPaths = [];

        try {
            $rowsQuery
                ->clone()
                ->reorder()
                ->select('id', 'uuid', 'pdf_status', 'generated_pdf_storage_path', 'generated_pdf_file_name')
                ->chunkById($chunkSize, function ($rows) use (&$usedPaths, &$includedRows, &$temporaryEntryPaths, $archive, $disk, $userId): void {
                    if ($this->cancellationRequested($userId)) {
                        throw new \RuntimeException(self::CANCEL_MESSAGE);
                    }

                    foreach ($rows as $row) {
                        $pdfStoragePath = (string) ($row->generated_pdf_storage_path ?? '');

                        if (
                            $row->pdf_status !== Form1702ExBatchRow::PDF_STATUS_GENERATED
                            || $pdfStoragePath === ''
                            || ! \App\Support\DocumentStorage::exists($pdfStoragePath)
                        ) {
                            continue;
                        }

                        $zipPath = $this->uniqueZipPath(
                            (string) ($row->generated_pdf_file_name ?? "imported-row-{$row->uuid}.pdf"),
                            $usedPaths,
                        );

                        $stream = $disk->readStream($pdfStoragePath);
                        if (! is_resource($stream)) {
                            continue;
                        }

                        $localEntryPath = tempnam(sys_get_temp_dir(), 'f1702pdf-');
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
                }, 'id');
        } finally {
            $archive->close();

            foreach ($temporaryEntryPaths as $temporaryEntryPath) {
                if (is_string($temporaryEntryPath) && is_file($temporaryEntryPath)) {
                    @unlink($temporaryEntryPath);
                }
            }
        }

        if ($includedRows === 0) {
            @unlink($temporaryZipPath);

            throw new \RuntimeException('No imported rows with generated PDFs matched this export request.');
        }

        try {
            $stream = fopen($temporaryZipPath, 'rb');

            if (! is_resource($stream)) {
                throw new \RuntimeException('The imported rows PDF ZIP could not be created.');
            }

            try {
                if (! $disk->put($storagePath, $stream)) {
                    throw new \RuntimeException('The imported rows PDF ZIP could not be stored.');
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
            'downloadFileName' => '1702-ex-imported-rows-pdfs.zip',
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
            $name = 'imported-row.pdf';
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
}
