<?php

declare(strict_types=1);

namespace App\Http\Requests\AfsFiling;

class AfsFilingSignatureStoreRequest extends AfsFilingSignaturePayloadRequest
{
    protected function signatureFileRule(): string
    {
        return 'required';
    }
}
