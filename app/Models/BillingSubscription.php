<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingSubscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }
}
