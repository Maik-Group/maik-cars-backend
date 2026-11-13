# Maik Cat authentication email reliability QA

November 13, 2026. Release readiness remains open. Registration and recovery now accept requests independently of Mailtrap, and the real worker recovered from a transport timeout. The ten-send reliability sample and the full Arabic and payment acceptance runs remain open.

## Findings

The original capture-only router and the later intermittent provider-transport timeouts were separate issues. Previous and current timeout records show a transport exception without an HTTP status. A direct credential-free HTTPS probe reached the Mailtrap send endpoint and returned the expected 401. In that sample TCP connected at 0.49 seconds and TLS completed at 5.05 seconds. The exact cause of the intermittent network delays remains unknown. In the synchronous implementation a failed Mailtrap request tied up the API for roughly 10 seconds and returned 503. A queued HTTP registration probe returned 200 in 0.684 seconds before its worker completed delivery.

## Implementation and security

- The controller returns an eligibility-neutral acknowledgment. AccountAuthenticationService stores the hashed challenge and dispatches SendAuthEmailCode after commit. If the dispatch itself fails, the challenge is invalidated and the API returns a generic 503. A process crash in the interval between commit and dispatch can leave a challenge without a job. The existing cooldown and expiration bound recovery through a new request.
- The job uses Laravel encrypted serialization and the existing jobs and failed_jobs tables. The delivery-state migration adds a generation ID, short worker lease, acceptance/failure timestamps, and provider message ID. The job checks the generation and deadline before sending. Resends supersede earlier generations and wait for any active send lease. The database records provider acceptance, not inbox delivery.
- AuthEmailSender keeps the official SDK. Timeouts and network errors, 429, and 5xx can retry up to three total attempts with 10- and 30-second delays, honoring a longer Retry-After when it fits the remaining code lifetime. Permanent failures are terminal. An ambiguous response can produce duplicate email with the same code. Provider acceptance followed by a worker crash can also produce a duplicate.
- Logs record purpose, delivery generation, job identifiers, attempt, duration and outcome. Provider response bodies, recipients, verification codes and credentials are excluded. Existing hashed-code, one-time consumption, attempt limits, token revocation and device binding stay in force.
- Mobile updates cover pending wording in all five supported locales, localized code/network/rate-limit errors, password visibility, request timeout and Arabic digits in the code input. Existing pending form state remains in memory and a full process restart can require requesting a new code.

Changed backend files include AccountAuthenticationService, AccountAuthenticationController, AuthEmailSender, SendAuthEmailCode, AuthEmailDeliveryException, queue and service configuration, .env.example, phpunit.xml, the delivery-state migration and authentication tests. Mobile changes include AccountFlowScreen, auth.service, five locale files and the account-flow tests.

## Test matrix

| Status | Scenario | Evidence |
| --- | --- | --- |
| PASS | Full backend Pest | 424 tests, 2528 assertions, 306.61 seconds. The final job acceptance-record update was subsequently covered by the focused suite. |
| PASS | Mobile Jest and TypeScript | 15 suites, 64 tests and tsc --noEmit passed. |
| PASS | Pint and focused auth | Pint passed. Final focused backend run passed 39 tests and 299 assertions. |
| PASS | Database queue integration | Seven tests covering commit boundary, encrypted payload, real worker, duplicate run, transient errors, Retry-After, permanent failure and stale jobs |
| PASS | Android English registration | Current Metro bundle on emulator-5554. Entered inbox code and saw Account verified. Database populated email_verified_at. |
| PASS | Android English recovery | Login email was prefilled. Entered actual inbox code and new password. Android returned to login with the success toast. |
| PASS | Live HTTP credential, token and device checks | Old token 401, old password 422, new password 200, other device 403 DEVICE_NOT_AUTHORIZED, profile 200 |
| PASS | Live wallet balance | Wallet 200 with balance zero and no grants |
| NOT TESTED | Positive-match purchase gate and Stripe checkout | Empty catalog search returned zero results and no charge. Checkout, return, webhook crediting and existing-credit access were not run. |
| PASS | Asynchronous delivery and automatic recovery | One injected timeout and one real timeout recovered on attempt two without another user request. |
| NOT TESTED | Ten-send reliability sample | Three queued challenges reached Gmail in this run. See delivery records below. |
| NOT TESTED | Full Android Arabic/RTL flows and restart while verification is pending | Android per-app locale changed to Arabic, but the existing app language selection remained English. Arabic digits and localized copy are covered in code and mobile tests. |
| BLOCKED | PHPStan and ESLint | No configured tools found in the repositories. |
| BLOCKED | Production worker readiness | Hosting process manager and worker configuration could not be verified locally. |

The live backend used a dedicated SQLite file, loopback-only routes and a worker allowlist for the single authorized mailbox. Earlier MySQL concurrency evidence was for the synchronous implementation. No queued MySQL concurrency result is claimed.

## Live delivery records

| Request | API timing | Attempts and provider result | Gmail arrival, Damascus |
| --- | --- | --- | --- |
| HTTP registration probe | 0.684 s | Injected transient failure, 10 s retry, accepted on attempt 2 in 9.813 s. ID 1e2c2d70-df77-11f1-0040-f15301b5479e | 18:30:12 |
| Android registration | Unmeasured | Real 10.051 s timeout, 10 s retry, accepted on attempt 2 in 9.844 s. ID cb392c20-df77-11f1-0000-f181d367954c | 18:35:02 |
| Android recovery | Unmeasured | Accepted on attempt 1 in 2.054 s. ID 9ac1d5a0-df78-11f1-0040-f11c4efa08d1 | 18:40:51 |

Evidence is in ignored storage/app/auth-e2e-evidence/queued-mail.log, queued-full-pest.log, queued-verify.png and queued-reset.png. Initial queue wait and API-to-inbox time were not instrumented for every request. No video was recorded.

## Deployment and rollback

Set MAILTRAP_API_TOKEN, MAILTRAP_FROM_ADDRESS and MAILTRAP_FROM_NAME for the authorized sending domain. Set AUTH_EMAIL_QUEUE_CONNECTION=auth-email. The dedicated connection uses the application database and retry_after=90. Apply the standard migrations to the intended database, retain the existing jobs and failed_jobs tables, and supervise the worker before enabling this release. Hosting was not established from repository configuration, so select the process manager after confirming the target host.

    php artisan queue:work auth-email --queue=auth-emails --sleep=1 --timeout=25
    php artisan queue:failed
    php artisan queue:restart

Use the same database and APP_KEY for the API and worker. On deployment refresh configuration and restart long-lived workers through the host process manager. Monitor queue depth, expired generations, terminal failures and send latency. Verify production using an approved test inbox and confirm acceptance and receipt separately. For rollback, stop intake, stop the dedicated worker, and drain or discard only the auth-emails jobs before reverting code. Preserve shared jobs and failed_jobs. The additive columns may remain through a code rollback. No deployment, commit or push was performed. The user-requested Remote Desktop Commander was unavailable in this turn. Local PowerShell and ADB provided equivalent access for the checks recorded here.

References
- https://laravel.com/framework/docs/13.x/queues
- https://docs.mailtrap.io/developers/email-sending/transactional
- https://docs.mailtrap.io/developers/rate-limits

