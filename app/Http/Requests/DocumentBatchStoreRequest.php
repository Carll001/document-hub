<?php

namespace App\Http\Requests;

use App\Models\DocumentGeneratorTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DocumentBatchStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'excel_file' => ['required', 'file', 'mimes:xls,xlsx'],
            'default_template_file' => ['nullable', 'file', 'mimes:docx'],
            'year_templates' => ['nullable', 'array'],
            'year_templates.*.year' => ['required_with:year_templates.*.template_file', 'integer', 'digits:4'],
            'year_templates.*.template_file' => ['required_with:year_templates.*.year', 'file', 'mimes:docx'],
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

                $yearTemplates = $this->input('year_templates', []);
                if (! is_array($yearTemplates)) {
                    return;
                }

                $years = [];
                foreach ($yearTemplates as $index => $template) {
                    if (! is_array($template)) {
                        continue;
                    }

                    $year = $template['year'] ?? null;
                    if ($year === null || $year === '') {
                        continue;
                    }

                    if (in_array((string) $year, $years, true)) {
                        $validator->errors()->add("year_templates.{$index}.year", 'Year template entries must use unique years.');
                    }

                    $years[] = (string) $year;
                }
            },
        ];
    }
}
