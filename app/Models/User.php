<?php

namespace App\Models;

use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable([
    'name',
    'email',
    'password',
    'role',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    public function isStaff(): bool
    {
        return $this->role === UserRole::Staff;
    }

    public function isSuperadmin(): bool
    {
        return $this->role === UserRole::Superadmin;
    }

    public function canAccessUserManagement(): bool
    {
        return $this->isSuperadmin();
    }

    public function canAccessMailboxAccounts(): bool
    {
        return $this->isSuperadmin();
    }

    public function canManageUser(self $user): bool
    {
        return $this->isSuperadmin() && $user->isStaff();
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    /**
     * @return HasMany<DocumentBatch, $this>
     */
    public function documentBatches(): HasMany
    {
        return $this->hasMany(DocumentBatch::class);
    }

    /**
     * @return HasOne<DocumentGeneratorSignature, $this>
     */
    public function documentGeneratorSignature(): HasOne
    {
        return $this->hasOne(DocumentGeneratorSignature::class);
    }
}
