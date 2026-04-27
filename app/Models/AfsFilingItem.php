<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AfsFilingItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'afs_filing_items';

    protected $fillable = [
        'user_id',
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
        'source_excel_name',
        'template_name',
    ];

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AfsFilingItemActivityLog::class, 'afs_filing_item_id');
    }
}
