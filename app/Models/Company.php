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
    'address',
    'data',
    'imported_via_excel',
])]
class Company extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'imported_via_excel' => 'boolean',
        ];
    }

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
