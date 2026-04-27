<?php

declare(strict_types=1);

namespace App\Http\Requests\AfsFiling;

class AfsFilingSignatureUpdateRequest extends AfsFilingSignaturePayloadRequest
{
    protected function signatureFileRule(): string
    {
        return 'nullable';
    }
}
