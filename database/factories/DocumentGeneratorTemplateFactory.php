<?php

namespace Database\Factories;

use App\Models\DocumentGeneratorTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentGeneratorTemplate>
 */
class DocumentGeneratorTemplateFactory extends Factory
{
    protected $model = DocumentGeneratorTemplate::class;

    public function definition(): array
    {
        return [
            'year' => null,
            'template_name' => 'template.docx',
            'template_path' => 'document-generator/global-templates/template.docx',
        ];
    }
}
