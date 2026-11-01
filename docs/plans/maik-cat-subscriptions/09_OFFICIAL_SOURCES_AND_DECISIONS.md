# Official sources and implementation decisions

The supplied plan was dated 21 October 2026. The user authorized local implementation on 22 October while keeping activation gated. Current evidence and outstanding acceptance are recorded in [the execution register](08_PHASED_EXECUTION_PLAN.md).

## Runtime choices

- Expo IAP is pinned to **6.1.1**, with its Expo config plugin on the existing Expo 54 / React Native 0.81.5 project. [OpenIAP Expo setup](https://www.openiap.dev/docs/setup/expo) and [upstream package manifest](https://raw.githubusercontent.com/hyodotdev/openiap/main/libraries/expo-iap/package.json) are references; the checked-in lockfile and local artifacts establish the installed version. Android compilation passed; iOS and real store callbacks remain unverified.
- The private Node helper uses **@apple/app-store-server-library 3.1.0**, fixed argv and bounded JSON stdin. It verifies signed transactions, nested notification/renewal data and notification history using configured roots and application identity. [Official Apple library](https://github.com/apple/app-store-server-library-node), [3.1.0 release](https://github.com/apple/app-store-server-library-node/releases/tag/v3.1.0).
- Google purchases are verified through the Play Developer API. **google/auth 1.50.1**, **firebase/php-jwt 7.0.5** and **phpseclib/phpseclib ~3.0** are explicit Composer dependencies; phpseclib supplies the RSA/JWK support used by Google's access-token verifier. Resolved versions are in `composer.lock`.

## Provider evidence and replacement rules

Google deferred replacements may include both a currently owned item and an unowned future item. The implementation selects the paid-through item using its expiry and successful order, then changes the benefit snapshot only when the verified current mapping changes. Transaction evidence is keyed by the authenticated subscription's successful order ID; an older ancestor may retain the order's purchase token. These choices were checked against the [SubscriptionPurchaseV2 resource](https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.subscriptionsv2) and [official replacement codelab](https://codelabs.developers.google.com/codelabs/play-billing-subs-replacement?hl=en) during implementation.

Apple uses current verified subscription status rather than trusting the order of incoming notifications. Retryable certificate-status transport failures remain retryable; a wrong environment, invalid certificate or forged JWS never creates access. Positive store-connected proof is still required. Store duration/change behavior is documented in [Apple subscription information](https://developer.apple.com/help/app-store-connect/reference/in-app-purchases-and-subscriptions/auto-renewable-subscription-information/).

## Product and acceptance boundaries

- Native auto-renewing digital access uses Apple and Google only. External billing and promotional offers are outside this release. The supplied policy references are [Apple review guidelines](https://developer.apple.com/app-store/review/guidelines/) and [Google Payments policy](https://support.google.com/googleplay/android-developer/answer/9858738). Recheck applicable policies before submission.
- No launch names, quotas, prices, product IDs or storefront availability have been invented. The app joins approved backend mappings to native store-returned localized prices; unverified mappings remain unavailable.
- Apple Sandbox and Google License Tester account setup are external prerequisites. See [Apple sandbox testing](https://developer.apple.com/help/app-store-connect/test-in-app-purchases/overview-of-testing-in-sandbox) and [Google billing testing](https://developer.android.com/google/play/billing/test). Internal Testing distribution alone is not evidence of a zero-charge purchase setup.
- Existing accounts receive 30 days from a fixed UTC cutover; unlimited transition access does not create payment records. Highest active allowance wins, serial families share daily claims, and leases cannot auto-spend a new day's allowance. These are approved project rules implemented in Laravel, rather than rules delegated to either store.
- The user-approved exception permits local code and isolated QA before P0 closes. It does not authorize deployment, store writes, a cutover or actual purchases. Purchases and enforcement remain disabled by default.
