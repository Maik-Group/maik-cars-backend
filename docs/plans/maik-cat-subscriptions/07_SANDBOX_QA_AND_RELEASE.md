> Approved design from 21 October 2026. Local implementation and validation are tracked in the [execution register](08_PHASED_EXECUTION_PLAN.md). The user authorized local implementation on 22 October while keeping native/store acceptance and production activation gated.

## 4. Test, sandbox, and release plan

### Acceptance suites

| ID | Required scenarios |
|---|---|
| **U1 — entitlement** | Active/expired/future/revoked grants; paid-through cancellation; grace/hold/pause/refund; highest allowance; unlimited transition; snapshot preservation; no synthetic payments. |
| **U2 — usage** | Normalized-family identity, UUID fallback, duplicate analyses, repeated reveal, finite/unlimited allowance, day rollover, expiry, calendar-month/leap-year boundaries. |
| **I1 — HTTP contract** | Authentication and inactive accounts; all price/assay leakage paths; hidden-item 404; stable camelCase errors; existing discount, currency and averaging behavior; search/calculator remain free. |
| **I2 — provider verification** | Invalid signatures/certificates, wrong app/product/environment/account, forged token, duplicates, pending purchase, retry after timeout, token replacements, cancellation/refund, missing identity. |
| **I3 — notifications/operations** | Forged Pub/Sub identity, duplicate/out-of-order events, durable acknowledgement, worker retry, downtime/replay, reconciliation, permanent failure visibility and local suppression persistence. |
| **I4 — Filament** | Every role and permission, direct action invocation, custom grant dates/reasons, revoke warnings, immutable history, mapping mismatch, safe diagnostics. |
| **I5 — MySQL concurrency** | Multiple processes/connections compete for the last allowance; exactly one new family succeeds. Same-family concurrency charges once. Initial-bucket race, concurrent revocation and midnight boundary remain consistent. |
| **E1 — native purchase** | Both platforms: success, decline, cancellation, pending/interrupted purchase, restart, duplicate callbacks, restore/reinstall/new device, renewal, lapse, refund/revoke, grace/hold, product unavailability and plan changes. |
| **E2 — access/UI** | Paywall, 0/1/N remaining, repeated family, offline startup, account switch, stale cache, deep links, RTL/localization, provider unavailable, transition expiry and old-client update gate. |
| **E3 — release** | Staging separation, production-build configuration, sandbox isolation, exact release-artifact checks, old/new client coexistence, rollout rehearsal, monitoring and rollback. |

Use Pest for backend coverage and an SDK-matched Expo Jest preset with React Native Testing Library for mobile lifecycle/component tests. Run MySQL concurrency tests against a separately verified disposable database; SQLite results do not establish concurrent quota safety.

### Zero-charge developer QA runbook

**Apple**

1. Confirm account access, Paid Apps Agreement, bundle `maik.cat.ios`, app identity, product metadata, subscription group and notifications configuration.
2. Configure all four periods and service levels; verify available storefronts and localized prices.
3. Use Xcode StoreKit configuration for local native-flow tests where a Mac is available.
4. Use physical-device Sandbox and TestFlight for server-connected purchase tests.
5. Verify transaction environment, notification delivery, agreement/transaction records, effective access and restore after reinstall.

Local StoreKit testing does not prove App Store Server API or notification integration. Sandbox/TestFlight testing does not incur purchase charges, but requires the correct account/build setup. [Apple sandbox overview](https://developer.apple.com/help/app-store-connect/test-in-app-purchases/overview-of-testing-in-sandbox).

**Google**

1. Confirm package `maik.cat.android`, Play product/base plans, service-account API access, Pub/Sub topic and authenticated push subscription.
2. Add the purchasing Google account to **License Testers**.
3. Verify the purchasing account in the purchase dialog and select a test payment instrument.
4. Use a native development build or Internal Testing distribution; add Play Billing Lab for accelerated lifecycle cases.
5. Confirm verification, acknowledgement, RTDN, renewal/order handling and access post-state.

**Internal Testing membership alone does not prevent real charges.** If the test dialog/account/payment instrument is not correct, stop before purchase. [Google billing testing](https://developer.android.com/google/play/billing/test).

**Environment rules**

- Development/preview builds use staging Laravel, a separate database, queue and secrets.
- Separate provider environments in every identity, inbox and ledger constraint.
- Production rejects sandbox/test grants for ordinary accounts. Explicit QA/review accounts may use an isolated test context to support TestFlight/store review.
- Provider environment comes from verified evidence, never a client “sandbox” flag.
- No production verification bypass or real-card test is introduced.

### Rollout and rollback

1. Deploy additive schema, workers and APIs with customer enforcement and purchases disabled.
2. Verify staging, native compatibility, provider connectivity, restore and QA suites.
3. Release billing-capable apps with purchase availability controlled for approved review/test accounts.
4. Confirm public availability in every intended storefront before raising minimum supported app versions, following the existing [release rule](C:/laragon/www/maik/maik-cat-mobile/docs/STORE_RELEASE_CHECKLIST.md:200).
5. Announce and configure cutover timestamp `T0`.
6. Idempotently grant active app accounts existing before `T0` transitional access through `T0 + 30 days`; record the eligible batch and reason.
7. At cutover, require the updated client, redact every legacy price/assay response, and enable approved store products.
8. Phase purchase availability through internal users, 5%, 25%, then all eligible users. Restricted data protection applies consistently; client-version headers never authorize legacy unrestricted responses.
9. Monitor verification failures, acknowledgement backlog, event lag, mapping mismatches, reveal errors and support cases before each expansion.
10. At transition expiry, require paid or administrative access.

Rollback disables new purchases and rolls back defective UI/service behavior while retaining ledgers, notifications and valid paid access. It must not reopen public protected fields or remove evidence of existing purchases. Use maintenance errors when secure valuation cannot operate.

Operational alerts: inbox/reconciliation lag over 15 minutes, oldest acknowledgement pending over one hour, failed subscription jobs, provider verification spikes, and catalog mismatches. The dashboard exposes redacted diagnostics and audited reconciliation/replay actions.
