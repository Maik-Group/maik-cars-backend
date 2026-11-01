<?php

$secretKey = env('STRIPE_SECRET_KEY');
$environment = match (true) {
    is_string($secretKey) && str_starts_with($secretKey, 'sk_test_') => 'sandbox',
    is_string($secretKey) && str_starts_with($secretKey, 'sk_live_') => 'production',
    default => null,
};

return [
    'environment' => $environment,
    'secret_key' => $secretKey,
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'api_version' => '2026-10-28.endive',
    'return_url' => env('STRIPE_RETURN_URL'),
    'portal_return_url' => env('STRIPE_PORTAL_RETURN_URL'),
    'portal_configuration' => env('STRIPE_PORTAL_CONFIGURATION'),
    'restricted_portal_configuration' => env('STRIPE_RESTRICTED_PORTAL_CONFIGURATION'),
];
