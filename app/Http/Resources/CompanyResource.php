<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tin' => $this->tin,
            'address' => (string) ($this->address ?? ''),
            'data' => is_array($this->data) ? $this->data : [],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
