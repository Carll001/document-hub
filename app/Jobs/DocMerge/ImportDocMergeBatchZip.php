<?php

declare(strict_types=1);

namespace App\Jobs\DocMerge;

use App\Models\DocMergeBatch;
use App\Services\DocMerge\DocMergeBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImportDocMergeBatchZip implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $batchId,
        public readonly string $zipStoragePath,
        public readonly string $zipOriginalName,
        public readonly ?string $outputPrefix = null,
    ) {
        $this->onQueue('document-merger');
    }

    public function handle(DocMergeBatchService $docMergeBatchService): void
    {
        $batch = DocMergeBatch::query()->find($this->batchId);

        if (! $batch instanceof DocMergeBatch) {
            $this->deleteStagedZip();

            return;
        }

        $batch->forceFill([
            'processing_status' => DocMergeBatch::PROCESSING_STATUS_PROCESSING,
            'processing_error' => null,
        ])->save();

        try {
            $this->processZip($docMergeBatchService, $batch);

            $batch->forceFill([
                'processing_status' => DocMergeBatch::PROCESSING_STATUS_QUEUED,
                'processing_error' => null,
            ])->save();

            ProcessDocMergeBatch::dispatch($batch->getKey(), $this->outputPrefix);
        } catch (ValidationException $exception) {
            $batch->forceFill([
                'processing_status' => DocMergeBatch::PROCESSING_STATUS_FAILED,
                'processing_error' => $this->validationMessage($exception),
            ])->save();

            throw $exception;
        } catch (\Throwable $exception) {
            $batch->forceFill([
                'processing_status' => DocMergeBatch::PROCESSING_STATUS_FAILED,
                'processing_error' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'The ZIP could not be imported right now.',
            ])->save();

            throw $exception;
        } finally {
            $this->deleteStagedZip();
        }
    }

    private function materializeZipToLocalPath(): string
    {
        $disk = \App\Support\DocumentStorage::disk();

        if (! $disk->exists($this->zipStoragePath)) {
            throw ValidationException::withMessages([
                'zip' => 'The uploaded ZIP file is no longer available.',
            ]);
        }

        $readStream = $disk->readStream($this->zipStoragePath);

        if (! is_resource($readStream)) {
            throw new RuntimeException('The uploaded ZIP file could not be read.');
        }

        $localArchivePath = tempnam(sys_get_temp_dir(), 'doc-merge-zip-');

        if ($localArchivePath === false) {
            fclose($readStream);
            throw new RuntimeException('A temporary ZIP file could not be created.');
        }

        $writeStream = fopen($localArchivePath, 'wb');

        if (! is_resource($writeStream)) {
            fclose($readStream);
            @unlink($localArchivePath);
            throw new RuntimeException('A temporary ZIP file could not be created.');
        }

        try {
            stream_copy_to_stream($readStream, $writeStream);
        } finally {
            fclose($readStream);
            fclose($writeStream);
        }

        return $localArchivePath;
    }

    private function deleteStagedZip(): void
    {
        \App\Support\DocumentStorage::disk()->delete($this->zipStoragePath);
    }

    private function processZip(DocMergeBatchService $docMergeBatchService, DocMergeBatch $batch): void
    {
        $localArchivePath = $this->materializeZipToLocalPath();

        try {
            $docMergeBatchService->storeZipFromArchivePath(
                $batch,
                $localArchivePath,
                $this->zipOriginalName,
            );
        } finally {
            @unlink($localArchivePath);
        }
    }

    private function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())
            ->flatten()
            ->first();

        return is_string($message) && $message !== ''
            ? $message
            : 'The ZIP could not be imported right now.';
    }
}
