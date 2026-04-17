<?php

namespace App\Services;

use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentBatchItemActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class DocumentBatchActivityLogger
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function log(
        DocumentBatch $batch,
        DocumentBatchItem $item,
        ?User $user,
        string $action,
        string $summary,
        ?array $details = null
    ): void {
        if (! Schema::hasTable('document_batch_item_activity_logs')) {
            return;
        }

        DocumentBatchItemActivityLog::query()->create([
            'document_batch_id' => $batch->id,
            'document_batch_item_id' => $item->id,
            'user_id' => $user?->id,
            'action' => $action,
            'summary' => $summary,
            'details' => $details,
        ]);
    }
}
