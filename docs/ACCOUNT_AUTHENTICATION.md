# Maik Cat account authentication

## Configure Mailtrap

Install the SDK if dependencies have not yet been installed:

```sh
composer install
# For a fresh integration:
composer require railsware/mailtrap-php symfony/http-client nyholm/psr7
```

Set these server-only environment variables in the backend .env:
```dotenv
MAILTRAP_API_TOKEN=your_actual_sending_api_token
MAILTRAP_FROM_ADDRESS=auth@your_verified_domain
MAILTRAP_FROM_NAME="Maik Cat"
```

Use an actual Email Sending API token from Mailtrap, not a Sandbox SMTP password. Verify the sender domain in Mailtrap and set the address to an authorized sender on that domain. The demo address hello@demomailtrap.co and sample token are not production credentials. Never put the token in the mobile app or source control.

After configuring the environment, run `php artisan config:clear` and review the pending migration `php artisan migrate:status`. Apply the migration in the intended environment with `php artisan migrate` (production: normal approved deployment). Ensure the existing app_user role has been created by RolesAndPermissionsSeeder.

Authentication email requests send directly through Mailtrap after saving the challenge. No authentication queue, worker, or cron is required. The provider call is bounded to 15 seconds; failures are logged without secrets, invalidate the generation, and retain the eligibility-neutral response. Users may request a new code after the existing cooldown. Other background jobs are unchanged.

Mailtrap delivery logs: https://mailtrap.io/sending/email_logs

## Branded authentication emails

`AuthCodeEmailFactory` builds both verification and password-reset messages from the shared `resources/views/emails/auth/code.blade.php` and `code-text.blade.php` templates. The HTML uses the mobile app palette (#FAF8F6, #FFFFFF, #C55000, #A84400, #FFEDE1, #E8DCCF, #1E1A17, #6A625C), responsive email tables and inline styles. The optimized PNG at `resources/images/maik-cat-email-logo.png` is derived from the mobile app logo and embedded as a CID attachment, so a public image URL is not required. The Mailtrap sender transports both HTML and plain text. Codes remain selectable text; no external fonts, scripts or forms are required. Older clients may display square corners or suppress images; the code and instructions remain readable.

Local browser previews generated with a synthetic code are under `storage/app/auth-email-previews/registration.html` and `storage/app/auth-email-previews/password_reset.html`. These are QA files, not public routes. For real client rendering, use the Mailtrap delivery logs after sending a test email with configured credentials.

## Mobile API contract

All routes are under /api/auth. JSON requests, Accept: application/json.

| Method | Endpoint | Payload |
| --- | --- | --- |
| POST | /register | email |
| POST | /resend-verification | email |
| POST | /verify-email | email, code (six-digit string), name, password, passwordConfirmation |
| POST | /forgot-password | email |
| POST | /reset-password | email, code, password, passwordConfirmation |

Snake case password_confirmation is also accepted by the API middleware. Request/resend returns 200 with a generic message. Verify returns 201 and creates the account only after successful proof of email ownership; reset returns 200. Neither returns an auth token. Sign in through the existing login API after completion.

Invalid/expired/used codes return 422. Resend cooldown is 60 seconds, maximum 5 requests per hour per email/purpose, maximum 5 incorrect attempts per code; codes expire after 10 minutes. Auth code endpoints also have a per-IP throttle. Provider failures are recorded server-side, invalidate the failed code, and return the same generic acknowledgment to avoid disclosing account eligibility. Codes are keyed HMAC digests using APP_KEY, never stored as plaintext. Reset is restricted to active app accounts, revokes API tokens and preserves existing authorized-device binding. Existing active mobile accounts without verified email must complete the forgot-password email-code flow before login or protected API access. Their previous tokens cannot bypass this check. Administrative roles are exempt from the mobile verification policy, and logout remains available. Wallet pricing, balances and paid-search entitlement rules are unchanged. Email changes via mobile profile are deliberately blocked until a separate verified-email-change flow exists. Import and manual metals refresh routes require the existing manage_items permission.

## QA

Feature tests use SQLite in memory and a mocked AuthEmailSender; no real email is sent. For live delivery, configure Mailtrap, run the local backend and mobile app against that backend, register with an inbox you control, verify the received code, sign in, then repeat password recovery. Check the Mailtrap logs and test expired/wrong codes.

No live migration or production mail delivery is required for automated QA. The follow-up local QA used guarded SQLite and disposable MySQL databases, an isolated loopback HTTP server, and a synthetic-only inbox capturing actual rendered email templates. See `AUTH_EMAIL_E2E_QA_FOLLOWUP_2026-11-13.md` for execution evidence. A successful local inbox test does not establish Mailtrap delivery; that requires an explicitly authorized real recipient.
