> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 2: Administration, Security & Billing**
> **See also:** [Configuration](CONFIGURATION.md#access-policy) · [Day-2 operations](ADMIN.md) · [Development](DEVELOPMENT.md)

# Who can create an account, and who can sign in

> **In this guide:** One page for the four ways people run Synaplan. Each profile has a block you can copy. The tables under it are the rules those blocks rely on: which door a new account comes through, which switch closes that door, what an account status allows, and why a level stays when billing changes.

A switch closes the **creation** of an account. It never closes a sign-in for an account that already exists.

---

## Pick a profile

Copy one block into the environment of that install. Leave a line out rather than inventing a value. Secrets stay in your secret store; the blocks below use placeholders.

### Developer — a clone on your machine

`git clone` and `make up`. The database is seeded, the register form is open, and MailHog catches mail. Keycloak is optional (`--profile oidc`).

This block describes that clone. Do not paste it onto a host that is reachable from the internet.

```dotenv
REGISTRATION_ENABLED=true
REGISTRATION_MODE=open
# No TURNSTILE_* and no RECAPTCHA_*: the human check stays off.
# docker-compose.yml raises SIGNUP_IP_LIMIT and SIGNUP_GLOBAL_LIMIT
# so local tests are not the public limit of 5 an hour.
```

Seeded sign-ins are listed in [Development](DEVELOPMENT.md). The CI overlay `docker-compose.minimal.yml` together with `backend/.env.minimal` only blanks optional modules for a test lane. It is not the install file `docker-compose-minimal.yml`.

### Operator — a self-hosted install people can reach

First administrator from the wizard at `/setup`, or from `BOOTSTRAP_ADMIN_*` so that window never opens. Local accounts, and optionally OIDC or Google, GitHub and Apple.

Behind an ingress or Cloudflare, the address Synaplan sees is the proxy unless you name that proxy. Until you do, every visitor shares one address and the per-address sign-up limit applies to the whole install.

```dotenv
REGISTRATION_ENABLED=true
REGISTRATION_MODE=open
SIGNUP_IP_LIMIT=5
SIGNUP_GLOBAL_LIMIT=20
# The proxy that actually connects to Synaplan. A private range you do not
# run, or the whole internet, lets a client choose the address that is limited.
SYMFONY_TRUSTED_PROXIES=10.0.0.8
SYMFONY_TRUSTED_HEADERS=x-forwarded-for
TURNSTILE_SITE_KEY=<widget site key>
TURNSTILE_SECRET_KEY=<widget secret>
```

`CF-Connecting-IP` counts only when the connecting hop is a Cloudflare address you listed in `SYMFONY_TRUSTED_PROXIES`. A header forwarded by anything else is ignored, so a visitor cannot pick their own address.

For a walk that the limit is per address: set `SIGNUP_IP_LIMIT=2`, sign up twice from one address, and the third attempt within the hour is refused. A sign-up from a second address still succeeds while `SIGNUP_GLOBAL_LIMIT` has room.

An install that should only accept the identity provider uses the integrator block instead, and sets `SETUP_WIZARD_ENABLED=false`.

### Integrator — SSO, desktop, Nextcloud, add-ins

People who already have an account sign in with OIDC or use an API key an administrator created. New accounts do not come from the register form or from Google, GitHub or Apple.

```dotenv
SETUP_WIZARD_ENABLED=false
REGISTRATION_ENABLED=false
GUEST_CHAT_ENABLED=false
OIDC_DISCOVERY_URL=https://idp.example.com/realms/main/.well-known/openid-configuration
OIDC_CLIENT_ID=synaplan
OIDC_CLIENT_SECRET=<client secret>
OIDC_AUTO_REDIRECT=true
# true: the first OIDC sign-in creates the account.
# false: only an account that OIDC already created here can sign in.
# An account created in People or by the admin API is never taken over
# by an OIDC sign-in that happens to use the same email address.
OIDC_ALLOW_USER_PROVISIONING=true
```

`OIDC_ALLOW_USER_PROVISIONING` is the only switch that stops the directory. `REGISTRATION_ENABLED` and `REGISTRATION_MODE` do not apply to it. Organization, role and permission rules are in the public guide [SSO access restrictions](https://docs.synaplan.com/sso-access).

Nextcloud uses accounts an administrator provisions, plus API keys. Desktop, Outlook and OpenCloud talk to an account that already exists. None of them calls the register form.

### Hosted platform — open sign-up with a check, or admission

Email, Google, GitHub and Apple, a human check, and per-address limits. `REGISTRATION_MODE=admission` means a new account needs a paid plan or an invitation code from People → Invitation codes. A plan waits as `pending_payment` until the first invoice is paid. A code grants that code's tier and the account is active.

Do not turn admission on until those codes, or Stripe, exist. With neither, a new person has no door. People who already have an account still sign in.

```dotenv
REGISTRATION_MODE=admission
SIGNUP_IP_LIMIT=5
SIGNUP_GLOBAL_LIMIT=20
SYMFONY_TRUSTED_PROXIES=<the edge that connects to Synaplan>
SYMFONY_TRUSTED_HEADERS=x-forwarded-for
TURNSTILE_SITE_KEY=<widget site key>
TURNSTILE_SECRET_KEY=<widget secret>
```

---

## Four kinds of door

Every new account comes through exactly one kind of door.

| Kind | Doors | Closed by | Never closed by |
| --- | --- | --- | --- |
| Self-sign-up | Email form, Google, GitHub, Apple on the web and in the app, integration setup wizards | `REGISTRATION_ENABLED=false`, `SIGNUP_PAUSED=true`, `REGISTRATION_MODE`, the human check, the sign-up limits | — |
| Directory | First OIDC sign-in in the browser, OIDC Bearer and token exchange | `OIDC_ALLOW_USER_PROVISIONING=false`, and `OIDC_REQUIRED_ROLE`, `OIDC_ORG_CODE`, the permissions claim when you set them | Registration switches, admission, the human check, sign-up limits |
| Operator | First-run wizard, `BOOTSTRAP_ADMIN_*`, People → Users, `POST /api/v1/admin/users` | The admin role, the setup window, the instance credentials | Registration switches, admission |
| Channel identity | Guest trial, inbound email, WhatsApp, Telegram | That channel's own flag and limit | Registration switches, admission |

Guest trials and channel identities stay `ANONYMOUS`. They are not a level grant and they are not a self-sign-up.

---

## Which switch wins

The first matching row wins. An environment value beats the switch in Admin → System configuration, which beats the default.

| Switch | Effect |
| --- | --- |
| `REGISTRATION_ENABLED=false` | No new account through any self-sign-up door. The register page says new accounts cannot be created and offers sign-in. `/login` keeps every configured sign-in method. A social callback for an address with no account answers `SIGNUP_CLOSED` and creates nothing. |
| `SIGNUP_REQUIRE_CLOUDFLARE=true` | A new self-sign-up is refused (`SIGNUP_EDGE_REQUIRED`, 403) unless the contiguous `SYMFONY_TRUSTED_PROXIES` suffix includes a published Cloudflare edge. The public default is off. An existing account still signs in. Checkout webhooks, directory provisioning and accounts an operator creates are not affected. |
| `SIGNUP_PAUSED=true` | The same close, temporary. The answer is `SIGNUP_PAUSED` (503). The runtime config shows it, so the form does not invite a person who will be refused. |
| `REGISTRATION_MODE=open` | Self-sign-up doors are open. The account is `NEW` and active. This is the default. |
| `REGISTRATION_MODE=admission` | A new self-sign-up needs a plan or a code. A plan stays `pending_payment`. A code is active at the code's tier. |
| Human check and limits | Apply to every open self-sign-up door, counted on the real client address. |

`/api/v1/config/runtime` reports `registrationEnabled`, `registrationMode`, `signupPaused` and which human check is in front.

The `SIGNUP_CLOSED` sentence, for an address that has no account, is: "There is no account for this address here, and new accounts cannot be created. Sign in with the account you already have, or ask your administrator."

---

## What an account status allows

| Status | Password, refresh, social, OIDC | API key, MCP, Desktop, add-in, widget, inbound channel | What the API allows |
| --- | --- | --- | --- |
| `active` | Yes | Yes | The account's normal API |
| `pending_payment` | Yes, so the person can reach checkout | No | `/api/v1/auth`, `/api/v1/subscription`, `/api/v1/profile`, `GET /api/v1/config/runtime`, and leaving an impersonation |
| `suspended`, `banned` | No | No | Nothing. An existing token stops working |

---

## Where a level comes from

The level on the account is the highest grant that is still in place. From lowest to highest: `NEW`, `PRO`, `TEAM`, `BUSINESS`, `ADMIN`. People → Users shows each grant and removes only the administrator's grant, from the same row. A lower choice snaps back, and the row says which grant is higher.

| Source | Written when | Taken back when |
| --- | --- | --- |
| `stripe` | A settled invoice. A plan change may set a lower tier than before. | The subscription is deleted, or a sync finds no active subscription |
| `iap` | An app-store entitlement is active or in grace | That entitlement lapses |
| `code` | An invitation code is redeemed | Not automatically |
| `admin` | An administrator sets a level in People → Users or `POST /api/v1/admin/users`, including the same tier again | An administrator removes that grant |
| `directory` | An OIDC sign-in carries an admin role | That role leaves the claims |
| `bootstrap` | `BOOTSTRAP_ADMIN_*`, the first-run wizard, or `app:admin:reset-password --promote` | An administrator removes it |

A billing event removes only the grant that channel wrote. A settled Pro invoice does not lower an administrator's Business. Cancelling Stripe after an administrator set Team again leaves Team. A lapsed app-store purchase, or a lost OIDC admin role, leaves a tier Stripe still grants. `ADMIN` is not changed by a billing event.

Accounts that existed before grants were introduced have no rows yet. `php bin/console app:iam:level-grants` prints what it would write and changes nothing. `--apply` writes. Run it twice and the second run changes nothing. Review the dry run before you apply it, and do not apply it on production without a backup you can restore.

---

## When it does the opposite of what you expected

| What you see | What is going on |
| --- | --- |
| Registration is off, and a first OIDC sign-in still creates an account | The directory follows `OIDC_ALLOW_USER_PROVISIONING`, not `REGISTRATION_ENABLED`. |
| Google says there is no account, and the person already has one | The social door found no account for that address, or sign-up is closed. An existing account signs in with the method that created it. Nothing new was stored. |
| Every visitor hits the sign-up limit together | `SYMFONY_TRUSTED_PROXIES` does not name the ingress, so every request shares the proxy's address. |
| You rotated `TOKEN_SECRET` and sessions did not change | Session cookies are signed with `APP_SECRET`. `TOKEN_SECRET` is not read. Realtime tokens use `REALTIME_TOKEN_SECRET`. |
| You set `CORS_ALLOW_ORIGIN` and other sites can still call the API | That variable is not read. The API allows any browser origin. The session cookie is HttpOnly; do not treat the unused variable as a lock. |
| Both Turnstile and reCAPTCHA are set | Turnstile runs and reCAPTCHA is ignored. With Turnstile keys set, an unreachable Cloudflare refuses the attempt. |
| You removed the OIDC admin role and the account is still Pro | The directory grant is gone. A Stripe grant that is still in place keeps Pro. The level is the highest grant that remains, not `NEW`. |
| The only administrator is gone | The wizard does not come back. `php bin/console app:admin:reset-password --promote` creates or promotes a local administrator. |

---

## Variables that look like switches and are not

| Variable | What to use instead |
| --- | --- |
| `TOKEN_SECRET` | `APP_SECRET` for session cookies. `REALTIME_TOKEN_SECRET` for realtime. Leave `TOKEN_SECRET` unset. A generated value in an older install is unused. |
| `CORS_ALLOW_ORIGIN` | Nothing. It is not read. |
| `RECAPTCHA_*` while `TURNSTILE_SECRET_KEY` is set | Turnstile. reCAPTCHA runs only when Turnstile is not configured. |
