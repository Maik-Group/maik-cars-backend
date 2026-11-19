<?php

namespace App\Filament\Support;

use App\Models\BillingPlanPrice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscriptions\StripeBillingGateway;
use App\Services\Subscriptions\StripeOfferCatalogService;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CurrencyOfferPricing
{
    public static function section(): Section
    {
        return Section::make('Prices · EUR and USD')
            ->description('Prices are separate Stripe offers. Changes create an unpublished draft, without changing existing customer purchases. Enter matching new Stripe IDs, then verify and publish the draft from the list.')
            ->columns(2)->schema([
                TextInput::make('price_eur')->label('Price (EUR €)')->numeric()->step(0.01)->minValue(0.01),
                TextInput::make('price_usd')->label('Price (USD $)')->numeric()->step(0.01)->minValue(0.01),
                TextInput::make('stripe_product_id_eur')->label('New EUR Stripe Product ID')->maxLength(255)->placeholder('prod_...'),
                TextInput::make('stripe_product_id_usd')->label('New USD Stripe Product ID')->maxLength(255)->placeholder('prod_...'),
                TextInput::make('stripe_price_id_eur')->label('New EUR Stripe Price ID')->maxLength(255)->placeholder('price_...'),
                TextInput::make('stripe_price_id_usd')->label('New USD Stripe Price ID')->maxLength(255)->placeholder('price_...'),
            ]);
    }

    public static function latest(string $key, string $currency): ?BillingPlanPrice
    {
        return BillingPlanPrice::query()->where('environment', config('stripe_billing.environment'))
            ->where('offer_key', $key)->where('currency', $currency)
            ->whereNull('retired_at')->orderByDesc('revision')->first();
    }

    public static function pending(string $key, string $currency): ?BillingPlanPrice
    {
        $latest = self::latest($key, $currency);

        return $latest && ! $latest->published_at ? $latest : null;
    }

    public static function fieldValues(string $key): array
    {
        $result = [];
        foreach (['EUR' => 'eur', 'USD' => 'usd'] as $currency => $suffix) {
            $offer = self::latest($key, $currency);
            $result['price_'.$suffix] = $offer ? number_format($offer->amount_minor / 100, 2, '.', '') : null;
            // Never suggest that an existing published Stripe Price ID is reusable for a changed amount.
            $draft = $offer && ! $offer->published_at ? $offer : null;
            $result['stripe_product_id_'.$suffix] = $draft?->stripe_product_id;
            $result['stripe_price_id_'.$suffix] = $draft?->stripe_price_id;
        }

        return $result;
    }

    public static function display(string $key, string $currency): string
    {
        $published = BillingPlanPrice::query()->where('environment', config('stripe_billing.environment'))
            ->where('offer_key', $key)->where('currency', $currency)
            ->whereNull('retired_at')->whereNotNull('published_at')
            ->orderByDesc('revision')->first();
        $latest = self::latest($key, $currency);
        $offer = $published ?? $latest;
        if (! $offer) {
            return '—';
        }
        $formatted = number_format($offer->amount_minor / 100, 2).' '.$currency;
        if (! $published) {
            return $formatted.' (draft)';
        }

        return $latest && $latest->revision > $published->revision
            ? $formatted.' (draft pending)' : $formatted;
    }

    public static function save(
        User $actor,
        string $key,
        string $kind,
        string $name,
        int $credits,
        ?int $months,
        ?SubscriptionPlan $plan,
        array $data,
    ): void {
        $rules = [];
        foreach (['eur', 'usd'] as $suffix) {
            $rules['price_'.$suffix] = ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999'];
            $rules['stripe_product_id_'.$suffix] = ['nullable', 'string', 'max:255', 'regex:/^prod_/'];
            $rules['stripe_price_id_'.$suffix] = ['nullable', 'string', 'max:255', 'regex:/^price_/'];
        }
        Validator::make($data, $rules)->validate();

        DB::transaction(function () use ($actor, $key, $kind, $name, $credits, $months, $plan, $data): void {
            foreach (['EUR' => 'eur', 'USD' => 'usd'] as $currency => $suffix) {
                $value = $data['price_'.$suffix] ?? null;
                $productId = trim((string) ($data['stripe_product_id_'.$suffix] ?? ''));
                $priceId = trim((string) ($data['stripe_price_id_'.$suffix] ?? ''));
                if ($value === null || $value === '') {
                    if ($productId !== '' || $priceId !== '') {
                        throw ValidationException::withMessages(['price_'.$suffix => 'Enter a price before providing Stripe IDs.']);
                    }

                    continue;
                }
                if (($productId === '') !== ($priceId === '')) {
                    throw ValidationException::withMessages(['stripe_price_id_'.$suffix => 'Provide both Stripe Product ID and Stripe Price ID.']);
                }
                $minor = (int) round((float) $value * 100);
                $latest = self::latest($key, $currency);
                if ($latest
                    && $latest->amount_minor === $minor
                    && $latest->name === $name
                    && (int) $latest->credits === $credits
                    && $latest->kind === $kind
                    && $latest->duration_months === $months
                    && ($latest->subscription_plan_id === $plan?->getKey())
                    && ($productId === '' || ($latest->stripe_product_id === $productId && $latest->stripe_price_id === $priceId))) {
                    continue;
                }
                $revision = (int) BillingPlanPrice::query()
                    ->where('environment', config('stripe_billing.environment'))
                    ->where('offer_key', $key)->max('revision') + 1;
                app(StripeOfferCatalogService::class)->createDraft($actor, [
                    'offer_key' => $key,
                    'revision' => $revision,
                    'environment' => (string) config('stripe_billing.environment'),
                    'kind' => $kind,
                    'subscription_plan_id' => $plan?->getKey(),
                    'name' => $name,
                    'duration_months' => $months,
                    'credits' => $credits,
                    'currency' => $currency,
                    'amount_minor' => $minor,
                    'terms_version' => $latest?->terms_version ?? 'admin-v1',
                    'stripe_product_id' => $productId !== '' ? $productId : null,
                    'stripe_price_id' => $priceId !== '' ? $priceId : null,
                ], 'Updated '.$currency.' price from admin');
            }
        });
    }

    public static function retireCurrency(User $actor, string $key, string $currency): void
    {
        DB::transaction(function () use ($actor, $key, $currency): void {
            $published = BillingPlanPrice::query()
                ->where('environment', config('stripe_billing.environment'))
                ->where('offer_key', $key)->where('kind', 'topup')
                ->where('currency', $currency)->whereNotNull('published_at')
                ->whereNull('retired_at')->orderByDesc('revision')->get();
            foreach ($published as $offer) {
                app(StripeOfferCatalogService::class)->retire(
                    $actor, $offer, 'Retired '.$currency.' credit package from admin'
                );
            }
        });
    }

    public static function publish(User $actor, string $key, string $currency): void
    {
        $draft = self::pending($key, $currency);
        if (! $draft) {
            throw ValidationException::withMessages(['price' => 'No pending draft for this currency.']);
        }
        app(StripeOfferCatalogService::class)->verifyAndPublish(
            $actor, $draft, 'Verified '.$currency.' price from admin', app(StripeBillingGateway::class)
        );
    }
}
