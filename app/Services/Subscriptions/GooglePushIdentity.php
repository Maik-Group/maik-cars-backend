<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionAccessException;
use Google\Auth\AccessToken;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class GooglePushIdentity
{
    public function __construct(private readonly GoogleHttpTransport $transport) {}

    public function verify(?string $jwt): void
    {
        $audience = config('subscriptions.google.push_audience');
        $email = config('subscriptions.google.push_service_account');
        if (! $audience || ! $email) {
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        }
        if (! $jwt || strlen($jwt) > 16384) {
            throw new SubscriptionAccessException('INVALID_PUSH_IDENTITY', 401);
        }
        try {
            $claims = (new AccessToken($this->transport))->verify($jwt, ['audience' => $audience]);
        } catch (ConnectionException) {
            throw new SubscriptionAccessException('VERIFICATION_UNAVAILABLE', 503);
        } catch (Throwable) {
            throw new SubscriptionAccessException('INVALID_PUSH_IDENTITY', 401);
        }
        if (! $claims || ! in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
            || ($claims['email'] ?? null) !== $email || ($claims['email_verified'] ?? false) !== true) {
            throw new SubscriptionAccessException('INVALID_PUSH_IDENTITY', 401);
        }
    }
}
