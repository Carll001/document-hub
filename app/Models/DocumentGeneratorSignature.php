<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentGeneratorSignature extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentGeneratorSignatureFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'processed_signature_path',
        'original_signature_path',
        'anchor',
        'offset_x',
        'offset_y',
        'width',
        'height',
        'page2_anchor',
        'page2_offset_x',
        'page2_offset_y',
        'page2_width',
        'page2_height',
        'page3_anchor',
        'page3_offset_x',
        'page3_offset_y',
        'page3_width',
        'page3_height',
        'page4_anchor',
        'page4_offset_x',
        'page4_offset_y',
        'page4_width',
        'page4_height',
        'page8_anchor',
        'page8_offset_x',
        'page8_offset_y',
        'page8_width',
        'page8_height',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offset_x' => 'float',
            'offset_y' => 'float',
            'width' => 'float',
            'height' => 'float',
            'page2_offset_x' => 'float',
            'page2_offset_y' => 'float',
            'page2_width' => 'float',
            'page2_height' => 'float',
            'page3_offset_x' => 'float',
            'page3_offset_y' => 'float',
            'page3_width' => 'float',
            'page3_height' => 'float',
            'page4_offset_x' => 'float',
            'page4_offset_y' => 'float',
            'page4_width' => 'float',
            'page4_height' => 'float',
            'page8_offset_x' => 'float',
            'page8_offset_y' => 'float',
            'page8_width' => 'float',
            'page8_height' => 'float',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
