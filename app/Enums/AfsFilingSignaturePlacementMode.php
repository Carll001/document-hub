<?php

declare(strict_types=1);

namespace App\Enums;

enum AfsFilingSignaturePlacementMode: string
{
    case Fixed = 'fixed';
    case TextAnchor = 'text_anchor';
}
