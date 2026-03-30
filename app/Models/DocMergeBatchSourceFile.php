<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'doc_merge_batch_id',
    'page_folder_name',
    'page_folder_number',
    'display_name',
    'storage_path',
    'file_size',
    'match_key',
    'group_label',
])]
class DocMergeBatchSourceFile extends Model
{
    /**
     * Delete the stored source PDF when the record is removed.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $sourceFile): void {
            Storage::disk('local')->delete($sourceFile->storage_path);
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
            'page_folder_number' => 'integer',
            'file_size' => 'integer',
        ];
    }

    /**
     * Get the batch that owns the source file.
     */
    public function docMergeBatch(): BelongsTo
    {
        return $this->belongsTo(DocMergeBatch::class);
    }
}
