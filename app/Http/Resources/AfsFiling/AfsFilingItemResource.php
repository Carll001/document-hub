<?php

declare(strict_types=1);

namespace App\Http\Resources\AfsFiling;

use App\Support\DocumentStorage;
use App\Support\FormFieldAliasResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AfsFilingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rowData = is_array($this->row_data) ? $this->row_data : [];
        $company = FormFieldAliasResolver::resolveCompany($rowData, FormFieldAliasResolver::FORM_AFS);

        return [
            'id' => (int) $this->id,
            'row_number' => (int) $this->row_number,
            'company' => is_string($company) && trim($company) !== '' ? $company : '-',
            'tin' => FormFieldAliasResolver::resolveTin($rowData, FormFieldAliasResolver::FORM_AFS),
            'status' => (string) $this->status,
            'row_data' => $rowData,
            'docx_available' => is_string($this->docx_path) && $this->docx_path !== '' && DocumentStorage::disk()->exists($this->docx_path),
            'pdf_available' => is_string($this->pdf_path) && $this->pdf_path !== '' && DocumentStorage::disk()->exists($this->pdf_path),
            'signature_applied' => $this->signature_applied_at !== null,
            'signature_applied_at' => optional($this->signature_applied_at)?->toIso8601String(),
            'error_message' => $this->error_message,
            'error_details' => is_array($this->error_details) ? $this->error_details : null,
            'source_excel_name' => $this->source_excel_name,
            'template_name' => $this->template_name,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
