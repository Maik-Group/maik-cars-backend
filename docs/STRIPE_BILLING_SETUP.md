# Maik Cat: Stripe billing rollout gates

This integration is **disabled by default**. Existing free users receive no
automatic migration credits. Credits may be issued only by an explicit
administrator action, a successfully fulfilled qualifying Stripe invoice, or a
successfully paid one-time top-up.

## Configuration

Subscription enforcement and purchase logic have the same behavior in Stripe test and live accounts. **The Stripe secret key selects the mode automatically**: `sk_test_...` uses `sandbox`, and `sk_live_...` uses `production`. The payment mode must also match the verified webhook event `livemode` and the database price identities. Never mix the two environments for a wallet grant or transaction.

- `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` are private backend credentials for the same Stripe mode. Use separate webhook signing secrets for test and live.
- `STRIPE_RETURN_URL` and `STRIPE_PORTAL_RETURN_URL` are verified HTTPS return destinations. Returning from checkout never grants credit; only verified provider evidence does.
- `STRIPE_PORTAL_CONFIGURATION` and `STRIPE_RESTRICTED_PORTAL_CONFIGURATION` must enforce safe subscription changes.
- Publish and verify matching Stripe prices in the admin catalog. Unverified or draft prices cannot be purchased.
- Native external payments must meet Apple/Google policy and applicable country/program requirements before the app offers them. Keys do not provide policy approval.
## Operations

1. Run additive migrations against a verified staging database first. Do **not**
   run `migrate:fresh` on live or shared data.
2. Configure immutable `billing_plan_prices` with verified Stripe product
   and price identities; publish only matching price/quantity/term/currency
   snapshots. An unpublished/unverified price cannot create a checkout.
3. Register raw-body webhook `POST /api/v1/billing/stripe/webhook` in the
   Stripe account with API version `2026-10-28.endive`; events must be sent
   to this HTTPS path. Enable workers and scheduled
   `stripe:recover-events` and `stripe:reconcile-orders`, plus daily
   `stripe:audit-wallet` ledger identity checks. Reconciliation
   never retries an ambiguous new charge: unresolved remote identities
   require manual investigation.
4. Verify duplicates, out-of-order webhooks, initial invoices, 1/3/6/12 month
   renewals, top-ups, refunds and dispute cases using separate Stripe sandbox.
5. Enforce subscription and credit access consistently; admins explicitly issue complimentary credits if needed.
6. Do not publish native live checkout until tax classification, merchant identity,
   portal policy, country/program approvals, app links and legacy paid rights
   are reconciled.

No Stripe success/return page is authoritative. Fulfillment occurs only after
verified provider evidence, and any unknown payment state remains pending
operator reconciliation.
