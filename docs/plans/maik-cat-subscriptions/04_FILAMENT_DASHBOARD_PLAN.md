> Approved design from 21 October 2026. Local implementation and validation are tracked in the [execution register](08_PHASED_EXECUTION_PLAN.md). The user authorized local implementation on 22 October while keeping native/store acceptance and production activation gated.

### Filament dashboard plan

| Screen | Forms, tables, and actions | Authorization |
|---|---|---|
| Subscription plans | Name/key, tier, duration, service level, daily allowance, descriptions/features, visibility, sorting, active state, revision and prospective-change preview. | `view_subscriptions`, `manage_subscription_plans` |
| Store mappings | Platform/product/base-plan mapping, expected metadata, validation status, last verification and mismatch reason. Store prices remain clearly identified as store-controlled. | `manage_subscription_products` |
| Subscription ledger | User, source, tier, billing/access state, dates, renewal state, redacted reference, environment; filters for pending, failed, active, cancelled, expired, revoked, refunded and granted. | `view_subscriptions` |
| App-user subscription panel | Effective entitlement, quota/reset, contributing sources, history, custom-date grant, local revoke and restore-local-access actions. Reasons required. | Separate `grant_subscriptions` and `revoke_subscriptions` |
| Agreement detail | Verified transactions, lifecycle history, local suppression, management guidance and audited reconciliation. No editable paid-history fields. | View plus `reconcile_subscriptions` |
| Diagnostics | Inbox failures, sync lag, acknowledgement backlog, unavailable mappings, duplicate events and account conflicts. | `view_subscription_diagnostics` |

Default permissions: super-admin receives all; admin receives view/grant/revoke/reconcile/diagnostics; content manager and app user receive none. Catalog/product publishing permissions remain super-admin-only by default.

Apply checks to pages, actions, policies, and services. Hiding a navigation item is insufficient. Paid records cannot be manually marked “verified” or deleted to repair state.

The revoke confirmation must state that **local access stops while store billing may continue**. Cancellation/refund remains a separate store-console/support operation. Send access-change notifications through existing notification infrastructure; push delivery supplements server enforcement.
