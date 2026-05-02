<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Http\Requests\BaseFormRequest;

class CompaniesImportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'spreadsheet' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:15360'],
            'overwrite_existing' => ['nullable', 'boolean'],
        ];
    }
}

