<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PlanStoreProduct extends Model
{
    protected $fillable = ['subscription_plan_id', 'platform', 'environment', 'product_id', 'base_plan_id', 'plan_revision', 'expected_metadata'];

    protected function casts(): array
    {
        return ['expected_metadata' => 'array', 'checked_at' => 'immutable_datetime', 'plan_revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $product): void {
            if ($product->isDirty(['subscription_plan_id', 'platform', 'environment', 'product_id', 'base_plan_id'])) {
                throw new LogicException('Store mapping identities cannot be reassigned. Create a new mapping.');
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
