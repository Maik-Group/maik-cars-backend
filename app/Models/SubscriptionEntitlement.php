<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionEntitlement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['features' => 'array', 'daily_allowance' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(BillingAgreement::class, 'billing_agreement_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
