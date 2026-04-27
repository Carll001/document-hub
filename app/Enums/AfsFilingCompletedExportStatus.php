<?php

declare(strict_types=1);

namespace App\Enums;

enum AfsFilingCompletedExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Failed = 'failed';
    case Ready = 'ready';
}
