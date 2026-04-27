<?php

declare(strict_types=1);

namespace App\Http\Requests\AfsFiling;

use App\Http\Requests\BaseFormRequest;
use App\Models\DocumentGeneratorTemplate;
use Illuminate\Validation\Validator;

class AfsFilingUploadRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'excel_file' => ['required', 'file', 'mimes:xls,xlsx'],
            'default_template_file' => ['nullable', 'file', 'mimes:docx'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasFile('default_template_file')
                    && ! DocumentGeneratorTemplate::query()->whereNull('year')->exists()) {
                    $validator->errors()->add('default_template_file', 'A default DOCX template is required when no global default template is configured.');
                }
            },
        ];
    }
}
