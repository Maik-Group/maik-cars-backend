<?php

namespace Database\Seeders;

use App\Models\BillingPlanPrice;
use Illuminate\Database\Seeder;

/**
 * Prepare new QA offer revisions with clean database-owned display names.
 * Never rewrite a published Stripe offer or create remote Stripe objects.
 */
class MaikCatQaOfferNameRevisionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (MaikCatQaBillingSeeder::OFFER_NAMES as $key => $name) {
            $latest = BillingPlanPrice::query()->where('environment', 'sandbox')
                ->where('offer_key', $key)->orderByDesc('revision')->first();
            if (! $latest || $latest->name === $name || $latest->retired_at) {
                continue;
            }

            if (! $latest->published_at) {
                if (! $latest->stripe_product_id && ! $latest->stripe_price_id) {
                    $latest->forceFill(['name' => $name,
                        'offer_snapshot' => [...($latest->offer_snapshot ?? []), 'name' => $name]])->save();
                }
                continue;
            }

            BillingPlanPrice::query()->firstOrCreate([
                'environment' => 'sandbox', 'offer_key' => $key, 'revision' => $latest->revision + 1,
            ], [
                'kind' => $latest->kind, 'subscription_plan_id' => $latest->subscription_plan_id,
                'name' => $name, 'duration_months' => $latest->duration_months,
                'credits' => $latest->credits, 'currency' => $latest->currency,
                'amount_minor' => $latest->amount_minor, 'terms_version' => $latest->terms_version,
                'offer_snapshot' => [...($latest->offer_snapshot ?? []), 'name' => $name],
            ]);
        }
    }
}
