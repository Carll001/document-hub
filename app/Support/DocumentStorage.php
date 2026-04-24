<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

final class DocumentStorage
{
    public static function diskName(): string
    {
        $disk = (string) config('filesystems.default', 'local');

        return $disk !== '' ? $disk : 'local';
    }

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::diskName());
    }

    public static function isValidPath(mixed $path): bool
    {
        if (! is_string($path)) {
            return false;
        }

        $normalized = trim($path);

        return $normalized !== '' && $normalized !== '0';
    }
}
