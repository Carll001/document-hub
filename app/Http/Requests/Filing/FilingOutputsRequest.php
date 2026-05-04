<?php

declare(strict_types=1);

namespace App\Http\Requests\Filing;

use App\Http\Requests\BaseFormRequest;

class FilingOutputsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'formType' => ['nullable', 'string', 'in:afs,1702ex'],
            'status' => ['nullable', 'string', 'max:32'],
        ];
    }
}
