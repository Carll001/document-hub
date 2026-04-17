<?php

namespace Database\Factories;

use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentBatchItemActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentBatchItemActivityLog>
 */
class DocumentBatchItemActivityLogFactory extends Factory
{
    protected $model = DocumentBatchItemActivityLog::class;

    public function definition(): array
    {
        return [
            'document_batch_id' => DocumentBatch::factory(),
            'document_batch_item_id' => DocumentBatchItem::factory(),
            'user_id' => User::factory(),
            'action' => 'row_updated',
            'summary' => 'Row data updated.',
            'details' => [
                'before' => ['Name' => 'Jane'],
                'after' => ['Name' => 'John'],
            ],
        ];
    }
}
