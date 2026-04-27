<?php

declare(strict_types=1);

namespace App\Http\Requests\AfsFiling;

use App\Http\Requests\BaseFormRequest;

class AfsFilingDestroyCompletedItemsRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['required', 'integer'],
        ];
    }
}
