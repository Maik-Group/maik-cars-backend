<?php

use App\Models\BillingPlanPrice;
use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function giveActiveBillingPlan(User $user, ?string $environment = null): BillingSubscription
{
    $environment ??= config('stripe_billing.environment') ?: 'sandbox';
    config(['stripe_billing.environment' => $environment]);
    $offer = BillingPlanPrice::query()->create([
        'offer_key' => (string) Str::uuid(), 'revision' => 1,
        'environment' => $environment, 'kind' => 'subscription', 'name' => 'Test subscription',
        'duration_months' => 1, 'credits' => 10, 'currency' => 'USD',
        'amount_minor' => 100, 'terms_version' => 'test', 'offer_snapshot' => [],
    ]);

    return BillingSubscription::query()->create([
        'user_id' => $user->id, 'billing_plan_price_id' => $offer->id,
        'environment' => $environment, 'stripe_subscription_id' => 'sub_'.Str::uuid(),
        'stripe_customer_id' => 'cus_test', 'status' => 'active',
        'period_start' => now('UTC')->subDay(), 'period_end' => now('UTC')->addWeek(),
    ]);
}
