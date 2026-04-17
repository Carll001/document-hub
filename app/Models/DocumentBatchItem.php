<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentBatchItem extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentBatchItemFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_batch_id',
        'row_number',
        'row_data',
        'status',
        'docx_path',
        'pdf_path',
        'error_message',
        'error_details',
        'started_at',
        'completed_at',
        'signature_applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_data' => 'array',
            'error_details' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'signature_applied_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(DocumentBatch::class, 'document_batch_id');
    }

    /**
     * @return HasMany<DocumentBatchItemActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(DocumentBatchItemActivityLog::class, 'document_batch_item_id');
    }
}
