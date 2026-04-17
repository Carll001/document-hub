<?php

namespace Database\Factories;

use App\Models\DocumentBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentBatch>
 */
class DocumentBatchFactory extends Factory
{
    protected $model = DocumentBatch::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source_excel_name' => 'sample.xlsx',
            'template_name' => 'template.docx',
            'excel_path' => 'document-generator/1/uploads/sample.xlsx',
            'template_path' => 'document-generator/1/uploads/template.docx',
            'sheet_index' => 0,
            'headers_json' => ['Name', 'Email'],
            'total_items' => 1,
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
            'status' => 'queued',
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
