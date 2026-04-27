<?php

declare(strict_types=1);

namespace App\Enums;

enum AfsFilingSignatureAnchor: string
{
    case TopLeft = 'top_left';
    case TopRight = 'top_right';
    case BottomLeft = 'bottom_left';
    case BottomRight = 'bottom_right';
    case Center = 'center';
}
