<?php

declare(strict_types=1);

namespace App\Services\AfsFiling;

class AfsFilingImportStateService
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    public function cacheKey(int $userId): string
    {
        return "afs-filing:import-state:{$userId}";
    }

    /**
     * @return array{status: 'queued'|'processing'|'failed'|null, fileName: string|null, error: string|null}
     */
    public function getState(int $userId): array
    {
        $state = cache()->get($this->cacheKey($userId));
        if (! is_array($state)) {
            return [
                'status' => null,
                'fileName' => null,
                'error' => null,
            ];
        }

        $status = is_string($state['status'] ?? null) ? $state['status'] : null;
        $fileName = is_string($state['fileName'] ?? null) && trim((string) $state['fileName']) !== ''
            ? (string) $state['fileName']
            : null;
        $error = is_string($state['error'] ?? null) && trim((string) $state['error']) !== ''
            ? (string) $state['error']
            : null;

        if (! in_array($status, [self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_FAILED], true)) {
            $status = null;
        }

        return [
            'status' => $status,
            'fileName' => $fileName,
            'error' => $error,
        ];
    }

    public function putQueued(int $userId, string $fileName): void
    {
        $this->putState($userId, self::STATUS_QUEUED, $fileName, null);
    }

    public function putProcessing(int $userId, string $fileName): void
    {
        $this->putState($userId, self::STATUS_PROCESSING, $fileName, null);
    }

    public function putFailed(int $userId, string $fileName, string $error): void
    {
        $this->putState($userId, self::STATUS_FAILED, $fileName, $error);
    }

    public function clear(int $userId): void
    {
        cache()->forget($this->cacheKey($userId));
    }

    private function putState(int $userId, string $status, string $fileName, ?string $error): void
    {
        cache()->put($this->cacheKey($userId), [
            'status' => $status,
            'fileName' => trim($fileName) !== '' ? $fileName : null,
            'error' => $error !== null && trim($error) !== '' ? $error : null,
        ], now()->addHours(1));
    }
}

