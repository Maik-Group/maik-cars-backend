# Maik Cat — Stripe Checkout & offer data ownership

## Database is the billing source of truth

- `billing_plan_prices` is the authoritative, versioned offer catalog, populated by Laravel seeders or authorized administration. No JSON price/offer catalog is loaded by the API or the React Native app.
- `GET /api/v1/billing/plans` returns only the latest **published, non-retired** revision per key. When commercial offers are published, QA fixtures are no longer advertised.
- `POST /api/v1/billing/checkouts` accepts an offer version ID and purchase kind. Laravel validates its published offer and writes immutable price, currency, name and credit quantity to `billing_orders.offer_snapshot` before creating the Stripe session.
- The response includes `data.offer` from that saved snapshot for the mobile in-app purchase summary. The Stripe `price_data.product_data.name` is also created from the order snapshot, never from client-provided strings or old Stripe Product titles.
- Existing purchase receipts keep their historical name. `paidSubscription.displayName` may show the latest verified published name for the **same** offer key only if credits, price, currency, term and purchase kind are unchanged. Otherwise display the purchased name.

## Seed and publish in test mode

The legacy QA `r1` Stripe test names may be misleading (for example `QA 10 credits — test price`). Do **not** edit or delete purchased/published rows. The following explicit workflow prepares and publishes new versioned names without changing financial terms:

```bash
php artisan db:seed --class=MaikCatQaOfferNameRevisionSeeder --force
php artisan stripe:publish-qa-offer-names --actor=<authorized-admin-id> --execute
```

The publication command requires sandbox billing, a `sk_test_` key, explicit `--execute`, and an active actor with `manage_subscription_products`; it checks Stripe products/prices for consistency. The legacy QA names are in `MaikCatQaBillingSeeder::OFFER_NAMES`. **The customer-facing source is now `MaikCatCommercialOffersSeeder::OFFERS`**, not QA fixtures. `DatabaseSeeder` no longer invokes `MaikCatQaBillingSeeder`; the latter remains opt-in for QA scenarios only.

On November 3, 2026, the seven canonical commercial offers were published in the *local* Stripe sandbox with `php artisan stripe:publish-commercial-test-offers --actor=<authorized-admin-id> --execute`. The current terms in the seeder are 30/105/240/540 credits for 1/3/6/12 months, and 10/30/100 one-time credits, with the listed prices unchanged. These values differ from the earlier QA allowances of 15/50/110/240 and must be reviewed before any production rollout. Historical purchase snapshots remain unchanged.

## JSON cleanup

- **Removed:** `storage/app/private/maik-qa-scenarios.json` (stale copied wallet data). Read live sandbox QA balances with `php artisan maik:qa-billing-report`. QA smoke checks use this command.
- **Kept:** `storage/app/private/maik-qa-credentials.json`. This is a private existing-account login credential manifest, not pricing data; removing it would break idempotent QA login setup. Never commit or expose it.
- **Kept:** localized `common.json` files (UI translations), product-import JSON datasets, and unrelated diagnostics. They do not control billing offers or Checkout names.

## Checkout branding and deployment

The connected Stripe **sandbox** account has Maik Cat orange `#C55000`, dark orange `#A84400`, white background, Inter font and rounded checkout controls. Stripe manages the hosted payment page and payment fields; these are not client-editable HTML/CSS. The in-app checkout header uses the Maik Cat logo, and the displayed order summary comes from Laravel. The Android QA checkout is rendered as a full-screen overlay in the existing activity window, not a separate native Modal window, to avoid WebView focus-timeout ANRs observed on the emulator. The Chrome Custom Tab secure-browser fallback is retained.

**This repository work and local DB updates are not a deployment to `maik-cat.mustafafares.com`.** The Android QA build may still show older offer names while that hosted API runs the previous code/database. Deploy the compatible backend + publish matching offers in the confirmed hosted environment separately; preserve purchased rights and verify real store policies before production native checkout.
