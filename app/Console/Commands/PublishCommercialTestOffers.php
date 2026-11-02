<?php

namespace App\Console\Commands;

use App\Models\BillingPlanPrice;
use App\Models\User;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Database\Seeders\MaikCatCommercialOffersSeeder;
use Illuminate\Console\Command;
use RuntimeException;

class PublishCommercialTestOffers extends Command
{
    protected $signature = 'stripe:publish-commercial-test-offers {--actor= : Authorized administrator ID} {--execute : Allow actual Stripe test publication}';

    protected $description = 'Explicitly publish existing commercial sandbox drafts to Stripe test mode';

    public function handle(StripeBillingGateway $gateway, StripeOfferCatalogService $catalog): int
    {
        if (! $this->option('execute')
            || ! str_starts_with((string) config('stripe_billing.secret_key'), 'sk_test_')
            || config('stripe_billing.environment') !== 'sandbox') {
            $this->error('Only --execute with sandbox configuration and sk_test_ credentials can publish.');

            return self::FAILURE;
        }
        $actor = User::query()->find($this->option('actor'));
        if (! $actor || ! $actor->is_active || ! $actor->can('manage_subscription_products')) {
            $this->error('An authorized active administrator is required.');

            return self::FAILURE;
        }

        // Validate all seven existing drafts before making any remote request.
        $offers = collect(MaikCatCommercialOffersSeeder::OFFERS)->map(function (array $definition): BillingPlanPrice {
            $offer = BillingPlanPrice::query()->where('environment', 'sandbox')
                ->where('offer_key', $definition['key'])->orderByDesc('revision')->first();
            if (! $offer || $offer->retired_at || $offer->kind !== $definition['kind']
                || $offer->credits !== $definition['credits']
                || $offer->amount_minor !== $definition['amount']
                || $offer->duration_months !== $definition['months']
                || ($offer->kind === 'subscription' && ! $offer->subscription_plan_id)) {
                throw new RuntimeException('Missing or inconsistent sandbox commercial offer: '.$definition['key']);
            }

            return $offer;
        });

        foreach ($offers as $offer) {
            if ($offer->published_at) {
                $this->line('Already published: '.$offer->offer_key.' r'.$offer->revision);
                continue;
            }
            if (! $offer->stripe_price_id) {
                $existing = $gateway->findCommercialTestPrice($offer);
                if ($existing !== null) {
                    if (($existing['livemode'] ?? true) !== false) {
                        throw new RuntimeException('Stripe returned a live price.');
                    }
                    $productId = is_array($existing['product'] ?? null)
                        ? ($existing['product']['id'] ?? null) : ($existing['product'] ?? null);
                    if (! is_string($productId) || ! str_starts_with($productId, 'prod_')
                        || ! str_starts_with((string) ($existing['id'] ?? ''), 'price_')
                        || ($offer->stripe_product_id && $offer->stripe_product_id !== $productId)) {
                        throw new RuntimeException('Recovered Stripe product/price mismatch.');
                    }
                    $offer->forceFill(['stripe_product_id' => $productId, 'stripe_price_id' => $existing['id']])->save();
                } else {
                    if (! $offer->stripe_product_id) {
                        $product = $gateway->createCommercialTestProduct($offer);
                        if (($product['livemode'] ?? true) !== false || ! str_starts_with((string) ($product['id'] ?? ''), 'prod_')) {
                            throw new RuntimeException('Invalid Stripe test product.');
                        }
                        $offer->forceFill(['stripe_product_id' => $product['id']])->save();
                    }
                    $price = $gateway->createCommercialTestPrice($offer, $offer->stripe_product_id);
                    if (($price['livemode'] ?? true) !== false || ! str_starts_with((string) ($price['id'] ?? ''), 'price_')) {
                        throw new RuntimeException('Invalid Stripe test price.');
                    }
                    $offer->forceFill(['stripe_price_id' => $price['id']])->save();
                }
            }
            $catalog->verifyAndPublish($actor, $offer, 'Explicit test commercial catalog publication', $gateway);
            $this->info('Published: '.$offer->offer_key.' r'.$offer->revision);
        }

        return self::SUCCESS;
    }
}
