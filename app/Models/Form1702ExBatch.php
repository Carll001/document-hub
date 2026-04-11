<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'user_id',
    'uuid',
    'name',
    'file_name_prefix',
    'footer_source_path',
    'footer_printed_date',
    'receipt_acceptance_start_date',
])]
class Form1702ExBatch extends Model
{
    protected $table = 'form_1702_ex_batches';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_name_prefix' => 'string',
            'footer_source_path' => 'string',
            'footer_printed_date' => 'string',
            'receipt_acceptance_start_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            if (! filled($batch->uuid)) {
                $batch->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (self $batch): void {
            $batch->rows()->get()->each->delete();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(Form1702ExBatchRow::class, 'form_1702_ex_batch_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isProcessing(): bool
    {
        return $this->rows()
            ->whereIn('pdf_status', [
                Form1702ExBatchRow::PDF_STATUS_QUEUED,
                Form1702ExBatchRow::PDF_STATUS_PROCESSING,
            ])
            ->exists();
    }

    public function hasActiveReceiptJobs(): bool
    {
        return $this->rows()
            ->whereIn('receipt_job_status', [
                Form1702ExBatchRow::RECEIPT_JOB_STATUS_QUEUED,
                Form1702ExBatchRow::RECEIPT_JOB_STATUS_PROCESSING,
            ])
            ->exists();
    }
}
