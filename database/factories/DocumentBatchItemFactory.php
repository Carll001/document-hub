<?php

namespace Database\Factories;

use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentBatchItem>
 */
class DocumentBatchItemFactory extends Factory
{
    protected $model = DocumentBatchItem::class;

    public function definition(): array
    {
        return [
            'document_batch_id' => DocumentBatch::factory(),
            'row_number' => 2,
            'row_data' => [
                'Name' => fake()->name(),
                'Email' => fake()->safeEmail(),
            ],
            'status' => 'queued',
            'docx_path' => null,
            'pdf_path' => null,
            'error_message' => null,
            'error_details' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
