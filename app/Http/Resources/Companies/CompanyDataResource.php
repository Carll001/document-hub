<?php

declare(strict_types=1);

namespace App\Http\Resources\Companies;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyDataResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'tin' => (string) $this->tin,
            'address' => (string) ($this->address ?? ''),
            'imported_via_excel' => (bool) $this->imported_via_excel,
            'data' => is_array($this->data) ? $this->data : [],
        ];
    }
}

