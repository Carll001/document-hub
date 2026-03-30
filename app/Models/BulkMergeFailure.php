<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'doc_merge_batch_id',
    'uuid',
    'input_mode',
    'input_label',
    'group_label',
    'output_file_name',
    'error_message',
])]
class BulkMergeFailure extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $failure): void {
            if (! filled($failure->uuid)) {
                $failure->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * Get the user that owns the failed bulk-merge result.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the batch that owns the failure, if any.
     */
    public function docMergeBatch(): BelongsTo
    {
        return $this->belongsTo(DocMergeBatch::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
