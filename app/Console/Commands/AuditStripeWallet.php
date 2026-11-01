<?php

namespace App\Console\Commands;

use App\Models\BillingOrder;
use App\Models\CreditGrant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only audit. Never repairs a balance by editing the append-only ledger.
 * Any discrepancy must be investigated and corrected with an audited entry.
 */
class AuditStripeWallet extends Command
{
    protected $signature = 'stripe:audit-wallet {--limit=50000}';

    protected $description = 'Compare Stripe fulfillment identities, wallet balances and immutable credit ledger.';

    public function handle(): int
    {
        $env = (string) config('stripe_billing.environment');
        $limit = min(500000, max(1, (int) $this->option('limit')));
        $checked = 0;
        $errors = 0;
        CreditGrant::query()->where('environment', $env)->orderBy('id')
            ->chunkById(250, function ($grants) use (&$checked, &$errors, $limit): bool {
                foreach ($grants as $grant) {
                    if ($checked++ >= $limit) {
                        return false;
                    }
                    $net = (int) DB::table('credit_ledger_entries')
                        ->where('credit_grant_id', $grant->id)->sum('amount');
                    if ($grant->remaining < 0 || $grant->remaining > $grant->credits
                        || $net !== (int) $grant->remaining) {
                        $errors++;
                        $this->warn('Wallet ledger mismatch: grant '.$grant->id);
                    }
                }

                return $checked < $limit;
            });

        $invoiceGrants = DB::table('billing_invoice_grants as gifts')
            ->join('billing_invoices as invoices', 'invoices.id', '=', 'gifts.billing_invoice_id')
            ->where('invoices.environment', $env)
            ->select('gifts.source_key', 'invoices.user_id')->cursor();
        foreach ($invoiceGrants as $invoiceGrant) {
            if (! CreditGrant::query()->where('environment', $env)
                ->where('user_id', $invoiceGrant->user_id)
                ->where('source_type', 'paid_cycle')->where('source_key', $invoiceGrant->source_key)->exists()) {
                $errors++;
                $this->warn('Paid invoice credit grant missing: '.$invoiceGrant->source_key);
            }
        }

        BillingOrder::query()->where('environment', $env)->where('kind', 'topup')
            ->where('fulfillment_status', 'fulfilled')->orderBy('id')
            ->chunk(250, function ($orders) use (&$errors, $env): void {
                foreach ($orders as $order) {
                    if (! CreditGrant::query()->where('environment', $env)
                        ->where('user_id', $order->user_id)
                        ->where('source_type', 'purchased_topup')
                        ->where('source_key', 'stripe-topup:'.$env.':'.$order->getKey())->exists()) {
                        $errors++;
                        $this->warn('Fulfilled topup has no credit grant: '.$order->getKey());
                    }
                }
            });
        $this->info("Audited {$checked} wallet sources; {$errors} inconsistencies.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
