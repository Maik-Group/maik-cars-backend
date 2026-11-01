<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BillingAgreement extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['purchase_reference', 'identity_fingerprint', 'reference_fingerprint'];

    protected function casts(): array
    {
        return ['purchase_reference' => 'encrypted', 'features' => 'array', 'daily_allowance' => 'integer', 'auto_renews' => 'boolean', 'acknowledgement_pending' => 'boolean', 'verified_at' => 'immutable_datetime', 'suppressed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PlanStoreProduct::class, 'plan_store_product_id');
    }

    public function entitlement(): HasOne
    {
        return $this->hasOne(SubscriptionEntitlement::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PurchaseTransaction::class);
    }
}
