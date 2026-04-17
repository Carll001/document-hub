<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable([
    'form_1702_ex_batch_id',
    'client_id',
    'company_id',
    'uuid',
    'source_name',
    'source_type',
    'source_row_number',
    'uploaded_at',
    'payload',
    'pdf_status',
    'pdf_error',
    'generated_pdf_file_name',
    'generated_pdf_storage_path',
    'generated_pdf_file_size',
    'generated_at',
    'receipt_file_name',
    'receipt_storage_path',
    'receipt_file_size',
    'receipt_is_temporary',
    'receipt_job_status',
    'receipt_job_error',
    'auto_receipt_synced_email_id',
    'auto_receipt_status',
    'auto_receipt_error',
    'duplicate_resolution_status',
    'duplicate_of_form_1702_ex_batch_row_id',
    'duplicate_resolved_at',
    'completed_email_auto_hash',
    'completed_email_auto_recipient',
    'completed_email_recipient',
    'completed_email_auto_queued_at',
])]
class Form1702ExBatchRow extends Model
{
    protected $table = 'form_1702_ex_batch_rows';

    public const PDF_STATUS_QUEUED = 'queued';

    public const PDF_STATUS_PROCESSING = 'processing';

    public const PDF_STATUS_GENERATED = 'generated';

    public const PDF_STATUS_FAILED = 'failed';

    public const RECEIPT_JOB_STATUS_QUEUED = 'queued';

    public const RECEIPT_JOB_STATUS_PROCESSING = 'processing';

    public const RECEIPT_JOB_STATUS_FAILED = 'failed';

    public const DUPLICATE_RESOLUTION_SKIPPED = 'skipped_duplicate';

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if (! filled($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (self $row): void {
            $baseStoragePath = $row->receiptBaseStoragePath();

            if (filled($row->generated_pdf_storage_path)) {
                Storage::disk('local')->delete($row->generated_pdf_storage_path);
            }

            if (filled($row->receipt_storage_path)) {
                Storage::disk('local')->delete($row->receipt_storage_path);
            }

            if ($baseStoragePath !== '' && Storage::disk('local')->exists($baseStoragePath)) {
                Storage::disk('local')->delete($baseStoragePath);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'uploaded_at' => 'datetime',
            'generated_pdf_file_size' => 'integer',
            'generated_at' => 'datetime',
            'receipt_file_size' => 'integer',
            'receipt_is_temporary' => 'boolean',
            'auto_receipt_synced_email_id' => 'integer',
            'duplicate_of_form_1702_ex_batch_row_id' => 'integer',
            'duplicate_resolved_at' => 'datetime',
            'completed_email_auto_queued_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Form1702ExBatch::class, 'form_1702_ex_batch_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function autoReceiptSyncedEmail(): BelongsTo
    {
        return $this->belongsTo(SyncedEmail::class, 'auto_receipt_synced_email_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_form_1702_ex_batch_row_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isProcessing(): bool
    {
        return in_array($this->pdf_status, [
            self::PDF_STATUS_QUEUED,
            self::PDF_STATUS_PROCESSING,
        ], true);
    }

    public function receiptJobIsBusy(): bool
    {
        return in_array($this->receipt_job_status, [
            self::RECEIPT_JOB_STATUS_QUEUED,
            self::RECEIPT_JOB_STATUS_PROCESSING,
        ], true);
    }

    public function isSkippedDuplicate(): bool
    {
        return $this->duplicate_resolution_status === self::DUPLICATE_RESOLUTION_SKIPPED;
    }

    public function receiptBaseStoragePath(): string
    {
        $this->loadMissing('batch');

        return sprintf(
            'forms/%d/1702-ex/receipt-bases/%d/base-%s.pdf',
            $this->batch->user_id,
            $this->form_1702_ex_batch_id,
            $this->uuid,
        );
    }
}
