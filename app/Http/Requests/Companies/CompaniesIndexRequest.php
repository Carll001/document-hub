<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Http\Requests\BaseFormRequest;

class CompaniesIndexRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'in:name,tin,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'perPage' => ['nullable', 'integer', 'in:10,25,50,100'],
        ];
    }
}

