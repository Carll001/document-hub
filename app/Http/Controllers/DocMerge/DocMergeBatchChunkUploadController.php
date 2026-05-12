<?php

declare(strict_types=1);

namespace App\Http\Controllers\DocMerge;

use App\Http\Controllers\Controller;
use App\Models\DocMergeBatch;
use App\Models\DocMergeBatchChunkUpload;
use App\Services\DocMerge\DocMergeBatchChunkUploadService;
use App\Services\DocMerge\DocMergeBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocMergeBatchChunkUploadController extends Controller
{
    public function init(
        Request $request,
        DocMergeBatch $docMergeBatch,
        DocMergeBatchChunkUploadService $chunkUploadService,
    ): JsonResponse {
        abort_unless($docMergeBatch->user->is($request->user()), 404);

        if ($docMergeBatch->isBusy()) {
            return response()->json([
                'message' => 'This batch is already queued or processing. Wait for it to finish before making more changes.',
            ], 409);
        }

        $validated = $request->validate([
            'outputPrefix' => ['nullable', 'string', 'max:120'],
            'pageFolders' => ['required', 'array', 'min:2'],
            'pageFolders.*.name' => ['required', 'string', 'max:120'],
            'pageFolders.*.number' => ['required', 'integer', 'min:1'],
            'pageFolders.*.hasNestedEntries' => ['nullable', 'boolean'],
            'pageFolders.*.hasInvalidFiles' => ['nullable', 'boolean'],
            'pageFolders.*.files' => ['required', 'array', 'min:1'],
            'pageFolders.*.files.*.fileKey' => ['required', 'string', 'max:120'],
            'pageFolders.*.files.*.displayName' => ['required', 'string', 'max:255'],
            'pageFolders.*.files.*.size' => ['required', 'integer', 'min:1'],
            'pageFolders.*.files.*.mimeType' => ['nullable', 'string', 'max:120'],
        ]);

        $upload = $chunkUploadService->initSession($docMergeBatch, (int) $request->user()->id, $validated);

        return response()->json([
            'uploadId' => $upload->uuid,
            'chunkSize' => DocMergeBatchChunkUploadService::DEFAULT_CHUNK_SIZE,
            'expiresAt' => $upload->expires_at?->toIso8601String(),
        ]);
    }

    public function chunk(
        Request $request,
        DocMergeBatch $docMergeBatch,
        string $uploadId,
        DocMergeBatchChunkUploadService $chunkUploadService,
    ): JsonResponse {
        $upload = $this->resolveUpload($request, $docMergeBatch, $uploadId);
        $validated = $request->validate([
            'fileKey' => ['required', 'string', 'max:120'],
            'chunkIndex' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
            'checksum' => ['nullable', 'string', 'size:64'],
        ]);

        $chunkUploadService->storeChunk(
            $upload,
            (string) $validated['fileKey'],
            (int) $validated['chunkIndex'],
            $validated['chunk'],
            isset($validated['checksum']) ? (string) $validated['checksum'] : null,
        );

        return response()->json(['message' => 'Chunk received.']);
    }

    public function complete(
        Request $request,
        DocMergeBatch $docMergeBatch,
        string $uploadId,
        DocMergeBatchChunkUploadService $chunkUploadService,
    ): JsonResponse {
        $upload = $this->resolveUpload($request, $docMergeBatch, $uploadId);
        $validated = $request->validate([
            'fileKey' => ['required', 'string', 'max:120'],
            'totalChunks' => ['required', 'integer', 'min:1'],
            'expectedSize' => ['required', 'integer', 'min:1'],
            'checksum' => ['nullable', 'string', 'size:64'],
        ]);

        $chunkUploadService->completeFile(
            $upload,
            (string) $validated['fileKey'],
            (int) $validated['totalChunks'],
            (int) $validated['expectedSize'],
            isset($validated['checksum']) ? (string) $validated['checksum'] : null,
        );

        return response()->json(['message' => 'File completed.']);
    }

    public function finalize(
        Request $request,
        DocMergeBatch $docMergeBatch,
        string $uploadId,
        DocMergeBatchChunkUploadService $chunkUploadService,
        DocMergeBatchService $docMergeBatchService,
    ): JsonResponse {
        $upload = $this->resolveUpload($request, $docMergeBatch, $uploadId);
        $validated = $request->validate([
            'outputPrefix' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $chunkUploadService->finalize(
                $upload,
                $docMergeBatchService,
                isset($validated['outputPrefix']) ? (string) $validated['outputPrefix'] : null,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'The page folders could not be uploaded right now.',
            ], 422);
        }

        return response()->json([
            'message' => 'Batch processing queued. Results will refresh automatically.',
        ]);
    }

    public function destroy(
        Request $request,
        DocMergeBatch $docMergeBatch,
        string $uploadId,
        DocMergeBatchChunkUploadService $chunkUploadService,
    ): JsonResponse {
        $upload = $this->resolveUpload($request, $docMergeBatch, $uploadId);
        $chunkUploadService->cancel($upload);

        return response()->json(['message' => 'Upload session cancelled.']);
    }

    private function resolveUpload(
        Request $request,
        DocMergeBatch $docMergeBatch,
        string $uploadId,
    ): DocMergeBatchChunkUpload {
        abort_unless($docMergeBatch->user->is($request->user()), 404);

        $upload = DocMergeBatchChunkUpload::query()
            ->where('uuid', $uploadId)
            ->where('doc_merge_batch_id', $docMergeBatch->id)
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless($upload instanceof DocMergeBatchChunkUpload, 404);

        return $upload;
    }
}

