<?php

return [
    'terms_url' => env('SUBSCRIPTIONS_TERMS_URL'),
    'support_url' => env('SUBSCRIPTIONS_SUPPORT_URL'),
    'display_lease_seconds' => 60,
    'apple' => [
        'node_binary' => env('APPLE_BILLING_NODE_BINARY', 'node'),
        'bundle_id' => 'maik.cat.ios',
        'app_id' => env('APPLE_BILLING_APP_ID'),
        'issuer_id' => env('APPLE_BILLING_ISSUER_ID'),
        'key_id' => env('APPLE_BILLING_KEY_ID'),
        'private_key_path' => env('APPLE_BILLING_PRIVATE_KEY_PATH'),
        'root_certificates_path' => env('APPLE_BILLING_ROOT_CERTIFICATES_PATH'),
        'subscription_group_id' => env('APPLE_SUBSCRIPTION_GROUP_ID'),
        'catalog_issuer_id' => env('APPLE_CATALOG_ISSUER_ID'),
        'catalog_key_id' => env('APPLE_CATALOG_KEY_ID'),
        'catalog_private_key_path' => env('APPLE_CATALOG_PRIVATE_KEY_PATH'),
    ],
    'google' => [
        'package_name' => 'maik.cat.android',
        'credentials_path' => env('GOOGLE_PLAY_CREDENTIALS_PATH'),
        'push_audience' => env('GOOGLE_PLAY_PUSH_AUDIENCE'),
        'push_service_account' => env('GOOGLE_PLAY_PUSH_SERVICE_ACCOUNT'),
    ],
];
