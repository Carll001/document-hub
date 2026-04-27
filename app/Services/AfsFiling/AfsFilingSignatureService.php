<?php

declare(strict_types=1);

namespace App\Services\AfsFiling;

use App\Http\Resources\AfsFiling\AfsFilingSignatureResource;
use App\Models\DocumentGeneratorSignature;
use App\Models\User;
use App\Services\SignatureImageService;
use App\Support\DocumentStorage;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AfsFilingSignatureService
{
    public function ensureFeatureEnabledOr404(): void
    {
        abort_unless($this->signatureFeatureEnabled(), 404);
    }

    /**
     * @return array{signature: array<string, mixed>|null}
     */
    public function payload(User $user): array
    {
        $signature = $user->documentGeneratorSignature;

        if (! $signature instanceof DocumentGeneratorSignature) {
            return ['signature' => null];
        }

        return ['signature' => (new AfsFilingSignatureResource($signature))->resolve()];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{signature: array<string, mixed>|null}
     */
    public function store(
        User $user,
        array $validated,
        UploadedFile $signatureFile,
        SignatureImageService $signatureImageService,
    ): array {
        if ($user->documentGeneratorSignature instanceof DocumentGeneratorSignature) {
            throw ValidationException::withMessages([
                'signature' => ['Signature already exists. Use update endpoint instead.'],
            ]);
        }

        return $this->upsert($user, $validated, $signatureFile, $signatureImageService);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{signature: array<string, mixed>|null}
     */
    public function update(
        User $user,
        array $validated,
        ?UploadedFile $signatureFile,
        SignatureImageService $signatureImageService,
    ): array {
        if (! $user->documentGeneratorSignature instanceof DocumentGeneratorSignature) {
            throw ValidationException::withMessages([
                'signature' => ['No signature exists yet. Use store endpoint first.'],
            ]);
        }

        return $this->upsert($user, $validated, $signatureFile, $signatureImageService);
    }

    /**
     * @return array{signature: null}
     */
    public function destroy(User $user): array
    {
        $signature = DocumentGeneratorSignature::query()
            ->where('user_id', (int) $user->getKey())
            ->first();

        if ($signature instanceof DocumentGeneratorSignature) {
            $paths = array_filter([
                $signature->processed_signature_path,
                $signature->original_signature_path,
            ]);

            $signature->delete();
            $this->deleteSignatureFiles($paths);
        }

        return ['signature' => null];
    }

    public function preview(User $user): StreamedResponse
    {
        $signature = DocumentGeneratorSignature::query()
            ->where('user_id', (int) $user->getKey())
            ->first();

        if (! $signature instanceof DocumentGeneratorSignature) {
            abort(404);
        }

        $path = (string) $signature->processed_signature_path;
        if (! DocumentStorage::disk()->exists($path)) {
            abort(404);
        }

        return DocumentStorage::disk()->response($path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{signature: array<string, mixed>|null}
     */
    private function upsert(
        User $user,
        array $validated,
        ?UploadedFile $signatureFile,
        SignatureImageService $signatureImageService,
    ): array {
        $signature = DocumentGeneratorSignature::query()
            ->where('user_id', (int) $user->getKey())
            ->first();

        $processedPath = $signature?->processed_signature_path;
        $originalPath = $signature?->original_signature_path;
        $oldPaths = [];

        if ($signatureFile instanceof UploadedFile) {
            $oldPaths = array_filter([
                $signature?->processed_signature_path,
                $signature?->original_signature_path,
            ]);

            $processedTempPath = $signatureImageService->processToTransparentPng($signatureFile->getPathname());
            $originalPath = $signatureFile->store("afs_filing/{$user->id}/signature", DocumentStorage::diskName());

            $processedPath = "afs_filing/{$user->id}/signature/processed-".Str::uuid().'.png';
            $processedFile = new File($processedTempPath);
            DocumentStorage::disk()->putFileAs(
                "afs_filing/{$user->id}/signature",
                $processedFile,
                basename($processedPath),
            );
            @unlink($processedTempPath);
        }

        if (! is_string($processedPath) || trim($processedPath) === '') {
            throw ValidationException::withMessages([
                'signature_file' => ['Processed signature was not generated.'],
            ]);
        }

        $attributes = [
            'processed_signature_path' => $processedPath,
            'original_signature_path' => $originalPath,
            'anchor' => (string) $validated['page2_anchor'],
            'offset_x' => (float) $validated['page2_offset_x'],
            'offset_y' => (float) $validated['page2_offset_y'],
            'width' => (float) $validated['page2_width'],
            'height' => (float) $validated['page2_height'],
            'page2_anchor' => (string) $validated['page2_anchor'],
            'page2_offset_x' => (float) $validated['page2_offset_x'],
            'page2_offset_y' => (float) $validated['page2_offset_y'],
            'page2_width' => (float) $validated['page2_width'],
            'page2_height' => (float) $validated['page2_height'],
            'page3_anchor' => (string) $validated['page3_anchor'],
            'page3_offset_x' => (float) $validated['page3_offset_x'],
            'page3_offset_y' => (float) $validated['page3_offset_y'],
            'page3_width' => (float) $validated['page3_width'],
            'page3_height' => (float) $validated['page3_height'],
            'page4_anchor' => (string) $validated['page4_anchor'],
            'page4_offset_x' => (float) $validated['page4_offset_x'],
            'page4_offset_y' => (float) $validated['page4_offset_y'],
            'page4_width' => (float) $validated['page4_width'],
            'page4_height' => (float) $validated['page4_height'],
            'page8_anchor' => (string) $validated['page8_anchor'],
            'page8_offset_x' => (float) $validated['page8_offset_x'],
            'page8_offset_y' => (float) $validated['page8_offset_y'],
            'page8_width' => (float) $validated['page8_width'],
            'page8_height' => (float) $validated['page8_height'],
            'page2_placement_mode' => (string) ($validated['page2_placement_mode'] ?? 'fixed'),
            'page2_anchor_text' => $this->normalizeAnchorText($validated['page2_anchor_text'] ?? null),
            'page3_placement_mode' => (string) ($validated['page3_placement_mode'] ?? 'fixed'),
            'page3_anchor_text' => $this->normalizeAnchorText($validated['page3_anchor_text'] ?? null),
            'page4_placement_mode' => (string) ($validated['page4_placement_mode'] ?? 'fixed'),
            'page4_anchor_text' => $this->normalizeAnchorText($validated['page4_anchor_text'] ?? null),
            'page8_placement_mode' => (string) ($validated['page8_placement_mode'] ?? 'fixed'),
            'page8_anchor_text' => $this->normalizeAnchorText($validated['page8_anchor_text'] ?? null),
        ];

        DocumentGeneratorSignature::query()->updateOrCreate(
            ['user_id' => (int) $user->getKey()],
            $attributes,
        );

        $this->deleteSignatureFiles($oldPaths);

        /** @var User $freshUser */
        $freshUser = $user->fresh('documentGeneratorSignature') ?? $user;

        return $this->payload($freshUser);
    }

    private function signatureFeatureEnabled(): bool
    {
        return (bool) config('services.document_generator.signature_enabled', true);
    }

    private function normalizeAnchorText(mixed $value): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteSignatureFiles(array $paths): void
    {
        foreach (array_values(array_unique($paths)) as $path) {
            if (DocumentStorage::disk()->exists($path)) {
                DocumentStorage::disk()->delete($path);
            }
        }
    }
}
