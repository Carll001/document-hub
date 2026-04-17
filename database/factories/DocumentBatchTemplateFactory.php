<?php

namespace Database\Factories;

use App\Models\DocumentBatch;
use App\Models\DocumentBatchTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentBatchTemplate>
 */
class DocumentBatchTemplateFactory extends Factory
{
    protected $model = DocumentBatchTemplate::class;

    public function definition(): array
    {
        return [
            'document_batch_id' => DocumentBatch::factory(),
            'year' => null,
            'template_name' => 'template.docx',
            'template_path' => 'document-generator/1/uploads/template.docx',
        ];
    }
}
