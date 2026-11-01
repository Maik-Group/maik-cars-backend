<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplimentaryAllocation extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $allocation): void {
            if ($allocation->isDirty(['user_id', 'credits', 'months', 'issuance_mode', 'starts_at', 'ends_at', 'batch_recipient_key', 'subscription_plan_id', 'plan_snapshot', 'environment'])) {
                throw new \LogicException('Complimentary allocation terms are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'plan_snapshot' => 'array'];
    }
}
