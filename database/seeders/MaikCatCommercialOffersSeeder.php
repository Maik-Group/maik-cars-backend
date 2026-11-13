<?php

namespace Database\Seeders;

use App\Models\BillingPlanPrice;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Creates the canonical Maik Cat commercial offer catalog in sandbox.
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
                    'terms_version' => 'maik-commercial-1',
                    'offer_snapshot' => [
                        'offer_key' => $definition['key'], 'revision' => 1,
                        'environment' => 'sandbox', 'kind' => $definition['kind'],
                        'name' => $definition['name'], 'duration_months' => $definition['months'],
                        'credits' => $definition['credits'], 'currency' => 'USD',
                        'amount_minor' => $definition['amount'], 'terms_version' => 'maik-commercial-1',
                    ],
                ],
            );
            // Keep only unlinked drafts synchronized with the PHP seeder.
            // Published Stripe prices and orders are immutable historical data.
            if (! $offer->published_at && ! $offer->stripe_price_id && ! $offer->stripe_product_id) {
                $offer->forceFill([
                    'name' => $definition['name'],
                    'credits' => $definition['credits'],
                    'amount_minor' => $definition['amount'],
                    'duration_months' => $definition['months'],
                    'terms_version' => 'maik-commercial-1',
                    'offer_snapshot' => [
                        'offer_key' => $definition['key'], 'revision' => $offer->revision,
                        'environment' => 'sandbox', 'kind' => $definition['kind'],
                        'name' => $definition['name'], 'duration_months' => $definition['months'],
                        'credits' => $definition['credits'], 'currency' => 'USD',
                        'amount_minor' => $definition['amount'], 'terms_version' => 'maik-commercial-1',
                    ],
                ])->save();
            }
        }

        $this->command?->info('Seven Maik Cat commercial sandbox offers prepared. Default database seeding automatically publishes them when Stripe test credentials are configured.');
    }
}
