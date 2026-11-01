<?php

return [
    // No Stripe calls are made unless checkout is explicitly enabled AND a
    // server-approved purchase context is present. Keep both closed by default.
    'checkout_enabled' => (bool) env('STRIPE_CHECKOUT_ENABLED', false),
    'environment' => env('STRIPE_BILLING_ENVIRONMENT', env('SUBSCRIPTIONS_ENVIRONMENT', 'production')),
    'secret_key' => env('STRIPE_SECRET_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'api_version' => '2026-10-28.endive',
    'return_url' => env('STRIPE_RETURN_URL'),
    'portal_return_url' => env('STRIPE_PORTAL_RETURN_URL'),
    'portal_configuration' => env('STRIPE_PORTAL_CONFIGURATION'),
    'restricted_portal_configuration' => env('STRIPE_RESTRICTED_PORTAL_CONFIGURATION'),
    'purchase_contexts' => array_values(array_filter(array_map('trim', explode(',', (string) env('STRIPE_APPROVED_PURCHASE_CONTEXTS', ''))))),
    // This switch must remain false until store-specific native adapters,
    // program enrollment, regional checks and purchase reporting are verified.
    'native_external_checkout_approved' => (bool) env('STRIPE_NATIVE_EXTERNAL_CHECKOUT_APPROVED', false),
    // Show *unpublished*, non-purchasable sample offers ONLY for local QA.
    // Drafts never gain verified_at, published_at or checkout eligibility.
    'local_draft_preview' => (bool) env('STRIPE_LOCAL_DRAFT_PREVIEW', false),
];
