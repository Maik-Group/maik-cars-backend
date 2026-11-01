<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingInvoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime', 'amount_paid_minor' => 'integer',
        ];
    }
}
