# Live authentication QA continuation — November 13, 2026

The capture-only backend on port 8766 rejected addresses outside example.test before it called Mailtrap. A direct registration request reproduced the screenshot’s HTTP 503 and generic delivery error. There is no provider response for that failure. The regular Laravel configuration loaded the sending credential and auth@maikcat.com without a configuration cache. MAIL_MAILER=log is independent of the direct SDK transport.

The live QA backend ran on loopback port 8784 using an isolated SQLite database and an ignored router that permits only the authorized Gmail recipient. The Android QA bundle reached it through ADB reverse. The existing development APK opened registration, verification, recovery, reset, and login without the earlier native-module crash.

| Request | Mailtrap response | Inbox and Android evidence |
| --- | --- | --- |
| HTTP registration probe | 200, bad648a0-df71-11f1-0040-f11c4efa08d1 | Gmail inbox receipt at 17:51:38 Damascus |
| Android registration | 200, e0750560-df71-11f1-0040-f15301b5479e | Gmail inbox receipt at 17:52:41. The emulator showed the verification screen and resend countdown. The received code completed registration and returned to login. SQLite confirmed email_verified_at and app_user. |
| Android password reset | 200, 55da6d40-df72-11f1-0040-f15301b5479e | Gmail inbox receipt at 17:55:58. The received code completed reset and Android returned to login with the success toast. |

Gmail included the expected subjects and the verification message included the inline PNG. Android screenshots are in ignored storage/app/auth-e2e-evidence/android-verify.png and android-reset.png. The reset request experienced intermittent transport timeouts before a successful manual resend. A later probe timed out again. The provider returned no HTTP response for those attempts. The precise network cause remains unverified.

AuthEmailSender now bounds SDK requests to 10 seconds of inactivity and 15 seconds total, disables redirects, checks the provider success response, and logs HTTP status, an accepted message ID, or exception type and timeout classification. It leaves automatic retries disabled and keeps the existing generic client error. The mobile login link now passes the entered email into password recovery. The APK session used an earlier bundle and still displayed the earlier login link wording.

Focused backend authentication checks passed (32 tests, 231 assertions). They cover legacy verification, code handling, password reset, token revocation and device binding. Mobile Jest passed (15 suites, 62 tests). Pint passed. The full backend run initially reported 413 passing and four image-processing failures. All 23 tests in those four files passed together in a focused rerun, and the final full suite passed all 417 tests. Old-password rejection was observed after the live reset. Fresh-password sign-in, wallet and no-credit gate, live token revocation with a preexisting session, authenticated profile recovery, and Arabic/RTL remain outside the completed live run.

Commands included php artisan migrate --force and php artisan db:seed --class=RolesAndPermissionsSeeder --force under explicit SQLite QA overrides, php -S 127.0.0.1:8784 -t public storage/app/auth-e2e-evidence/live-router.php, adb reverse, adb shell input and screencap, php artisan test --compact, php artisan test --compact tests/Feature/Auth, php vendor/bin/pint --test, npm test -- --runInBand, and npx tsc --noEmit.

Deployment still requires the normal review, the auth-email-code migration and app_user role on the intended database, and a sending credential for the verified production domain. No deployment, commit, or push was performed.

---

# Maik Cat — Authentication & Email End-to-End QA Report

**Date:** 2026-11-13  
**Historical status for the earlier capture-only run.** See the live QA continuation above for current results.

## 1. Executive summary

Remote Desktop Commander connected to the Windows development machine. Both existing repositories and their uncommitted changes were inspected and preserved. The existing Mailtrap PHP SDK integration was retained. Two confirmed defects were corrected: missing login throttling / malformed device-identifier validation, and missing mobile resend countdown enforcement. Five backend security regression tests and one mobile cooldown test were added.

Results: 30/30 focused backend authentication tests (213 assertions), 62/62 mobile Jest tests across 15 suites, TypeScript compilation, nine-file scoped authentication Pint check, and 12/12 real local HTTP checks passed. The repository-wide backend run reported 388 passed and 22 failed (2,313 assertions); full Pint reported 58 style issues across 512 files. No clean baseline comparison was performed, so the non-authentication failures are not attributed to these fixes.

Live Mailtrap sending and actual email-based account creation/reset are BLOCKED: no explicitly authorized receiving mailbox was provided. Android execution reached a native runtime error, and rebuilding the development APK failed because the required Android NDK is incomplete. No production readiness claim is made.

## 2. Local environment

| Item | Observed |
|---|---|
| Backend | C:/laragon/www/maik/maik-cat-backend |
| Mobile | C:/laragon/www/maik/maik-cat-mobile |
| Mobile remote | https://github.com/Maik-Group/maik-cat-mobile.git |
| Git branches | main; both dirty at intake |
| PHP / Composer | 8.4.27 / 2.8.12 |
| Laravel / Mailtrap SDK | 13.6.0 / 3.18.1 |
| Node / npm | 25.2.1 / 11.6.2 |
| React Native / Expo | 0.81.5 / SDK 54 |
| Java | Android Studio JBR 21.0.10 |
| Emulator | Maik_Cat_Visual_API35; emulator-5554; API 35 |
| Existing local DB config | MySQL on localhost, schema maik_cat_final_pro; inspected read-only, not migrated or used for test writes |
| Isolated HTTP QA DB | storage/app/auth-e2e-qa-20261113.sqlite |
| Isolated HTTP QA server | http://127.0.0.1:8765 |
| Android Metro | localhost:8091 through ADB reverse |
| Automated test DB | SQLite in memory, mocked AuthEmailSender for authentication tests |

The existing MySQL schema has three pending migrations, including the authentication-code migration. A separate disposable SQLite file was created and migrated; only synthetic accounts and roles were created there. No production database, DNS records, billing settings or deployment were changed. The isolated HTTP QA process explicitly replaced the Mailtrap credential with a non-production disabled sentinel, verified by a guard. No genuine email was sent. The actual configured credential was never printed.

## 3. Authentication architecture and API contract

React Native -> Laravel API routes -> AccountAuthenticationController / AuthController -> AccountAuthenticationService -> auth_email_codes / User -> AuthEmailSender -> Mailtrap SDK -> recipient.

AuthCodeEmailFactory builds the branded HTML and plain-text multipart message with a CID-embedded Maik Cat logo. Registration creates the account only after ownership proof; code requests do not create a user. Login is a separate operation, returning a Sanctum token and user object. Password recovery changes the password, revokes existing tokens and preserves the authorized device binding.

All paths below are POST under /api/auth and accept JSON.

| Endpoint | Request | Success |
|---|---|---|
| /register | email | 200 generic request message |
| /resend-verification | email | 200 generic request message |
| /verify-email | email, name, six-digit string code, password, passwordConfirmation | 201; verified account created; sign in separately |
| /login | email, password, deviceId and/or fcmToken according to device policy | 200; token and user |
| /forgot-password | email | 200 generic request message |
| /reset-password | email, six-digit string code, password, passwordConfirmation | 200; password reset |
| /logout | bearer token | 200; session invalidated |

Email normalization and snake/camel case compatibility follow existing controller/middleware contracts. Password validation is 8–72 characters, confirmed; email max 254 characters; name max 255 characters. Invalid/expired/used codes return 422. Code delivery failures return 503. Login and authentication routes are throttled. Protected profile requests use GET /api/profile with a bearer token.

## 4. Mailtrap configuration verification

| Setting | Observed |
|---|---|
| MAILTRAP_API_TOKEN | Present in local configuration; value withheld; validity and scope not verified by live send |
| MAILTRAP_FROM_ADDRESS | auth@maikcat.com |
| MAILTRAP_FROM_NAME | Maik Cat |
| MAIL_MAILER | log |
| Config cache | Absent |
| Service resolution | App/Services/AuthEmailSender resolved |
| Sending SDK | railsware/mailtrap-php 3.18.1; initSendingEmails()->send() |
| DNS approval | User reports verified records; provider dashboard not independently checked |

MAIL_MAILER=log does not replace the direct SDK transport. Existing service/template implementation was not rewritten or replaced with SMTP. The branded multipart serialization tests pass for registration and password reset. An unsupported purpose is rejected.

## 5. Real email delivery evidence

**BLOCKED.** No explicitly authorized QA recipient or inbox was supplied. A linked account email is not equivalent to authorization to send a QA message. No direct real service send, Mailtrap acceptance ID, provider log status or inbox delivery evidence exists for this run.

The template subject, HTML/text parts, inline logo serialization and expiration wording are covered by automated tests, not a received email client rendering. The DNS verification is user-reported. Live delivery, provider token permissions/compliance status, actual inbox rendering and receipt remain pending.

## 6. Registration QA

Automated mocked-transport feature tests confirm account creation only after code proof, no user before successful verification, code hashing, role assignment, normalization, rejection of reused/expired/wrong codes, five-attempt locking, resend cooldown, and rollback of challenge state when email delivery fails. Real HTTP negative validation and verification without a challenge passed. The real positive registration journey is blocked because the code must be received from an authorized inbox; no database extraction of a live code was attempted.

## 7. Login QA

Actual HTTP login succeeded with a synthetic pre-verified fixture, and protected profile access succeeded using its issued token. Incorrect password and nonexistent email both returned 422. Invalid, missing and revoked tokens returned 401. Logout succeeded. Focused tests also cover inactive users, device binding, different-device rejection and session lifecycle. The newly added login limiter is 10 requests per minute using a separate login throttle key; malformed array-valued device identifiers are now rejected rather than cast.

Legacy active accounts with email_verified_at=null are an unresolved verification-policy issue: AuthController::login checks credentials and is_active but does not check the verification timestamp. New public registration is not affected because it creates a user only after proof. Existing documentation expressly preserves existing account access. The dedicated synthetic legacy HTTP check was prepared but the connector stopped the command; a review control was offered, and no execution outcome was observed. No blanket lockout, silent verification backfill, or legacy data changes were introduced. A migration/onboarding policy for existing accounts is required before universal verified-only access can be claimed.

## 8. Password reset QA

Mocked feature tests confirm valid reset proof, password hash change, old password rejected, new password accepted, old tokens revoked, code single use, purpose separation, password confirmation/validation, unknown account generic response and device binding preserved. The actual HTTP unknown-email request returned 200. Successful live reset via a received email, followed by old/new credential testing, remains BLOCKED. MySQL concurrency and ambiguous provider acceptance after network timeout were not exercised.

## 9. Native Android QA

The original Maik_Cat_QA_UI_API35 emulator could not start because the google_atd system image was missing. The alternative Maik_Cat_Visual_API35 booted successfully. ADB installed the existing debug APK, reverse-forwarded Metro and the local QA backend, and launched the real application. Metro bundled the current JavaScript (3,679 modules).

The installed APK predates the current native dependencies. Native startup failed with:
`TurboModuleRegistry.getEnforcing(...): 'RNCWebViewModule' could not be found.`

An actual Gradle :app:assembleDebug attempt with JBR 21 and x86_64 failed:
`[CXX1101] NDK at C:/Android/Sdk/ndk/27.1.12297006 did not have a source.properties file.`

The required 27.1.12297006 folder contains an incomplete installer and lacks both source.properties and clang.exe. NDK 28.2.13676358 is installed, but the project-required NDK version was not changed without compatibility evidence. Repair/install the exact required NDK, rebuild the current debug APK, and reinstall it.

Consequently registration, login, reset, keyboard, focus, overflow, small screen, appearance, app restart persistence and real visual checks are BLOCKED, not passed. Jest component interaction tests are a separate passing signal.

## 10. Security findings

- random_int six-digit codes; keyed HMAC digest with APP_KEY; ten-minute expiry; purpose/email binding; server-side single-use invalidation.
- Five wrong attempts per challenge; 60-second resend cooldown; five issue attempts/hour/email/purpose; per-IP auth route throttle; separate login throttle added.
- DB transaction and row locks inspected; SQLite tests passed, but production-like MySQL race/concurrency behavior remains untested.
- Password hashing uses Laravel's hashed model cast; reset revokes API tokens and does not authorize device transfer.
- Unknown registration/reset addresses use generic responses; wrong and nonexistent credentials produce the same 422 response shape. Timing-side-channel equivalence has not been independently established.
- Provider exceptions are suppressed at the application boundary and returned as generic 503; rollback tested with mocked failure. Provider acceptance followed by timeout / duplicate-delivery behavior not proven.
- No real credential, token or usable code recorded in this report. Existing active unverified legacy users remain a release-policy gap; no production mutation made.

## 11. Defects fixed

| ID | Issue | Fix | Retest |
|---|---|---|---|
| FIX-01 | Login route lacked explicit rate limiter | Add throttle:10,1,login | Focused Pest login-rate-limit regression PASS |
| FIX-02 | Non-string device/FCM input coerced to string | Trim only strings; validation rejects malformed types | Focused Pest regression PASS |
| FIX-03 | Mobile resend remained available during backend cooldown | Add countdown deadline/state, disable resend, prevent concurrent request/submit actions with in-flight guard | Mobile Jest cooldown test and full mobile suite PASS |

Android native dependency/build issue is diagnosed but not resolved. Legacy verification policy remains open. Unrelated catalog/pricing failures were not rewritten.

## 12. Files modified by this QA run

Backend:
- routes/api.php — login-specific throttle; existing unrelated edits preserved.
- app/Http/Requests/API/Auth/LoginRequest.php — typed device/FCM normalization.
- tests/Feature/Auth/AuthenticationSecurityRegressionTest.php — five security regression cases.

Mobile:
- src/features/auth/AccountFlowScreen.tsx — server-aligned resend countdown and immediate submission lock.
- src/features/auth/__tests__/account-flow.test.tsx — resend cooldown regression.

Local QA-only evidence/helpers, not intended for commit:
- storage/app/auth-e2e-qa-20261113.sqlite
- storage/app/auth-qa-config-check.php
- storage/app/auth-qa-seed.php
- storage/app/auth-qa-http.ps1
- storage/app/auth-qa-legacy.php (prepared, not executed)
- storage/app/auth-e2e-evidence/maik-auth-runtime-blocker.png

Existing authentication services, templates, controller, migration and other pre-existing dirty worktree files were preserved. No commit, push or deploy.

## 13. Test matrix

| Test ID | Scenario | Expected Result | Actual Result | Status | Evidence |
|---|---|---|---|---|---|
| ENV-01 | Framework/SDK/service configuration | Installed and resolves | Laravel 13.6.0, SDK 3.18.1, service resolves; token present | PASS | Sanitized config check |
| ENV-02 | Isolate test DB | Disposable non-production database | Guarded SQLite file / in-memory Pest | PASS | QA guard and migration |
| MAIL-01 | Build registration/reset messages | Branded multipart, inline logo, correct subject | SDK serialization assertions passed | PASS | AuthCodeEmailTest |
| MAIL-02 | Real direct service send | Provider accepts and inbox receives | No authorized recipient | BLOCKED | No provider message ID |
| MAIL-03 | Real inbox visual rendering/logs | Readable code and logo, delivered log | No received message | BLOCKED | No provider evidence |
| REG-01 | Account created only after proof | Verified regular account | Mocked feature test passed; pre-proof user absent | PASS | AccountAuthenticationTest; security regression |
| REG-02 | Incorrect/expired/reused codes | Rejected | Attempt lock, expiry and reuse assertions passed | PASS | AccountAuthenticationTest |
| REG-03 | Resend cooldown/replacement | Reject early resend, allow latest code | Mocked feature tests passed | PASS | AccountAuthenticationTest |
| REG-04 | Real invalid email | 422 | HTTP 422 | PASS | HTTP-01 |
| REG-05 | Real missing email | 422 | HTTP 422 | PASS | HTTP-02 |
| REG-06 | Real verification without challenge | 422 | HTTP 422 | PASS | HTTP-03 |
| REG-07 | Invalid registration/reset payload | 422 | Focused validation regression passed | PASS | AuthenticationSecurityRegressionTest |
| REG-08 | Existing/privileged registration/reset address behavior | Generic response, no unauthorized send | Mocked feature test passed | PASS | AccountAuthenticationTest |
| REG-09 | Cross-email/purpose proof | Reject wrong account/purpose | Scope and wrong-email/purpose assertions passed in mocked flow | PASS | Focused auth tests |
| REG-10 | Real positive registration via inbox | Challenge delivered and verified | No authorized recipient | BLOCKED | Live journey not run |
| REG-11 | Concurrent resend on MySQL | Consistent challenge/rate limit | Not exercised | NOT RUN | Row-lock inspection only |
| LOGIN-01 | Wrong password | 422 | HTTP 422 | PASS | HTTP-07 |
| LOGIN-02 | Unknown account | 422 | HTTP 422 | PASS | HTTP-08 |
| LOGIN-03 | Verified synthetic fixture | 200 and valid token | HTTP 200 and token accepted | PASS | HTTP-09 |
| LOGIN-04 | Protected profile | 200 | HTTP 200 | PASS | HTTP-10 |
| LOGIN-05 | Missing/invalid token | 401 | HTTP 401 for both | PASS | HTTP-05/06 |
| LOGIN-06 | Logout / revoked token | 200 then 401 | HTTP 200 then 401 | PASS | HTTP-11/12 |
| LOGIN-07 | Inactive user | Rejected | Focused test passed | PASS | LoginFcmTest |
| LOGIN-08 | Login throttle | Repeated attempts rejected | Focused regression passed | PASS | AuthenticationSecurityRegressionTest |
| LOGIN-09 | Invalid device input | Rejected without string coercion | Focused regression passed | PASS | AuthenticationSecurityRegressionTest |
| LOGIN-10 | New registration before proof | No authentication | Focused proof regression passed | PASS | AuthenticationSecurityRegressionTest |
| LOGIN-11 | Existing unverified legacy row | Verified-only access | Code review shows no verification timestamp check; actual command blocked by connector and awaiting user review | NOT RUN | AuthController::login; auth-qa-legacy.php |
| LOGIN-12 | Device binding and revocation | Existing policy enforced | Focused tests passed | PASS | LoginFcmTest |
| RESET-01 | Unknown email, actual API | Generic 200 | HTTP 200 | PASS | HTTP-04 |
| RESET-02 | Valid reset with mocked email proof | Hash changes; old password fails/new succeeds | Focused regression passed | PASS | AccountAuthenticationTest; security regression |
| RESET-03 | Reset token reuse/purpose mismatch | Reject | Focused mocked tests passed | PASS | AccountAuthenticationTest |
| RESET-04 | Invalid reset/confirmation | Reject | Focused validation tests passed | PASS | Security regression; mobile Jest |
| RESET-05 | Reset invalidates sessions, preserves device binding | Revoked tokens, unchanged binding | Focused tests passed | PASS | AccountAuthenticationTest; security regression |
| RESET-06 | Real inbox reset | Delivered email, real code, old/new login | No authorized recipient | BLOCKED | Live journey not run |
| RESET-07 | Concurrent reset / issue on MySQL | Single-use and consistent challenge | Not executed | NOT RUN | Requires disposable MySQL QA |
| REL-01 | Provider failure rollback | Generic 503 and no corrupt challenge | Mocked failure test passed | PASS | AccountAuthenticationTest |
| REL-02 | Provider timeout after acceptance | No unintended duplicates | Not executed | NOT RUN | Live provider reliability test |
| UI-01 | Launch actual app | Auth screens usable | Native RNCWebViewModule missing | FAIL | Metro runtime error |
| UI-02 | Rebuild native debug APK | Successful assembleDebug | NDK 27.1.12297006 incomplete; CXX1101 | FAIL | Gradle build output |
| UI-03 | Registration/reset component flow | Expected state transitions | Mocked component tests passed | PASS | account-flow.test.tsx |
| UI-04 | Resend countdown | Disabled for 60 seconds; enabled after | Jest regression passed | PASS | account-flow.test.tsx |
| UI-05 | Real UI registration/verification/reset | Complete via inbox | Native launch and recipient blockers | BLOCKED | UI-01/02, MAIL-02 |
| UI-06 | Visual/keyboard/persistence/appearance QA | Actual device checks | Native runtime prevents meaningful checks | BLOCKED | Runtime screenshot |
| AUTO-01 | Focused backend auth suite | All pass | 30 passed, 213 assertions | PASS | php artisan test --compact tests/Feature/Auth |
| AUTO-02 | Whole backend suite | All pass | 388 passed, 22 failed, 2313 assertions | FAIL | php artisan test --compact |
| AUTO-03 | Scoped Pint | No style errors | Nine authentication files pass | PASS | Pint scoped test |
| AUTO-04 | Full Pint | No style errors | 512 files, 58 style issues | FAIL | Pint --test --no-interaction |
| AUTO-05 | PHPStan | No errors | Binary not installed | BLOCKED | vendor/bin/phpstan absent |
| AUTO-06 | Mobile TypeScript | No errors | tsc --noEmit exit 0 | PASS | Local node_modules TypeScript |
| AUTO-07 | Full mobile Jest | All pass | 15 suites, 62 tests | PASS | npm test |
| AUTO-08 | ESLint | Configured lint passes | No installed ESLint or lint script | BLOCKED | package.json inspection |

Full backend failures were in CatalystSerialAndExtraCodeMatchTest (3), EcotradeProductImageImportCommandTest (14), FixWatermarkIssueImagesCommandTest (1), ItemDiscoveryApiTest (1), MobileApiTest (2), and UniqueSerialItemApiTest (1). The full run was initiated before the final targeted fixes; no clean baseline or final full rerun was performed. The focused auth suite after the fixes is the reliable scoped acceptance signal. No suppression or weakening of failing assertions.

## 14. Evidence locations

All paths relative to backend root unless otherwise specified:

- storage/app/auth-e2e-evidence/maik-auth-runtime-blocker.png — emulator capture, file confirmed (294,873 bytes). Native startup blocker, not successful authentication UI evidence.
- storage/app/auth-qa-http.ps1 — 12 real HTTP checks; successful run exit 0, 12/12 passed.
- storage/app/auth-qa-config-check.php — sanitized configuration diagnostics.
- storage/app/auth-qa-seed.php — guarded synthetic verified fixture.
- storage/app/auth-qa-legacy.php — guarded synthetic legacy test helper, execution pending connector review.
- storage/app/auth-e2e-qa-20261113.sqlite — disposable QA data; no production export.
- tests/Feature/Auth/AuthenticationSecurityRegressionTest.php — added regression evidence.
- Mobile src/features/auth/__tests__/account-flow.test.tsx — added cooldown regression evidence.

No real inbox screenshot, reusable code, real auth token, or live Mailtrap log is included.

## 15. Remaining blockers and next actions

1. Authorize one controlled receiving QA email address and ensure access to its inbox. Perform one direct Mailtrap service send, check provider logs and inbox, then use live registration and reset requests with actual inbox codes.
2. Repair exact Android NDK 27.1.12297006 and rebuild/install the current debug APK. Repeat actual emulator UI login, registration, email verification, reset, keyboard, small-screen, appearance and restart persistence tests.
3. Decide how existing active unverified accounts should be migrated/onboarded before enforcing universal verified-only authentication. Complete the synthetic legacy HTTP check and verify protected resource policy.
4. Resolve 22 full-suite failures and repository-wide formatting debt; rerun all backend checks. PHPStan and ESLint require installation/configuration if desired as release gates.
5. Run concurrent challenge issuance/consumption tests against a strictly isolated disposable MySQL schema and confirm provider timeout/ambiguous acceptance reliability.
6. Before deployment, apply the pending auth migration through the normal approved deployment workflow and verify role availability; never run a destructive migration against existing data.

## 16. Production readiness assessment

**NOT READY / BLOCKED.** Authentication logic has strong scoped automated coverage and the real local HTTP negative/login/token lifecycle checks pass. However real Mailtrap sending, email-receipt proof, complete native user journeys, legacy verified-only policy, concurrent MySQL acceptance, and full backend quality gates have not passed. No production deployment, push or commit is authorized or performed.

The QA responsibilities were handled directly through Remote Desktop Commander in coordinated backend, email and mobile workstreams; no independent subagents were spawned. This is a partial acceptance report, not a claim of complete E2E success.


