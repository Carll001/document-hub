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
        ];
    }
}

