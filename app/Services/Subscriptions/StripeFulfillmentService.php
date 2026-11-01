<?php

namespace App\Services\Subscriptions;

use App\Models\BillingInvoice;
use App\Models\BillingOrder;
use App\Models\BillingPlanPrice;
use App\Models\BillingSubscription;
use App\Models\CreditGrant;
use App\Models\StripeCustomer;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Provider-confirmed fulfillment only. Checkout return URLs never call this.
 * Grant and billing state changes share one transaction with the wallet ledger.
 */
class StripeFulfillmentService
{
    public function __construct(private readonly CreditWalletService $wallet) {}

    private function assertEnvironment(string $environment): void
    {
        if (! in_array($environment, ['sandbox', 'production'], true)
            || $environment !== config('stripe_billing.environment')
            || $environment !== config('subscriptions.environment')) {
            throw new RuntimeException('Stripe and wallet environments must match.');
        }
    }

    private function id(mixed $object): ?string
    {
        if (is_string($object)) {
            return $object;
        }

        return is_array($object) ? ($object['id'] ?? null) : null;
    }

    public function fulfillCheckout(array $session, string $environment): void
    {
        $this->assertEnvironment($environment);
        $sessionId = $this->id($session['id'] ?? null);
        $orderId = $session['client_reference_id'] ?? null;
        if (! is_string($sessionId) || ! is_string($orderId)
            || ($session['metadata']['order_id'] ?? null) !== $orderId
            || ($session['metadata']['billing_environment'] ?? null) !== $environment) {
            throw new RuntimeException('Stripe session identity mismatch.');
        }

        $order = BillingOrder::query()->whereKey($orderId)->where('environment', $environment)->firstOrFail();
        $customer = StripeCustomer::query()->where('user_id', $order->user_id)->where('environment', $environment)->firstOrFail();
        $snapshot = $order->offer_snapshot;
        if ($this->id($session['customer'] ?? null) !== $customer->stripe_customer_id
            || ($order->stripe_checkout_session_id && $order->stripe_checkout_session_id !== $sessionId)
            || (int) ($session['amount_total'] ?? -1) !== (int) $snapshot['amountMinor']
            || strtoupper((string) ($session['currency'] ?? '')) !== strtoupper($snapshot['currency'])
            || ($session['mode'] ?? '') !== ($order->kind === 'topup' ? 'payment' : 'subscription')) {
            throw new RuntimeException('Stripe checkout terms or customer mismatch.');
        }

        DB::transaction(function () use ($session, $sessionId, $orderId, $environment, $customer): void {
            $order = BillingOrder::query()->whereKey($orderId)->firstOrFail();
            User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $order = BillingOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->fulfillment_status === 'fulfilled') {
                return;
            }
            $mode = $order->kind;
            $isComplete = ($session['status'] ?? null) === 'complete';
            $isPaid = ($session['payment_status'] ?? null) === 'paid';
            if (! $isComplete) {
                return;
            }
            $order->stripe_checkout_session_id = $sessionId;
            $order->stripe_payment_intent_id = $this->id($session['payment_intent'] ?? null);
            $order->stripe_subscription_id = $this->id($session['subscription'] ?? null);
            $order->payment_status = $isPaid ? 'paid' : 'unpaid';
            $order->status = $isPaid ? 'paid' : 'checkout_created';
            if ($mode === 'topup' && $isPaid) {
                if (! str_starts_with((string) $order->stripe_payment_intent_id, 'pi_')) {
                    throw new RuntimeException('Top-up lacks a confirmed payment intent.');
                }
                $blockedRefund = DB::table('billing_refunds')->where('billing_order_id', $order->getKey())
                    ->whereIn('status', ['pending', 'succeeded', 'needs_review'])->exists();
                if ($blockedRefund) {
                    throw new RuntimeException('Top-up has a refund hold.');
                }
                $this->wallet->grant(
                    User::query()->findOrFail($order->user_id), 'purchased_topup',
                    (int) $order->offer_snapshot['credits'], 'stripe-topup:'.$environment.':'.$order->getKey(), null, $environment,
                );
                $order->status = 'fulfilled';
                $order->fulfillment_status = 'fulfilled';
                $order->fulfilled_at = now('UTC');
            }
            if ($mode === 'subscription') {
                if (! str_starts_with((string) $order->stripe_subscription_id, 'sub_')) {
                    throw new RuntimeException('Stripe subscription identity missing.');
                }
                // A qualifying paid invoice, not Checkout completion, issues term credits.
                BillingSubscription::query()->firstOrCreate(
                    ['environment' => $environment, 'stripe_subscription_id' => $order->stripe_subscription_id],
                    ['user_id' => $order->user_id, 'billing_plan_price_id' => $order->billing_plan_price_id,
                        'stripe_customer_id' => $customer->stripe_customer_id, 'status' => 'incomplete'],
                );
                // The invoice may have arrived and been fulfilled before its
                // checkout.session.completed notification. Never issue a
                // second allowance; merely repair the visible order state.
                if (DB::table('billing_invoice_grants as grants')
                    ->join('billing_subscriptions as subs', 'subs.id', '=', 'grants.billing_subscription_id')
                    ->where('subs.environment', $environment)
                    ->where('subs.stripe_subscription_id', $order->stripe_subscription_id)->exists()) {
                    $order->status = 'fulfilled';
                    $order->fulfillment_status = 'fulfilled';
                    $order->payment_status = 'paid';
                    $order->fulfilled_at = now('UTC');
                }
            }
            $order->save();
        }, 3);
    }

    public function expireCheckout(string $sessionId, string $environment, string $status): void
    {
        $this->assertEnvironment($environment);
        BillingOrder::query()->where('environment', $environment)->where('stripe_checkout_session_id', $sessionId)
            ->whereNotIn('status', ['fulfilled', 'paid'])->update([
                'status' => $status, 'payment_status' => 'unpaid',
            ]);
    }

    public function fulfillInvoice(array $invoice, string $environment): void
    {
        $this->assertEnvironment($environment);
        $invoiceId = $this->id($invoice['id'] ?? null);
        $subscriptionId = $this->id(data_get($invoice, 'parent.subscription_details.subscription'))
            ?? $this->id($invoice['subscription'] ?? null);
        $customerId = $this->id($invoice['customer'] ?? null);
        if (! is_string($invoiceId) || ! str_starts_with($invoiceId, 'in_')
            || ! str_starts_with((string) $subscriptionId, 'sub_')
            || ! str_starts_with((string) $customerId, 'cus_')) {
            throw new RuntimeException('Invoice identity or subscription mapping missing.');
        }
        if (($invoice['status'] ?? null) !== 'paid' || ($invoice['paid_out_of_band'] ?? false)
            || ($invoice['collection_method'] ?? null) !== 'charge_automatically'
            || ! in_array($invoice['billing_reason'] ?? null, ['subscription_create', 'subscription_cycle'], true)) {
            throw new RuntimeException('Invoice is not a qualifying paid recurring term.');
        }

        $lines = $invoice['lines']['data'] ?? [];
        if (! is_array($lines) || ($invoice['lines']['has_more'] ?? true) || count($lines) !== 1) {
            throw new RuntimeException('Invoice contains extra or incomplete line items.');
        }
        $line = $lines[0];
        $lineSubscription = $this->id(data_get($line, 'parent.subscription_item_details.subscription'))
            ?? $this->id($line['subscription'] ?? null);
        $priceId = $this->id(data_get($line, 'pricing.price_details.price')) ?? $this->id($line['price'] ?? null);
        if ($lineSubscription !== $subscriptionId || ! is_string($priceId)
            || data_get($line, 'parent.subscription_item_details.proration', $line['proration'] ?? false)
            || (int) ($line['quantity'] ?? 0) !== 1) {
            throw new RuntimeException('Unexpected invoice line or proration.');
        }
        $price = BillingPlanPrice::query()->where('environment', $environment)
            ->where('stripe_price_id', $priceId)->where('kind', 'subscription')->firstOrFail();
        if (! $price->checkoutEligible()) {
            // Retired offers remain valid for previously contracted renewals.
            if (! $price->verified_at || ! $price->published_at) {
                throw new RuntimeException('Unverified invoice price.');
            }
        }
        $paid = (int) ($invoice['amount_paid'] ?? 0);
        if ($paid <= 0 || $paid !== (int) $price->amount_minor
            || strtoupper((string) ($invoice['currency'] ?? '')) !== $price->currency) {
            throw new RuntimeException('Unsupported invoice amount or settlement.');
        }

        $startTs = (int) data_get($line, 'period.start', 0);
        $endTs = (int) data_get($line, 'period.end', 0);
        $periodDays = ($endTs - $startTs) / 86400;
        $ranges = [1 => [27, 32], 3 => [88, 94], 6 => [180, 186], 12 => [364, 367]];
        [$min, $max] = $ranges[(int) $price->duration_months] ?? [PHP_INT_MAX, PHP_INT_MAX];
        if ($endTs <= $startTs || $periodDays < $min || $periodDays > $max) {
            throw new RuntimeException('Invoice does not cover a full configured term.');
        }

        $intent = $this->id($invoice['payment_intent'] ?? null);
        if (! $intent) {
            foreach (($invoice['payments']['data'] ?? []) as $payment) {
                $intent = $this->id(data_get($payment, 'payment.payment_intent'))
                    ?? $this->id($payment['payment_intent'] ?? null);
                if ($intent) {
                    break;
                }
            }
        }
        if (! str_starts_with((string) $intent, 'pi_')) {
            throw new RuntimeException('Paid invoice lacks traceable payment evidence.');
        }
        $owner = StripeCustomer::query()->where('environment', $environment)
            ->where('stripe_customer_id', $customerId)->firstOrFail();
        $user = User::query()->findOrFail($owner->user_id);
        $start = CarbonImmutable::createFromTimestampUTC($startTs);
        $end = CarbonImmutable::createFromTimestampUTC($endTs);

        DB::transaction(function () use ($invoice, $invoiceId, $subscriptionId, $customerId, $environment, $price, $user, $start, $end, $paid, $intent): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $subscription = BillingSubscription::query()->where('environment', $environment)
                ->where('stripe_subscription_id', $subscriptionId)->lockForUpdate()->first();
            if ($subscription && ($subscription->user_id !== $user->getKey() || $subscription->stripe_customer_id !== $customerId)) {
                throw new RuntimeException('Invoice subscription owner mismatch.');
            }
            if (! $subscription) {
                // Invoice webhooks may arrive before Checkout webhooks. In that
                // case defer until a signed Checkout session links the local
                // reservation to this subscription. Customer identity alone
                // must not authorize arbitrary dashboard-created subscriptions.
                throw new RuntimeException('Unlinked paid subscription; await verified Checkout.');
            }
            $existingPeriod = DB::table('billing_invoice_grants')
                ->where('billing_subscription_id', $subscription->getKey())
                ->where('period_start', $start)->where('period_end', $end)->exists();
            if ($existingPeriod) {
                return;
            }
            $record = BillingInvoice::query()->firstOrCreate(
                ['environment' => $environment, 'stripe_invoice_id' => $invoiceId],
                [
                    'billing_subscription_id' => $subscription->getKey(),
                    'user_id' => $user->getKey(), 'billing_plan_price_id' => $price->getKey(),
                    'stripe_payment_intent_id' => $intent,
                    'billing_reason' => $invoice['billing_reason'], 'period_start' => $start, 'period_end' => $end,
                    'currency' => $price->currency, 'amount_paid_minor' => $paid,
                    'status' => 'paid', 'paid_at' => now('UTC'),
                ],
            );
            if ($record->user_id !== $user->getKey() || $record->billing_subscription_id !== $subscription->getKey()) {
                throw new RuntimeException('Conflicting invoice identity.');
            }
            $sourceKey = 'stripe-invoice:'.$environment.':'.$invoiceId;
            $this->wallet->grant($user, 'paid_cycle', (int) $price->credits, $sourceKey, $end, $environment);
            DB::table('billing_invoice_grants')->insert([
                'billing_invoice_id' => $record->getKey(),
                'billing_subscription_id' => $subscription->getKey(),
                'period_start' => $start, 'period_end' => $end,
                'source_key' => $sourceKey, 'credits' => $price->credits, 'created_at' => now('UTC'),
            ]);
            // A late paid event must never move the displayed current cycle backwards.
            if (! $subscription->period_end || $subscription->period_end->lessThanOrEqualTo($end)) {
                $subscription->forceFill([
                    'billing_plan_price_id' => $price->getKey(), 'status' => 'active',
                    'period_start' => $start, 'period_end' => $end,
                ])->save();
            }
            BillingOrder::query()->where('user_id', $user->getKey())->where('environment', $environment)
                ->where('stripe_subscription_id', $subscriptionId)->where('kind', 'subscription')
                ->whereNotIn('status', ['fulfilled', 'expired'])->update([
                    'status' => 'fulfilled', 'payment_status' => 'paid',
                    'fulfillment_status' => 'fulfilled', 'fulfilled_at' => now('UTC'),
                ]);
        }, 3);
    }

    public function subscriptionStatus(array $provider, string $environment): void
    {
        $this->assertEnvironment($environment);
        $id = $this->id($provider['id'] ?? null);
        if (! $id) {
            throw new RuntimeException('Invalid subscription identity.');
        }
        $subscription = BillingSubscription::query()->where('environment', $environment)
            ->where('stripe_subscription_id', $id)->first();
        if (! $subscription) {
            return; // Invoice/checkout reconciliation resolves verified ownership.
        }
        if ($this->id($provider['customer'] ?? null) !== $subscription->stripe_customer_id) {
            throw new RuntimeException('Subscription customer changed unexpectedly.');
        }
        $status = (string) ($provider['status'] ?? '');
        if (! in_array($status, ['incomplete', 'incomplete_expired', 'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'paused'], true)) {
            throw new RuntimeException('Unknown subscription status.');
        }
        $subscription->forceFill([
            'status' => $status,
            'cancel_at_period_end' => (bool) ($provider['cancel_at_period_end'] ?? false),
        ])->save();
    }

    public function refund(array $refund, string $environment): void
    {
        $this->assertEnvironment($environment);
        $refundId = $this->id($refund['id'] ?? null);
        $intent = $this->id($refund['payment_intent'] ?? null);
        if (! $refundId || ! $intent) {
            throw new RuntimeException('Refund lacks traceable payment intent.');
        }
        $invoice = BillingInvoice::query()->where('environment', $environment)->where('stripe_payment_intent_id', $intent)->first();
        $order = $invoice ? null : BillingOrder::query()->where('environment', $environment)->where('kind', 'topup')
            ->where('stripe_payment_intent_id', $intent)->first();
        if (! $invoice && ! $order) {
            throw new RuntimeException('Refund source not yet reconciled.');
        }
        $user = User::findOrFail($invoice?->user_id ?? $order->user_id);
        $sourceType = $invoice ? 'paid_cycle' : 'purchased_topup';
        $sourceKey = $invoice
            ? 'stripe-invoice:'.$environment.':'.$invoice->stripe_invoice_id
            : 'stripe-topup:'.$environment.':'.$order->getKey();
        $status = (string) ($refund['status'] ?? '');
        if (! in_array($status, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)) {
            throw new RuntimeException('Unsupported refund state.');
        }

        DB::transaction(function () use ($environment, $refundId, $invoice, $order, $refund, $sourceType, $sourceKey, $status, $user): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $record = DB::table('billing_refunds')->where('environment', $environment)
                ->where('stripe_refund_id', $refundId)->first();
            $amount = (int) ($refund['amount'] ?? 0);
            $currency = strtoupper((string) ($refund['currency'] ?? ''));
            $original = $invoice ? $invoice->amount_paid_minor : (int) $order->offer_snapshot['amountMinor'];
            $originalCurrency = $invoice ? $invoice->currency : (string) $order->offer_snapshot['currency'];
            if ($amount <= 0 || $amount > $original || $currency !== strtoupper($originalCurrency)) {
                throw new RuntimeException('Refund currency or amount mismatch.');
            }
            $partial = $amount !== $original;
            $state = $partial ? 'needs_review' : $status;
            if (! $record) {
                DB::table('billing_refunds')->insert([
                    'environment' => $environment, 'stripe_refund_id' => $refundId,
                    'billing_invoice_id' => $invoice?->getKey(), 'billing_order_id' => $order?->getKey(),
                    'amount_minor' => $amount, 'currency' => $currency, 'status' => $state,
                    'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            } else {
                DB::table('billing_refunds')->where('id', $record->id)
                    ->update(['status' => $state, 'updated_at' => now('UTC'),
                        'resolved_at' => in_array($state, ['succeeded', 'failed', 'canceled'], true) ? now('UTC') : null]);
            }
            $grant = CreditGrant::query()->where('environment', $environment)
                ->where('source_type', $sourceType)->where('source_key', $sourceKey)->first();
            if (! $grant) {
                return; // A late paid/checkout event will be stopped by the source refund record.
            }
            if ($partial || in_array($status, ['pending', 'requires_action'], true)) {
                $this->wallet->hold($grant, 'stripe-refund:'.$refundId);

                return;
            }
            if ($status === 'succeeded') {
                $consumed = $grant->credits - $grant->remaining;
                $this->wallet->revoke($grant, 'stripe-refund:'.$refundId);
                if ($consumed > 0) {
                    DB::table('credit_recovery_cases')->insertOrIgnore([
                        'user_id' => $user->getKey(), 'environment' => $environment,
                        'source_type' => $sourceType, 'source_key' => $sourceKey,
                        'credits_affected' => $consumed, 'reason' => 'refunded_after_consumption',
                        'status' => 'needs_review', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                    ]);
                }

                return;
            }
            if (! $this->hasUnresolvedSourceRisk($environment, $sourceType, $sourceKey, $invoice?->getKey(), $order?->getKey())) {
                $this->wallet->releaseHold($grant, 'stripe-refund:'.$refundId);
            }
        }, 3);
    }

    public function dispute(array $dispute, string $environment): void
    {
        $this->assertEnvironment($environment);
        $disputeId = $this->id($dispute['id'] ?? null);
        $chargeId = $this->id($dispute['charge'] ?? null);
        if (! $disputeId || ! $chargeId) {
            throw new RuntimeException('Dispute identity or charge missing.');
        }
        $charge = app(StripeBillingGateway::class)->charge($chargeId);
        $intent = $this->id($charge['payment_intent'] ?? null);
        if (! $intent) {
            throw new RuntimeException('Dispute has no original payment evidence.');
        }
        $invoice = BillingInvoice::query()->where('environment', $environment)
            ->where('stripe_payment_intent_id', $intent)->first();
        $order = $invoice ? null : BillingOrder::query()->where('environment', $environment)
            ->where('stripe_payment_intent_id', $intent)->where('kind', 'topup')->first();
        if (! $invoice && ! $order) {
            throw new RuntimeException('Disputed payment source needs reconciliation.');
        }
        $user = User::findOrFail($invoice?->user_id ?? $order->user_id);
        $sourceType = $invoice ? 'paid_cycle' : 'purchased_topup';
        $sourceKey = $invoice ? 'stripe-invoice:'.$environment.':'.$invoice->stripe_invoice_id
            : 'stripe-topup:'.$environment.':'.$order->getKey();
        $status = (string) ($dispute['status'] ?? 'needs_response');
        DB::transaction(function () use ($user, $environment, $sourceType, $sourceKey, $status, $disputeId, $invoice, $order): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $grant = CreditGrant::query()->where('environment', $environment)
                ->where('source_type', $sourceType)->where('source_key', $sourceKey)->first();
            DB::table('credit_recovery_cases')->insertOrIgnore([
                'user_id' => $user->getKey(), 'environment' => $environment,
                'source_type' => $sourceType, 'source_key' => $sourceKey,
                'credits_affected' => $grant?->credits ?? 0, 'reason' => 'dispute:'.$disputeId,
                'status' => 'needs_review', 'details' => json_encode(['dispute_id' => $disputeId]),
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            if (! $grant) {
                return;
            }
            if ($status === 'won') {
                DB::table('credit_recovery_cases')->where('environment', $environment)
                    ->where('source_type', $sourceType)->where('source_key', $sourceKey)
                    ->where('reason', 'dispute:'.$disputeId)->update(['status' => 'resolved', 'updated_at' => now('UTC')]);
                if (! $this->hasUnresolvedSourceRisk($environment, $sourceType, $sourceKey, $invoice?->getKey(), $order?->getKey())) {
                    $this->wallet->releaseHold($grant, 'stripe-dispute:'.$disputeId);
                }
            } elseif ($status === 'lost') {
                $this->wallet->revoke($grant, 'stripe-dispute:'.$disputeId);
            } else {
                $this->wallet->hold($grant, 'stripe-dispute:'.$disputeId);
            }
        }, 3);
    }

    /**
     * The wallet exposes a single source-level hold. Never release it while
     * another refund, dispute, or early fraud warning still needs review.
     * Called only under the source owner's transaction/row lock.
     */
    private function hasUnresolvedSourceRisk(
        string $environment,
        string $sourceType,
        string $sourceKey,
        ?int $invoiceId,
        ?string $orderId,
    ): bool {
        return DB::table('billing_refunds')
            ->where('environment', $environment)
            ->where('billing_invoice_id', $invoiceId)
            ->where('billing_order_id', $orderId)
            ->whereIn('status', ['pending', 'requires_action', 'needs_review'])->exists()
            || DB::table('credit_recovery_cases')
                ->where('environment', $environment)
                ->where('source_type', $sourceType)
                ->where('source_key', $sourceKey)
                ->where('reason', 'like', 'dispute:%')
                ->where('status', 'needs_review')->exists();
    }
}
