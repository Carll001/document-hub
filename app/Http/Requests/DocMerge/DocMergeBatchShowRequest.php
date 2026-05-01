<?php

declare(strict_types=1);

namespace App\Http\Requests\DocMerge;

use App\Http\Requests\BaseFormRequest;

class DocMergeBatchShowRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

