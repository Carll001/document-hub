<?php

declare(strict_types=1);

namespace App\Http\Requests\Filing;

use App\Http\Requests\BaseFormRequest;

class FilingIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'step' => ['nullable', 'integer', 'min:1', 'max:4'],
            'companyId' => ['nullable', 'array'],
            'companyId.*' => ['integer', 'exists:companies,id'],
            'filingType' => ['nullable', 'string', 'in:afs,1702ex'],
        ];
    }
}
