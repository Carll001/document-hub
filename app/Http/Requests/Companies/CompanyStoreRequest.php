<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Http\Requests\BaseFormRequest;

class CompanyStoreRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tin' => ['required', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}

