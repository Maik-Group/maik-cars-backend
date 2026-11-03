<?php

namespace Database\Seeders;

use App\Models\BillingPlanPrice;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Creates proposed commercial catalog drafts for sandbox review.
 * Published offers and previously assigned Stripe prices are never modified.
 */
class MaikCatCommercialOffersSeeder extends Seeder
{
    public const OFFERS = [
        ['key' => 'maik-starter-monthly', 'kind' => 'subscription', 'name' => 'Monthly Plan', 'months' => 1, 'credits' => 30, 'amount' => 999],
        ['key' => 'maik-plus-quarterly', 'kind' => 'subscription', 'name' => 'Quarterly Plan', 'months' => 3, 'credits' => 105, 'amount' => 2499],
        ['key' => 'maik-pro-semiannual', 'kind' => 'subscription', 'name' => 'Semiannual Plan', 'months' => 6, 'credits' => 240, 'amount' => 4499],
        ['key' => 'maik-max-annual', 'kind' => 'subscription', 'name' => 'Annual Plan', 'months' => 12, 'credits' => 540, 'amount' => 7999],
        ['key' => 'maik-credits-10', 'kind' => 'topup', 'name' => '10 Extra Credits', 'months' => null, 'credits' => 10, 'amount' => 399],
        ['key' => 'maik-credits-30', 'kind' => 'topup', 'name' => '30 Extra Credits', 'months' => null, 'credits' => 30, 'amount' => 999],
        ['key' => 'maik-credits-100', 'kind' => 'topup', 'name' => '100 Extra Credits', 'months' => null, 'credits' => 100, 'amount' => 2499],
    ];

    public function run(): void
    {
        foreach (self::OFFERS as $definition) {
            $plan = $definition['months'] === null ? null : SubscriptionPlan::query()->firstOrCreate(
                ['key' => $definition['key']],
                ['name' => $definition['name'], 'tier_key' => $definition['key'],
                    'duration_months' => $definition['months'], 'service_level' => 1,
                    'features' => ['valuation' => true], 'descriptions' => ['en' => $definition['credits'].' prepaid credits for the selected access term; no automatic renewal'],
                    'is_active' => true, 'is_visible' => true, 'sort_order' => $definition['months']],
            );
            $offer = BillingPlanPrice::query()->firstOrCreate(
                ['environment' => 'sandbox', 'offer_key' => $definition['key'], 'revision' => 1],
                [
                    'subscription_plan_id' => $plan?->getKey(),
                    'kind' => $definition['kind'],
                    'name' => $definition['name'],
                    'duration_months' => $definition['months'],
                    'credits' => $definition['credits'],
                    'currency' => 'USD',
                    'amount_minor' => $definition['amount'],
                    'terms_version' => 'commercial-proposal-1',
                    'offer_snapshot' => [
                        'offer_key' => $definition['key'], 'revision' => 1,
                        'environment' => 'sandbox', 'kind' => $definition['kind'],
                        'name' => $definition['name'], 'duration_months' => $definition['months'],
                        'credits' => $definition['credits'], 'currency' => 'USD',
                        'amount_minor' => $definition['amount'], 'terms_version' => 'commercial-proposal-1',
                    ],
                ],
            );
            // Seeders may update a local draft that has never been linked to a
            // Stripe price. Published/versioned offers are immutable by design.
            if (! $offer->published_at && ! $offer->stripe_price_id && ! $offer->stripe_product_id
                && $offer->name !== $definition['name']) {
                $offer->forceFill([
                    'name' => $definition['name'],
                    'offer_snapshot' => [...($offer->offer_snapshot ?? []), 'name' => $definition['name']],
                ])->save();
            }
        }

        $this->command?->info('Seven commercial sandbox offer drafts prepared. Verify Stripe products and prices before publication.');
    }
}
