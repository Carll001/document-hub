<?php

declare(strict_types=1);

namespace App\Http\Requests\AfsFiling;

use App\Http\Requests\BaseFormRequest;

class AfsFilingPageIndexRequest extends BaseFormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:uploadedAt,generatedAt,pdfStatus,sourceRowNumber,created_at,updated_at,status,row_number'],
            'direction' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:queued,processing,signing,deleting,docx_done,generated,signed,failed'],
            'open_settings' => ['nullable', 'boolean'],
        ];
    }
}
