<?php

declare(strict_types=1);

namespace App\Http\Requests\Filing;

use App\Http\Requests\BaseFormRequest;

class FilingAfsGenerateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'filingType' => ['required', 'string', 'in:afs'],
            'companyId' => ['required', 'array', 'min:1'],
            'companyId.*' => ['integer', 'exists:companies,id'],
            'overwriteExisting' => ['nullable', 'boolean'],
            'presidentSignature' => ['nullable', 'array'],
            'presidentSignature.*' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'getorSignature' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ];
    }
}
