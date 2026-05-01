<?php

declare(strict_types=1);

namespace App\Http\Resources\DocMerge;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocMergeBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'mergedCount' => (int) ($this->merged_pdfs_count ?? 0),
            'failedCount' => (int) ($this->bulk_merge_failures_count ?? 0),
            'lastProcessedAt' => $this->last_processed_at?->toIso8601String(),
            'processingStatus' => $this->processing_status,
            'processingError' => $this->processing_error,
        ];
    }
}

