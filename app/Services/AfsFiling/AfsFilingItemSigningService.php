<?php

declare(strict_types=1);

namespace App\Services\AfsFiling;

use App\Models\AfsFilingItem;
use App\Models\DocumentGeneratorSignature;
use App\Models\FilingOutput;
use App\Models\User;
use App\Services\DocxTemplateService;
use App\Services\PdfConversionService;
use App\Services\SignatureImageService;
use App\Support\DocumentStorage;
use Illuminate\Support\Facades\Log;

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
        if ((string) $item->status !== 'generated') {
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
        if (! in_array((string) $item->status, ['generated', 'signing'], true)) {
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

        $signature = $this->resolveSignature($user);

        $sourceDocxTemp = $this->copyStorageFileToTemporaryPath($item->docx_path, '.docx');
        $signedDocxTemp = tempnam(sys_get_temp_dir(), 'afs-signed-docx-');
        if ($signedDocxTemp === false) {
            @unlink($sourceDocxTemp);
            throw new \RuntimeException('Unable to allocate a temporary signed DOCX path.');
        }
        $signedDocxTemp .= '.docx';

        $presidentSourceTemp = null;
        $resolvedPresidentSourcePath = $presidentSignatureSourcePath;
        if (trim($resolvedPresidentSourcePath) === '') {
            $resolvedPresidentSourcePath = $this->resolveStoredPresidentSignaturePath($item) ?? '';
        }
        if ($resolvedPresidentSourcePath === '') {
            throw new \RuntimeException('President signature image source not found.');
        }
        if (! is_file($resolvedPresidentSourcePath)) {
            if (! DocumentStorage::disk()->exists($resolvedPresidentSourcePath)) {
                @unlink($sourceDocxTemp);
                @unlink($signedDocxTemp);
                throw new \RuntimeException('President signature image source not found.');
            }

            $extension = pathinfo($resolvedPresidentSourcePath, PATHINFO_EXTENSION);
            $presidentSourceTemp = $this->copyStorageFileToTemporaryPath(
                $resolvedPresidentSourcePath,
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
            Log::info('AFS signing placeholders detected.', [
                'item_id' => (int) $item->id,
                'placeholders' => $placeholderKeys,
            ]);

            if (! in_array('president_signature', $placeholderKeys, true)) {
                throw new \RuntimeException('Template placeholder {president_signature} is required.');
            }

            $images = [
                'president_signature' => ['path' => $presidentTemp],
            ];

            $storedGetorSignaturePath = $this->resolveStoredGetorSignaturePath($item);
            $hasStoredGetorSignature = is_string($storedGetorSignaturePath)
                && $storedGetorSignaturePath !== ''
                && DocumentStorage::disk()->exists($storedGetorSignaturePath);
            $hasGlobalGetorSignature = $signature instanceof DocumentGeneratorSignature
                && is_string($signature->processed_signature_path)
                && $signature->processed_signature_path !== ''
                && DocumentStorage::disk()->exists($signature->processed_signature_path);

            if (($hasStoredGetorSignature || $hasGlobalGetorSignature) && ! in_array('getor_signature', $placeholderKeys, true)) {
                throw new \RuntimeException('Template placeholder {getor_signature} is required when GETOR signature is provided.');
            }

            if (is_string($storedGetorSignaturePath)
                && $storedGetorSignaturePath !== ''
                && DocumentStorage::disk()->exists($storedGetorSignaturePath)
                && in_array('getor_signature', $placeholderKeys, true)) {
                $getorTemp = $this->copyStorageFileToTemporaryPath($storedGetorSignaturePath, '.png');
                $images['getor_signature'] = ['path' => $getorTemp];
            } elseif ($signature instanceof DocumentGeneratorSignature
                && is_string($signature->processed_signature_path)
                && $signature->processed_signature_path !== ''
                && DocumentStorage::disk()->exists($signature->processed_signature_path)
                && in_array('getor_signature', $placeholderKeys, true)) {
                $getorTemp = $this->copyStorageFileToTemporaryPath($signature->processed_signature_path, '.png');
                $images['getor_signature'] = ['path' => $getorTemp];
            }

            $this->docxTemplateService->injectSignatureImages($sourceDocxTemp, $signedDocxTemp, $images);
            $signedPdfTemp = $this->pdfConversionService->convertDocxToPdf($signedDocxTemp);

            $this->storeLocalFileToDocumentStorage($signedPdfTemp, $item->pdf_path);
            $item->status = 'signed';
            $item->signature_applied_at = now();
            $item->save();
            $this->syncFilingOutputStatus($item, 'signed');
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

    private function resolveStoredPresidentSignaturePath(AfsFilingItem $item): ?string
    {
        $rowData = is_array($item->row_data) ? $item->row_data : [];
        $fromRow = $rowData['__president_signature_path'] ?? null;
        if (is_string($fromRow) && trim($fromRow) !== '') {
            return trim($fromRow);
        }

        $filingOutputId = (int) ($rowData['__filing_output_id'] ?? 0);
        if ($filingOutputId <= 0) {
            return null;
        }

        $output = FilingOutput::query()->find($filingOutputId);
        if (! $output instanceof FilingOutput) {
            return null;
        }

        return is_string($output->president_signature_path) && trim($output->president_signature_path) !== ''
            ? trim($output->president_signature_path)
            : null;
    }

    private function resolveStoredGetorSignaturePath(AfsFilingItem $item): ?string
    {
        $rowData = is_array($item->row_data) ? $item->row_data : [];
        $fromRow = $rowData['__getor_signature_path'] ?? null;
        if (is_string($fromRow) && trim($fromRow) !== '') {
            return trim($fromRow);
        }

        return null;
    }

    private function syncFilingOutputStatus(AfsFilingItem $item, string $status): void
    {
        $rowData = is_array($item->row_data) ? $item->row_data : [];
        $filingOutputId = (int) ($rowData['__filing_output_id'] ?? 0);
        if ($filingOutputId <= 0) {
            return;
        }

        $output = FilingOutput::query()->find($filingOutputId);
        if (! $output instanceof FilingOutput) {
            return;
        }

        $output->status = $status;
        $output->error_message = null;
        $output->save();
    }

    private function resolveSignature(User $user): ?DocumentGeneratorSignature
    {
        $signature = $user->documentGeneratorSignature;
        return $signature instanceof DocumentGeneratorSignature ? $signature : null;
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
