# Maik Cat — search-charged credits / automatic product valuations

**Date:** 2026-11-03  
**State:** local implementation and automated QA; **not deployed**. No production DB migration, live Stripe publication or payment was performed.

## Final customer instruction (supersedes Gold/Platinum unlimited-search proposal)

- Users purchase points / point packages rather than relying on unlimited catalogue access.
- **One explicit search with at least one actual visible result consumes one credit.**
- Typing, autocomplete, choosing filters, empty results, opening product details, viewing its price/metal analysis, paging and refreshing a paid result do not consume additional credits.
- The product details screen must display the price and metal analysis automatically when the item is part of a valid paid-search session. The old **View price and analysis** button is removed.
- A successful search that uses the last credit keeps its paid results accessible for the server-confirmed session lifetime, even though wallet remaining becomes zero.
- Retried network requests must reuse the same idempotency key to avoid accidental double charging; a different deliberate search request may cost another point.
- The existing purchase/channel restrictions for Apple/Google compliance remain unchanged.

## Server implementation

Add migration `database/migrations/2026_11_03_000001_create_paid_search_sessions.php` (new `paid_search_sessions` table). Each session records user, environment, normalized intent string, idempotency-key hash, associated wallet receipt, charged flag, and expiry (currently **24 hours** after the search).

| Request | Purpose / cost |
| --- | --- |
| `POST /api/v2/items/search` body `{text,perPage,page}` + `Idempotency-Key` | Only charge one point if visible results and valuation data exist; return `searchId`, `searchExpiresAt`, `chargedCredits`, items, pagination and wallet balance |
| `GET /api/v2/items/search/{searchId}?page=N` | Replay/paginate an owned, unexpired search; zero cost |
| `GET /api/v2/items/{id}/valuation?searchId=...` | Return protected price and assay ONLY for an owned, funded, valid search matching that item; zero cost |
| `GET /api/v1/wallet` | Include `data.activeSearchUntil`, the latest valid paid-search expiry even if `balance=0` |
| Old `POST /api/v1|v2/items/{id}/valuation` | Now requires an existing paid `searchId` and does not debit on product opening |

The public `/api/items`, `/api/items/{id}`, favorites and home catalogue keep redacting protected prices and metal fields. User scope, environment, credit-grant revocation/hold, expiration, and active-account status are checked server-side.

## Mobile implementation

- Search screen POSTs only when the user submits/selects a search, not on render, blur, filter, component refetch, or pagination. Debounced suggestion GET stays free.
- The valid `searchId` and expiry are persisted with the account ID, restored using a non-charging GET. Unverified or expired sessions cannot unlock prices.
- The latest unconfirmed request's **idempotency key** is also persisted per account and search text so a failed network response can be retried without a second debit.
- React Native updates the wallet header from the server after successful paid POST.
- `SubscriptionAccessGate` honors a backend-confirmed active search until expiry to preserve results when the last point was consumed.
- Product details display valuation automatically when reached with the paid search ID; no manual price-reveal button. Product entries not linked to an eligible paid search display a prompt to search first rather than pretending a price can be revealed for free.
- A short-lived valuation-display response is refreshed using a **GET**, never an additional wallet debit. Invalid/revoked sessions are rejected.

## Deployment prerequisites and QA

1. Review DB connection and verify an isolated staging or QA target. Back up the target and apply the additive migration there, **not to an unidentified or live database**.
2. Deploy backend API and migration together before distributing the new mobile build. Old mobile manual reveal calls no longer charge and require a paid-search ID.
3. Run `php artisan test tests/Feature/Credits tests/Feature/Subscriptions` and mobile `npx tsc --noEmit` + `npm test -- --silent`.
4. Confirm on emulator against the **updated backend**, using an isolated QA account with 2 points and another with 1 point: paid hit; empty query; autocomplete; filtered no-result; repeated request key; product price; related product not in paid search; back-to-results; pagination; retry after connection loss; last point; app restart/reopen; refunded/revoked grant; expiration.
5. Verify the current App Store / Play storefront policy path before enabling external Stripe purchasing in production builds. This change itself does not enable forbidden checkout channels.
6. No deployment, DB seeding, destructive reset, real Stripe payment, repo commit/push or claim of successful device QA until verified.

**Caution:** The 24-hour paid-search lifetime is an implementation default and can be changed later by product decision. Repeated **new explicit searches** consume new points even for the same text; repeated GETs/identified retries do not.

## Local validation results (November 3, 2026)

- Backend `php artisan test tests/Feature/Credits tests/Feature/Subscriptions --stop-on-failure`: **73 passed, 470 assertions**.
- React Native `npx tsc --noEmit`: **passed**; `npm test -- --silent`: **14 suites, 55 tests passed**.
- `npx expo export --platform android --output-dir .expo/paid-search-bundle-check`: **passed**, including the Android JavaScript bundle.
- The emulator `emulator-5554` can launch the installed `maik.cat.android` application, but it is an **older non-debuggable Release APK** and still shows the old price-reveal button. It does **not** validate the updated UI.
- A fresh `npx expo run:android --no-install` attempted an Android build but blocked during installation of required NDK `27.1.12297006`; only `28.2.13676358` was installed beforehand. The SDK-created `27.1...` directory remained incomplete (missing `source.properties` and `clang.exe`). Build was cancelled rather than leaving a hanging installer.
- No staging/production database migration, checkout, public deployment or remote push was performed. A new Android build and QA with an updated backend are release blockers.
