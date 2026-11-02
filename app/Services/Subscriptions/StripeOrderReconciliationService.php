<?php

namespace App\Services\Subscriptions;

use App\Models\BillingOrder;
use App\Models\StripeCustomer;
use RuntimeException;

/**
 * Read-only Stripe discovery for ambiguous Checkout creation.
 * Never recreates a Checkout session after an unknown provider timeout.
 */
class StripeOrderReconciliationService
{
    public function __construct(
        private readonly StripeBillingGateway $stripe,
        private readonly StripeFulfillmentService $fulfillment,
    ) {}

    public function reconcile(BillingOrder $order): bool
    {
        if (in_array($order->status, ['fulfilled', 'expired', 'cancelled'], true)) {
            return true;
        }
        $mapping = StripeCustomer::query()->where('user_id', $order->user_id)
            ->where('environment', $order->environment)->first();
        if (! $mapping || ! $mapping->stripe_customer_id) {
            return false; // Operator must resolve an ambiguous Customer creation.
        }
        $matched = null;
        if ($order->stripe_checkout_session_id) {
            $matched = $this->stripe->checkoutSession($order->stripe_checkout_session_id);
        } else {
            $after = null;
            // Bounded paginated search. Absence is not proof that a remote
            // Checkout was never created; never create another charge here.
            for ($page = 0; $page < 5; $page++) {
                $sessions = $this->stripe->checkoutSessionsForCustomer($mapping->stripe_customer_id, $after);
                foreach (($sessions['data'] ?? []) as $session) {
                    if (($session['client_reference_id'] ?? null) === $order->getKey()) {
                        $matched = $session;
                        break 2;
                    }
                }
                $list = $sessions['data'] ?? [];
                if (! ($sessions['has_more'] ?? false) || count($list) === 0) {
                    break;
                }
                $last = end($list);
                $after = $last['id'] ?? null;
                if (! is_string($after)) {
                    break;
                }
            }
        }
        if (! is_array($matched)) {
            return false;
        }
        $order->refresh();
        $snapshot = $order->offer_snapshot;
        $customerId = is_string($matched['customer'] ?? null)
            ? $matched['customer'] : ($matched['customer']['id'] ?? null);
        if (($matched['client_reference_id'] ?? null) !== $order->getKey()
            || ($matched['metadata']['order_id'] ?? null) !== $order->getKey()
            || ($matched['metadata']['billing_environment'] ?? null) !== $order->environment
            || $customerId !== $mapping->stripe_customer_id
            || ($matched['mode'] ?? null) !== ($order->kind === 'topup' ? 'payment' : 'subscription')
            || (int) ($matched['amount_total'] ?? -1) !== (int) $snapshot['amountMinor']
            || strtoupper((string) ($matched['currency'] ?? '')) !== strtoupper((string) $snapshot['currency'])) {
            throw new RuntimeException('Reconciled Checkout does not match its immutable local order.');
        }
        $sessionId = (string) ($matched['id'] ?? '');
        if (! str_starts_with($sessionId, 'cs_')) {
            throw new RuntimeException('Invalid Stripe Checkout identity during reconciliation.');
        }
        if (($matched['status'] ?? null) === 'expired') {
            // Persist the verified remote session identity first. The expiry
            // transition intentionally selects by that identity; otherwise an
            // order whose original Checkout response was lost stays reserved
            // forever despite a confirmed provider expiry.
            if (! $order->stripe_checkout_session_id) {
                $order->forceFill(['stripe_checkout_session_id' => $sessionId])->save();
            }
            $this->fulfillment->expireCheckout($sessionId, $order->environment, 'expired');

            return true;
        }
        if (($matched['status'] ?? null) === 'complete') {
            $this->fulfillment->fulfillCheckout($matched, $order->environment);
            // Subscriptions grant credits for a paid invoice, not a Checkout
            // redirect. When webhooks are delayed, retrieve that invoice from
            // Stripe and apply the exact same idempotent validated fulfillment.
            if ($order->kind === 'subscription'
                && ($matched['payment_status'] ?? null) === 'paid') {
                $subscriptionId = is_string($matched['subscription'] ?? null)
                    ? $matched['subscription'] : ($matched['subscription']['id'] ?? null);
                if (is_string($subscriptionId) && str_starts_with($subscriptionId, 'sub_')) {
                    $providerSubscription = $this->stripe->subscription($subscriptionId);
                    $latestInvoice = $providerSubscription['latest_invoice'] ?? null;
                    $invoiceId = is_string($latestInvoice) ? $latestInvoice : ($latestInvoice['id'] ?? null);
                    if (($providerSubscription['id'] ?? null) !== $subscriptionId
                        || (is_string($providerSubscription['customer'] ?? null)
                            ? $providerSubscription['customer'] : ($providerSubscription['customer']['id'] ?? null))
                        !== $mapping->stripe_customer_id) {
                        throw new RuntimeException('Stripe subscription owner mismatch.');
                    }
                    if (is_string($invoiceId) && str_starts_with($invoiceId, 'in_')) {
                        $invoice = $this->stripe->invoice($invoiceId);
                        $this->fulfillment->fulfillInvoice($invoice, $order->environment);
                    }
                }
            }

            return true;
        }
        $checkoutUrl = $matched['url'] ?? null;
        if (is_string($checkoutUrl) && parse_url($checkoutUrl, PHP_URL_HOST) === 'checkout.stripe.com'
            && str_starts_with($checkoutUrl, 'https://')) {
            $order->forceFill([
                'stripe_checkout_session_id' => $sessionId, 'checkout_url' => $checkoutUrl,
                'status' => 'checkout_created',
                'expires_at' => isset($matched['expires_at']) ? now()->setTimestamp((int) $matched['expires_at']) : null,
            ])->save();

            return true;
        }

        return false;
    }
}
