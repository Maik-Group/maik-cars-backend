<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionAccessException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class AppleBillingProvider
{
    public function verify(string $reference, string $environment): VerifiedPurchase
    {
        return new VerifiedPurchase($this->invoke('purchase', ['reference' => $reference, 'environment' => $environment]));
    }

    public function notification(string $signedPayload): array
    {
        return $this->invoke('notification', ['signed_payload' => $signedPayload]);
    }

    public function history(string $environment, array $interval, ?string $paginationToken): array
    {
        return $this->invoke('history', ['environment' => $environment, 'start_date' => $interval['start'], 'end_date' => $interval['end'], 'pagination_token' => $paginationToken]);
    }

    public function catalog(string $productId, string $environment): array
    {
        return $this->invoke('catalog', ['product_id' => $productId, 'environment' => $environment]);
    }

    private function invoke(string $operation, array $parameters): array
    {
        $configuration = config('subscriptions.apple');
        try {
            $response = Process::timeout(25)->input(json_encode([
                'operation' => $operation, 'config' => $configuration, ...$parameters,
            ], JSON_THROW_ON_ERROR))->run([$configuration['node_binary'], base_path('tools/apple-billing/verifier.mjs')]);
        } catch (ProcessTimedOutException) {
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        }
        $decoded = json_decode($response->output(), true);
        if (! $response->successful() || ! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new SubscriptionAccessException(
                ($decoded['retryable'] ?? true) ? 'VERIFICATION_UNAVAILABLE' : 'INVALID_PURCHASE',
                ($decoded['retryable'] ?? true) ? 503 : 422,
            );
        }

        return $decoded['data'];
    }
}
