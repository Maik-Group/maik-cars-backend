<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionAccessException;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GooglePlayAuthorization
{
    public function __construct(private readonly GoogleHttpTransport $transport) {}

    public function token(): string
    {
        $path = config('subscriptions.google.credentials_path');
        if (! $path || ! is_file($path)) {
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        }

        try {
            return Cache::remember('subscriptions:google-auth:'.hash('sha256', $path), 3000, function () use ($path): string {
                $credentials = new ServiceAccountCredentials('https://www.googleapis.com/auth/androidpublisher', $path);
                $token = $credentials->fetchAuthToken($this->transport);
                if (! is_string($token['access_token'] ?? null)) {
                    throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
                }

                return $token['access_token'];
            });
        } catch (Throwable) {
            // Provider authentication failures can include key paths or HTTP request details.
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        }
    }
}
