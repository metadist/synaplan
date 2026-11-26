# Findings: authentication and session (AREA = auth)

Repo commit a2e57b08d. All paths are relative to `backend/` unless they start with `frontend/`.
Test accounts created (all mine, none seeded): `audit-auth-a@example.test` (id 4917, verified),
`audit-auth-b@example.test` (id 4918, verified, password reset once, refresh tokens revoked),
`audit-auth-c@example.test` (id 4926, left unverified). No flags, seeded accounts or containers were
changed. Tokens, passwords and secrets are redacted throughout.

Summary table

| # | Title | Severity | Status |
|---|---|---|---|
| F1 | Apple Sign-In links an Apple identity to any existing account using an unsigned, client-supplied email | Critical (when Apple sign-in is enabled) | Confirmed by code trace; not reproduced (Apple not configured locally, one external hop) |
| F2 | Session access token renews itself through `GET /api/v1/auth/token`; logout, revoke-all and password reset do not end a stolen session | Medium (borders High) | Confirmed on the stack |
| F3 | Account pre-hijack: an unverified password signup survives when the real owner later proves the mailbox through social login, email link or code signup | Medium | Confirmed by code trace; first half reproduced, merge step needs a real IdP |
| F4 | No throttling on password login, forgot-password, reset-password, verify-email | Medium | Confirmed on the stack |
| F5 | Login CSRF: OAuth state not bound to the browser; `/auth/login` accepts non-JSON content types | Low | Confirmed by code trace |
| F6 | First-run setup wizard needs no proof of operator; first network client becomes admin | Low (Medium on an exposed fresh install) | Suspected (cannot test: stack has users) |

---

## 1. Entry points enumerated

Firewalls (`config/packages/security.yaml`): `openai_compat` (`^/v1`), `mcp` (`^/mcp`), `wopi` (security off),
`api` (`^/api`; authenticator order Cookie, OAuthBearer, OidcBearer, QueryToken, ApiKey), `main`.
78 `PUBLIC_ACCESS` rules, user checker `AccountStatusChecker`.

Public auth routes read: `/api/v1/auth/{login,register,refresh,logout,verify-email,forgot-password,reset-password,
resend-verification,native/exchange,providers}`, `/api/v1/auth/email-link/*`, code signup,
`/api/v1/auth/{google,github,apple,keycloak}/{login,callback}`, `/api/v1/auth/apple/native`,
`/api/v1/setup/{state,admin}`, `/api/v1/desktop/pair`.

Authenticated auth routes read: `/api/v1/auth/{me,token,revoke-all}`, `/api/v1/profile/password/initial`,
`/api/v1/user/verify-phone/*`, `/api/v1/desktop/pairing-codes`, admin impersonation start/stop/refresh.

Cross-cutting: `TokenService`, `AuthCookieFactory`, `OAuthStateService`, `NativeAuthHandoffService`,
`MediaAccessTokenService`, `JwtValidator`, `OidcTokenService`, `OidcUserService`, `AppleIdentityTokenVerifier`,
`OAuthSignupGate`, `OAuthLoginResponder`, `UserLifecycleService`, `ApiKeyScope(+Subscriber)`, `AccountAccess`,
subscribers `SetupLockdownSubscriber`, `PendingPaymentSubscriber`, `PasswordChangeRequiredSubscriber`,
`AdminPreviewListener`, `config/packages/rate_limiter.yaml`.

---

## 2. Findings

### F1. Apple Sign-In matches accounts on an unsigned client-supplied email (account takeover, including admin)

- Severity: Critical where Apple sign-in is configured (unauthenticated takeover of any non-organisation-managed
  account whose email is known). Not applicable to installs without Apple credentials.
- Status: Confirmed by complete code trace. Not reproduced: Apple sign-in is not configured on the local stack
  and reproducing needs Apple's servers (forbidden by the brief). One hop is external behaviour, stated below.

Where
- `src/Controller/AppleAuthController.php:111` reads the form field `user` from the callback request.
- `:146` `$profile = $this->parseUserField($userField);` and `:271-293` copy `email` out of that JSON with no check.
- `:323` `$email = $claims['email'] ?? $profile['email'];`
- `:327-330` `findByAppleSub($appleSub)`, else `findOneBy(['mail' => $email])`.
- `:333-336` only refusal is `isManagedExternally()`.
- `:~350` writes `apple_sub` of the caller onto the matched account, `:372` `setEmailVerified(true)`.
- Native endpoint: `:190-218` takes the profile (including `email`) from the JSON body and reaches the same
  `findOrCreateUser`.
- `src/Service/Auth/AppleIdentityTokenVerifier.php:78-86` returns `email: null` when the token carries no email
  claim; `emailVerified` is returned but never consulted for matching. No `nonce` is checked.
- `config/packages/security.yaml:161-163` all three Apple routes are public.

What
The `user` form field of Apple's form_post response (and the `email` field of the native JSON body) is plain
client input: it is not signed and passes through the user's browser. When the verified id_token has no `email`
claim, the controller falls back to that field and uses it to look up an existing account, then signs the caller in
as that account and permanently binds the caller's Apple `sub` to it.
Where the codebase does it right: `src/Service/OidcUserService.php:73-125` refuses to merge into an existing
account unless it already belongs to the same provider, and matches on claims from the validated token only.

Preconditions
- Apple sign-in enabled (`APPLE_CLIENT_ID` etc. set). Attacker has any Apple ID. Victim email known. Victim
  account not organisation-managed. No victim interaction.
- External hop (not verifiable here): Apple omits the `email` claim from the id_token when the authorization
  request did not ask for the `email` scope. The scope is a parameter of the authorize URL, which the attacker
  controls in their own browser (`:88` only sets the default the server sends).

Reproduction (steps to run on a test install with Apple configured; not executed)
1. `GET /api/v1/auth/apple/login`, keep the signed `state` from the redirect.
2. Open Apple's authorize URL with the same `client_id`, `redirect_uri`, `state`, but without `scope`. Sign in
   with the attacker's Apple ID.
3. Take the returned `code` and send
   `POST /api/v1/auth/apple/callback` with `code=<code>&state=<state>&user={"email":"admin@victim.example"}`.
4. Expected: 302 to `/auth/callback?success=true&provider=apple` with auth cookies for the victim account;
   `apple_sub` of the attacker now stored on it, so later Apple logins go straight in (`:327`).
Even if Apple did include `email`, the same code links to an existing account when `email_verified` is false
(`:323` does not look at it).

Impact
Full takeover of any password or social account by email, including admin, with a persistent backdoor (the linked
`apple_sub`). The victim's password keeps working, so nothing looks wrong.

CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:N = 9.1 (drops to AC:H 7.4 if the external hop turns out to need extra
conditions). CWE-290 (authentication bypass by spoofing), CWE-345.

Fix
- Never use `$profile['email']` for lookup. Use it at most as a display value for a brand-new account.
- Link to an existing account only when `$claims['email']` is present and `$claims['emailVerified']` is true;
  otherwise create a separate account or refuse with "sign in with your existing method and link Apple from settings".
- Send and verify a `nonce` bound to the state.
```php
$verifiedEmail = ($claims['emailVerified'] && null !== $claims['email']) ? $claims['email'] : null;
$user = $this->userRepository->findByAppleSub($appleSub);
if (!$user && null !== $verifiedEmail) {
    $user = $this->userRepository->findOneBy(['mail' => $verifiedEmail]);
}
```
- Guarding test: controller test with a stub verifier returning `email: null` and a `user` field naming an
  existing account; assert no session for that account and `apple_sub` unchanged. Same for the native endpoint
  and for `emailVerified: false`.

Detection
Today: an info line for a successful Apple login, indistinguishable from a normal one. No audit record that an
Apple identity was newly linked. Proposed: audit event "external identity linked to existing account" (provider,
sub hash, account id, source of the email), alert when the email did not come from a verified claim, and a
notification mail to the account owner on every new link.

Related occurrences (same root cause: linking by email without proof from the IdP)
- `src/Controller/GoogleAuthController.php:187` links by email without checking `verified_email` (it is only used
  at `:237-239`). Low/Suspected: Google returns unverified addresses only in edge cases.
- `src/Controller/GitHubAuthController.php:150-169` falls back to `$emails[0]['email']` when no primary verified
  address exists, then links at `:210`. Suspected/Medium: GitHub lets a user add an unverified address; whether
  the API ever returns only unverified entries for an account was not tested (needs GitHub).

---

### F2. Access token self-renewal defeats logout, revoke-all and password reset

- Severity: Medium (close to High: full account access that the victim cannot end).
- Status: Confirmed on the running stack with my own user 4918.

Where
- `src/Controller/AuthController.php:1157-1198` (`GET /api/v1/auth/token`): mints a new 5-minute access token for
  whatever principal is authenticated (`generateAccessToken($user, $impersonatorId, carriedMailboxGeneration($request))`).
- `src/Security/CookieTokenAuthenticator.php:58-63, 88-125, 221-242`: `/auth/token` is one of the auth paths the
  authenticator serves, and it accepts the access token from the cookie or a `Bearer` header.
- `src/Security/QueryTokenAuthenticator.php:33` `return $request->query->has('token');` on every `/api` route,
  validating the full session token (`:36-68`).
- `src/Service/Auth/TokenService.php:164-183` validation is signature + `exp` + user status only; `:307-330`
  `revokeAllUserTokens` deletes refresh tokens only. Access tokens carry no session id.
- Token in URLs: `frontend/src/services/apiService.ts:435` (`messages/stream?...&token=`),
  `frontend/src/services/api/usageApi.ts:172`.

What
The design assumes "a leaked access token is good for at most 5 minutes" (stated in
`src/Service/Auth/ImpersonationService.php:32-35`). But any valid access token can call `/auth/token` and get a
fresh one, forever, without the refresh token. Revoking refresh tokens therefore does not end the session.
Where the codebase does it right: refresh tokens are server-side rows that are checked and deleted
(`TokenService.php:234-273`), and API-key minting is tied to a mailbox generation counter (`mbg`).

Preconditions
One leaked access token: it is placed in query strings for SSE (proxy/CDN/access logs, browser history, Referer),
or stolen by XSS through `/auth/token` itself (the cookie is HttpOnly, but the endpoint hands the token to script).

Reproduction (observed)
1. Log in as user 4918, keep access token T0.
2. `POST /api/v1/auth/revoke-all` -> 200 `{"success":true,...,"sessions_revoked":1}`.
3. `POST /api/v1/auth/refresh` with the old refresh cookie -> 401 `INVALID_REFRESH_TOKEN` (revocation works).
4. `GET /api/v1/auth/token` with `Authorization: Bearer T0` -> 200 `{"token":"<T1>"}`, `exp` advanced, `mbg` 1.
5. `GET /api/v1/auth/token?token=<T1>` (no header, no cookie) -> 200 `{"token":"<T2>"}`.
6. Forgot-password + reset-password for the account (200), then `GET /auth/token` with T2 -> 200 new token.
7. 453 s after T0 was issued (TTL is 300 s) `GET /api/v1/auth/me` with the latest chained token -> 200.
Script: `SCRATCH/auth/renew.sh`; chain log `SCRATCH/auth/chain_b.txt`.

Further consequences (traced, not executed)
- Impersonation: `AuthController.php:1183-1187` keeps the `impersonator_id` only when the stash cookie is present.
  A copied impersonation access token used without that cookie yields a normal token for the target user: the
  impersonation outlives "stop", and later actions are no longer marked as impersonated.
- API keys: `/auth/token` does not check the credential type. An unrestricted API key (empty/legacy/`*` scopes,
  `src/Security/ApiKeyScope.php:410`) could trade itself for a session token and then reach the routes that are
  meant to be session-only (`ApiKeyScope.php:161`: `/api/v1/oauth/consent`, `/api/v1/cloud/watches/from-message`).
  Suspected: I could not create a key (`POST /api/v1/apikeys` -> 403 `API_PLAN_REQUIRED` for my free test user),
  and did not confirm that `ApiKeyAuthenticator::supports()` serves this path.

Impact
An attacker with one access token keeps full account access indefinitely. Logout, "sign out everywhere" and a
password reset all appear to succeed but do not help. Only suspending the account or rotating `APP_SECRET` stops it.

CVSS 3.1: AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:H/A:N = 7.4 (AC:H for obtaining the first token). CWE-613 (insufficient
session expiration), CWE-598 (credential in query string).

Fix
- Put a session id (`sid` = id of the refresh-token row) in the access token; `validateAccessToken` or at least
  `/auth/token` and `/auth/refresh` reject a token whose `sid` row is gone. Logout, revoke-all, password
  reset and suspension then cut the chain.
- `/auth/token` should return a purpose-limited stream token (`type: "sse"`, about 60 s, accepted only by
  `QueryTokenAuthenticator` and only on the SSE routes) instead of a full session token; it must refuse to mint
  from a `?token=` credential, from an API key and from an OAuth/OIDC bearer.
- `QueryTokenAuthenticator::supports()` should be limited to the SSE/stream routes.
- Guarding tests: (a) token issued before revoke-all gets 401 on `/auth/token` afterwards; (b) `?token=` on a
  non-stream route is ignored; (c) `/auth/token` with an API key returns 403; (d) impersonation token without
  stash cookie cannot obtain a clean token.

Detection
Today nothing: `/auth/token` logs nothing and no row changes. Proposed: log each mint with user id, credential
source (cookie/header/query), IP; alert on mints for a user with zero live refresh tokens, and on `token=` in
query strings of non-stream routes.

Related occurrences
`src/Security/ApiKeyAuthenticator.php` also accepts `?api_key=` in the URL (same log-leak class).
`src/Service/Media/MediaAccessTokenService.php` tokens (1800 s) ignore later account suspension.

---

### F3. Pre-hijack through an unverified password signup that is later "verified" by another door

- Severity: Medium (needs the attacker to register first and the victim to arrive later via social/link/code).
- Status: Confirmed by code trace. First half reproduced; the merge step needs a real Google/GitHub/Apple consent
  (third-party traffic, not allowed) or the pay-first flag (email link / code signup are off locally).

Where
- `src/Controller/AuthController.php:220-268` and `src/Service/Iam/UserLifecycleService.php:61-91`: anyone can
  create an unverified account with a password of their choice for any free email address.
- `src/Controller/GoogleAuthController.php:187` (match by email), `:237-239`
  `if (!$user->isEmailVerified() && verified_email) $user->setEmailVerified(true)`; password untouched.
- `src/Controller/GitHubAuthController.php:210`, `:278-280`; `src/Controller/AppleAuthController.php:329`, `:372`.
- `src/Service/Auth/EmailLinkSignIn.php` (consume) and `src/Service/Auth/CodeSignup.php:136` call
  `UserLifecycleService::markEmailVerified` (`:204-210`): revokes refresh tokens but keeps the password.

What
The attacker registers `victim@example.com` with their own password and never verifies. When the real owner later
signs in with Google (enabled locally per `/api/v1/auth/providers`) or another door, the existing row is reused and
flipped to verified. The attacker's password now logs in to the victim's live account.
Where the codebase does it right: `OidcUserService.php:73-125` refuses cross-provider merges; `createSignup`
never overwrites an existing account.

Preconditions: open registration; victim not yet registered; a social provider, email link or code signup enabled.

Reproduction (observed part)
1. `POST /api/v1/auth/register` as `audit-auth-c@example.test` with my password -> 200/201, account 4926 unverified.
2. `POST /api/v1/auth/login` with that password -> 403 `EMAIL_NOT_VERIFIED`; with a wrong one -> 401. So the
   password is stored and only the verified flag blocks login.
3. Missing step: owner signs in with Google for the same address -> `GoogleAuthController.php:237-239` sets the
   flag. After that step 2 would return 200 for the attacker.

Impact: takeover of accounts of users who join later via social login, including data they add afterwards.

CVSS 3.1: AV:N/AC:H/PR:N/UI:R/S:U/C:H/I:H/A:N = 6.8. CWE-1390 / CWE-287 (pre-hijacking by unverified merge).

Fix
When an external proof arrives for an account that is not yet verified: null the password, delete outstanding
verification/reset tokens and API keys, revoke refresh tokens, call `recordMailboxProof` (one helper in
`UserLifecycleService`, used by the three social controllers and by `markEmailVerified`). The owner can set a
password later through the existing initial-password route.
Guarding test: unverified user with password -> simulate Google callback for the same email -> password login
must return 401 and the old refresh token must be dead.

Detection: today none (normal social login log line). Proposed: audit event when a social login adopts an
unverified local account; periodic purge of unverified accounts older than N days.

Related occurrences: all three social controllers plus the two `markEmailVerified` callers listed above.

---

### F4. No throttling on password login and password-reset mail

- Severity: Medium. Status: Confirmed on the stack (against my own accounts only, small counts).

Where
- `src/Controller/AuthController.php:344-437` (login): no limiter, no lockout, CAPTCHA only when a provider is
  configured (`src/Service/Auth/SignupChallenge.php`, `TurnstileVerifier.php` return true when unconfigured).
- `:906-943` (forgot-password): no limiter, mail sent synchronously.
- `:947-989` (reset-password) and `:860-902` (verify-email): no limiter.
- `config/packages/rate_limiter.yaml`: limiters exist for setup, signup, checkout, email link, code signup, OAuth
  client auth, but none for these four.
Where the codebase does it right: `EmailLinkController` / `CodeSignup` use `email_link_*` and `code_signup_*`
limiters per IP and per address; `SetupController` uses `setup_attempt`.

Reproduction (observed)
- 40 consecutive wrong passwords for user 4917 -> 40 x 401, then the right password -> 200. No delay, no 429.
- 8 x `POST /api/v1/auth/forgot-password` for user 4918 -> 8 mails in Mailhog, 8 live `password_reset` rows in
  `BVERIFICATION_TOKENS`; using one (reset 200, reuse 400) leaves the other 7 valid until expiry.

Impact
Online password guessing and credential stuffing at full speed against any account (reset policy is only length
>= 8); mail bombing of arbitrary users through the instance's sender reputation; response-time difference on
forgot-password reveals whether an address exists (synchronous send).

CVSS 3.1: AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:L/A:N = 6.5. CWE-307, CWE-799.

Fix
Add `login_ip` and `login_account` limiters (for example 10/min per IP, 5 failures per 15 min per account with
back-off), `password_reset_ip` and `password_reset_email` (3 per hour per address), a limiter on reset-password
and verify-email; on a successful reset delete all other reset tokens of the user; queue the mail.
Guarding test: functional test that the Nth failed login returns 429 and that a second reset token is dead after
one was used.

Detection: failed logins are logged individually but nothing aggregates them. Proposed: counter per account and
per IP with an alert threshold; the limiter's 429s give the signal for free.

Related occurrences: `/api/v1/auth/resend-verification` (has its own cooldown service; not load-tested).

---

### F5. Login CSRF (OAuth state not tied to the browser; JSON login accepts any content type)

- Severity: Low. Status: Confirmed by code trace (not executed in a browser).

Where
- `src/Service/OAuth/OAuthStateService.php:43-52, 65-96`: state is a stateless HMAC token checked for signature,
  provider and 600 s age only; nothing links it to the browser that started the flow. Used by the Google, GitHub,
  Apple and Keycloak callbacks.
- `src/Controller/AuthController.php:344+`: body is `json_decode`d whatever the `Content-Type`, so a cross-site
  HTML form with `enctype="text/plain"` can submit valid credentials.
Where the codebase does it right: `src/Controller/EmailLinkController.php:~118-128` insists on a JSON content
type for exactly this reason.

What / impact
An attacker can make a victim's browser complete a login into the attacker's account (callback URL with the
attacker's own `code` and `state`, or an auto-submitting form). The victim then works inside the attacker's
account and may upload documents or connect integrations there. Production cookies are `SameSite=Strict`
(`src/Service/Auth/AuthCookieFactory.php:53`), which limits sending cookies but not this (the cookie is being set).

CVSS 3.1: AV:N/AC:L/PR:N/UI:R/S:U/C:L/I:L/A:N = 5.4 nominal; rated Low because the victim must then enter data.
CWE-352.

Fix: set a short-lived HttpOnly cookie with the state nonce at `/login` and compare it in the callback; require
`Content-Type: application/json` on `/auth/login`, `/auth/register`, `/auth/refresh`.
Test: callback with a valid state but without the matching cookie -> error redirect; login with `text/plain` -> 415.

Detection: none today; with the fix, log state/cookie mismatches.

Related: Keycloak additionally stores the PKCE `code_verifier` in clear inside the state that travels through the
browser and the IdP (`src/Controller/KeycloakAuthController.php:73-108`), which removes PKCE's protection against
an attacker who sees the authorization response. Keep the verifier server-side or in an HttpOnly cookie.

---

### F6. First-run setup has no operator proof

- Severity: Low (Medium for an install reachable from the internet before the wizard is finished).
- Status: Suspected; cannot be exercised because the stack already has users.

Where: `src/Controller/SetupController.php` (createFirstAdmin: `setup_attempt` limiter, lock
`first-run-setup-admin`, recheck under lock), `src/Service/Setup/SetupStateService.php:83-98`,
`src/EventSubscriber/SetupLockdownSubscriber.php:55-64`.

What: while the wizard is enabled, not completed and the user table is empty, any client that reaches
`POST /api/v1/setup/admin` becomes the admin. The race is handled correctly; the missing piece is a secret.
Mitigations present: window closes with the first user; installer path can use `BOOTSTRAP_ADMIN`.

CVSS 3.1: AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:H/A:H = 8.1 inside the window; rated Low/Medium because the window is
short and the install is empty. CWE-306.

Fix: one-time setup token generated at first boot, printed to the container log (or `SETUP_TOKEN` env), required
by `/setup/admin`. Test: request without the token on a virgin database -> 403.
Detection: the first-admin creation is logged; add the client IP and send it to the operator mail if configured.

---

## 3. Ruled out (examined and found sound)

- JWT `alg` confusion / `none` / HMAC-with-public-key: allow-list RS256/384/512, ES256/384/512 only
  (`src/Service/Auth/JwtValidator.php:41-48`). Apple tokens: firebase JWT with JWKS, `iss` and `aud` enforced
  (`AppleIdentityTokenVerifier.php:64-76`).
- OIDC/Keycloak merging into another provider's account by email: refused
  (`src/Service/OidcUserService.php:73-125`).
- App access token forgery: HMAC-SHA256 over the payload, `hash_equals` compare
  (`src/Service/Auth/TokenService.php:409-458`); running stack does not use the committed placeholder secret
  (compared without printing).
- Signing oracle across token types: `OAuthStateService` and `NativeAuthHandoffService` share the raw
  `APP_SECRET` with access tokens, but every signed payload has fixed keys (`native, plan, code, human, language,
  owner_id, pkce_*, scopes, server_id, return_to, nonce, provider, created_at`) and `validateAccessToken`
  requires `type=access`, `user_id`, `exp`; `OAuthSignupGate.php:65-91` whitelists the extras. No endpoint signs
  attacker-shaped JSON. (Still listed under hardening.)
- Suspended/deleted/pending accounts: `src/Security/AccountStatusChecker.php:34-37`, `TokenService.php:251,
  465-477`, `src/Security/ApiKeyAuthenticator.php:130-138`, `OAuthLoginResponder.php:42-47`;
  `PendingPaymentSubscriber` (priority 7) covers API routes.
- Authenticator fall-through: a failed Cookie/Bearer credential does not fall through to a weaker one in a way
  that grants access; restricted API keys are default-deny (`src/Security/ApiKeyScope.php:388-412`), session-only
  prefixes are blocked for all keys (`:161, :273`). Exception noted in F2 (token exchange).
- CAPTCHA when configured: fails closed (`TurnstileVerifier.php`, `RecaptchaService.php`).
- Client IP spoofing for limiters: `src/Service/Http/ClientAddress.php` trusts CF/XFF only from trusted proxies.
- Setup re-entry after users exist: `SetupStateService.php:83-98`, lock + recheck in `SetupController`;
  lockdown covers `/api`, `/mcp`, `/v1`.
- Impersonation authorisation and audit: admin check, target checks, audit log
  (`src/Service/Auth/ImpersonationService.php:95-145`), admin restored only from the stashed refresh token
  (`:461-471`). `AdminPreviewListener` is an unrelated preview gate.
- Must-change-password lock: `PasswordChangeRequiredSubscriber.php:46-52` allow-list only.
- Token entropy and single use: `bin2hex(random_bytes(32))` (`VerificationTokenRepository`, `TokenRepository`);
  reset token reuse -> 400; refresh after revoke -> 401 (both observed).
- Native handoff: 120 s, purpose-bound, single-use nonce (`NativeAuthHandoffService.php`).
- Desktop pairing: 8 chars from a 30-symbol alphabet (about 6.5e11), 10 min, single use, creation capped at 5
  outstanding / 20 per hour, `/pair` rate limited (`src/Service/Desktop/PairingCodeService.php`,
  `src/Controller/DesktopController.php:150-157`).
- Cookie flags: HttpOnly always, Secure derived from `APP_URL` scheme or override, `SameSite=Strict` in prod and
  `Lax` in dev (`src/Service/Auth/AuthCookieFactory.php:44-70`); matches the comment in `security.yaml`.
- OAuth redirect targets: callbacks redirect only to the configured `frontendUrl` or the fixed deep-link scheme
  (`OAuthLoginResponder.php:56-107`); no user-controlled redirect found. `redirect_uri` sent to IdPs is built from
  `appUrl`, not from the request.
- Phone verification: reverse flow (user sends the code from WhatsApp), 5 min, attempt counter
  (`src/Controller/PhoneVerificationController.php:265-291`).
- Initial password: refused when a password exists or the account is organisation-managed
  (`src/Controller/InitialPasswordController.php`).

## 4. Hardening ideas

- Derive separate HMAC keys per purpose (access token, OAuth state, native handoff) with HKDF, as
  `MediaAccessTokenService` already does.
- Rotate refresh tokens on use; store refresh, verification, reset tokens and API keys hashed (login-link tokens
  already are).
- `JwtValidator.php:137` accepts tokens without `exp`, and `:161` skips `aud` when no expected audience is
  configured: make both mandatory.
- Revoke or flag API keys on password reset; make media tokens check account status.
- Make the native handoff nonce check atomic (cache `getItem`/`save` race).
- Refuse to boot in `prod` with the placeholder `APP_SECRET` from `.env.example` / `_docker/backend/entrypoint.sh`.
- Stronger password policy on reset (currently length >= 8) and constant-time/queued forgot-password responses.
- Notify the account owner by mail on: new external identity linked, password changed, revoke-all.
- Add `nonce` to Apple and Google flows.

## 5. Coverage gaps

- F1 and the merge step of F3 were not executed: they need real Apple/Google/GitHub consent screens (third-party
  traffic is out of bounds) and Apple is not configured locally. The Apple claim behaviour for a request without
  the `email` scope is from documentation knowledge, not observed.
- API-key-to-session exchange (F2 sub-item) not executed: key creation is plan-gated for my test user and I did
  not change plans or flags.
- Email-link sign-in and code signup are off unless pay-first is active; reviewed in code only.
- F6 cannot be exercised on a populated database.
- Not reviewed in depth: `CheckoutClaimController`, `VerificationResendService` internals, OIDC discovery
  controller and `OidcAccessPolicy` claim rules, `OAuthBearerAuthenticator` token issuance side (OAuth server
  endpoints belong to another area), `openai_compat` and `mcp` firewalls beyond authenticator order, WOPI
  (firewall off; assumed covered by another reviewer), frontend token handling beyond the two `?token=` call sites.
- No browser-level test of login CSRF; no timing measurements for enumeration.
