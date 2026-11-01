<?php

namespace App\Jobs;

use App\Services\Subscriptions\StripeEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStripeWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public array $backoff = [15, 60, 120, 300, 900];

    public function __construct(public readonly int $eventId) {}

    public function handle(StripeEventProcessor $processor): void
    {
        $processor->process($this->eventId);
    }
}
