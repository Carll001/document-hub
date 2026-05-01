<?php

declare(strict_types=1);

namespace App\Http\Requests\DocMerge;

use App\Http\Requests\BaseFormRequest;

class DocMergeBatchCursorRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

