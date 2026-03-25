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
    'receipt_file_name',
    'receipt_storage_path',
    'receipt_file_size',
])]
class MergedPdf extends Model
{
    /**
     * Delete the stored merged PDF file when the record is removed.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $mergedPdf): void {
            $disk = Storage::disk('local');

            $disk->delete($mergedPdf->storage_path);
            $disk->deleteDirectory(
                sprintf(
                    'doc-merge/%d/receipt-bases/%d',
                    $mergedPdf->user_id,
                    $mergedPdf->id,
                ),
            );

            if (filled($mergedPdf->receipt_storage_path)) {
                $disk->delete($mergedPdf->receipt_storage_path);
            }
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
            'receipt_file_size' => 'integer',
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
