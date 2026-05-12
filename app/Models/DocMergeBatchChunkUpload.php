<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'doc_merge_batch_id',
    'user_id',
    'uuid',
    'status',
    'expires_at',
    'manifest_json',
    'progress_json',
    'assembled_files_json',
])]
class DocMergeBatchChunkUpload extends Model
{
    public const STATUS_INITIATED = 'initiated';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_CANCELLED = 'cancelled';

    protected static function booted(): void
    {
        static::creating(function (self $upload): void {
            if (! filled($upload->uuid)) {
                $upload->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::deleting(function (self $upload): void {
            \App\Support\DocumentStorage::disk()->deleteDirectory(
                sprintf(
                    'doc-merge/%d/batches/%d/uploads-temp/%s',
                    $upload->user_id,
                    $upload->doc_merge_batch_id,
                    $upload->uuid,
                ),
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'manifest_json' => 'array',
            'progress_json' => 'array',
            'assembled_files_json' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DocMergeBatch::class, 'doc_merge_batch_id');
    }
}

