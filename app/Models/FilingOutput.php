<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilingOutput extends Model
{
    protected $fillable = [
        'company_id',
        'company_name',
        'tin',
        'form_type',
        'file_path',
        'file_name',
        'status',
        'error_message',
        'president_signature_path',
        'filing_signature',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
