<?php

namespace App\Console\Commands;

use App\Models\BillingOrder;
use App\Services\Subscriptions\StripeOrderReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileStripeOrders extends Command
{
    protected $signature = 'stripe:reconcile-orders {--limit=100}';

    protected $description = 'Recover pending and ambiguous Checkout orders without creating replacement payments.';

    public function handle(StripeOrderReconciliationService $reconciler): int
    {
        if (! filled(config('stripe_billing.secret_key'))) {
            $this->line('No Stripe credentials; reconciliation disabled.');

            return self::SUCCESS;
        }

        $environment = (string) config('stripe_billing.environment');
        if ($environment !== (string) config('subscriptions.environment')) {
            $this->error('Stripe and wallet environments differ. No provider requests were made.');

            return self::FAILURE;
        }
        $orders = BillingOrder::query()->where('environment', $environment)
            ->whereIn('status', ['reserved', 'needs_reconciliation', 'checkout_created', 'paid'])
            ->where('created_at', '<', now('UTC')->subMinutes(2))
            ->orderBy('created_at')->limit(min(500, max(1, (int) $this->option('limit'))))->get();
        $recovered = 0;
        $unresolved = 0;
        foreach ($orders as $order) {
            try {
                $reconciler->reconcile($order) ? $recovered++ : $unresolved++;
            } catch (Throwable $error) {
                $unresolved++;
                report($error);
            }
        }
        $this->line("Checked {$orders->count()} orders: {$recovered} reconciled, {$unresolved} unresolved.");

        return $unresolved ? self::FAILURE : self::SUCCESS;
    }
}
