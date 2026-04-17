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
    'login_user_id',
    'uuid',
    'name',
    'name_normalized',
])]
class Client extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $client): void {
            if (! filled($client->uuid)) {
                $client->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loginUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'login_user_id');
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(Form1702ExBatchRow::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
