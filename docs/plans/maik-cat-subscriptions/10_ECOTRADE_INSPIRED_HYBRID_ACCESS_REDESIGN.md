# Maik Cat — EcoTrade-inspired subscription and search-access redesign

## Superseding product decision (2026-11-03): paid searches, not price reveals

**This replaces the Gold/Platinum unlimited proposal and per-valuation charges below.** The product owner chose **points as purchasable products** and **one point deducted only when an explicitly submitted search returns valid items**. Typing/autocomplete, empty results, paging and opening product details incur no further debit. The price and metal analysis display automatically in product details reached from a paid search. Requests use server-side idempotency to avoid double charges.

The current implementation uses a user- and result-scoped paid search session valid for 24 hours; it is revoked if its funding credit grant is revoked or held. Implemented locally (not deployed): POST /api/v2/items/search, GET /api/v2/items/search/{searchId}, GET /api/v2/items/{item}/valuation?searchId=..., an additive paid_search_sessions table, and credit-ledger charging. Public list and detail APIs remain redacted. Old valuation POST APIs now require a valid search session instead of deducting credits on item opens. **Older installed mobile builds will need an update** before deploying the backend route change.

The remaining text is historical proposal context, not approval to turn on unlimited tiers, daily free searches, or per-product reveal charges.

**Date:** 2026-11-03

**Status:** SUPERSEDED — historical proposal only. The owner subsequently selected point-based purchasing, one point per successful explicit search, and automatic product prices. See `11_PAID_SEARCH_POINTS_IMPLEMENTATION.md` for the current implementation and behavior. The Gold/Platinum unlimited-search model below must not be treated as approved.

**Repositories inspected:** `maik-cat-backend` (Laravel / Filament / MySQL) and `../maik-cat-mobile` (React Native / Expo).
**Goal:** Replace the confusing fixed-credit "subscription" experience with distinct **one-time search passes** and **genuine, automatically renewing unlimited subscriptions**, while retaining all existing valid purchased rights, Stripe web billing, admin grants and audited wallet records.

## 1. Decisions and scope

### Recommended commercial model (proposal, not live prices)

| Product | Purchase type | Catalog access | Expiration / renewal | Notes |
| --- | --- | --- | --- | --- |
| No purchase | None | **No paid valuation access** | N/A | Retains Maik owner's explicit **no free plan, no grace period** decision. Existing blocking sign-in/paywall can remain. |
| **Search Pass 20** | One-time Stripe payment | 20 successful paid valuation reveals | Credits expire **365 days after payment** | EcoTrade-inspired entry product; number and expiry are proposed defaults, not a currently published Maik offer. |
| Search Pass 50/100 (optional) | One-time payment | 50/100 paid reveals | Configurable, default 365 days | Optional alternatives; do not hardcode unless commercially approved. |
| **Gold** | Auto-renewing Stripe subscription, monthly/yearly | **Unlimited standard catalog price reveals** | While a verified, paid subscription period is active | Basic professional tier; no wallet debit for eligible reveals. |
| **Platinum** | Auto-renewing Stripe subscription, monthly/yearly | **Unlimited standard reveals** + clearly defined advanced features | While a verified, paid subscription period is active | Differentiate using existing, actually implemented features. Do not advertise unimplemented calculators, market analytics or exports. |
| **Admin complimentary access** | Admin-only, no payment | Configurable unlimited access or metered wallet credits | Explicit grant start/end dates | For one or all users, audited; never impersonates a paid Stripe subscription. |

**User decisions preserved:** no automatically granted free tier; no migration grace period for unpaid users; Stripe web checkout is preferred over Apple/Google billing; access and credits may be granted by admins. The EcoTrade **1 free search/day** tier is an **optional future feature, disabled by default**. Do not silently create it or give all existing unpaid accounts free search access.

**Commercial decisions requiring owner approval before implementing or publishing:** final tier names; Gold vs Platinum feature matrix; monthly vs yearly pricing; whether quarterly/semiannual terms remain; search-pass quantities/expiration; whether nonexpiring current top-ups continue to sell; whether a paid pass can be bought alongside a subscription; and whether previously revealed serials are chargeable again after the display lease. No EcoTrade price is automatically adopted for Maik.

### EcoTrade model versus the proposed Maik model

EcoTrade publicly offers a free 1-search/day tier, Pass 20 (20 credits), and Gold/Platinum unlimited subscriptions. Its terms define one credit per search and 365-day pass validity. Its app purchase paths include Apple/Google store billing. Maik intentionally differs in **no free access** and **Stripe web-first**, subject to store policy review.

Sources: https://www.ecotradegroup.com/en/subscriptions ; https://www.ecotradegroup.com/en/FAQ/how-to-purchase-a-subscription-plan ; https://www.ecotradegroup.com/ar/%D8%B4%D8%B1%D9%88%D8%B7-%D8%A7%D9%84%D8%A7%D8%B3%D8%AA%D8%AE%D8%AF%D8%A7%D9%85 .

## 2. Current implementation audit — verified in local code

1. `database/seeders/MaikCatCommercialOffersSeeder.php` creates four nominal subscription terms of **30/105/240/540 credits** plus **10/30/100-credit top-ups**, as unpublished sandbox drafts. These are **proposed**, not confirmed production offers.
2. `StripeOfferCatalogService.php` currently requires `credits >= 1` for **all** offers; `billing_plan_prices.credits` is non-null. It cannot represent an unlimited subscription without a schema/API change.
3. `StripeCheckoutService::begin()` labels new subscription orders `billingMode=prepaid_term` and blocks duplicate/pending terms. `StripeBillingGateway::checkoutSessionParameters()` then uses Stripe `mode=payment` and inline one-time price data for prepaid terms, even where seeded Stripe Price records are recurring. This is **not auto-renewing subscription checkout**. Legacy recurring support and portal paths also exist. These paths must be reconciled deliberately, not toggled blindly.
4. `CreditWalletService::spend()` charges **one credit for every fresh valuation attempt**, with request-key idempotency, and grants/spends in an audited, environment-scoped ledger. A paid, unlimited subscriber with zero credits would currently fail this path.
5. `POST /api/v2/items/{item}/valuation` uses `CreditValuationController` (wallet); `POST /api/v1/items/{item}/valuation` uses `ItemValuationController` / `DailyUsageService` / `EntitlementResolver` (legacy daily entitlement). Unifying authorization across both, or safely retiring v1, is a critical P0 requirement.
6. `ItemResource` globally redacts valuation/metal fields; protected fields must remain server-gated after unlimited access is introduced. Public list/search/filter endpoints must **not** leak protected valuations.
7. Mobile `SubscriptionAccessGate.tsx` blocks other authenticated screens until `hasSubscriptionAccess` succeeds; this function currently checks wallet balance, paid state or complimentary grants. It requires a new explicit backend `accessMode` because an unlimited user may have zero wallet credits.
8. Mobile subscription screen and types assume each recurring plan has a numeric `credits` quantity and 1/3/6/12-month terms. Provider status currently joins `/billing/subscription` and `/wallet` client-side.
9. `StripeCheckoutService::catalog()` returns `externalCheckoutAllowed=false`; mobile `web-checkout-policy.ts` currently permits hosted Checkout only in internal QA builds (`EXPO_PUBLIC_APP_ENV=qa`, `EXPO_PUBLIC_QA_WEB_CHECKOUT=true`, sandbox). **Do not bypass this safety gate in production.**
10. Old Apple/Google routes and classes are still present in `routes/api.php`, `StorePurchaseController`, store purchase services, `SubscriptionController` and related jobs. The owner's decision to decommission them requires a compatibility/data migration and actual removal plan.
11. The working tree of `maik-cat-mobile` has substantial **existing uncommitted changes**, including checkout modal and access gate changes. Preserve those changes; do not overwrite them as part of planning.

## 3. Domain model — distinguish membership, credits and rights

### A. Unified access resolver

Create `CatalogAccessResolver` (or refactor the current resolver) as the **single backend authority** for the current user, environment and time:

```text
if account is inactive -> DENIED
else if active + verified paid Gold/Platinum cycle, not suspended/revoked -> UNLIMITED
else if an active, explicitly granted admin unlimited entitlement -> UNLIMITED
else if spendable pass/top-up/complimentary credits > 0 -> METERED
else -> NONE (paywall)
```

- Prefer **unlimited over metered**. A user with Gold plus 12 pass credits spends **0 pass credits** during Gold's paid term; after Gold ends, those still-valid pass credits remain spendable.
- No credit consumption or Stripe grant during app login, filter entry, autocomplete, category search, empty results, item listing or unsuccessful pricing fetch.
- For metered access, charge **one credit for one successfully delivered protected valuation**; use a stable idempotency key to make retries free. Existing wallet semantics charge each *new* request; retaining that behavior is the safest compatibility default. Optionally add a configurable short replay lease for the same revealed item/serial family (not an automatic unlimited daily-repeat rule). Make the user-facing rule explicit.
- For unlimited access, still record a non-wallet **access event** for anti-abuse/analytics, but no debit. Add server-side fair-use rate limits, anomaly detection and account/device protections; "unlimited" is not permission for automated scraping.
- A pending checkout or a successful browser redirect is **not** an access entitlement. Server-verified Stripe payment or authorized admin grant is required.
- Prefer atomic provider-confirmed entitlement + wallet state. `403 ACCESS_REQUIRED`, `402 INSUFFICIENT_CREDITS`, `409 RECEIPT_INVALID`, `429 RATE_LIMITED` should be deterministic and documented.

### B. Proposed additive database changes

- Add immutable fields to `billing_plan_prices`: `access_mode` (`metered`/`unlimited`), `feature_set_key`/version, `billing_mode` (`one_time`/`recurring`), and explicit `validity_days` for passes. Permit **nullable `credits` only for unlimited offers**; enforce `credits > 0` for metered purchases and validate the full tuple on publish.
- Distinguish product `kind`: `subscription` (recurring), `pass` (one-time prepaid credit), and `topup` (one-time add-on). Migrate API shapes and validators additively. Existing rows and snapshots continue to parse using their old definitions.
- Store entitlement rights in an auditable, environment-scoped model (for example `catalog_access_entitlements` with user, source, provider identity, tier, feature set, starts/ends, revoke/hold). Avoid inferring unlimited access solely from a Stripe Customer ID or client display state.
- Reuse `billing_subscriptions` and verified `billing_invoices` for real recurring periods; **do not** fabricate `credit_grants` or `billing_invoice_grants` for unlimited periods. For Gold/Platinum, each successfully paid invoice updates/extends an unlimited entitlement idempotently by environment + provider invoice/cycle identity.
- Keep legacy `credit_grants`, `credit_consumptions`, and `credit_ledger_entries` unchanged for pass/top-up/legacy buckets; retain source, expiry, hold, refund, revocation, period and reconciliation evidence. Add source names where needed, not a destructive conversion.
- Keep versioned `offer_snapshot`, verified Stripe Price/Product identities, `terms_version`, and immutable purchased terms. Any new pricing or logic uses **new offer revisions**, never edits a published commercial offer in place.
- Add optional `catalog_access_events` for successful unlimited reveals/abuse audit; do not store sensitive unnecessary customer data. Make indexes and uniqueness choices explicit before migration.

### C. Billing state machine

```text
Visitor signs in
    -> GET /api/v1/billing/access-summary
    -> UNLIMITED | METERED | NONE | PENDING | UNAVAILABLE

Pass purchase: hosted Stripe payment -> verified paid Checkout ->
  append 20-credit pass grant (expires at paid_at + 365 days)
  -> consume 1 only on metered successful valuation

Gold/Platinum purchase: Stripe Checkout mode=subscription (recurring Price) ->
  signed/verified invoice.paid + subscription status -> activate unlimited rights
  -> each verified paid renewal extends the next period -> never debit wallet

Cancel: stop future renewals -> retain unlimited rights until paid period end
  -> then fall back to remaining valid pass/top-up/admin credits or paywall

Failed renewal / unpaid / disputed / refunded: apply explicitly documented
  provider-verified access policy; do not grant a fresh paid period by redirect.
```

Use `past_due`, incomplete, cancelled and payment-failed states safely. Specify whether an already paid and nonrefunded period survives a failed future renewal; recommended: retain paid-through access, **no new unpaid period**, and show a payment warning. Refund/dispute reviews should hold/revoke only affected rights with traceable audit. Plan upgrades/downgrades must use Stripe price schedules/portal with explicit effective dates and prevent parallel subscriptions.

## 4. Stripe / web billing implementation

1. Revise `StripeOfferCatalogService`, `BillingPlanPrice`, `MaikCatCommercialOffersSeeder`, and guarded `PublishCommercialTestOffers` command to describe **distinct recurring unlimited** Gold/Platinum and one-time Pass offers. Keep existing drafts as legacy revisions; do not silently rewrite them or publish remote products.
2. In `StripeBillingGateway::checkoutSessionParameters`, use `mode=subscription` with a **verified recurring Stripe Price ID** for new Gold/Platinum orders; use `mode=payment` with a verified one-time offer for passes/top-ups. Never display `"renews"` on a prepaid one-time payment.
3. Update `StripeCheckoutService` reservation, expiry, abandonment, duplicate order, active subscription, switching and idempotency logic for the two purchase modes. Preserve the recent abandoned-session fixes: closing an unpaid checkout can safely expire/recreate it after provider confirmation, never erase an ambiguous or paid session.
4. Extend `StripeEventProcessor`, `StripeFulfillmentService`, `StripeOrderReconciliationService` and reconciliation jobs to activate unlimited access **only** from verified Stripe invoice/payment and period evidence. Retain signature, livemode/environment, product/price, amount, customer, user, interval and request identity checks. Add separate pass-grant fulfillment path.
5. Update cancellation and Customer Portal behavior so Gold/Platinum auto-renew unless cancelled; cancelled users keep paid-through unlimited service. Add a clear next renewal date and invoice history in account UX.
6. Scope all reads, admin grants, entitlements and charge/refund cases to active `sandbox` or `production`. No `migrate:fresh`, live grant, test transaction on production, real charge or default automatic sandbox publication.
7. Preserve secure web-to-app handoff/deep link and embedded QA checkout experience only where technically and store-policy permitted. The app must poll server fulfillment after return and on foreground/cold start.

**Store distribution blocker:** The owner requested permanent Stripe web billing and removal of Apple/Google in-app purchase code. This is an **architecture preference, not blanket App Store / Google Play approval**. Keep production native external-buy CTAs disabled until actual distribution channel/storefront eligibility and any required entitlements/programs are verified. Android Play can run as consumption-only with no in-app purchase/link; direct APK distribution has different constraints. Verify official policies for each release target before enabling a payment link or WebView. Do not falsely represent EcoTrade's mixed App Store/Play checkout as proof Maik's web-only native checkout is allowed.

Official references: https://developer.apple.com/app-store/review/guidelines/ and https://support.google.com/googleplay/android-developer/answer/10281818 .

## 5. API consolidation and contract

**Primary new authenticated response** (example, not already implemented):

```json
{
  "data": {
    "access": {"mode": "unlimited", "source": "paid", "tier": "gold", "canReveal": true, "featureKeys": ["catalog_valuation"], "validUntil": "<ISO-8601>"},
    "membership": {"state": "active", "planName": "Gold", "autoRenews": true, "nextBillingAt": "<ISO-8601>", "cancelAtPeriodEnd": false},
    "wallet": {"remaining": 12, "passCredits": 12, "topUpCredits": 0, "complimentaryCredits": 0},
    "purchaseChannel": {"webCheckoutAllowed": false, "reason": "STORE_POLICY_REVIEW_REQUIRED"}
  }
}
```

- Implement one strongly typed `GET /api/v1/billing/access-summary`, no-store and authenticated. Its `access.mode` is authoritative, not inferred from `wallet.remaining` in React Native.
- Catalog `GET /api/v1/billing/plans` returns `kind`, `tierKey`, `accessMode`, `billingMode`, `renewalInterval`, `credits` (metered only), `validityDays`, `featureKeys`, price/currency, Stripe publication eligibility, and localized descriptions. Retain backward-compatibility keys until all app builds migrate.
- Normalize `POST /api/v2/items/{item}/valuation` and legacy `POST /api/v1/items/{item}/valuation` through **the same access resolver and reveal authorization**. Either sunset the legacy route safely with measured client adoption or update it to enforce identical pass/unlimited semantics. It must never be a cheaper bypass.
- Keep protected metal/price values redacted from listings, item details, similar items, calculator responses and cached/public APIs unless a valid server entitlement exists. Valuation endpoints emit `chargedCredits: 0|1`, `accessMode` and short-lived view lease. New client attempts may use idempotency keys; handle item mismatch correctly.
- Feature-gate Platinum-only endpoints on the backend as well as in the UI. Do not claim a distinct tier if its features are not actually enforceable.
- Keep provider invoice, order status and user ownership checks; do not accept client-provided prices, tier names, balances or entitlement dates.

## 6. Mobile UX specification

**At sign-in:** fetch `access-summary` after auth hydration. Preserve the existing modal paywall, but base it on `access.mode`:

- `none`: non-dismissible access modal with **Choose Plan**, **Buy Search Pass**, **Refresh**, **Sign Out**; billing screen and privacy/support must be reachable. No protected catalog navigation behind the modal. No automatic free access.
- `metered`: permit catalog; show **Search pass: 14/20 remaining** (or aggregated balance) and pass expiry in account; explain one paid valuation costs one credit.
- `unlimited`: permit catalog even if wallet says **0**; display **Gold / Platinum — Unlimited Searches**, renewal/purchased-through date; **do not** display misleading "0 credits — buy now" prompts.
- `pending`: payment verification state; show refresh/retry with no optimistic access.
- `unavailable` / offline: preserve verified short-lived cached viewing rights only per backend-approved lease; never turn absence of a response into free/unlimited service.
- `expired` / cancelled after paid-through: downgrade automatically to valid pass/admin credit access or return to paywall.

**Screen redesign:** two distinct sections (**Unlimited memberships**, **Search passes**) instead of one credits-per-cycle list; compact current-plan card; account email and membership in a restrained header; optional pass balance and remaining validity; invoice and cancellation actions only for recurring members. Billing copy must say `Renews automatically` only for recurring subscriptions, and `One-time purchase` for passes/top-ups. The app must display server prices and plan names, not hardcoded Stripe catalog values. Keep English, Arabic, German, Bulgarian and Croatian translations and RTL/layout checks.

**Files:** `src/features/subscriptions/{subscription-provider.tsx,subscription-access.ts,SubscriptionAccessGate.tsx,StripeCheckoutModal.tsx,ValuationPanel.tsx,types/subscription.types.ts,services/subscriptions.service.ts,web-checkout-policy.ts}`, `app/subscriptions/index.tsx`, `src/components/layout/{AppHeader,AppShell}.tsx`, relevant home/settings UI, `src/lib/http/api-endpoints.ts`, `src/i18n/locales/*/common.json` and existing unit tests. Preserve the uncommitted mobile tree before implementing.

## 7. Admin / Filament requirements

- Billing products: managed tier key, access mode, plan interval, feature flags, pass count/expiry, verified Stripe identity, revision, publish/retire with reason, audit, and an explicit sandbox/test warning. No editing a published immutable offer.
- User view: separate **current paid membership**, **unlimited entitlement**, **wallet passes**, **admin grants**, **billing history** and **valuation usage**. Clarify that having credits is not the same as having a paid subscription.
- Grant actions: issue N metered credits or time-bound unlimited Gold/Platinum equivalent to one user or a previewed batch/all users, with start/end, environment, reason, revocation and idempotency. Never forge an invoice/subscription as evidence of a grant.
- Operational dashboards: active Gold/Platinum counts, expiring passes, credit liabilities, actual unlimited usage, retry/error rates, refunds/disputes and suspicious traffic.
- Role/permission checks with Spatie + Filament; only authorized operators may issue unlimited access, change publication or revoke grants.

## 8. Migration / backward compatibility policy

1. Snapshot existing `billing_plan_prices`, `billing_orders`, `billing_subscriptions`, `billing_invoices`, wallet grants and entitlements. Measure active users and Stripe provider types in an isolated **read-only** audit before schema changes.
2. Preserve prepaid term purchases through their original expiration and remaining valid credit grants. Preserve nonexpiring top-up credits and explicit admin grants. **Never silently convert a 30-credit purchase into unlimited or revoke bought value.**
3. Preserve any verified legacy Stripe renewable subscriptions until their current billing rights have been mapped with an explicit policy and provider reconciliation.
4. Use additive, reversible migrations and a versioned API; implement new access code behind an admin-controlled rollout. Do not delete historical Stripe or Apple/Google transaction records needed for old rights and audits even when disabling deprecated purchase routes.
5. Run sandbox migrations and provider-integrated tests before publishing **new**, verified Stripe test products. Allow an operator-controlled commercial cutover only after all acceptance tests. Disable sale of older packages by retiring offers, not rewriting payment history.

## 9. Delivery stages and acceptance gates

| Stage | Implementation tasks | Completion gate |
| --- | --- | --- |
| **P0 — freeze contract** | Approve free-tier policy, pass counting, tier features, pricing and migration compatibility; audit both valuation routes and existing Stripe records | Written product decision matrix and no unresolved billed-vs-prepaid ambiguity |
| **P1 — backend domain** | Add access-mode schema/models; unify access and spending; protect all valuation routes; expand admin grants | Zero-credit active Gold can reveal without wallet debit; metered pass cannot reveal after zero; no v1 bypass |
| **P2 — billing** | Genuine recurring Stripe Gold/Platinum; one-time Pass 20; verified provider renewal, cancel, refund and reconciliation; publish gated sandbox offers | `mode=subscription` for memberships; `mode=payment` for passes; exactly-once provider-confirmed rights |
| **P3 — mobile / Filament** | Revised server DTOs, paywall/access gates, plan/pass cards, header and admin management | UI correctly distinguishes unlimited, remaining pass credits, no access, pending and paid-through |
| **P4 — QA & release** | Unit/feature/contract/concurrency tests, Stripe Test webhooks, emulator flows, RTL, channel policy verification, staging dry run | Reviewable evidence, no false charging, no unapproved native checkout, rollback and migration report |

### Minimum test cases

- `NONE` with no pass -> modal; cannot reveal; billing route still reachable.
- Pass 20 -> exactly 20 charged successful reveals; input typing, no results, API failures and exact-request retries cost **0**; 21st new reveal denied; 365-day expiry tested at boundary.
- Gold/Platinum active + zero wallet -> reveal succeeds, **0** credit debit, correct tier-specific features.
- Gold + unused Pass 20 -> unlimited access consumes **0** pass credits; after subscription ends, valid pass credits become usable.
- Gold cancel at period end -> active until actual paid-through time, then fallback; future unpaid renewal never grants rights.
- Stripe duplicate/out-of-order events and simultaneous multi-device reveals -> no duplicate grants, double charges or bypass; environment isolation strict.
- Existing prepaid-term or top-up purchaser retains exactly the same purchased credit rights and expiry; restored verified legacy store purchases are not discarded.
- Refund/dispute -> traceable hold/revocation and appropriate customer support state; never negative or silently erased wallet.
- Server `v1` and `v2` protected valuation endpoints enforce identical rules; public responses never leak protected price/PGM fields.
- Auth token expired / offline / checkout closed / delayed webhook / account switch -> no optimistic unlock or misattributed rights.
- Test Android QA build in emulator and iOS where available, verify all five locales, header, modal, payment browser and renewal messaging.

### Explicit non-goals of this planning deliverable

No production deployment, no Stripe purchases or product publication, no migration execution, no QA seeding on an unidentified database, no modification of existing mobile uncommitted code, and no assumption that native WebView Stripe checkout is policy-approved.

## 10. Further official technical references

- Stripe recurring subscriptions / Checkout: https://docs.stripe.com/billing/subscriptions/build-subscriptions?payment-ui=stripe-hosted
- Stripe billing webhooks: https://docs.stripe.com/billing/subscriptions/webhooks
- Apple App Review policy (external purchase rules and prior-purchase rights): https://developer.apple.com/app-store/review/guidelines/
- Google Play payments / consumption-only apps: https://support.google.com/googleplay/android-developer/answer/10281818

**Recommendation:** Implement the backend unified access resolver first, then migrate the catalog to **Pass 20 (one-time) + Gold/Platinum (true recurring unlimited)**. Retain **no free tier** unless the owner explicitly changes the earlier requirement. Defer publishing and mobile store checkout until the owner approves pricing/feature differentiation and the release channel satisfies its policies.
