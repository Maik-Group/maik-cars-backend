> Approved design from 21 October 2026. Local implementation and validation are tracked in the [execution register](08_PHASED_EXECUTION_PLAN.md). The user authorized local implementation on 22 October while keeping native/store acceptance and production activation gated.

### Proposed API contracts

These routes are implemented locally under `/api/v1`; see the execution register for validation and activation gates.

| Method and route | Contract |
|---|---|
| `GET /subscription-plans?platform=ios\|android` | Public active business plans and verified store mappings; mobile fetches actual checkout prices from its store. |
| `GET /subscriptions/status` | Authenticated effective access, contributing sources, billing state, dates, renewal state, usage, reset time, account-binding identifier and management guidance. |
| `POST /subscriptions/purchases/apple` | Authenticated StoreKit transaction reference; verify through Apple before activation. |
| `POST /subscriptions/purchases/google` | Authenticated purchase token; derive trusted product/base plan from Google’s response. |
| `POST /subscriptions/restore` | Bounded purchase references, maximum 20, processed through the same verification and ownership rules. |
| `POST /subscriptions/resync` | Reconcile agreements already owned by the authenticated user. |
| `POST /items/{item}/valuation` | Authenticated atomic price/assay reveal. |
| `GET /subscriptions/manage` | Fixed, provider-appropriate management/help destinations. |

Use camelCase JSON to match the current middleware.

**Illustrative reveal response; numbers are examples, not launch configuration:**

```json
{
  "data": {
    "itemId": "<item UUID>",
    "price": 125.50,
    "basePrice": 130.00,
    "currency": "EUR",
    "weightKg": 1.2,
    "ptPpm": 500,
    "pdPpm": 800,
    "rhPpm": 100
  },
  "usage": {
    "limit": 5,
    "used": 3,
    "remaining": 2,
    "counted": true,
    "resetsAt": "2026-10-23T00:00:00Z"
  },
  "validUntil": "<short-lived authorization expiry>"
}
```

Stable failures:

| HTTP | Code | Behavior |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Login required. |
| 403 | `SUBSCRIPTION_REQUIRED` / `ACCESS_REVOKED` / `ACCOUNT_INACTIVE` | No restricted fields returned. |
| 409 | `PURCHASE_ACCOUNT_CONFLICT` | Purchase is bound elsewhere; provide support guidance without disclosing its owner. |
| 429 | `DAILY_QUOTA_EXHAUSTED` | Include usage/reset metadata. Previously revealed families remain accessible while entitlement is valid. |
| 429 | `RATE_LIMITED` | Distinct from business quota exhaustion. |
| 503 | `VERIFICATION_UNAVAILABLE` / `VALUATION_UNAVAILABLE` | Retryable failure; no quota consumption or speculative access. |
| 202 | `VERIFICATION_PENDING` | Durable verification is pending; mobile polls status and does not unlock early. |

Rate-limit purchases to 10/minute/user and reveals to 60/minute/user initially. Keep infrastructure throttling separate from daily business allowances.

### Provider verification and lifecycle

**Apple**

- The helper uses the official SDK to validate transaction and notification signatures, certificate trust, expected bundle ID, app identity, environment, products, dates, and account binding.
- Invoke a fixed helper executable using structured process arguments and JSON stdin. No shell composition, user-supplied paths, secrets in arguments, or raw token logging.
- Allow only named verification/reconciliation operations; enforce a process deadline and structured success/retryable/permanent-failure results.
- Validate outer notification and nested signed data. Use Server API status/history for reconciliation rather than treating notification arrival order as entitlement truth.
- Configure one subscription group for this access family. Map duration variants and service levels consistently; disable Family Sharing for this release.

**Google**

- Validate tokens with `purchases.subscriptionsv2.get`, using the server-configured package name and verified product/base-plan mapping.
- Bind verified purchases to the Maik Cat account; follow linked tokens for replacements.
- Acknowledge verified new purchases promptly through the backend, with durable retry. Renewals are recorded separately without incorrectly treating a purchase token as a unique renewal transaction.
- Verify Pub/Sub JWT signature, issuer, audience, and authorized service identity before accepting RTDN. Fetch current subscription state after notification.
- Use verified successful-order references for transaction records; never label pending, free, or administrative access as money received. [Google verification reference](https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.subscriptionsv2/get), [subscription lifecycle](https://developer.android.com/google/play/billing/lifecycle/subscriptions), [authenticated Pub/Sub delivery](https://docs.cloud.google.com/pubsub/docs/authenticate-push-subscriptions).

**Shared processing**

- Register dedicated webhook routes outside JSON key transformation and session/CSRF middleware; retain provider authentication and request-size limits.
- Acknowledge delivery only after authenticity checks and durable inbox persistence. Temporary verification/storage failure requests provider retry.
- Serialize reconciliation per agreement. Deduplicate notification IDs and transactions; never replay an older event payload over newer verified state.
- Use queued retries with backoff, failed-job visibility, and audited replay.
- Reconcile active/pending/grace/near-expiry agreements every 15 minutes with batching and jitter; recover missed Apple notifications through history.
- Existing verified access may continue through its known valid period during provider downtime; never extend an unknown period.
- Prevent normal UI purchases when an active agreement exists on another platform. Still record unexpected valid overlapping purchases and provide management/support guidance; do not discard evidence of a charge.
