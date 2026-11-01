<?php

namespace App\Console\Commands;

use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\StripeWebhookEvent;
use Illuminate\Console\Command;

class RecoverStripeWebhooks extends Command
{
    protected $signature = 'stripe:recover-events {--limit=100}';

    protected $description = 'Retry verified pending or stale Stripe webhook events without replaying payments.';

    public function handle(): int
    {
        $limit = min(500, max(1, (int) $this->option('limit')));
        $rows = StripeWebhookEvent::query()
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(fn ($q) => $q->where('status', 'failed')
                        ->where('next_attempt_at', '<=', now('UTC')))
                    ->orWhere(fn ($q) => $q->where('status', 'processing')
                        ->where('updated_at', '<=', now('UTC')->subMinutes(10)));
            })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($rows as $id) {
            ProcessStripeWebhookEvent::dispatch((int) $id);
        }
        $this->info('Queued '.$rows->count().' durable Stripe events.');

        return self::SUCCESS;
    }
}
