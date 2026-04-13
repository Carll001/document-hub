<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'display_name',
    'username',
    'password',
    'host',
    'port',
    'encryption',
    'mailbox',
    'validate_certificate',
    'is_active',
])]
class EmailSyncAccount extends Model
{
    use SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'validate_certificate' => 'boolean',
            'is_active' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the synced emails fetched from this account.
     */
    public function syncedEmails(): HasMany
    {
        return $this->hasMany(SyncedEmail::class);
    }

    public function label(): string
    {
        $displayName = trim((string) $this->display_name);

        return $displayName !== ''
            ? $displayName
            : trim((string) $this->username);
    }
}
