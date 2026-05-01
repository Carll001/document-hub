<?php

declare(strict_types=1);

namespace App\Services\DocMerge;

use App\Models\DocMergeBatch;
use App\Support\DocumentStorage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DocMergeBatchExportService
{
    private const CACHE_TTL_SECONDS = 21600;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    public const STATUS_READY = 'ready';

    public function cacheKey(int $userId, int $batchId): string
    {
        return "doc-merge:batch-export:{$userId}:{$batchId}";
    }

    public function getState(int $userId, int $batchId): array
    {
        $state = Cache::get($this->cacheKey($userId, $batchId));

        if (! is_array($state)) {
            return $this->emptyState();
        }

        $status = $state['status'] ?? null;
        if (! in_array($status, [self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_FAILED, self::STATUS_READY], true)) {
            return $this->emptyState();
        }

        if ($status === self::STATUS_READY) {
            $localPath = is_string($state['localPath'] ?? null) ? $state['localPath'] : null;
            if ($localPath === null || ! Storage::disk('local')->exists($localPath)) {
                $this->forgetState($userId, $batchId);

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

    public function putState(int $userId, int $batchId, array $state): void
    {
        Cache::put($this->cacheKey($userId, $batchId), $state, now()->addSeconds(self::CACHE_TTL_SECONDS));
    }

    public function forgetState(int $userId, int $batchId): void
    {
        $cached = Cache::get($this->cacheKey($userId, $batchId));
        if (is_array($cached)) {
            $localPath = $cached['localPath'] ?? null;
            if (is_string($localPath) && $localPath !== '') {
                Storage::disk('local')->delete($localPath);
            }
        }

        Cache::forget($this->cacheKey($userId, $batchId));
    }

    public function buildZip(DocMergeBatch $batch): array
    {
        $batch->loadMissing(['mergedPdfs']);
        $directory = "tmp/doc-merge-batch-exports/user-{$batch->user_id}/batch-{$batch->id}";
        Storage::disk('local')->makeDirectory($directory);
        $fileName = 'doc-merge-batch-'.Str::uuid().'.zip';
        $localStoragePath = "{$directory}/{$fileName}";
        $localArchivePath = tempnam(sys_get_temp_dir(), 'doc-merge-batch-export-');

        if ($localArchivePath === false) {
            throw new \RuntimeException('The batch ZIP could not be prepared.');
        }

        $archive = new ZipArchive;
        if ($archive->open($localArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($localArchivePath);
            throw new \RuntimeException('The batch ZIP could not be created.');
        }

        $includedItems = 0;
        $usedPaths = [];
        $disk = DocumentStorage::disk();

        try {
            foreach ($batch->mergedPdfs->sortBy([['file_name', 'asc'], ['id', 'asc']]) as $mergedPdf) {
                if (! $disk->exists($mergedPdf->storage_path)) {
                    continue;
                }

                $stream = $disk->readStream($mergedPdf->storage_path);
                if (! is_resource($stream)) {
                    continue;
                }

                $contents = stream_get_contents($stream);
                fclose($stream);
                if (! is_string($contents) || $contents === '') {
                    continue;
                }

                $zipPath = $this->uniqueZipPath($mergedPdf->file_name, $usedPaths);
                $archive->addFromString($zipPath, $contents);
                $includedItems++;
            }
        } finally {
            $archive->close();
        }

        if ($includedItems === 0) {
            @unlink($localArchivePath);
            throw new \RuntimeException('No merged files were available to add to the ZIP.');
        }

        $zipStream = @fopen($localArchivePath, 'rb');
        if (! is_resource($zipStream)) {
            @unlink($localArchivePath);
            throw new \RuntimeException('The batch ZIP could not be read.');
        }

        try {
            Storage::disk('local')->writeStream($localStoragePath, $zipStream);
        } finally {
            fclose($zipStream);
            @unlink($localArchivePath);
        }

        return [
            'localPath' => $localStoragePath,
            'itemCount' => $includedItems,
        ];
    }

    public function localAbsolutePath(string $localPath): string
    {
        return Storage::disk('local')->path($localPath);
    }

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
            $name = 'merged.pdf';
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $baseName = pathinfo($name, PATHINFO_FILENAME);
        $candidate = $name;
        $suffix = 2;

        while (isset($usedPaths[mb_strtolower($candidate)])) {
            $candidate = $extension !== '' ? "{$baseName}-{$suffix}.{$extension}" : "{$baseName}-{$suffix}";
            $suffix++;
        }

        $usedPaths[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
