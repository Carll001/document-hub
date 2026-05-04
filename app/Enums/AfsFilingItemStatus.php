<?php

declare(strict_types=1);

namespace App\Enums;

enum AfsFilingItemStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case DocxDone = 'docx_done';
    case Generated = 'generated';
    case Signed = 'signed';
    case Failed = 'failed';
}
