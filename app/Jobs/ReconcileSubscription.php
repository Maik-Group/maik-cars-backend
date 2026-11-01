<?php

namespace App\Jobs;

use App\Models\BillingAgreement;
use App\Services\Subscriptions\StorePurchaseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileSubscription implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $agreementId) {}

    public function backoff(): array
    {
        return [30, 60, 300, 900];
    }

    public function handle(StorePurchaseService $purchases): void
    {
        $agreement = BillingAgreement::query()->with('user')->findOrFail($this->agreementId);
        $purchases->verify($agreement->user, $agreement->provider, $agreement->purchase_reference);
    }
}
