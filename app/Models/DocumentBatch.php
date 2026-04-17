<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentBatch extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentBatchFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'source_excel_name',
        'template_name',
        'excel_path',
        'template_path',
        'sheet_index',
        'headers_json',
        'total_items',
        'processed_items',
        'success_items',
        'failed_items',
        'status',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DocumentBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DocumentBatchItem::class);
    }

    /**
     * @return HasMany<DocumentBatchTemplate, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(DocumentBatchTemplate::class);
    }

    /**
     * @return HasMany<DocumentBatchItemActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(DocumentBatchItemActivityLog::class);
    }
}
