<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSubscriptionEvent;
use App\Services\Subscriptions\AppleBillingProvider;
use App\Services\Subscriptions\SubscriptionInbox;
use Illuminate\Console\Command;

class RecoverAppleSubscriptionEvents extends Command
{
    protected $signature = 'subscriptions:recover-apple {--hours=24} {--pages=20}';

    protected $description = 'Recover and verify Apple notification history into the durable inbox';

    public function handle(AppleBillingProvider $apple, SubscriptionInbox $inbox): int
    {
        if (! config('subscriptions.apple.private_key_path')) {
            $this->warn('Apple verification is not configured.');

            return self::FAILURE;
        }
        $hours = max(1, min(4320, (int) $this->option('hours')));
        $pages = max(1, min(100, (int) $this->option('pages')));
        $interval = ['start' => now()->subHours($hours)->getTimestampMs(), 'end' => now()->getTimestampMs()];
        $cursor = null;
        for ($pageIndex = 0; $pageIndex < $pages; $pageIndex++) {
            $page = $apple->history(config('stripe_billing.environment'), $interval, $cursor);
            foreach ($page['notifications'] as $notification) {
                $event = $inbox->provider('apple', $notification['id'], $notification);
                ProcessSubscriptionEvent::dispatch($event->getKey());
            }
            $cursor = $page['pagination_token'];
            if (! $page['has_more']) {
                return self::SUCCESS;
            }
        }
        $this->warn('History page limit reached; continue recovery with a narrower interval.');

        return self::FAILURE;
    }
}
