# Maik Cat: Stripe billing rollout gates

This integration is **disabled by default**. Existing free users receive no
automatic migration credits. Credits may be issued only by an explicit
administrator action, a successfully fulfilled qualifying Stripe invoice, or a
successfully paid one-time top-up.

## Required environment

- `SUBSCRIPTIONS_ENVIRONMENT=sandbox` during QA; use matching
  `STRIPE_BILLING_ENVIRONMENT=sandbox` for Stripe and wallet isolation.
  Both must agree or checkout, webhook fulfillment and reconciliation fail
  closed without credit issuance.
- `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`: secure server-only Stripe
  restricted credentials for the environment.
- `STRIPE_RETURN_URL`: approved HTTPS endpoint, e.g. this application's
  `/billing/return`; no access grants occur on return.
- `STRIPE_PORTAL_RETURN_URL`: verified HTTPS return location.
- `STRIPE_PORTAL_CONFIGURATION`, `STRIPE_RESTRICTED_PORTAL_CONFIGURATION`:
  Stripe Customer Portal configurations with **no unrestricted tier changes**.
- `STRIPE_APPROVED_PURCHASE_CONTEXTS`: explicit comma-separated contexts.
  Empty by default; do not add native contexts without verified store approvals.
- `STRIPE_CHECKOUT_ENABLED=false` until sandbox QA and app/store approvals pass.
- `STRIPE_NATIVE_EXTERNAL_CHECKOUT_APPROVED=false` until the native legal,
  regional, program and reporting adapters are verified and approved.

Never treat a client-provided country or purchase context as proof of regional
eligibility. The native purchase flow must remain disabled until the external
purchase program requirements are actually implemented.

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
5. Activate catalog enforcement independently of purchase enablement.
6. Do not enable live checkout until tax classification, merchant identity,
   portal policy, country/program approvals, app links and legacy paid rights
   are reconciled.

No Stripe success/return page is authoritative. Fulfillment occurs only after
verified provider evidence, and any unknown payment state remains pending
operator reconciliation.
