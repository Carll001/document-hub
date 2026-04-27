<?php

declare(strict_types=1);

namespace App\Http\Requests\AfsFiling;

use App\Http\Requests\BaseFormRequest;

class AfsFilingItemSignRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'president_signature_file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ];
    }
}

