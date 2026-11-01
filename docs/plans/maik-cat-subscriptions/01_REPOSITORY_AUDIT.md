> Approved design from 21 October 2026. Local implementation and validation are tracked in the [execution register](08_PHASED_EXECUTION_PLAN.md). The user authorized local implementation on 22 October while keeping native/store acceptance and production activation gated.

### Verified repository baseline

This section records the pre-implementation audit from the supplied plan. P0 dependency and configuration changes made afterward are listed in the execution register.

| Repository | Branch and commit | State and stack |
|---|---|---|
| `C:/laragon/www/maik/maik-cat-backend` | `main` — `372a55699d243e476724d0894878b5f1e58b954c` | Existing deletion of `storage/app/public/.gitignore`; preserve it. Installed and locked Laravel **13.6.0**, Filament **5.6.1**, Sanctum **4.3.1**, Spatie Permission **7.3.0**, Pest **4.6.3**. |
| `C:/laragon/www/maik/maik-cat-mobile` | `main` — `6ef7944cbe7a80b0bf3be0445f628a5569f43096` | Clean. Expo **54**, React Native **0.81.5**, React **19.1.0**, Expo Router, New Architecture, EAS managed native generation. No committed native projects or billing package. |

Three delegated research workstreams executed with Luna requested: backend/API, mobile, and official policies/SDKs. The orchestrator reviewed Filament, the contract, and conflicting findings.

Key source findings:

| Finding | Evidence and consequence |
|---|---|
| Prices and assays currently accompany normal catalog responses. | [ItemResource.php:13](C:/laragon/www/maik/maik-cat-backend/app/Http/Resources/API/ItemResource.php:13) is reused by listings, home, details, related items, and saved items. Every path needs protection. |
| Serial families already define catalog grouping. | [ItemApiResponseService.php:152](C:/laragon/www/maik/maik-cat-backend/app/Services/Mobile/ItemApiResponseService.php:152). Quota identity must remain independent of the optional display-deduplication setting. |
| Public valuation inputs permit reconstruction. | [ItemPriceService.php:117](C:/laragon/www/maik/maik-cat-backend/app/Services/Mobile/ItemPriceService.php:117). Protecting assays and weight closes this structured-data bypass while retaining the free manual calculator. |
| API JSON keys are transformed. | [TransformApiCaseMiddleware.php:13](C:/laragon/www/maik/maik-cat-backend/app/Http/Middleware/TransformApiCaseMiddleware.php:13). Mobile contracts use camelCase; provider webhook payloads must bypass this transformation. |
| App-user administration and permissions already exist. | [AppUserResource.php:79](C:/laragon/www/maik/maik-cat-backend/app/Filament/Resources/AppUsers/AppUserResource.php:79) and [RolesAndPermissionsSeeder.php:16](C:/laragon/www/maik/maik-cat-backend/database/seeders/RolesAndPermissionsSeeder.php:16). Extend these conventions with subscription-specific permissions. |
| Device restrictions apply at login. | [AuthController.php:17](C:/laragon/www/maik/maik-cat-backend/app/Http/Controllers/API/AuthController.php:17). Purchase restoration must preserve account ownership and existing device rules. |
| Prices can remain in memory. | [query-client.ts:3](C:/laragon/www/maik/maik-cat-mobile/src/lib/react-query/query-client.ts:3) retains queries; the detail screen also retains `stableItem`. Restricted data needs separate, short-lived state. |
| Development and preview target production. | [eas.json:7](C:/laragon/www/maik/maik-cat-mobile/eas.json:7). Separate staging endpoints and data before billing QA. |

At the recorded baseline, no subscription implementation or payment integration was found in the inspected application code and dependency manifests. Actual store products, console permissions, deployed infrastructure, and production database state remain **EXTERNAL / MANUAL**.
