# Maik Cat billing QA seed data

Run `MaikCatQaBillingSeeder` **explicitly** on the intended QA database. It
does not run from `DatabaseSeeder`, deletes no users, and never resets a DB.
The seeder creates dedicated `qa-maik-*@example.test` accounts and records
wallet activity in **sandbox** only, even if the application's Stripe key
currently selects production. This isolation is deliberate; **do not** rely on
it to make a production/shared database an appropriate payment test system.

```bash
php artisan migrate:status
php artisan db:seed --class=MaikCatQaBillingSeeder --force
```

Keep the following private files **outside the web root, source control,
screenshots and public chats**:

- `storage/app/private/maik-qa-credentials.json` — generated passwords and
  scenario emails. Never regenerated for existing QA users; new scenario
  credentials are appended on a later run.

QA balances are now read from MySQL when needed via `php artisan maik:qa-billing-report`.
The old `maik-qa-scenarios.json` report is no longer created or used: it could
become stale and was a duplicate of database records. The private credential
manifest above is *not* an offer catalog; it is retained solely so QA users can
log in without changing their existing password hashes.

## Accounts and expected wallet behavior

| Scenario | Wallet / verification goal |
|---|---|
| empty | Zero; subscription prompt, valuation denied (402) |
| free | 7 free credits |
| paid | 15 simulated paid-cycle credits (no Stripe subscription) |
| topup | 10 non-expiring top-up credits |
| mixed | 5 paid + 4 free + 8 top-up credits |
| complimentary | 6 per-month complimentary credits while allocation active |
| once | 4 complimentary credits in a single allocation |
| expired | Expired free and revoked top-up; zero spendable |
| held | 5 paid credits held, 2 free credits available |
| bulk | 3 credits issued via completed admin batch; a 99-credit preview is cancelled |
| admin | QA admin; temporary seeder-specific permissions revoked afterwards |
| renewed | Previous expired cycle plus 12 current paid-cycle credits |
| renewal_due | 2 paid-cycle credits expiring ~48 hours after first seed (expiry frozen on rerun) |
| expired_only | Expired paid-cycle bucket; zero spendable |
| topup_only | 20 non-expiring top-up credits |
| low_balance | Exactly 1 remaining credit |
| partially_used | 5 issued, 2 consumed on distinct serial families, 3 remaining |
| exhausted | 2 issued and consumed, zero remaining |
| repeat_reveal | 3 issued, 1 consumed; repeating that serial on the same UTC day does not charge again |
| paid_priority | Paid 1 consumed first; free 2 and top-up 3 remain |
| held_only | 4 paid credits held, zero available |
| revoked_only | 4 free credits revoked, zero available |
| scheduled_free | 5 complimentary credits scheduled to begin next month; initially zero |
| revoked_free | Complimentary allocation revoked before first issuance |
| expired_free | Complimentary allocation ended before first issuance |

The seeded paid-cycle and top-up buckets are *wallet fixtures*, **not
payments**. No Stripe customer, Checkout Session, invoice, subscription,
refund, dispute, webhook, or fulfilled order is forged. On a catalog with at
least two different, calculable serial families, the seeder reuses real items;
if there are fewer than two, it creates two unmistakably labeled `QA WALLET
FIXTURE` items so valuation-receipt testing works on an empty dev database.
Those fallback items are visible in an otherwise empty catalog.

The monthly source keys, grant expiry, admin batches, allocation identities,
and spending receipts are idempotent. Repeating the command on another day of
the same UTC month does not spend credits again. Credit amounts can naturally
change when grants expire, the complimentary scheduler advances, or a tester
manually spends credits. Rerunning does not reset balances.

## Automatic Stripe **test-mode** offers (no JSON manifest)

The seeder contains the exact 7 QA offer definitions. If the **effective**
Laravel configuration has `STRIPE_SECRET_KEY=sk_test_...`, running
`MaikCatQaBillingSeeder` creates matching Product and Price objects in your
Stripe **test** account, saves their returned IDs to `billing_plan_prices`,
verifies them through `StripeOfferCatalogService::verifyAndPublish`, and makes
them available from `GET /api/v1/billing/plans`. No extra JSON mapping file is
needed. If no test key is configured, the 7 offers remain local drafts; no
network calls occur. **Live keys never create test offers.**

| Offer key | Price | Stripe type |
|---|---:|---|
| qa-wallet-1m | USD 9.99; 15 credits | Monthly recurring, interval 1 |
| qa-wallet-3m | USD 24.99; 50 credits | Monthly recurring, interval 3 |
| qa-wallet-6m | USD 44.99; 110 credits | Monthly recurring, interval 6 |
| qa-wallet-12m | USD 79.99; 240 credits | Monthly recurring, interval 12 |
| qa-topup-10 | USD 3.99; 10 credits | One-time |
| qa-topup-30 | USD 9.99; 30 credits | One-time |
| qa-topup-100 | USD 24.99; 100 credits | One-time |

The amounts above are **QA fixture values, not commercial prices**. Once the
updated code is deployed on your dedicated QA server:

```bash
php artisan config:clear
php artisan db:seed --class=MaikCatQaBillingSeeder --force
```

Run the seeder **only on the intended QA database**, after reviewing the
effective Stripe test configuration; never run it on live customer data
accidentally. It does not create a Stripe charge, payment, customer
subscription, invoice or any fake paid entitlement. Each price uses a
deterministic Stripe `lookup_key` and each create call an idempotency key;
re-running reuses already-published database records. If Stripe succeeded
before the local record was saved, the stable lookup key recovers its Price.
Partially saved Product IDs are also reused. An existing Stripe Price is
reverified against immutable local currency, amount, kind, interval and
Product ID before it can be published.

Expected catalog: **4 published subscription terms and 3 one-time top-ups**.
If the API still returns empty lists, check the effective Stripe key,
exceptions in the seeding output, offer publication status and the active
billing environment. Never substitute made-up `price_` values.

Stripe must also have a correctly configured HTTPS return URL, a matching
test webhook signing secret, and webhook delivery/reconciliation workers to
verify a genuine paid Checkout end-to-end. Receipt and webhook handling are
outside this seeder's remit.

### Important Android restriction

`externalCheckoutAllowed` remains `false` for native Android/iOS, and the
backend only accepts `purchaseContextId=web` for hosted Checkout. Creating
seed data does **not** authorize Stripe external checkout inside store apps.
Use the QA accounts on the Android emulator to verify sign-in, balances,
zero-credit paywall, and valuation/receipt behavior. Test actual Stripe
payments through the authorized web checkout and verified test webhooks until
native billing is explicitly approved and implemented.
