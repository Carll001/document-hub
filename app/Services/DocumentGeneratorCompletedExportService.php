<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentBatchItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DocumentGeneratorCompletedExportService
{
    private const CACHE_TTL_SECONDS = 21600;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    public const STATUS_READY = 'ready';

    public function cacheKey(int $userId): string
    {
        return "document-generator:afs:completed-export:{$userId}";
    }

    /**
     * @return array{
     *     status: string|null,
     *     error: string|null,
     *     itemCount: int|null,
     *     downloadUrl: string|null
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
            self::STATUS_FAILED,
            self::STATUS_READY,
        ], true)) {
            return $this->emptyState();
        }

        if ($status === self::STATUS_READY) {
            $storagePath = is_string($state['storagePath'] ?? null) ? $state['storagePath'] : null;
            if ($storagePath === null || ! Storage::disk('s3')->exists($storagePath)) {
                $this->forgetState($userId);

                return $this->emptyState();
            }
        }

        return [
            'status' => $status,
            'error' => is_string($state['error'] ?? null) ? $state['error'] : null,
            'itemCount' => is_numeric($state['itemCount'] ?? null) ? (int) $state['itemCount'] : null,
            'downloadUrl' => is_string($state['downloadUrl'] ?? null) ? $state['downloadUrl'] : null,
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
                Storage::disk('s3')->delete($storagePath);
            }
        }

        Cache::forget($this->cacheKey($userId));
    }

    /**
     * @param  Collection<int, DocumentBatchItem>  $items
     * @return array{storagePath: string, downloadFileName: string, itemCount: int}
     */
    public function buildZip(Collection $items, int $userId): array
    {
        $directory = "tmp/document-generator-afs-completed-exports/user-{$userId}";
        Storage::disk('s3')->makeDirectory($directory);

        $fileName = 'afs-completed-files-'.Str::uuid().'.zip';
        $storagePath = "{$directory}/{$fileName}";
        $archivePath = Storage::disk('s3')->path($storagePath);

        $archive = new ZipArchive;
        if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The completed files ZIP could not be created.');
        }

        $usedPaths = [];
        $disk = Storage::disk('s3');
        $includedItems = 0;

        foreach ($items as $item) {
            $pdfStoragePath = (string) ($item->pdf_path ?? '');
            if ($pdfStoragePath === '' || ! $disk->exists($pdfStoragePath)) {
                continue;
            }

            $zipPath = $this->uniqueZipPath(
                "afs-batch-{$item->document_batch_id}-row-{$item->row_number}.pdf",
                $usedPaths,
            );

            $archive->addFile($disk->path($pdfStoragePath), $zipPath);
            $includedItems++;
        }

        $archive->close();

        if ($includedItems === 0) {
            $disk->delete($storagePath);
            throw new \RuntimeException('No completed files were available to add to the ZIP.');
        }

        return [
            'storagePath' => $storagePath,
            'downloadFileName' => 'afs-completed-files.zip',
            'itemCount' => $includedItems,
        ];
    }

    /**
     * @return array{
     *     status: null,
     *     error: null,
     *     itemCount: null,
     *     downloadUrl: null
     * }
     */
    private function emptyState(): array
    {
        return [
            'status' => null,
            'error' => null,
            'itemCount' => null,
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
            $name = 'afs-completed-file.pdf';
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

