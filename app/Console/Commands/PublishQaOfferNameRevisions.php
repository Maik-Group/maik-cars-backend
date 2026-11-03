<?php

namespace App\Console\Commands;

use App\Models\BillingPlanPrice;
use App\Models\User;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Database\Seeders\MaikCatQaBillingSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Explicit, guarded Stripe TEST publication of correctly named QA revisions.
 * Old published prices and paid order snapshots remain immutable.
 */
class PublishQaOfferNameRevisions extends Command
{
    protected $signature = 'stripe:publish-qa-offer-names {--actor= : Authorized administrator ID} {--execute : Publish in Stripe sandbox}';

    protected $description = 'Publish clean display-name revisions of existing QA offers in Stripe test mode';

    public function handle(StripeBillingGateway $gateway, StripeOfferCatalogService $catalog): int
    {
        if (! $this->option('execute') || config('stripe_billing.environment') !== 'sandbox'
            || ! str_starts_with((string) config('stripe_billing.secret_key'), 'sk_test_')) {
            $this->error('Requires explicit --execute, a Stripe test key and sandbox billing.');

            return self::FAILURE;
        }

        $actor = User::query()->find($this->option('actor'));
        if (! $actor?->is_active || ! $actor->can('manage_subscription_products')) {
            $this->error('An active administrator with manage_subscription_products is required.');

            return self::FAILURE;
        }

        // Check every local revision before contacting Stripe or publishing any.
        $offers = collect(MaikCatQaBillingSeeder::OFFER_NAMES)->map(function (string $name, string $key): BillingPlanPrice {
            $offer = BillingPlanPrice::query()->where('environment', 'sandbox')
                ->where('offer_key', $key)->orderByDesc('revision')->first();
            if (! $offer || $offer->name !== $name || $offer->retired_at) {
                throw new RuntimeException('Prepare the correct named offer draft first: '.$key);
            }

            return $offer;
        });

        foreach ($offers as $offer) {
            if ($offer->published_at) {
                $this->line('Already published: '.$offer->offer_key.' r'.$offer->revision);
                continue;
            }

            if (! $offer->stripe_price_id) {
                $existing = $gateway->findQaPrice($offer);
                if ($existing !== null) {
                    $product = is_array($existing['product'] ?? null)
                        ? ($existing['product']['id'] ?? null) : ($existing['product'] ?? null);
                    if (($existing['livemode'] ?? true) !== false || ! str_starts_with((string) $product, 'prod_')
                        || ! str_starts_with((string) ($existing['id'] ?? ''), 'price_')
                        || ($offer->stripe_product_id && $offer->stripe_product_id !== $product)) {
                        throw new RuntimeException('Recovered QA Stripe Price mismatch: '.$offer->offer_key);
                    }
                    $offer->forceFill(['stripe_product_id' => $product, 'stripe_price_id' => $existing['id']])->save();
                } else {
                    if (! $offer->stripe_product_id) {
                        $created = $gateway->createQaProduct($offer);
                        if (($created['livemode'] ?? true) !== false || ! str_starts_with((string) ($created['id'] ?? ''), 'prod_')) {
                            throw new RuntimeException('Invalid Stripe test product.');
                        }
                        $offer->forceFill(['stripe_product_id' => $created['id']])->save();
                    }
                    $created = $gateway->createQaPrice($offer, $offer->stripe_product_id);
                    if (($created['livemode'] ?? true) !== false || ! str_starts_with((string) ($created['id'] ?? ''), 'price_')) {
                        throw new RuntimeException('Invalid Stripe test price.');
                    }
                    $offer->forceFill(['stripe_price_id' => $created['id']])->save();
                }
            }

            $catalog->verifyAndPublish($actor, $offer, 'Approved clean QA offer name revision', $gateway);
            $this->info('Published: '.$offer->offer_key.' r'.$offer->revision.' — '.$offer->name);
        }

        return self::SUCCESS;
    }
}
