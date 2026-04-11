<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'recipient_email',
    'recipient_email_normalized',
    'latest_group_hash',
    'latest_group_queued_at',
])]
class Form1702ExCompletedEmailRecipient extends Model
{
    protected $table = 'form_1702_ex_completed_email_recipients';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latest_group_queued_at' => 'datetime',
        ];
    }
}
