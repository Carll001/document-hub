<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'user_id',
    'doc_merge_batch_id',
    'uuid',
    'file_name',
    'storage_path',
    'file_size',
    'source_count',
    'source_file_names',
    'tin_number',
    'footer_text',
    'receipt_file_name',
    'receipt_storage_path',
    'receipt_file_size',
    'receipt_job_status',
    'receipt_job_error',
])]
class MergedPdf extends Model
{
    public const RECEIPT_JOB_STATUS_QUEUED = 'queued';

    public const RECEIPT_JOB_STATUS_PROCESSING = 'processing';

    public const RECEIPT_JOB_STATUS_FAILED = 'failed';

    /**
     * Delete the stored merged PDF file when the record is removed.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $mergedPdf): void {
            $disk = Storage::disk('s3');

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

        static::creating(function (self $mergedPdf): void {
            if (! filled($mergedPdf->uuid)) {
                $mergedPdf->uuid = (string) \Illuminate\Support\Str::uuid();
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

    /**
     * Get the batch that produced the merged PDF, if any.
     */
    public function docMergeBatch(): BelongsTo
    {
        return $this->belongsTo(DocMergeBatch::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function receiptJobIsBusy(): bool
    {
        return in_array($this->receipt_job_status, [
            self::RECEIPT_JOB_STATUS_QUEUED,
            self::RECEIPT_JOB_STATUS_PROCESSING,
        ], true);
    }
}
