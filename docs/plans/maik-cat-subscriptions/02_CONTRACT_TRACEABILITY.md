> Approved design from 21 October 2026. Local implementation and validation are tracked in the [execution register](08_PHASED_EXECUTION_PLAN.md). The user authorized local implementation on 22 October while keeping native/store acceptance and production activation gated.

## 2. Contract traceability and scope change

| PDF requirement | Implementation component | Acceptance evidence | Delta or clarification |
|---|---|---|---|
| p.1: project, dated offer, USD 300, one week | Commercial change record | Written revised scope and estimate | Original figures do not include native billing. |
| p.2 §§1–2: configurable plans, four durations, prices and limits | Plan catalog and store mappings | Administrator configures all four periods; app displays available products | Stores own checkout prices and product availability. |
| p.3 §3: count access to restricted content | Explicit valuation-reveal API | Browsing consumes zero; first family reveal consumes one | Unique-family/day rule confirmed in this conversation. |
| p.3 §4: mobile, dashboard, backend, operation records | All three implementation workstreams | End-to-end purchase, reveal, grant, and revoke evidence | Replace mobile Stripe with native billing. |
| p.4 §5.1: plan CRUD, visibility, sorting, settings | Filament plan resource | Authorized CRUD and mapping validation tests | Store duration/product changes require console operations. |
| p.4 §5.2: selection, current subscription, dates, quota, verified activation | Paywall, status screen, purchase verification | No access from an unverified or pending purchase | Add renewal, restore, and store-management behavior. |
| pp.4–5 §5.3: list, cancel, grant, custom dates, source, audit | Entitlement ledger and audited admin actions | Grant without payment; immediate local revocation; complete history | Local revocation does not cancel or refund store billing. |
| p.5 §5.4: independent daily usage, reset, no overspend, free search | Daily buckets and reveal claims | Concurrent final-slot test; UTC rollover; zero search consumption | UTC retained; shared count across overlapping access. |
| p.6 §6: active, exhausted, expired, revoked, granted, verified-paid | Entitlement resolver | State-transition and HTTP tests | Add provider pending, grace, hold, pause, refund, and renewal states. |
| p.6 §7: persistence, APIs, screens, critical tests, deployment | Schema, API, dashboard, mobile, QA | Phase completion evidence | Native integration and lifecycle operations expand delivery effort. |
| p.7 §8: acceptance criteria | QA matrix below | All original criteria mapped to passing tests | Store billing replaces Stripe-specific mobile acceptance. |
| p.7 §9: native billing and other exclusions | Change-control record | Explicit approval before implementation | Native billing is expressly excluded from the original price. |
| p.8 §§10–11: schedule and payment milestones | Revised commercial agreement | Approved effort, cost, milestones, and prerequisites | Do not reuse the original one-week estimate. |
| p.9 §§12–14: support, changes, approval | Handover and support agreement | Named operational owner and approved support terms | Renewal infrastructure and provider changes need ongoing maintenance. |

The added scope includes store configuration, native builds, server verification, lifecycle notifications, reconciliation, purchase restoration, subscription disclosures, sandbox QA, coordinated app/API release, and monitoring.

Formal tax invoices, accounting integration, a general redesign, web checkout, promotional offers, and new registration flows remain excluded.
