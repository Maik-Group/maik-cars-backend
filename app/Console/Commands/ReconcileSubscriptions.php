<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSubscriptionEvent;
use App\Jobs\ReconcileSubscription;
use App\Models\BillingAgreement;
use App\Models\SubscriptionEvent;
use Illuminate\Console\Command;

class ReconcileSubscriptions extends Command
{
    protected $signature = 'subscriptions:reconcile';

    protected $description = 'Queue due agreements and recover abandoned subscription inbox work';

    public function handle(): int
    {
        BillingAgreement::query()->where('verified_at', '<=', now()->subMinutes(15))
            ->where(function ($query): void {
                $query->whereIn('provider_state', ['active', 'grace', 'pending', 'hold', 'paused'])->orWhere('acknowledgement_pending', true);
            })->orderBy('id')->chunkById(100, function ($agreements): void {
                foreach ($agreements as $agreement) {
                    ReconcileSubscription::dispatch($agreement->getKey())->delay(now()->addSeconds(random_int(0, 120)));
                }
            });
        SubscriptionEvent::query()->whereIn('state', ['pending', 'processing'])->where('updated_at', '<', now()->subMinutes(5))
            ->orderBy('id')->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    ProcessSubscriptionEvent::dispatch($event->getKey());
                }
            });

        return self::SUCCESS;
    }
}
