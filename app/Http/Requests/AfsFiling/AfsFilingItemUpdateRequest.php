<?php

declare(strict_types=1);

namespace App\Http\Requests\AfsFiling;

use App\Http\Requests\BaseFormRequest;

class AfsFilingItemUpdateRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'row_data' => ['required', 'array', 'min:1'],
        ];
    }
}

