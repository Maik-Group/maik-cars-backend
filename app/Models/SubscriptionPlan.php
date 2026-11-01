<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $attributes = ['revision' => 1];

    protected $fillable = ['key', 'name', 'tier_key', 'duration_months', 'service_level', 'daily_allowance', 'features', 'descriptions', 'is_active', 'is_visible', 'sort_order'];

    protected function casts(): array
    {
        return ['duration_months' => 'integer', 'daily_allowance' => 'integer', 'features' => 'array', 'descriptions' => 'array', 'is_active' => 'boolean', 'is_visible' => 'boolean', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $plan): void {
            $plan->revision = $plan->getOriginal('revision') + 1;
        });
    }

    public function storeProducts(): HasMany
    {
        return $this->hasMany(PlanStoreProduct::class);
    }
}
