<?php

declare(strict_types=1);

namespace App\Http\Resources\Filing;

use App\Models\FilingOutput;
use App\Support\DocumentStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FilingOutput */
class FilingOutputResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $filePath = trim((string) ($this->file_path ?? ''));

        return [
            'id' => (int) $this->id,
            'company_id' => (int) ($this->company_id ?? 0),
            'name' => (string) $this->company_name,
            'tin' => (string) $this->tin,
            'form_type' => (string) $this->form_type,
            'status' => (string) $this->status,
            'file_path' => $filePath !== '' ? $filePath : null,
            'pdf_available' => $filePath !== '' && DocumentStorage::disk()->exists($filePath),
            'error_message' => $this->error_message,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

