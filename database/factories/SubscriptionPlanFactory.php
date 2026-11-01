<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriptionPlan> */
class SubscriptionPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(3), 'name' => 'Test monthly access', 'tier_key' => 'test',
            'duration_months' => 1, 'service_level' => 1, 'daily_allowance' => 5,
            'features' => ['valuation' => true], 'is_active' => false, 'is_visible' => false, 'sort_order' => 0,
        ];
    }
}
