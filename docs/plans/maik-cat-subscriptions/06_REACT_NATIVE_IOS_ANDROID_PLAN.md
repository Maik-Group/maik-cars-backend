> Approved design from 21 October 2026. Local implementation and validation are tracked in the [execution register](08_PHASED_EXECUTION_PLAN.md). The user authorized local implementation on 22 October while keeping native/store acceptance and production activation gated.

### React Native / Expo plan

Numbered navigation flow:

1. Browse/search/home/saved items show public item information and a “View price and analysis” action.
2. Logged-out users sign in through the existing account flow.
3. The explicit reveal action calls the valuation endpoint.
4. Missing access opens `/subscriptions`; exhausted quota displays the reset time.
5. The paywall joins backend plan benefits with store-returned localized prices.
6. Native purchase completes, then Laravel verifies it.
7. Only confirmed server access unlocks a reveal.
8. Settings provides current access, usage, renewal/expiry, Restore Purchases, Manage Subscription, and support.

Purchase states:

`idle → loadingProducts → ready → purchasing → pending or verifying → active`

Cancellation returns to `ready`; retryable verification retains the purchase for recovery; terminal failure shows actionable guidance. Startup, interrupted callbacks, and duplicate delivery all reuse server idempotency.

Additional requirements:

- Choose `expo-iap@6.1.1`; do not add Nitro or eject the Expo app.
- Preserve the Expo 54 toolchain unless the compatibility gate proves a change necessary. On failure, stop with evidence and obtain a revised compatibility plan.
- Register purchase recovery at app-provider level. Finish native transactions only after server confirmation; Google backend acknowledgement remains authoritative.
- Extend `HttpClientError` to retain parsed error code/details while preserving existing callers.
- Separate public item types from restricted valuation types.
- Use explicit reveal mutations, never list serialization, prefetch, or automatic detail effects that spend allowances.
- Keep valuation data out of general query caches and `stableItem`. Clear it on logout/account switch, backgrounding, offline transition, expiry, and access-change notification.
- Give visible valuation responses a maximum 60-second display lease, bounded by entitlement expiry and daily reset. Revalidate while visible; hide expired data. Server revocation takes effect immediately for subsequent requests.
- Preserve all five locales: Arabic, English, Bulgarian, German, Croatian. Review Arabic RTL and localized store prices.
- Keep purchase controls unavailable on web; show applicable access status and support guidance.
- Preserve original account ownership during restore and explain the existing device-reset path.
