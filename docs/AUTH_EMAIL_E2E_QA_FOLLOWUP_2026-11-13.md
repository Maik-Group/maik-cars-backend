# Maik Cat authentication QA — follow-up
Date: 2026-11-13
Scope: local Laravel backend, React Native/Expo Android application, isolated QA data.
Status: backend regressions resolved; local HTTP E2E and MySQL concurrency passed; Android build/render verified; native credential-entry and external Mailtrap delivery remain unverified.

## Summary
- Full backend: 417 passed, 2460 assertions, 611.85 s, exit 0. All 22 previous failures resolved.
- Pint: 514 files passed, exit 0. Repository-wide formatting applied; existing worktree changes retained.
- Authentication: 32 passed, 231 assertions.
- Mobile: 15 suites, 62 tests passed; TypeScript noEmit exit 0.
- Image import/watermark targeted regression: 18 passed, 113 assertions.
- Local HTTP registration/reset lifecycle: 17 checks passed.
- MySQL concurrency: 4 paired HTTP cases and final database integrity passed.
- Android: clean debug build succeeded, 502 tasks; current JavaScript bundle 3679 modules; login, register and reset request screens rendered on isolated emulator-5556.

## Implemented fixes
1. Require verified email for legacy active mobile accounts at login and protected APIs; return EMAIL_VERIFICATION_REQUIRED. Existing unverified tokens cannot bypass verification. Logout remains accessible, administrative roles retain separate policy, and account recovery verifies ownership. Preserve authorized-device binding and revoke prior tokens on reset.
2. Mobile routes unverified login/session users to password recovery, with correct initial email and sign-out behavior. Correct forgot-password labels across en/ar/bg/de/hr. Preserve resend cooldown and request lock.
3. Align stale catalog fixtures with canonical JAPAN/OPEL grouping and current import rules. Replace obsolete public-price assertions with paid-search valuation assertions; preserve credit entitlement and pricing arithmetic.
4. Make CSV escape behavior explicit for PHP 8.4 in image-link and watermark commands; update obsolete Gemini test fixture to the actual image-only request contract.
5. Apply PHP formatting across repository; do not conflate format-only changes with behavior changes.

## Real execution evidence
SQLite local router: 127.0.0.1:8766, exact disposable database and loopback guard, sentinel mail credential, synthetic @example.test recipients only. Uses actual AuthCodeEmailFactory and captures HTML and text in storage/app/auth-e2e-evidence/local-inbox. Actual HTTP checks: registration 200; pre-verification login 422; immediate resend 429; wrong verification 422; valid verification 201; reuse 422; login 200; profile 200; forgot password 200; wrong reset 422; valid reset 200; old token 401; old password 422; new login 200; profile 200; logout 200; revoked token 401. All passed.
MySQL: maik_auth_qa_20261113, fresh migrations/roles, guarded independent Laravel server processes on 8767/8768; concurrent request/verify/forgot/reset pairs respectively [200,429], [201,422], [200,429], [200,422]. Exactly one verified user, correct new password hash, both challenges consumed. Exit 0. No production data touched.

## Android
Exact NDK 27.1.12297006 remained incomplete; SDK Manager download failed. Local ignored QA Gradle init script android/.gradle/maik-qa-ndk.init.gradle selects installed NDK 28.2.13676358 without changing pinned project NDK. Actual offline x86_64 assembleDebug successful, 502 tasks; APK installed. Original missing WebView native module resolved. Fresh Maik_Cat_Auth_Isolated_API35 (emulator-5556) installed APK and loaded current Metro bundle at 8093 with loopback API at 8766. UI hierarchy verified login, registration and forgot-password request screens; registration and reset reached through app deep links after inconsistent ADB taps during first-boot System UI stall. Screenshots in storage/app/auth-e2e-evidence: native-login-clean.png, native-register-clean.png, native-reset-clean.png.
Full native credential entry, submit, verify, reset and login sequence NOT completed: synthetic credential-entry script awaiting Desktop Commander review. Existing original emulator displayed user-entered real account information; it was not submitted or used. Do not describe UI rendering as full native end-to-end success.

## Remaining release gates
- Explicitly authorize a receiving mailbox and verify actual Mailtrap Email Sending delivery and code receipt; local captured messages do not establish provider delivery. Do not use a real address without authorization.
- Approve the Desktop Commander synthetic native test script and rerun full native flow on isolated emulator. Verify button interactions, registration, code entry, recovery and login.
- Restore/install the exact pinned NDK for reproducible standard build, or separately validate and approve upgrading the pinned version. QA-only override is not a production toolchain change.
- PHPStan and ESLint are not configured/installed in the inspected projects; not counted as passed.
- Review changes, including broad Pint changes, before any commit/deploy. No commit, push, production deployment, or production database changes performed.

## Local QA helpers
storage/app/auth-qa-router.php
storage/app/auth-e2e-http-capture.php
storage/app/auth-qa-mysql-router.php
storage/app/auth-qa-mysql-race.php
storage/app/auth-e2e-evidence/
