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
    'processing_status',
    'processing_action',
    'processing_run_uuid',
    'processing_error',
    'processing_started_at',
    'last_sync_run_uuid',
    'last_sync_action',
    'last_sync_fetched_count',
    'last_sync_created_count',
    'last_sync_updated_count',
    'last_sync_completed_at',
])]
class EmailSyncAccount extends Model
{
    use SoftDeletes;

    public const PROCESSING_STATUS_QUEUED = 'queued';

    public const PROCESSING_STATUS_PROCESSING = 'processing';

    public const PROCESSING_STATUS_FAILED = 'failed';

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
            'last_sync_fetched_count' => 'integer',
            'last_sync_created_count' => 'integer',
            'last_sync_updated_count' => 'integer',
            'processing_started_at' => 'datetime',
            'last_sync_completed_at' => 'datetime',
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

    public function isBusy(): bool
    {
        return in_array($this->processing_status, [
            self::PROCESSING_STATUS_QUEUED,
            self::PROCESSING_STATUS_PROCESSING,
        ], true);
    }
}
