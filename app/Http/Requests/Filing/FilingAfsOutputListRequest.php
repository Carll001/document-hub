<?php

declare(strict_types=1);

namespace App\Http\Requests\Filing;

use App\Http\Requests\BaseFormRequest;

class FilingAfsOutputListRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'companyId' => ['nullable', 'array'],
            'companyId.*' => ['integer', 'exists:companies,id'],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }
}

