<?php

declare(strict_types=1);

namespace App\Services\AfsFiling;

use App\Models\AfsFilingItem;
use App\Models\DocumentGeneratorSignature;
use App\Models\User;
use App\Services\DocxTemplateService;
use App\Services\PdfConversionService;
use App\Services\SignatureImageService;
use App\Support\DocumentStorage;

class AfsFilingItemSigningService
{
    public function __construct(
        private readonly SignatureImageService $signatureImageService,
        private readonly DocxTemplateService $docxTemplateService,
        private readonly PdfConversionService $pdfConversionService,
    ) {}

    /**
     * @return array{ok: bool, message: string, errors: array<string, list<string>>}
     */
    public function preflight(AfsFilingItem $item): array
    {
        if ((string) $item->status !== 'pdf_done') {
            return [
                'ok' => false,
                'message' => 'Row must be generated before signing.',
                'errors' => ['signature' => ['Row must be generated before signing.']],
            ];
        }

        if (! is_string($item->docx_path) || $item->docx_path === '' || ! DocumentStorage::disk()->exists($item->docx_path)) {
            return [
                'ok' => false,
                'message' => 'Generated DOCX is required for placeholder signing.',
                'errors' => ['signature' => ['Generated DOCX is required for placeholder signing.']],
            ];
        }

        return [
            'ok' => true,
            'message' => 'Signature placeholder preflight passed.',
            'errors' => [],
        ];
    }

    public function sign(AfsFilingItem $item, User $user, string $presidentSignatureSourcePath): void
    {
        if (! in_array((string) $item->status, ['pdf_done', 'signing'], true)) {
            throw new \RuntimeException('Only generated rows can be signed.');
        }

        if ($item->signature_applied_at !== null) {
            throw new \RuntimeException('Signature is already applied for this row.');
        }

        if (! is_string($item->docx_path) || $item->docx_path === '' || ! DocumentStorage::disk()->exists($item->docx_path)) {
            throw new \RuntimeException('Generated DOCX not found in storage.');
        }

        if (! is_string($item->pdf_path) || $item->pdf_path === '') {
            throw new \RuntimeException('Generated PDF path not found.');
        }

        $signature = $this->resolveSignatureOrFail($user);

        $sourceDocxTemp = $this->copyStorageFileToTemporaryPath($item->docx_path, '.docx');
        $signedDocxTemp = tempnam(sys_get_temp_dir(), 'afs-signed-docx-');
        if ($signedDocxTemp === false) {
            @unlink($sourceDocxTemp);
            throw new \RuntimeException('Unable to allocate a temporary signed DOCX path.');
        }
        $signedDocxTemp .= '.docx';

        $presidentSourceTemp = null;
        $resolvedPresidentSourcePath = $presidentSignatureSourcePath;
        if (! is_file($resolvedPresidentSourcePath)) {
            if (! DocumentStorage::disk()->exists($presidentSignatureSourcePath)) {
                @unlink($sourceDocxTemp);
                @unlink($signedDocxTemp);
                throw new \RuntimeException('President signature image source not found.');
            }

            $extension = pathinfo($presidentSignatureSourcePath, PATHINFO_EXTENSION);
            $presidentSourceTemp = $this->copyStorageFileToTemporaryPath(
                $presidentSignatureSourcePath,
                $extension !== '' ? '.'.$extension : '',
            );
            $resolvedPresidentSourcePath = $presidentSourceTemp;
        }

        $presidentTemp = $this->signatureImageService->processToTransparentPng($resolvedPresidentSourcePath);
        $getorTemp = null;
        $signedPdfTemp = null;

        try {
            $placeholderKeys = array_map(
                static fn (string $key): string => mb_strtolower(trim($key)),
                $this->docxTemplateService->placeholderKeys($sourceDocxTemp),
            );

            if (! in_array('president_signature', $placeholderKeys, true)) {
                throw new \RuntimeException('Template placeholder {president_signature} is required.');
            }

            $images = [
                'president_signature' => ['path' => $presidentTemp],
            ];

            if (is_string($signature->processed_signature_path)
                && $signature->processed_signature_path !== ''
                && DocumentStorage::disk()->exists($signature->processed_signature_path)
                && in_array('getor_signature', $placeholderKeys, true)) {
                $getorTemp = $this->copyStorageFileToTemporaryPath($signature->processed_signature_path, '.png');
                $images['getor_signature'] = ['path' => $getorTemp];
            }

            $this->docxTemplateService->injectSignatureImages($sourceDocxTemp, $signedDocxTemp, $images);
            $signedPdfTemp = $this->pdfConversionService->convertDocxToPdf($signedDocxTemp);

            $this->storeLocalFileToDocumentStorage($signedPdfTemp, $item->pdf_path);
            $item->status = 'pdf_done';
            $item->signature_applied_at = now();
            $item->save();
        } finally {
            @unlink($sourceDocxTemp);
            @unlink($signedDocxTemp);
            if (is_string($presidentSourceTemp)) {
                @unlink($presidentSourceTemp);
            }
            @unlink($presidentTemp);
            if (is_string($getorTemp)) {
                @unlink($getorTemp);
            }
            if (is_string($signedPdfTemp)) {
                @unlink($signedPdfTemp);
            }
        }
    }

    private function resolveSignatureOrFail(User $user): DocumentGeneratorSignature
    {
        $signature = $user->documentGeneratorSignature;
        if (! $signature instanceof DocumentGeneratorSignature) {
            throw new \RuntimeException('Getor signature settings are required before signing.');
        }

        return $signature;
    }

    private function copyStorageFileToTemporaryPath(string $storagePath, string $extension = ''): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'afs-sign-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Unable to allocate a temporary file path.');
        }

        $resolvedPath = $temporaryPath;
        if ($extension !== '') {
            $resolvedPath = $temporaryPath.(str_starts_with($extension, '.') ? $extension : '.'.$extension);
            if (! @rename($temporaryPath, $resolvedPath)) {
                @unlink($temporaryPath);
                throw new \RuntimeException('Unable to prepare temporary file path.');
            }
        }

        $stream = DocumentStorage::disk()->readStream($storagePath);
        if (! is_resource($stream)) {
            @unlink($resolvedPath);
            throw new \RuntimeException('Unable to read file from storage.');
        }

        $target = @fopen($resolvedPath, 'wb');
        if (! is_resource($target)) {
            fclose($stream);
            @unlink($resolvedPath);
            throw new \RuntimeException('Unable to open temporary file for writing.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        return $resolvedPath;
    }

    private function storeLocalFileToDocumentStorage(string $localPath, string $storagePath): void
    {
        $stream = @fopen($localPath, 'rb');
        if (! is_resource($stream)) {
            throw new \RuntimeException('Unable to read local file for storage.');
        }

        try {
            DocumentStorage::disk()->writeStream($storagePath, $stream);
        } finally {
            fclose($stream);
        }
    }
}
