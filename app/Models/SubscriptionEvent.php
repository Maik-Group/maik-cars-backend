<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionEvent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload', 'deduplication_key'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'attempts' => 'integer', 'processed_at' => 'immutable_datetime'];
    }
}
