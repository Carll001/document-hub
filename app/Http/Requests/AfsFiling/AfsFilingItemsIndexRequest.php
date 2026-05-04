<?php

declare(strict_types=1);

namespace App\Http\Requests\AfsFiling;

use App\Http\Requests\BaseFormRequest;

class AfsFilingItemsIndexRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_by' => ['nullable', 'in:created_at,status,row_number,updated_at'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:queued,processing,signing,deleting,docx_done,generated,signed,failed'],
            'company_search' => ['nullable', 'string', 'max:255'],
            'unsigned_only' => ['nullable', 'boolean'],
            'completed_only' => ['nullable', 'boolean'],
        ];
    }
}
