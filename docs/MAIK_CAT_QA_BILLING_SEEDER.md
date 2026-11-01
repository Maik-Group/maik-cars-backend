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
- `storage/app/private/maik-qa-scenarios.json` — report with each user ID,
  balance, receipt count, explanatory notes, and two item IDs/serials for
  testing valuation. No passwords in this report.

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

## Optional: real Stripe **test-mode** prices

To show purchasable *web* test offers in `/api/v1/billing/plans`, first create
real **test-mode** Stripe products/prices that match the immutable local
terms. The seeder never creates prices remotely; it verifies existing Stripe
Price IDs with the configured `sk_test_...` key. The seven offers are draft by
default and cannot be purchased until Stripe verification succeeds.

| Offer key | Price | Stripe type |
|---|---:|---|
| qa-wallet-1m | USD 9.99; 15 credits | Monthly recurring, interval 1 |
| qa-wallet-3m | USD 24.99; 50 credits | Monthly recurring, interval 3 |
| qa-wallet-6m | USD 44.99; 110 credits | Monthly recurring, interval 6 |
| qa-wallet-12m | USD 79.99; 240 credits | Monthly recurring, interval 12 |
| qa-topup-10 | USD 3.99; 10 credits | One-time |
| qa-topup-30 | USD 9.99; 30 credits | One-time |
| qa-topup-100 | USD 24.99; 100 credits | One-time |

The amounts above are **QA fixture values, not commercial prices**. Create a
private `storage/app/private/maik-qa-stripe-prices.json` with any subset of
those offer keys, for example:

```json
{
  "qa-wallet-1m": {
    "stripe_product_id": "prod_REPLACE_WITH_TEST_PRODUCT_ID",
    "stripe_price_id": "price_REPLACE_WITH_TEST_PRICE_ID"
  },
  "qa-topup-10": {
    "stripe_product_id": "prod_REPLACE_WITH_OTHER_TEST_PRODUCT_ID",
    "stripe_price_id": "price_REPLACE_WITH_OTHER_TEST_PRICE_ID"
  }
}
```

With `STRIPE_SECRET_KEY=sk_test_...` in Laravel's **effective configuration**,
rerun the seeder. Each mapping must match Stripe's active status, product,
currency, amount, interval, and kind, or it is rejected. The normal audited
offer-publishing service is used; a published offer cannot be edited or
republished with another ID. **Never** use a live key for this manifest.

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
