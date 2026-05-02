<?php

declare(strict_types=1);

namespace App\Http\Resources\Companies;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyIndexResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tin' => $this->tin,
            'address' => (string) ($this->address ?? ''),
            'created_at' => $this->created_at?->toDateString(),
        ];
    }
}

