<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['encrypted_payload', 'last_error'];

    protected function casts(): array
    {
        return [
            'encrypted_payload' => 'encrypted',
            'received_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
