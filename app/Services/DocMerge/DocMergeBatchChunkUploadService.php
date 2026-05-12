<?php

declare(strict_types=1);

namespace App\Services\DocMerge;

use App\Jobs\DocMerge\ProcessDocMergeBatch;
use App\Models\DocMergeBatch;
use App\Models\DocMergeBatchChunkUpload;
use App\Support\DocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DocMergeBatchChunkUploadService
{
    public const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024;

    public const SESSION_TTL_MINUTES = 120;

    /**
     * @param array{
     *  outputPrefix?: string|null,
     *  pageFolders: list<array{
     *      name: string,
     *      number: int|string|null,
     *      hasNestedEntries?: bool|int|string|null,
     *      hasInvalidFiles?: bool|int|string|null,
     *      files: list<array{
     *          fileKey: string,
     *          displayName: string,
     *          size: int,
     *          mimeType?: string|null
     *      }>
     *  }>
     * } $manifest
     */
    public function initSession(DocMergeBatch $batch, int $userId, array $manifest): DocMergeBatchChunkUpload
    {
        $outputPrefix = $manifest['outputPrefix'] ?? null;
        $pageFolders = $manifest['pageFolders'] ?? null;

        if (! is_array($pageFolders) || $pageFolders === []) {
            throw ValidationException::withMessages([
                'pageFolders' => 'Add at least two page folders like PAGE 1 and PAGE 2.',
            ]);
        }

        $fileKeys = [];
        foreach ($pageFolders as $pageFolder) {
            foreach (($pageFolder['files'] ?? []) as $file) {
                $fileKey = (string) ($file['fileKey'] ?? '');
                if ($fileKey === '') {
                    throw ValidationException::withMessages([
                        'pageFolders' => 'Each upload file needs a file key.',
                    ]);
                }
                if (isset($fileKeys[$fileKey])) {
                    throw ValidationException::withMessages([
                        'pageFolders' => 'Duplicate upload file keys are not allowed.',
                    ]);
                }
                $fileKeys[$fileKey] = true;
            }
        }

        return DocMergeBatchChunkUpload::query()->create([
            'doc_merge_batch_id' => $batch->id,
            'user_id' => $userId,
            'status' => DocMergeBatchChunkUpload::STATUS_INITIATED,
            'expires_at' => now()->addMinutes(self::SESSION_TTL_MINUTES),
            'manifest_json' => [
                'outputPrefix' => is_string($outputPrefix) ? $outputPrefix : null,
                'pageFolders' => $pageFolders,
            ],
            'progress_json' => ['files' => []],
            'assembled_files_json' => ['files' => []],
        ]);
    }

    public function storeChunk(
        DocMergeBatchChunkUpload $upload,
        string $fileKey,
        int $chunkIndex,
        UploadedFile $chunkFile,
        ?string $checksum = null,
    ): void {
        $this->guardActiveUpload($upload);
        $this->ensureFileKeyExists($upload, $fileKey);
        if ($chunkIndex < 0) {
            throw ValidationException::withMessages(['chunkIndex' => 'Chunk index must be zero or greater.']);
        }

        $tmpPath = $chunkFile->getRealPath();
        if ($tmpPath === false || ! is_file($tmpPath)) {
            throw ValidationException::withMessages(['chunk' => 'The uploaded chunk is no longer available.']);
        }

        if (is_string($checksum) && $checksum !== '') {
            $calculated = hash_file('sha256', $tmpPath);
            if (! hash_equals(Str::lower($checksum), Str::lower($calculated))) {
                throw ValidationException::withMessages(['checksum' => 'Chunk checksum mismatch.']);
            }
        }

        DB::transaction(function () use ($upload, $fileKey, $chunkIndex, $tmpPath): void {
            /** @var DocMergeBatchChunkUpload $locked */
            $locked = DocMergeBatchChunkUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();
            $this->guardActiveUpload($locked);

            $disk = DocumentStorage::disk();
            $storagePath = $this->chunkStoragePath($locked, $fileKey, $chunkIndex);
            $stream = fopen($tmpPath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to read uploaded chunk.');
            }

            try {
                $stored = $disk->put($storagePath, $stream);
            } finally {
                fclose($stream);
            }

            if ($stored !== true || ! $disk->exists($storagePath)) {
                throw new RuntimeException('Chunk could not be stored.');
            }

            $progress = is_array($locked->progress_json) ? $locked->progress_json : ['files' => []];
            $progress['files'] ??= [];
            $progress['files'][$fileKey] ??= ['receivedChunks' => [], 'completed' => false];
            $progress['files'][$fileKey]['receivedChunks'] ??= [];

            if (! in_array($chunkIndex, $progress['files'][$fileKey]['receivedChunks'], true)) {
                $progress['files'][$fileKey]['receivedChunks'][] = $chunkIndex;
            }

            sort($progress['files'][$fileKey]['receivedChunks']);
            $locked->status = DocMergeBatchChunkUpload::STATUS_UPLOADING;
            $locked->progress_json = $progress;
            $locked->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
            $locked->save();
        });
    }

    public function completeFile(
        DocMergeBatchChunkUpload $upload,
        string $fileKey,
        int $totalChunks,
        int $expectedSize,
        ?string $checksum = null,
    ): void {
        if ($totalChunks <= 0) {
            throw ValidationException::withMessages(['totalChunks' => 'Total chunks must be greater than zero.']);
        }
        if ($expectedSize <= 0) {
            throw ValidationException::withMessages(['expectedSize' => 'Expected file size must be greater than zero.']);
        }

        DB::transaction(function () use ($upload, $fileKey, $totalChunks, $expectedSize, $checksum): void {
            /** @var DocMergeBatchChunkUpload $locked */
            $locked = DocMergeBatchChunkUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();
            $this->guardActiveUpload($locked);
            $this->ensureFileKeyExists($locked, $fileKey);

            $disk = DocumentStorage::disk();
            $progress = is_array($locked->progress_json) ? $locked->progress_json : ['files' => []];
            $received = $progress['files'][$fileKey]['receivedChunks'] ?? [];

            for ($i = 0; $i < $totalChunks; $i++) {
                if (! in_array($i, $received, true)) {
                    throw ValidationException::withMessages(['fileKey' => 'One or more chunks are missing for this file.']);
                }
            }

            $assembledPath = $this->assembledStoragePath($locked, $fileKey);
            $tempLocalPath = tempnam(sys_get_temp_dir(), 'doc-merge-assembled-');
            if ($tempLocalPath === false) {
                throw new RuntimeException('Temporary file could not be created.');
            }

            $write = fopen($tempLocalPath, 'wb');
            if ($write === false) {
                @unlink($tempLocalPath);
                throw new RuntimeException('Temporary file could not be created.');
            }

            try {
                for ($i = 0; $i < $totalChunks; $i++) {
                    $chunkPath = $this->chunkStoragePath($locked, $fileKey, $i);
                    if (! $disk->exists($chunkPath)) {
                        throw ValidationException::withMessages(['fileKey' => 'One or more chunks are missing for this file.']);
                    }

                    $read = $disk->readStream($chunkPath);
                    if (! is_resource($read)) {
                        throw new RuntimeException('A chunk could not be read.');
                    }

                    try {
                        stream_copy_to_stream($read, $write);
                    } finally {
                        fclose($read);
                    }
                }
            } finally {
                fclose($write);
            }

            if (filesize($tempLocalPath) !== $expectedSize) {
                @unlink($tempLocalPath);
                throw ValidationException::withMessages(['expectedSize' => 'Uploaded file size does not match expected size.']);
            }

            if (is_string($checksum) && $checksum !== '') {
                $calculated = hash_file('sha256', $tempLocalPath);
                if (! hash_equals(Str::lower($checksum), Str::lower($calculated))) {
                    @unlink($tempLocalPath);
                    throw ValidationException::withMessages(['checksum' => 'File checksum mismatch.']);
                }
            }

            $stream = fopen($tempLocalPath, 'rb');
            if ($stream === false) {
                @unlink($tempLocalPath);
                throw new RuntimeException('Temporary file could not be read.');
            }
            try {
                $stored = $disk->put($assembledPath, $stream);
            } finally {
                fclose($stream);
                @unlink($tempLocalPath);
            }

            if ($stored !== true || ! $disk->exists($assembledPath)) {
                throw new RuntimeException('Assembled file could not be stored.');
            }

            $assembled = is_array($locked->assembled_files_json) ? $locked->assembled_files_json : ['files' => []];
            $assembled['files'] ??= [];
            $assembled['files'][$fileKey] = [
                'path' => $assembledPath,
                'size' => $expectedSize,
                'checksum' => $checksum,
            ];

            $progress['files'][$fileKey]['completed'] = true;
            $locked->assembled_files_json = $assembled;
            $locked->progress_json = $progress;
            $locked->expires_at = now()->addMinutes(self::SESSION_TTL_MINUTES);
            $locked->save();
        });
    }

    public function finalize(
        DocMergeBatchChunkUpload $upload,
        DocMergeBatchService $docMergeBatchService,
        ?string $outputPrefix = null,
    ): void {
        DB::transaction(function () use ($upload, $docMergeBatchService, $outputPrefix): void {
            /** @var DocMergeBatchChunkUpload $locked */
            $locked = DocMergeBatchChunkUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();
            $this->guardActiveUpload($locked);

            $batch = DocMergeBatch::query()->whereKey($locked->doc_merge_batch_id)->lockForUpdate()->first();
            if (! $batch instanceof DocMergeBatch) {
                throw ValidationException::withMessages(['upload' => 'Batch not found for this upload session.']);
            }
            if ($batch->isBusy()) {
                throw ValidationException::withMessages(['batch' => 'This batch is already queued or processing. Wait for it to finish before making more changes.']);
            }

            $manifest = is_array($locked->manifest_json) ? $locked->manifest_json : [];
            $assembled = is_array($locked->assembled_files_json) ? $locked->assembled_files_json : [];
            $assembledFiles = is_array($assembled['files'] ?? null) ? $assembled['files'] : [];
            $pageFolders = [];

            foreach (($manifest['pageFolders'] ?? []) as $pageFolder) {
                $files = [];
                foreach (($pageFolder['files'] ?? []) as $file) {
                    $fileKey = (string) ($file['fileKey'] ?? '');
                    $assembledFile = $assembledFiles[$fileKey] ?? null;
                    if (! is_array($assembledFile) || ! is_string($assembledFile['path'] ?? null)) {
                        throw ValidationException::withMessages(['fileKey' => "File {$fileKey} is not completed yet."]);
                    }
                    $diskPath = $assembledFile['path'];
                    $localPath = $this->materializeDiskFileToLocalTemp($diskPath);
                    $files[] = [
                        'displayName' => (string) ($file['displayName'] ?? 'source.pdf'),
                        'path' => $localPath,
                    ];
                }

                $pageFolders[] = [
                    'name' => (string) ($pageFolder['name'] ?? ''),
                    'number' => $pageFolder['number'] ?? null,
                    'hasNestedEntries' => $pageFolder['hasNestedEntries'] ?? false,
                    'hasInvalidFiles' => $pageFolder['hasInvalidFiles'] ?? false,
                    'files' => $files,
                ];
            }

            try {
                $docMergeBatchService->storePageFolders($batch, $pageFolders);
            } finally {
                foreach ($pageFolders as $pageFolder) {
                    foreach ($pageFolder['files'] as $file) {
                        @unlink((string) ($file['path'] ?? ''));
                    }
                }
            }

            $batch->forceFill([
                'processing_status' => DocMergeBatch::PROCESSING_STATUS_QUEUED,
                'processing_error' => null,
            ])->save();

            ProcessDocMergeBatch::dispatch(
                $batch->getKey(),
                $outputPrefix ?? (is_string($manifest['outputPrefix'] ?? null) ? $manifest['outputPrefix'] : null),
            )->afterCommit();

            $locked->status = DocMergeBatchChunkUpload::STATUS_FINALIZED;
            $locked->save();
        });
    }

    public function cancel(DocMergeBatchChunkUpload $upload): void
    {
        $upload->status = DocMergeBatchChunkUpload::STATUS_CANCELLED;
        $upload->save();
        $upload->delete();
    }

    public function purgeExpired(): int
    {
        $uploads = DocMergeBatchChunkUpload::query()
            ->where('expires_at', '<', now())
            ->orWhereIn('status', [DocMergeBatchChunkUpload::STATUS_FINALIZED, DocMergeBatchChunkUpload::STATUS_CANCELLED])
            ->get();

        $count = $uploads->count();
        foreach ($uploads as $upload) {
            $upload->delete();
        }

        return $count;
    }

    private function guardActiveUpload(DocMergeBatchChunkUpload $upload): void
    {
        if ($upload->expires_at?->isPast()) {
            throw ValidationException::withMessages(['upload' => 'Upload session has expired. Please restart upload.']);
        }
        if (in_array($upload->status, [DocMergeBatchChunkUpload::STATUS_FINALIZED, DocMergeBatchChunkUpload::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages(['upload' => 'Upload session is no longer active.']);
        }
    }

    private function ensureFileKeyExists(DocMergeBatchChunkUpload $upload, string $fileKey): void
    {
        $manifest = is_array($upload->manifest_json) ? $upload->manifest_json : [];
        foreach (($manifest['pageFolders'] ?? []) as $pageFolder) {
            foreach (($pageFolder['files'] ?? []) as $file) {
                if (($file['fileKey'] ?? null) === $fileKey) {
                    return;
                }
            }
        }

        throw ValidationException::withMessages(['fileKey' => 'Unknown file key.']);
    }

    private function chunkStoragePath(DocMergeBatchChunkUpload $upload, string $fileKey, int $chunkIndex): string
    {
        return sprintf(
            'doc-merge/%d/batches/%d/uploads-temp/%s/chunks/%s/%d.part',
            $upload->user_id,
            $upload->doc_merge_batch_id,
            $upload->uuid,
            rawurlencode($fileKey),
            $chunkIndex,
        );
    }

    private function assembledStoragePath(DocMergeBatchChunkUpload $upload, string $fileKey): string
    {
        return sprintf(
            'doc-merge/%d/batches/%d/uploads-temp/%s/assembled/%s.pdf',
            $upload->user_id,
            $upload->doc_merge_batch_id,
            $upload->uuid,
            rawurlencode($fileKey),
        );
    }

    private function materializeDiskFileToLocalTemp(string $storagePath): string
    {
        $disk = DocumentStorage::disk();
        $readStream = $disk->readStream($storagePath);
        if (! is_resource($readStream)) {
            throw new RuntimeException('One of the upload source files is no longer available.');
        }

        $localPath = tempnam(sys_get_temp_dir(), 'doc-merge-upload-source-');
        if ($localPath === false) {
            fclose($readStream);
            throw new RuntimeException('A temporary source PDF could not be created.');
        }

        $writeStream = fopen($localPath, 'wb');
        if (! is_resource($writeStream)) {
            fclose($readStream);
            @unlink($localPath);
            throw new RuntimeException('A temporary source PDF could not be created.');
        }

        try {
            stream_copy_to_stream($readStream, $writeStream);
        } finally {
            fclose($readStream);
            fclose($writeStream);
        }

        return $localPath;
    }
}

