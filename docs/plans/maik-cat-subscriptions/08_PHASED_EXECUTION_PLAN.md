# Implementation execution register

Status on 22 October 2026: **LOCAL IMPLEMENTATION AVAILABLE; ACTIVATION AND NATIVE/STORE ACCEPTANCE GATED**.

The user explicitly authorized: **“Continue local implementation; keep activation gated.”** This permits P1–P4 code and isolated QA while the original P0 external prerequisites remain open. It does not approve production deployment, store configuration, a cutover, a transition batch, or a revised commercial agreement.

## Implemented locally

| Area | Implementation and evidence | Remaining acceptance |
|---|---|---|
| P0 configuration | Pinned Expo IAP 6.1.1; internal EAS profiles require an explicit approved staging HTTPS origin; production fallback is rejected for those profiles. Android debug native compilation passed. | Staging URL/infrastructure, production helper/queue/scheduler checks, iOS build and real products/callbacks on both platforms. |
| P1 persistence and access | Additive nine-table migration; encrypted provider references/payloads; stable account binding; snapshotted grants/agreements; entitlement resolver; user-first locking; atomic normalized-family/day usage with UTC reset and UUID fallback. | Production migration/recovery rehearsal; concurrent revocation and midnight stress acceptance. |
| P1 HTTP | Public catalog redaction, explicit valuation POST, existing pricing/discount/averaging, usage/status/history/catalog/manage APIs, stable failures and separate throttles. Revalidation cannot charge a new day or an unrevealed family. | Old-client coexistence and update gate on real artifacts; review free-text descriptions/media for valuation duplicates. |
| P2 verification | Official Apple Node SDK 3.1.0 through bounded JSON-stdin helper; real signature verification; Google server evidence, account/environment binding, deferred item selection, token lineage, order evidence and durable acknowledgement. | Apple/Google sandbox positive proofs, real renewals/refunds/replacements and console metadata. |
| P2 operations | Authenticated uncased webhook ingress, encrypted durable inbox, deduplication counters, retries, redacted failures, forced administrative replay, 15-minute reconciliation and hourly Apple history recovery. Each restore receives fresh verification. | Live HTTPS delivery, queue interruption/recovery, alert routing and provider downtime rehearsal. |
| P3 dashboard | Plans and verified immutable store mappings; audited changes with reasons; calendar/custom UTC grants; local suppression/restoration; entitlement, transaction and audit ledgers; app-user access/history panel; diagnostics. | Native/store-backed operational acceptance and complete dashboard locale review. |
| P4 mobile | Native callbacks/recovery/restore and management; store-returned prices; five locales; explicit price/analysis reveal; ephemeral display leases cleared on logout/background/offline/access changes. Public query payloads are recursively sanitized. | Physical-device lifecycle and process-death/reinstall tests on both platforms; actual storefront prices/terms. |
| P5 release | Ten plan documents, environment examples, isolated concurrency harness, local test coverage and English/Arabic rendered preview. | Release gates below remain open. |

Defaults remain `SUBSCRIPTIONS_ENFORCEMENT_ENABLED=false`, `SUBSCRIPTIONS_PURCHASES_ENABLED=false`, and `EXPO_PUBLIC_SUBSCRIPTIONS_ENABLED=false`. No launch products, prices, quotas or active plans have been seeded into the application database. QA values exist only in disposable test databases and preview fixtures.

## Validation evidence

- Focused backend subscription suite: **43 passed, 246 assertions**. Covers HTTP leakage paths, ownership/environment, actual signed Google identity verification, deferred replacement evidence, late ancestor-token recovery, grant authorization/snapshots, UTC/calendar boundaries, fresh restore requests and safe Filament views. Provider positive purchase responses use external-boundary fixtures; they are not store acceptance.
- Full `composer test`: **298 passed, 19 failed, 1,650 assertions** on the run before the final provider/diagnostic refinements and extra focused assertions. The failures are in `CatalystSerialAndExtraCodeMatchTest`, `EcotradeProductImageImportCommandTest`, `FixWatermarkIssueImagesCommandTest`, and one `MobileApiTest` image-visibility case.
- Those same four files on an isolated archive of baseline commit `372a55699d243e476724d0894878b5f1e58b954c` reproduced **19 failed, 31 passed, 198 assertions** with the current installed dependencies and environment. Autoloading was verified to use the archived application files. The full regression gate is therefore **not green**, even though the failures reproduce without this feature.
- Real local MySQL 8.4 acceptance: eight separate PHP processes/connections per scenario, **24 workers total**. Exactly one final-slot claim succeeds; eight concurrent sibling reveals charge once; initial-bucket contention permits one family. Claim rows equal used counts, all business rejections are `DAILY_QUOTA_EXHAUSTED`, and the generated QA schema is dropped. See [recorded process/database evidence](evidence/mysql-concurrency.json). No writes to the configured application database.
- Mobile TypeScript: `npx tsc --noEmit` passed. Full Jest run: **10 passed**, including nine subscription store-option/cache/lifecycle/callback tests and the existing text component test migrated from an unsaved starter snapshot to rendered assertions. Build-environment Node tests: **5 passed**.
- Apple helper Node tests: **4 passed**, including rejection of unsigned/forged JWS data by the real SDK and retry classification for OCSP/server failures. No real signed Apple sandbox transaction was available.
- Expo static web export passed. Authenticated preview used a dedicated SQLite file and loopback-only servers, with purchases disabled. English status and Arabic phone-width layout were checked. See [Arabic QA screenshot](evidence/mobile-subscriptions-ar.png). This is web rendering evidence, not native store proof. Preview values and local HTTP addresses are fixtures, not staging configuration.
- Rendered QA fixed two compatibility issues: Zustand `import.meta` required Expo's provided transform, and native notification modules must be skipped on web to avoid blocking login on unsupported permissions.
- PHP lint passed for all 77 changed PHP files; Pint, Composer manifest validation and Git whitespace checks passed. Composer validates with warnings about the intentionally pinned Google/JWT package versions. Migration up/rollback passed on a separate SQLite file.
- The recorded MySQL run passed earlier on 22 October. A fresh retry after the idle interval failed with PDO connection error 2002: localhost:3306 is now unavailable. No schema was created by the failed retry. The earlier successful evidence is retained; the harness now explicitly exits nonzero and redacts exception details on failure.
- Dependency installation reports existing advisory backlogs (Composer and npm). These have not been represented as a clean dependency-security gate; resolve applicable advisories before release.

## Native build record

Android `:app:assembleDebug` completed successfully, exit **0**, in **40m 3s**, with 500 actionable tasks (496 executed). A project-local `jdk.net.unixdomain.tmpdir` and checksum-verified local JDK 17.0.20.1+1 got past the earlier Java loopback and Maven access failures. NDK 27.1.12297006 was installed; Expo IAP and NetInfo native modules compiled.

Artifact: `maik-cat-mobile/android/app/build/outputs/apk/debug/app-debug.apk`, **80,079,881 bytes**, created on 22 October at **09:02 Asia/Damascus**. SHA256: `ABC730FA24D155F05E26B094FBD626FB28DEE73C8A82F7E2555983D66410EA1D`. This is a development-client APK that needs Metro; it is not a signed store-release artifact. No native installation or launch was performed; the currently attached Android device is unauthorized for ADB.

Offline Gradle dependency insight passed, resolving **Google Billing 9.1.0** through **OpenIAP Google 4.0.3 → Expo IAP 6.1.1**. The APK manifest independently contains Billing 9.1.0 and the billing permission. See [artifact identity](evidence/android-build.json) and [resolved dependency chain](evidence/android-billing-dependencies.txt).

The compile-only URL `https://staging.invalid` is deliberately non-routable; no store purchase or real staging connection is implied. iOS compilation requires a macOS builder. The Expo 54 / IAP 6.1.1 native compatibility gate remains open until both artifacts and store callbacks are verified.

## Activation and operations handover

These commands are documented for a later approved rollout; they have **not** been run against the application database.

1. Back up the target database and deploy additive migrations with purchases/enforcement disabled. Install Composer dependencies and `npm ci` under `tools/apple-billing`; configure Node executable, Apple roots/keys and Google credentials through protected filesystem paths/environment values.
2. Seed the seven subscription permissions using the existing permission seeder after reviewing role changes. Configure approved plans and store mappings in Filament; verify store metadata through the provided action. Verify the separate Apple catalog credentials/group/app and Google service account/package/base plans.
3. Configure approved HTTPS terms and support destinations. Provision staging, queue workers, scheduler, authenticated provider ingress and monitoring. The webhook routes are `/billing/apple` and `/billing/google`, outside API casing and browser CSRF middleware.
4. Complete both native/store QA matrices, old-client release availability, terms/support approvals and public-artifact checks. Do not infer any of these from local fixtures or configuration tests.
5. Configure the approved UTC `SUBSCRIPTIONS_CUTOVER_AT`. Rehearse `php artisan subscriptions:grant-transition --dry-run --reason="approved change reference"` against an isolated copy; then run the approved transition batch on the target environment. Existing active app accounts receive unlimited access for exactly 30 days from the cutover.
6. At the approved effective cutover, with enforcement enabled, run `php artisan subscriptions:activate-enforcement --reason="approved change reference"`. This persists `subscriptions.enforcement_activated_at` in settings and an audit record. The latch keeps catalog data protected if the purchase/enforcement environment switches are subsequently disabled. Do not delete that setting as rollback.
7. Operate workers and scheduler. Inspect diagnostics for failed events, duplicate deliveries, account conflicts, overdue acknowledgements, reconciliation lag and stale mappings. `subscriptions:reconcile` queues due agreements and abandoned inbox work; `subscriptions:recover-apple --hours=24 --pages=20` recovers verified notification history. Filament replay/reconcile actions require permissions and reasons.
8. Roll back purchase availability or use maintenance errors if needed; retain all ledgers, provider processing and valid paid/granted access. Do not reverse the subscription migration or reopen public protected values after activation.

No deployment, production migration, actual transition batch, enforcement latch, store purchase, store-console write, submission, commit or push was performed. The pre-existing deletion of `storage/app/public/.gitignore` remains untouched.

---

> Approved design from 21 October 2026. Local implementation and validation are tracked in the [execution register](08_PHASED_EXECUTION_PLAN.md). The user authorized local implementation on 22 October while keeping native/store acceptance and production activation gated.

## 5. Execution backlog, delivery structure, and gates

Every task inherits the behavior and file bundles above. Test IDs specify unit, integration and native acceptance work; external-only setup tasks require the corresponding configuration/E2E evidence rather than artificial unit tests.

| ID / priority | Owner; prerequisites/dependencies | Rationale and changes / file bundles | Definition of Done and tests | Risk; engineering days |
|---|---|---|---|---|
| **P0-01 / critical** | Orchestrator + operations; approved scope | Confirm console roles/products/storefronts, staging, production Node execution, queue/scheduler and required secrets. Freeze API/data contracts. **B4, B5, M3** | Configuration register complete; no assumed console state; commercial change approved. **E3** | External access/hosting; **1.5–2.5** |
| **P0-02 / critical** | Mobile; P0-01 access | Pin billing dependency and plugin; verify Expo 54 native feasibility without feature rollout. **M3** | Successful iOS/Android native builds, product fetch and test purchase callback; resolved Android billing dependency recorded. Failure stops implementation expansion. **E1** | SDK/toolchain compatibility; **1.5–2.5** |
| **P1-01 / critical** | Backend; P0 gates | Add additive schema, models, ownership identities, immutable snapshots and factories. **B1** | Constraints prevent duplicate identities; paid/granted records remain distinct; safe migration/recovery documented. **U1, I2** | Data integrity; **2–3** |
| **P1-02 / critical** | Backend; P1-01 | Implement entitlement resolution and atomic family/day usage. **B2** | Highest allowance, exact dates and idempotent quota claims work under real concurrency. **U1, U2, I5** | Overspend/revocation races; **2–3** |
| **P1-03 / critical** | Backend; P1-02 | Implement status/catalog/reveal API and coordinated serializer redaction. **B3** | No protected data through any browse path; existing pricing results preserved; stable errors. **I1, E2** | Old-client behavior/data leakage; **2–3** |
| **P2-01 / critical** | Backend/Node; P1-01, P0 runtime | Official Apple verification helper and Laravel adapter. **B4 Apple files** | Trusted verification, account/environment binding and retry behavior; no secrets/process injection. **I2, E1 Apple** | Signature/runtime integration; **2.5–4** |
| **P2-02 / critical** | Backend; P1-01, Google access | Google verification, binding, acknowledgement and token/order lineage. **B4 Google files** | Pending never unlocks; verified new purchases acknowledged; renewals/replacements preserved. **I2, E1 Google** | Incorrect ownership/acknowledgement; **2.5–4** |
| **P2-03 / critical** | Backend/operations; both provider adapters | Authenticated inbox, jobs, reconciliation, replay and alerts. **B5** | Duplicate/out-of-order events cannot corrupt access; downtime recovery demonstrated. **I3, E1** | Lost or stale lifecycle state; **2–3** |
| **P3-01 / high** | Filament; P1-01 and frozen service interfaces | Plan catalog, mappings, forms/tables and authorization. **F1 plan resource, F2** | Prospective edits, visibility and store mismatch handling pass permission tests. **I4, E2 admin** | Business/store catalog drift; **2–3** |
| **P3-02 / high** | Filament; P1-02, P2-03 | Entitlement ledger, grants/revocations, audit and diagnostics. **F1 remaining files, F2** | Custom grants have no payment; local revoke persists; direct unauthorized actions fail. **U1, I4, E2 admin** | Privileged misuse/confusing cancellation; **2–3** |
| **P4-01 / critical** | Mobile; P0-02, purchase API contracts | Purchase provider, callbacks, recovery, restore and management. **M1 services/provider, M2 provider registration** | All purchase states recover safely; unlock only after server confirmation. **I2 mocked client, E1** | Interrupted/duplicate native delivery; **2.5–4** |
| **P4-02 / high** | Mobile; P4-01, plan/status API | Paywall, current-plan/quota screen, disclosures and five locales. **M1 route/types, M2 navigation/locales** | Store-sourced price and correct renewal/access messages; RTL reviewed. **E1, E2** | Misleading terms/price display; **2–3** |
| **P4-03 / critical** | Mobile; P1-03 | Public/restricted types, explicit reveal UI, parsed errors and sensitive-state clearing. **M2** | Cards cannot reveal cached prices; offline/account-switch/expiry behavior passes. **I1 client contracts, E2** | Stale sensitive data; **2–3** |
| **P5-01 / critical** | Backend + QA; P1–P4 complete | Full HTTP/Filament regression and MySQL concurrent acceptance. **B1–B5, F1–F2** | Focused suites and full backend suite pass; concurrency evidence includes DB/process identity. **U1–U2, I1–I5** | Cross-subsystem regressions; **2–3** |
| **P5-02 / critical** | Mobile + QA; P5-01, store testers | Physical-device Sandbox/License Tester lifecycle QA and final release builds. **M1–M3** | Both platforms satisfy purchase, restore, quota and failure matrix; evidence tied to exact artifacts. **E1–E3** | Device/store-only failures; **3–5** |
| **P5-03 / critical** | Operations + product owner; all acceptance gates | Transition rehearsal, legal/disclosure updates, release monitoring and handover. **B5, M3, documentation** | Cutover/rollback rehearsed; metadata, support ownership and revised terms approved. **I3, E3** | Paid-user disruption; **2–3** |

Estimated effort:

| Workstream | Engineering days |
|---|---:|
| Discovery, accounts and native feasibility | 3–5 |
| Laravel, quota and provider integration | 13–20 |
| Filament administration | 4–6 |
| Mobile feature implementation | 6.5–10 |
| Cross-platform QA | 5–8 |
| Release and handover | 2–3 |
| **Total** | **Approximately 34–52 person-days** |

With dedicated backend, mobile and dashboard contributors, allow approximately **5–8 working weeks**, excluding account provisioning, store review waits and a failed compatibility gate. These are planning estimates, not an approved price.

Parallel work is safe after schema/service/API contracts freeze:

- Apple and Google adapters can have separate owners and files.
- Filament can proceed against frozen application services while mobile uses agreed response fixtures.
- The backend owner controls models, migrations, routes and shared services.
- The mobile owner controls native configuration, HTTP/types and shared product components.
- Only one writer owns each shared file. The orchestrator reviews phase evidence before the next gate.

### Proposed file ownership and touchpoints

The following are proposed paths; **NEW** means the file does not yet exist. Generated resource subfiles follow the repository’s existing `Schemas`, `Tables`, and `Pages` conventions.

| Bundle | Exact principal touchpoints |
|---|---|
| **B1 — persistence** | NEW `C:/laragon/www/maik/maik-cat-backend/database/migrations/2026_10_21_230001_create_subscription_tables.php`; NEW model files in `C:/laragon/www/maik/maik-cat-backend/app/Models/` named `SubscriptionPlan.php`, `PlanStoreProduct.php`, `BillingAgreement.php`, `PurchaseTransaction.php`, `SubscriptionEntitlement.php`, `SubscriptionEvent.php`, `SubscriptionAuditLog.php`, `DailyUsage.php`, `DailyUsageReveal.php`; existing [User.php](C:/laragon/www/maik/maik-cat-backend/app/Models/User.php). |
| **B2 — entitlement and quota** | NEW `C:/laragon/www/maik/maik-cat-backend/app/Services/Subscriptions/EntitlementResolver.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Services/Subscriptions/DailyUsageService.php`; NEW `C:/laragon/www/maik/maik-cat-backend/config/subscriptions.php`. |
| **B3 — API and redaction** | Existing [api.php](C:/laragon/www/maik/maik-cat-backend/routes/api.php), [ItemResource.php](C:/laragon/www/maik/maik-cat-backend/app/Http/Resources/API/ItemResource.php), [ItemController.php](C:/laragon/www/maik/maik-cat-backend/app/Http/Controllers/API/ItemController.php), [HomeController.php](C:/laragon/www/maik/maik-cat-backend/app/Http/Controllers/API/HomeController.php), [SavedItemController.php](C:/laragon/www/maik/maik-cat-backend/app/Http/Controllers/API/SavedItemController.php); NEW `C:/laragon/www/maik/maik-cat-backend/app/Http/Controllers/API/SubscriptionController.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Http/Controllers/API/ItemValuationController.php`. Add validated requests/resources beside existing API equivalents. |
| **B4 — providers** | NEW `C:/laragon/www/maik/maik-cat-backend/app/Services/Subscriptions/AppleBillingProvider.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Services/Subscriptions/GooglePlayBillingProvider.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Services/Subscriptions/StorePurchaseService.php`; NEW `C:/laragon/www/maik/maik-cat-backend/tools/apple-billing/package.json`; NEW `C:/laragon/www/maik/maik-cat-backend/tools/apple-billing/verifier.mjs`. |
| **B5 — events and operations** | NEW `C:/laragon/www/maik/maik-cat-backend/routes/billing.php`; existing [bootstrap/app.php](C:/laragon/www/maik/maik-cat-backend/bootstrap/app.php), [console.php](C:/laragon/www/maik/maik-cat-backend/routes/console.php); NEW `C:/laragon/www/maik/maik-cat-backend/app/Jobs/ProcessSubscriptionEvent.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Jobs/ReconcileSubscription.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Console/Commands/GrantSubscriptionTransition.php`. |
| **F1 — dashboard** | NEW `C:/laragon/www/maik/maik-cat-backend/app/Filament/Resources/SubscriptionPlans/SubscriptionPlanResource.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Filament/Resources/SubscriptionEntitlements/SubscriptionEntitlementResource.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Filament/Resources/BillingAgreements/BillingAgreementResource.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Filament/Pages/SubscriptionDiagnostics.php`; existing [EditAppUser.php](C:/laragon/www/maik/maik-cat-backend/app/Filament/Resources/AppUsers/Pages/EditAppUser.php). |
| **F2 — authorization** | NEW `C:/laragon/www/maik/maik-cat-backend/app/Policies/SubscriptionPlanPolicy.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Policies/SubscriptionEntitlementPolicy.php`; NEW `C:/laragon/www/maik/maik-cat-backend/app/Policies/BillingAgreementPolicy.php`; existing [permission seeder](C:/laragon/www/maik/maik-cat-backend/database/seeders/RolesAndPermissionsSeeder.php). |
| **M1 — subscription feature** | NEW `C:/laragon/www/maik/maik-cat-mobile/app/subscriptions/index.tsx`; NEW `C:/laragon/www/maik/maik-cat-mobile/src/features/subscriptions/types/subscription.types.ts`; NEW `C:/laragon/www/maik/maik-cat-mobile/src/features/subscriptions/services/subscriptions.service.ts`; NEW `C:/laragon/www/maik/maik-cat-mobile/src/features/subscriptions/services/store-purchase.service.ts`; NEW `C:/laragon/www/maik/maik-cat-mobile/src/features/subscriptions/subscription-provider.tsx`. |
| **M2 — integration** | Existing [app-providers.tsx](C:/laragon/www/maik/maik-cat-mobile/src/providers/app-providers.tsx), [http-client.ts](C:/laragon/www/maik/maik-cat-mobile/src/lib/http/http-client.ts), [API endpoints](C:/laragon/www/maik/maik-cat-mobile/src/lib/http/api-endpoints.ts), [auth-context.tsx](C:/laragon/www/maik/maik-cat-mobile/src/features/auth/auth-context.tsx), [ProductCard.tsx](C:/laragon/www/maik/maik-cat-mobile/src/components/shared/ProductCard.tsx), [product detail](C:/laragon/www/maik/maik-cat-mobile/app/product/[id].tsx), [ProductInformationSection.tsx](C:/laragon/www/maik/maik-cat-mobile/src/components/pages/product-details/ProductInformationSection.tsx). Update associated catalog/home/saved types and locale files. |
| **M3 — native/release** | Existing [package.json](C:/laragon/www/maik/maik-cat-mobile/package.json), [app.json](C:/laragon/www/maik/maik-cat-mobile/app.json), [app.config.js](C:/laragon/www/maik/maik-cat-mobile/app.config.js), [eas.json](C:/laragon/www/maik/maik-cat-mobile/eas.json), [release checklist](C:/laragon/www/maik/maik-cat-mobile/docs/STORE_RELEASE_CHECKLIST.md). |

### Remaining gates and ownership

| Gate | Owner | Blocking point |
|---|---|---|
| Revised contract cost, schedule, deliverables and support | Product owner / technical supplier | Before feature implementation |
| Production Node helper, queue, scheduler, HTTPS ingress and staging feasibility | Operations | P0 completion |
| Expo 54 native compatibility with selected billing package | Mobile owner | P0 completion |
| Launch plan names, quotas, prices, product IDs and service levels | Product owner / store administrator | Product activation |
| Existing storefront list, agreements, API roles, credentials and testers | Store administrator | Provider QA and release |
| Subscription terms, privacy disclosures, cancellation/refund guidance and review metadata | Product owner | Store submission |
| Cross-device restore acceptance with the retained device policy | Mobile owner / product owner | Native acceptance and store review |
| Public release availability, cutover timestamp and transition batch | Product owner / operations | Enforcement activation |
