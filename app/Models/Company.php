<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'user_id',
    'client_id',
    'uuid',
    'name',
    'name_normalized',
    'tin',
    'tin_normalized',
])]
class Company extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $company): void {
            if (! filled($company->uuid)) {
                $company->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(Form1702ExBatchRow::class);
    }
}
