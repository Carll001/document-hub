<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'user_id',
    'file_name',
    'storage_path',
    'file_size',
    'source_count',
    'source_file_names',
])]
class MergedPdf extends Model
{
    /**
     * Delete the stored merged PDF file when the record is removed.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $mergedPdf): void {
            Storage::disk('local')->delete($mergedPdf->storage_path);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'source_count' => 'integer',
            'source_file_names' => 'array',
        ];
    }

    /**
     * Get the user that owns the merged PDF.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
