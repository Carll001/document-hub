<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'key',
    'file_name',
    'storage_path',
    'file_size',
    'uploaded_by_user_id',
])]
class ConfirmationTemplate extends Model
{
    public const SHARED_KEY = 'shared_receipt_template';

    /**
     * Remove the stored DOCX file when the shared template record is deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $template): void {
            if (filled($template->storage_path)) {
                Storage::disk('s3')->delete($template->storage_path);
            }
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
            'file_size' => 'integer',
        ];
    }

    /**
     * Return the shared receipt template record.
     */
    public static function shared(): ?self
    {
        return static::query()
            ->where('key', self::SHARED_KEY)
            ->first();
    }

    /**
     * Get the user who most recently uploaded the shared template.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
