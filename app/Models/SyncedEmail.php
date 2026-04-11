<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'user_id',
    'mailbox',
    'imap_uid',
    'message_id',
    'from_name',
    'from_email',
    'subject',
    'body_preview',
    'body_text',
    'body_html',
    'received_at',
    'synced_at',
])]
class SyncedEmail extends Model
{
    /**
     * Clean up stored attachment files when a synced email is deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $email): void {
            /** @var Collection<int, string> $paths */
            $paths = $email->attachments()
                ->pluck('storage_path');

            foreach ($paths as $path) {
                Storage::disk('local')->delete((string) $path);
            }

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
        ];
    }

    /**
     * Get the user that owns the synced email.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attachments stored for the synced email.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SyncedEmailAttachment::class);
    }
}
