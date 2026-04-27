<?php

declare(strict_types=1);

namespace App\Http\Requests\AfsFiling;

use App\Enums\AfsFilingSignatureAnchor;
use App\Enums\AfsFilingSignaturePlacementMode;
use App\Http\Requests\BaseFormRequest;

abstract class AfsFilingSignaturePayloadRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $anchorValues = array_map(
            static fn (AfsFilingSignatureAnchor $anchor): string => $anchor->value,
            AfsFilingSignatureAnchor::cases(),
        );

        $placementValues = array_map(
            static fn (AfsFilingSignaturePlacementMode $mode): string => $mode->value,
            AfsFilingSignaturePlacementMode::cases(),
        );

        return [
            'signature_file' => [$this->signatureFileRule(), 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'page2_anchor' => ['required', 'in:'.implode(',', $anchorValues)],
            'page2_placement_mode' => ['nullable', 'in:'.implode(',', $placementValues)],
            'page2_anchor_text' => ['nullable', 'string', 'max:255', 'required_if:page2_placement_mode,text_anchor'],
            'page2_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page2_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page2_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page2_height' => ['required', 'numeric', 'min:1', 'max:300'],
            'page3_anchor' => ['required', 'in:'.implode(',', $anchorValues)],
            'page3_placement_mode' => ['nullable', 'in:'.implode(',', $placementValues)],
            'page3_anchor_text' => ['nullable', 'string', 'max:255', 'required_if:page3_placement_mode,text_anchor'],
            'page3_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page3_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page3_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page3_height' => ['required', 'numeric', 'min:1', 'max:300'],
            'page4_anchor' => ['required', 'in:'.implode(',', $anchorValues)],
            'page4_placement_mode' => ['nullable', 'in:'.implode(',', $placementValues)],
            'page4_anchor_text' => ['nullable', 'string', 'max:255', 'required_if:page4_placement_mode,text_anchor'],
            'page4_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page4_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page4_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page4_height' => ['required', 'numeric', 'min:1', 'max:300'],
            'page8_anchor' => ['required', 'in:'.implode(',', $anchorValues)],
            'page8_placement_mode' => ['nullable', 'in:'.implode(',', $placementValues)],
            'page8_anchor_text' => ['nullable', 'string', 'max:255', 'required_if:page8_placement_mode,text_anchor'],
            'page8_offset_x' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page8_offset_y' => ['required', 'numeric', 'min:-500', 'max:500'],
            'page8_width' => ['required', 'numeric', 'min:1', 'max:300'],
            'page8_height' => ['required', 'numeric', 'min:1', 'max:300'],
        ];
    }

    abstract protected function signatureFileRule(): string;
}
