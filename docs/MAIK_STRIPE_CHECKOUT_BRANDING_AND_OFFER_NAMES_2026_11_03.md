# Maik Cat — Stripe Checkout branding and DB-owned offer names

## Implemented (Stripe test sandbox + local source)

- Stripe **sandbox only** account `Mustafa Team sandbox`: white background, Maik orange `#C55000`, dark orange `#A84400`, Inter typography, rounded controls, and the existing Maik Cat logo as a square compliant branding asset. Live settings were not changed.
- Checkout line items for one-time credit purchases use the **immutable Laravel billing order snapshot** for their name, price, currency and quantity. The mobile payment header shows the same server-confirmed snapshot when the updated backend returns it. No client-side offer JSON file supplies prices or titles.
- A legacy purchase may display the equivalent latest name in the membership header only if kind, credits, amount, currency, and duration all match; historic purchases, receipts, and published Stripe prices are unchanged.
- Published seven clean-name QA offer **revision 2** records and matching Stripe test products/prices. Subsequently, the seven canonical commercial offers were also published in the local Stripe sandbox and **supersede QA fixtures in the customer-facing catalog**. The API selects the newest published revision of each key.
- The sandbox Checkout Session suppresses Link (`wallet_options.link.display=never`) to use a brand-first card form rather than Link's independently styled green interface. Production payment-method choices are unchanged.
- Removed the obsolete generated `storage/app/private/maik-qa-scenarios.json` balance snapshot and replaced it with read-only `php artisan maik:qa-billing-report`; smoke scripts query this live report.

## JSON files deliberately retained

- `storage/app/private/maik-qa-credentials.json` is a **private login credential manifest**, not an offer catalog. Removing it would block safe, idempotent QA account seeding; never copy or commit it.
- `ecotrade_products_all.json` and imported Ecotrade image JSON reports are legacy import/evidence inputs and unrelated to billing offers.
- Mobile `src/i18n/locales/*/common.json` are translations, not subscriptions or prices. Framework manifests, test evidence, and package lockfiles are also not offer sources.

## QA results and release boundary

- Local billing catalog: 4 commercial subscription terms and 3 commercial one-time top-ups, all eligible and correctly named. Sandbox Stripe prices are test-mode only. Default `DatabaseSeeder` no longer creates QA billing fixtures.
- Backend billing tests: **48 passed**, 407 assertions. Mobile subscription/layout tests: **51 passed**; TypeScript and Android JS export passed.
- A fresh Android release APK was built and installed on the running emulator. The new native checkout header opens a Stripe-hosted sandbox session without completing a payment.
- **Remote API not deployed:** the emulator currently targets `https://maik-cat.mustafafares.com/api`, while the updated Laravel code and published QA DB revisions were prepared locally. Its catalog will continue to display old QA names until the compatible backend code/data is deployed on that API and its catalog is verified. Do not misinterpret a remote QA screenshot as evidence of the local update.
- **WebView ANR observed:** on the resource-constrained emulator, opening/closing a Link-hosted Stripe page produced Android `Input dispatching timed out` during Chromium WebView activity. A secure-browser fallback is available. The new sandbox Link-free server setting was **not** tested end-to-end against the remote API; retest after staging deployment, and validate on a physical Android device before release.
- Stripe-hosted Checkout may still display the Stripe account's merchant name (`Mustafa Team sandbox`) and Stripe-controlled spacing. A shared Stripe account name should not be renamed solely for this app without checking other integrations.
- No production database migration, production payments, Stripe live changes, or production deployment were performed. Existing unrelated dirty working-tree files remain untouched.

## Verification after deploying to an isolated QA API

1. Confirm sandbox environment and a matching Stripe test account. Apply only reviewed schema migrations and the idempotent QA name-revision seeder; run the guarded QA publication command only with authorized admin approval if that server lacks the new Stripe price identities.
2. Read `GET /api/v1/billing/plans` as an authenticated QA user and confirm only the seven revised names, unique `priceVersionId`s, and correct amounts.
3. Start a **10 Extra Credits** test checkout without paying; verify a one-time product, `$3.99`, correct name, orange branding and logo, and no Link takeover. Close it and verify abandonment allows a fresh session.
4. Verify existing 12-month QA membership displays `Annual Plan` while old receipts retain the original purchased name. Confirm no incorrect access gate for a valid wallet/subscription.
5. Exercise WebView and secure-browser fallback on emulator and a physical device; inspect Android ANR/system WebView health. Do not enable store-distributed native external checkout until the policy review passes.
