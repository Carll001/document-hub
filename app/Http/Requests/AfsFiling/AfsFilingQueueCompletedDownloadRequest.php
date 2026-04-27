<?php

declare(strict_types=1);

namespace App\Http\Requests\AfsFiling;

use App\Http\Requests\BaseFormRequest;

class AfsFilingQueueCompletedDownloadRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'company_search' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', 'in:created_at,status,row_number,updated_at'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'item_ids' => ['nullable', 'array', 'min:1'],
            'item_ids.*' => ['required', 'integer'],
        ];
    }
}
