<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'input_mode',
    'input_label',
    'group_label',
    'output_file_name',
    'error_message',
])]
class BulkMergeFailure extends Model
{
    /**
     * Get the user that owns the failed bulk-merge result.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
