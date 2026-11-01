<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditGrant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'held_at' => 'immutable_datetime'];
    }
}
