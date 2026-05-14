<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AfsFilingItem;
use App\Support\DocumentStorage;
use App\Support\FormFieldAliasResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ZipArchive;

class DocumentGeneratorCompletedExportService
{
    private const CACHE_TTL_SECONDS = 21600;
    public const CANCEL_MESSAGE = 'Export cancelled by user.';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_FAILED = 'failed';

    public const STATUS_READY = 'ready';

    public function cacheKey(int $userId): string
    {
        return "afs_filing:completed-export:{$userId}";
    }

    /**
     * @return array{
     *     status: string|null,
     *     error: string|null,
     *     itemCount: int|null,
     *     downloadUrl: string|null,
     *     expiresAt: string|null
     * }
     */
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

        if ($status === self::STATUS_READY) {
            $expiresAt = is_string($state['expiresAt'] ?? null) ? $state['expiresAt'] : null;
            if ($expiresAt !== null) {
                try {
                    if (now()->greaterThanOrEqualTo(\Carbon\CarbonImmutable::parse($expiresAt))) {
                        $this->forgetState($userId);

                        return $this->emptyState();
                    }
                } catch (\Throwable) {
                    // Ignore malformed expiry and continue with storage checks.
                }
            }

            $storagePath = is_string($state['storagePath'] ?? null) ? $state['storagePath'] : null;
            if ($storagePath === null || ! \App\Support\DocumentStorage::disk()->exists($storagePath)) {
                $this->forgetState($userId);

                return $this->emptyState();
            }
        }

        return [
            'status' => $status,
            'error' => is_string($state['error'] ?? null) ? $state['error'] : null,
            'itemCount' => is_numeric($state['itemCount'] ?? null) ? (int) $state['itemCount'] : null,
            'downloadUrl' => is_string($state['downloadUrl'] ?? null) ? $state['downloadUrl'] : null,
            'expiresAt' => is_string($state['expiresAt'] ?? null) ? $state['expiresAt'] : null,
        ];
    }

    /**
     * @param  array{
     *     status: string,
     *     error?: string|null,
     *     itemCount?: int|null,
     *     downloadUrl?: string|null,
     *     storagePath?: string|null
     * }  $state
     */
    public function putState(int $userId, array $state): void
    {
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
        Cache::put($this->cacheKey($userId), $state, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return true;
    }

    public function cancellationRequested(int $userId): bool
    {
        $state = Cache::get($this->cacheKey($userId));
        return is_array($state) && ($state['cancelRequested'] ?? false) === true;
    }

    /**
     * @return array{storagePath: string, downloadFileName: string, itemCount: int}
     */
    public function buildZipFromQuery(Builder $query, int $userId, int $chunkSize = 10): array
    {
        $directory = "tmp/afs_filing-completed-exports/user-{$userId}";
        DocumentStorage::disk()->makeDirectory($directory);

        $fileName = 'afs_filing-completed-files-'.Str::uuid().'.zip';
        $storagePath = "{$directory}/{$fileName}";
        $localArchivePath = tempnam(sys_get_temp_dir(), 'afs-filing-zip-');
        if ($localArchivePath === false) {
            throw new \RuntimeException('The completed files ZIP could not be prepared.');
        }

        $archive = new ZipArchive;
        if ($archive->open($localArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($localArchivePath);
            throw new \RuntimeException('The completed files ZIP could not be created.');
        }

        $usedPaths = [];
        $disk = DocumentStorage::disk();
        $includedItems = 0;

        $temporaryEntryPaths = [];

        try {
            $query
                ->clone()
                ->reorder()
                ->select(['id', 'row_number', 'row_data', 'pdf_path'])
                ->chunkById(max(1, $chunkSize), function ($items) use ($disk, $archive, &$usedPaths, &$includedItems, &$temporaryEntryPaths, $userId): void {
                    if ($this->cancellationRequested($userId)) {
                        throw new \RuntimeException(self::CANCEL_MESSAGE);
                    }

                    foreach ($items as $item) {
                        $pdfStoragePath = (string) ($item->pdf_path ?? '');
                        if ($pdfStoragePath === '' || ! $disk->exists($pdfStoragePath)) {
                            continue;
                        }

                        $stream = $disk->readStream($pdfStoragePath);
                        if (! is_resource($stream)) {
                            continue;
                        }

                        $localEntryPath = tempnam(sys_get_temp_dir(), 'afspdf-');
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

                        $zipPath = $this->uniqueZipPath($this->zipEntryFileName($item), $usedPaths);
                        if (! $archive->addFile($localEntryPath, $zipPath)) {
                            @unlink($localEntryPath);
                            continue;
                        }

                        $temporaryEntryPaths[] = $localEntryPath;
                        $includedItems++;
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

        if ($includedItems === 0) {
            @unlink($localArchivePath);
            throw new \RuntimeException('No completed files were available to add to the ZIP.');
        }

        $zipStream = @fopen($localArchivePath, 'rb');
        if (! is_resource($zipStream)) {
            @unlink($localArchivePath);
            throw new \RuntimeException('The completed files ZIP could not be read.');
        }

        try {
            $disk->writeStream($storagePath, $zipStream);
        } finally {
            fclose($zipStream);
            @unlink($localArchivePath);
        }

        return [
            'storagePath' => $storagePath,
            'downloadFileName' => 'afs_filing-completed-files.zip',
            'itemCount' => $includedItems,
        ];
    }

    /**
     * @return array{
     *     status: null,
     *     error: null,
     *     itemCount: null,
     *     downloadUrl: null,
     *     expiresAt: null
     * }
     */
    private function emptyState(): array
    {
        return [
            'status' => null,
            'error' => null,
            'itemCount' => null,
            'downloadUrl' => null,
            'expiresAt' => null,
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
            $name = 'afs_filing-completed-file.pdf';
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

    private function zipEntryFileName(AfsFilingItem $item): string
    {
        $rowData = is_array($item->row_data) ? $item->row_data : [];
        $company = FormFieldAliasResolver::resolveCompany($rowData, FormFieldAliasResolver::FORM_AFS);
        $raw = is_string($company) ? trim($company) : '';

        $normalized = Str::of($raw)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-._')
            ->value();

        $base = $normalized !== '' ? "{$normalized}-AFS" : "afs-row-{$item->row_number}";

        return "{$base}.pdf";
    }
}
