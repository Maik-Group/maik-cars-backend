<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionEvent;
use App\Models\User;
use Illuminate\Support\Str;

class SubscriptionInbox
{
    public function provider(string $provider, string $id, array $payload): SubscriptionEvent
    {
        $event = SubscriptionEvent::query()->firstOrCreate([
            'deduplication_key' => hash('sha256', $provider.':'.$id),
        ], [
            'provider' => $provider, 'environment' => $payload['environment'] ?? 'unknown',
            'correlation_id' => (string) Str::uuid(), 'payload' => $payload,
        ]);
        if (! $event->wasRecentlyCreated) {
            $event->increment('duplicate_deliveries');
        }

        return $event;
    }

    public function restore(User $user, string $provider, string $reference): SubscriptionEvent
    {
        $correlation = (string) Str::uuid();

        return SubscriptionEvent::query()->create([
            'user_id' => $user->getKey(), 'provider' => $provider,
            'environment' => app(EntitlementResolver::class)->environment($user),
            'deduplication_key' => hash('sha256', 'restore:'.$correlation), 'correlation_id' => $correlation,
            'payload' => ['operation' => 'restore', 'reference' => $reference],
        ]);
    }
}
