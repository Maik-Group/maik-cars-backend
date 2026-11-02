<?php

use App\Http\Controllers\API\BillingWebhookController;
use App\Http\Controllers\API\StripeBillingController;
use App\Http\Controllers\API\StripeWebhookController;
use App\Http\Controllers\BillingWebController;
use App\Http\Middleware\LimitBillingPayload;
use Illuminate\Support\Facades\Route;

Route::middleware(LimitBillingPayload::class)->prefix('billing')->group(function (): void {
    Route::post('apple', [BillingWebhookController::class, 'apple']);
    Route::post('google', [BillingWebhookController::class, 'google']);
});

// Stripe signs the original body bytes. Never place this endpoint behind the
// API's case-transforming request middleware or session/CSRF middleware.
Route::post('api/v1/billing/stripe/webhook', StripeWebhookController::class)
    ->middleware('throttle:120,1')->name('stripe.billing.webhook');

Route::get('api/v1/billing/plans', [StripeBillingController::class, 'plans'])
    ->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'throttle:subscription-purchases'])
    ->prefix('api/v1/billing')->group(function (): void {
        Route::post('web-handoff', [BillingWebController::class, 'issue']);
        Route::post('checkouts', [StripeBillingController::class, 'checkout']);
        Route::post('checkouts/abandon-pending', [StripeBillingController::class, 'abandonLatestCheckout']);
        Route::post('checkouts/{orderId}/abandon', [StripeBillingController::class, 'abandonCheckout']);
        Route::get('checkouts/{orderId}', [StripeBillingController::class, 'checkoutStatus']);
        Route::get('subscription', [StripeBillingController::class, 'subscription']);
        Route::post('customer-portal', [StripeBillingController::class, 'portal']);
        Route::post('subscription/plan-change', [StripeBillingController::class, 'planChange']);
        Route::delete('subscription/plan-change', [StripeBillingController::class, 'removePlanChange']);
        Route::post('subscription/cancel', [StripeBillingController::class, 'cancel']);
    });

// Return navigation is informational only: never grant credits from a GET.
Route::view('billing/return', 'billing.return')->name('stripe.billing.return');

Route::middleware(['web', 'throttle:30,1'])->group(function (): void {
    Route::get('billing/handoff', [BillingWebController::class, 'consume'])->name('billing.web.handoff');
    Route::group([], function (): void {
        Route::get('billing/shop', [BillingWebController::class, 'shop'])->name('billing.web.shop');
        Route::post('billing/shop/checkout', [BillingWebController::class, 'checkout'])
            ->middleware('throttle:subscription-purchases')->name('billing.web.checkout');
    });
});
