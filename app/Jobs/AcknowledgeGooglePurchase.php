<?php

namespace App\Jobs;

use App\Models\BillingAgreement;
use App\Models\User;
use App\Services\Subscriptions\GooglePlayBillingProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AcknowledgeGooglePurchase implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public readonly int $agreementId) {}

    public function backoff(): array
    {
        return [30, 60, 120, 300, 900];
    }

    public function handle(GooglePlayBillingProvider $google): void
    {
        $agreement = BillingAgreement::query()->findOrFail($this->agreementId);
        DB::transaction(function () use ($agreement, $google): void {
            User::query()->lockForUpdate()->findOrFail($agreement->user_id);
            $agreement = $agreement->fresh();
            if ($agreement->provider !== 'google' || ! $agreement->acknowledgement_pending) {
                return;
            }
            $google->acknowledge($agreement);
            $agreement->update(['acknowledgement_pending' => false]);
        });
    }
}
