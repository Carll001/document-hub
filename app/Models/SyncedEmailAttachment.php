<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'synced_email_id',
    'file_name',
    'storage_path',
    'content_type',
    'file_size',
])]
class SyncedEmailAttachment extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    /**
     * Get the parent synced email.
     */
    public function syncedEmail(): BelongsTo
    {
        return $this->belongsTo(SyncedEmail::class);
    }
}
