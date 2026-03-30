<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Staff = 'staff';
    case Superadmin = 'superadmin';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Superadmin',
            self::Staff => 'Staff',
        };
    }
}
