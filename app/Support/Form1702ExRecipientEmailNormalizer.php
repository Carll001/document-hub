<?php

declare(strict_types=1);

namespace App\Support;

class Form1702ExRecipientEmailNormalizer
{
    public function normalize(?string $value): ?string
    {
        $normalized = mb_strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
