<?php

declare(strict_types=1);

namespace App\Http\Resources\AfsFiling;

use App\Models\DocumentGeneratorSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentGeneratorSignature
 */
class AfsFilingSignatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'president' => [
                'page2' => $this->signatureLayout('page2'),
                'page3' => $this->signatureLayout('page3'),
            ],
            'getor' => [
                'page4' => $this->signatureLayout('page4'),
                'page8' => $this->signatureLayout('page8'),
                'preview_url' => route('afs-filing.signature.preview', [
                    'v' => $this->updated_at?->timestamp,
                ]),
            ],
        ];
    }

    private function signatureLayout(string $pageKey): array
    {
        return [
            'anchor' => (string) ($this->{"{$pageKey}_anchor"} ?: $this->anchor),
            'placement_mode' => (string) ($this->{"{$pageKey}_placement_mode"} ?: 'fixed'),
            'anchor_text' => (string) ($this->{"{$pageKey}_anchor_text"} ?: ''),
            'offset_x' => (float) ($this->{"{$pageKey}_offset_x"} ?? $this->offset_x),
            'offset_y' => (float) ($this->{"{$pageKey}_offset_y"} ?? $this->offset_y),
            'width' => (float) ($this->{"{$pageKey}_width"} ?? $this->width),
            'height' => (float) ($this->{"{$pageKey}_height"} ?? $this->height),
        ];
    }
}
