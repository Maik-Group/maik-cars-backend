<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BillingPlanPrice extends Model
{
    protected $guarded = ['id'];

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $offer): void {
            if (! $offer->getRawOriginal('published_at')) {
                return;
            }
            $changed = array_keys($offer->getDirty());
            if (array_diff($changed, ['retired_at', 'updated_at'])) {
                throw new LogicException('Published billing offer snapshots are immutable.');
            }
            if ($offer->getRawOriginal('retired_at') && $offer->isDirty('retired_at')) {
                throw new LogicException('Retired billing offers cannot be reopened.');
            }
        });
        static::deleting(fn (self $offer) => $offer->published_at ? throw new LogicException('Published billing offers cannot be deleted.') : null);
    }

    protected function casts(): array
    {
        return [
            'revision' => 'integer', 'duration_months' => 'integer',
            'credits' => 'integer', 'amount_minor' => 'integer',
            'offer_snapshot' => 'array', 'verified_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime',
        ];
    }

    public function checkoutEligible(): bool
    {
        return $this->published_at !== null
            && $this->verified_at !== null
            && $this->retired_at === null
            && filled($this->stripe_price_id);
    }
}
