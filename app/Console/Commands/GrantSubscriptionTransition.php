<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Subscriptions\AdminSubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GrantSubscriptionTransition extends Command
{
    protected $signature = 'subscriptions:grant-transition {--dry-run} {--reason=Announced subscription cutover}';

    protected $description = 'Grant eligible existing app accounts thirty days from the configured UTC cutover';

    public function handle(AdminSubscriptionService $subscriptions): int
    {
        $this->error('Transition grants are permanently disabled.');

        return self::FAILURE;
        $configured = config('subscriptions.cutover_at');
        if (! $configured) {
            $this->error('Configure SUBSCRIPTIONS_CUTOVER_AT before preparing transition access.');

            return self::FAILURE;
        }
        $cutover = CarbonImmutable::parse($configured)->utc();
        $count = 0;
        User::query()->appUsers()->where('is_active', true)->where('created_at', '<', $cutover)
            ->chunkById(100, function ($users) use ($subscriptions, $cutover, &$count): void {
                foreach ($users as $user) {
                    if (! $this->option('dry-run')) {
                        $subscriptions->grantTransition($user, $cutover, (string) $this->option('reason'));
                    }
                    $count++;
                }
            });
        $this->info(($this->option('dry-run') ? 'Eligible accounts: ' : 'Processed eligible accounts: ').$count);

        return self::SUCCESS;
    }
}
