<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function grant(): BelongsTo
    {
        return $this->belongsTo(CreditGrant::class, 'credit_grant_id');
    }
}
