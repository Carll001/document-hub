<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AfsFilingItemActivityLog extends Model
{
    use HasFactory;

    protected $table = 'afs_filing_item_activity_logs';

    protected $fillable = [
        'afs_filing_item_id',
        'user_id',
        'action',
        'summary',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AfsFilingItem::class, 'afs_filing_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
