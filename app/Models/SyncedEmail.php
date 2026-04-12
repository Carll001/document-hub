<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'mailbox',
    'imap_uid',
    'message_id',
    'from_name',
    'from_email',
    'subject',
    'body_preview',
    'body_text',
    'body_html',
    'bir_receipt_file_name',
    'bir_receipt_date_received_by_bir',
    'bir_receipt_time_received_by_bir',
    'bir_receipt_tin',
    'matched_form_1702_ex_batch_row_id',
    'bir_receipt_match_status',
    'bir_receipt_queued_at',
    'bir_receipt_applied_at',
    'bir_receipt_match_error',
    'received_at',
    'synced_at',
    'claimed_by_user_id',
    'claimed_at',
])]
class SyncedEmail extends Model
{
    /**
     * Clean up stored attachment files when a synced email is deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $email): void {
            Storage::disk('local')->deleteDirectory("email-sync/shared/{$email->id}");
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'synced_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    /**
     * Get the user that has claimed the synced email.
     */
    public function claimedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    /**
     * Get the attachments stored for the synced email.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SyncedEmailAttachment::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visibleQuery) use ($user): void {
            $visibleQuery
                ->whereNull('claimed_by_user_id')
                ->orWhere('claimed_by_user_id', $user->getKey());
        });
    }

    public function scopeClaimedBy(Builder $query, User $user): Builder
    {
        return $query->where('claimed_by_user_id', $user->getKey());
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->claimed_by_user_id === null
            ? $user->isStaff()
            : (int) $this->claimed_by_user_id === (int) $user->getKey();
    }
}
