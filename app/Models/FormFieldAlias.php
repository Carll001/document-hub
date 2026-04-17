<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormFieldAlias extends Model
{
    protected $fillable = [
        'form_type',
        'canonical_key',
        'aliases_json',
    ];

    protected function casts(): array
    {
        return [
            'aliases_json' => 'array',
        ];
    }
}
