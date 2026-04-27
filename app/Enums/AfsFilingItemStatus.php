<?php

declare(strict_types=1);

namespace App\Enums;

enum AfsFilingItemStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case DocxDone = 'docx_done';
    case PdfDone = 'pdf_done';
    case Failed = 'failed';
}
