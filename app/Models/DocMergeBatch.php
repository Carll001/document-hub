<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'user_id',
    'uuid',
    'name',
    'last_processed_at',
    'processing_status',
    'processing_error',
])]
class DocMergeBatch extends Model
{
    public const PROCESSING_STATUS_QUEUED = 'queued';

    public const PROCESSING_STATUS_PROCESSING = 'processing';

    public const PROCESSING_STATUS_FAILED = 'failed';

    /**
     * Delete stored batch source files and linked results when the batch is removed.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $batch): void {
            $batch->mergedPdfs()->get()->each->delete();
            $batch->bulkMergeFailures()->delete();
            $batch->sourceFiles()->get()->each->delete();

            Storage::disk('local')->deleteDirectory(
                sprintf('doc-merge/%d/batches/%d', $batch->user_id, $batch->id),
            );
        });

        static::creating(function (self $batch): void {
            if (! filled($batch->uuid)) {
                $batch->uuid = (string) \Illuminate\Support\Str::uuid();
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
            'last_processed_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the batch.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the persisted source files for the batch.
     */
    public function sourceFiles(): HasMany
    {
        return $this->hasMany(DocMergeBatchSourceFile::class);
    }

    /**
     * Get the latest merged outputs linked to the batch.
     */
    public function mergedPdfs(): HasMany
    {
        return $this->hasMany(MergedPdf::class);
    }

    /**
     * Get the latest failures linked to the batch.
     */
    public function bulkMergeFailures(): HasMany
    {
        return $this->hasMany(BulkMergeFailure::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isBusy(): bool
    {
        return in_array($this->processing_status, [
            self::PROCESSING_STATUS_QUEUED,
            self::PROCESSING_STATUS_PROCESSING,
        ], true);
    }
}
