# IAM consolidation — one identity model after the sign-up changes

Between 2026-10-24 and 2026-10-25, eight commits changed how accounts are
created and who may sign in (#2714, #2716, #2740, #2743, open #2744). They
were written under attack and each one is correct on its own. Together they
left the identity surface without one owner: some doors got the new gates
and some did not, some fixes were never committed, and nothing tests the
switches against each other.

This plan does not add a feature. It answers one question for every way a
person gets into Synaplan: **who can create an account here, who can sign
in, and which switch controls it**, and it writes that answer down as
tests, one creation path, one page of docs, and one admin overview. The
four audiences must not break each other:

| Profile | Who | How they get in today |
| ------- | --- | --------------------- |
| **Developer** | Anyone who runs `git clone` and `make up` | Seeded database logins (`admin@`, `demo@`, `test@synaplan.com`), open register form, MailHog; optional Keycloak via `--profile oidc` |
| **Operator** | Self-hoster, Kubernetes admin (`synaplan-charts`) | First-run wizard or `BOOTSTRAP_ADMIN_*`, local accounts, optional OIDC, optional Google/GitHub/Apple |
| **Integrator** | Enterprise SSO, Nextcloud, OpenCloud, Desktop, Outlook add-in | OIDC browser login and Bearer tokens (token exchange), admin-provisioned accounts with API keys, pairing codes |
| **Hosted platform** | web.synaplan.com (`synaplan-platform`) | Email + Google/GitHub/Apple, Turnstile, sign-up limits, Stripe, soon `REGISTRATION_MODE=admission` |

Plan of record for the admission sign-up itself stays in
[`synaplan-platform/planning/20261025-admission-signup/`](https://github.com/metadist/synaplan-platform/blob/main/planning/20261025-admission-signup/00_master_plan.md)
(row 28). This track does not redo AS1–AS6; it closes what that track
declared out of scope, and it orders itself around it (§7).

## 0. Security first

A security fix in an integration route (IC0) and three platform items
(PC1–PC3) are tracked in the private `synaplan-platform` repository,
`planning/20261025-iam-security-note/`, because this repository is public
and they were open on 2026-10-25. IC0 goes out before anything else in this
plan. The details move here only after the fix is deployed on the hosted
platform and released for self-hosters.

## 1. Findings (checked 2026-10-25 against `origin/main` and #2744)

| # | Prio | Finding | Evidence |
| - | ---- | ------- | -------- |
| F1 | **P0** | Security issue in an integration route | private note (IC0) |
| F2 | **P0** | The hosted per-address sign-up limit needs an edge fix | private note (PC1) |
| F3 | P1 | A platform sign-up switch has no effect | private note (PC2) |
| F4 | P1 | `REGISTRATION_ENABLED=false` closes only the email form. Google, GitHub and Apple (web and native) still create accounts. The admission plan's D1 says the switch "wins over both modes" — the code does not do that | `AuthController.php:230` is the only `isEnabled()` call; OAuth controllers create at `GoogleAuthController.php:206`, `GitHubAuthController.php:235`, `AppleAuthController.php:329`; `docs/CONFIGURATION.md:25` documents it as email/password only |
| F5 | P1 | Five account-creation paths outside `UserLifecycleService`, each with its own defaults (level, verified flag, details, model defaults): Google, GitHub, Apple, an integration wizard, OIDC, plus channel identities | the controllers above; `OidcUserService`, `EmailChatService`, `GuestSessionService` |
| F6 | P1 | No level write records where the level came from, and five writers lower a level without asking: `StripeEntitlement::apply()` sets the plan's tier even when it is lower (an admin-granted Business becomes Pro on a settled Pro invoice, although its docblock says it never lowers); `/subscription/sync` with no active subscription and `subscription.deleted` set `NEW`; an in-app purchase that lapses sets `NEW`; losing the OIDC admin role sets `NEW` and drops a paid tier with it. A same-tier admin grant cannot be told apart from Stripe's, and existing subscriptions carry no owner at all. Once codes ship, a code-granted tier is lost when that person merely opens a checkout | `StripeEntitlement.php:264-268`, `SubscriptionController.php:812`, `StripeWebhookController.php:494`, `MobilePurchaseService.php:191`, `OidcUserService.php:155`; other writers `AdminController.php:351`, `BootstrapAdminService.php:82`, `AdminResetPasswordCommand.php:152`, `UserLifecycleService.php:66`. The `NEW` downgrades pre-date #2714 |
| F7 | P1 | `pending_payment` is new and every entry point decides on its own (`canSignIn()` vs `isActive()` vs `PendingPaymentSubscriber`). #2743 already had to fix two missed edges (inbound mail, impersonation exit). No test enumerates entry points × status | `User.php:184-186`, `AccountStatusChecker.php`, `ApiKeyAuthenticator.php:116-120`, `PendingPaymentSubscriber.php:29-34` |
| F8 | P1 | Kubernetes: the chart exposes neither `SYMFONY_TRUSTED_PROXIES` nor the new variables. Behind an ingress every visitor has the ingress address, so the default 5 sign-ups per hour applies to the whole company | `synaplan-charts/charts/synaplan/values.yaml:198-201` (only `registration`, `guestChat`); `SignupGuard.php:18-21` |
| F9 | P1 | Open #2744 bundles AS2 (backend gate) and AS4 (page), has no Vitest or E2E for the admission step, and AS3 (the admin page that creates codes) does not exist. Flipping `admission` before AS3 leaves the code door with no way to issue a code | #2744 file list; issues #2734–#2736 open |
| F10 | P2 | Two client-IP rules: sign-up uses `Request::getClientIp()`, guest sessions prefer `CF-Connecting-IP` | `AuthController.php:239-243`; `GuestSessionService` |
| F11 | P2 | `signupPaused` is not in `/api/v1/config/runtime`: a visitor fills the form and learns afterwards | `AuthRuntimeConfig.php:58-72` |
| F12 | P2 | No page explains the identity model. `REGISTRATION_MODE`, Turnstile, sign-up limits, `OIDC_ALLOW_USER_PROVISIONING` and `pending_payment` are documented only in `.env.example` / `ADMIN.md` fragments; `CONFIGURATION.md` Access Policy (`:174`) still knows two switches | docs inventory |
| F13 | P2 | Dead or misleading variables: `TOKEN_SECRET` (tokens use `APP_SECRET`), `CORS_ALLOW_ORIGIN` (`nelmio_cors` is `*`), reCAPTCHA keys kept beside Turnstile keys although Turnstile wins | `.env.example`, `deploy/compose.yaml` |
| F14 | P2 | Untested: password reset end to end, OIDC first login with registration off or in admission mode, Turnstile switched on, sign-up limits behind a proxy, social callbacks, `UserLifecycleService::createSignup` | coverage matrix in §5 |
| F15 | owner | Platform secret handling | private note (PC3) |

## 2. What is fine — do not "fix" it

- **Developer clone.** Turnstile and reCAPTCHA are off without keys (`SignupChallenge.php:47-53`); `docker-compose.yml:426-427` raises the sign-up limits to 100000; seeded users are written by `UserFixtures` and stay `active`; `REGISTRATION_MODE` defaults to `open`. `registration.spec.ts`, `guest-registration.spec.ts`, `auth.spec.ts` are green in CI.
- **Enterprise OIDC.** First login provisions through `OidcUserService` under `OIDC_ALLOW_USER_PROVISIONING` and the OIDC access policy, independent of `REGISTRATION_*` and admission. This is intended (`docs/CONFIGURATION.md` SSO-only section) and covered by five `@oidc` specs including token exchange and auto-redirect.
- **Nextcloud, OpenCloud, Desktop, Outlook add-in.** None calls register or a social callback. Nextcloud uses admin-provisioned accounts and API keys (`POST /api/v1/admin/users`, platform links); OpenCloud uses Keycloak token exchange; Desktop and Synamail mint scoped keys for an existing, active account.
- **Existing accounts.** `OAuthSignupGate` returns early for an existing account (`:146-148`); sign-in is unchanged in both modes. Every switch in §3.2 keeps it that way: a switch closes the creation of an account, never a sign-in.

## 3. Target model

### 3.1 Four kinds of door

Every code path that creates a `BUSER` row belongs to exactly one kind. An
architecture test (T1) fails when a new path appears unclassified.

| Kind | Doors | Governed by | Never governed by |
| ---- | ----- | ----------- | ----------------- |
| **Self-sign-up** | email form, Google, GitHub, Apple web, Apple native, integration setup wizards | `REGISTRATION_ENABLED`, `REGISTRATION_MODE`, `SIGNUP_PAUSED`, human check, sign-up limits | — |
| **Directory** | OIDC browser first login, OIDC Bearer / token exchange | `OIDC_ALLOW_USER_PROVISIONING`, `OIDC_REQUIRED_ROLE`, `OIDC_ORG_CODE`, permissions claim | registration switches, admission, human check, sign-up limits |
| **Operator** | first-run wizard, `BOOTSTRAP_ADMIN_*`, `POST /api/v1/admin/users` (Nextcloud provision), fixtures | admin role, setup window, instance credentials | registration switches, admission |
| **Channel identity** | guest trial, inbound email, WhatsApp, Telegram (`ANONYMOUS`) | channel flags, channel limits | registration switches, admission |

Every self-sign-up door creates through `UserLifecycleService::createSignup(SignupDoor $door, …)`.
One place sets level, status, verified flag, details and model defaults.

### 3.2 One precedence for the switches

```text
REGISTRATION_ENABLED=false  → no new account through any self-sign-up door (email, social, integration wizards);
                              existing accounts still sign in through the same doors. Directory/operator/channel unaffected.
SIGNUP_PAUSED=true          → same, temporary, answer SIGNUP_PAUSED (503), visible in runtime config
REGISTRATION_MODE=open      → doors open, level NEW, active                                   (public default)
REGISTRATION_MODE=admission → doors require plan|code (AS2); plan → pending_payment, code → code tier, active
human check + limits        → apply to every open self-sign-up door, keyed on the real client address
```

Env pins, BCONFIG `ACCESS.*` is the admin fallback, code default last —
unchanged from `RegistrationConfig`. `/api/v1/config/runtime` exposes
`registrationEnabled`, `registrationMode`, `signupPaused` and the human-check
provider. `/login` keeps every configured sign-in method in every state and
drops only the register link when sign-up is closed; `/register` shows the
closed state (one sentence, one "Sign in" action) instead of a form that
will refuse. A social callback for an address with no account answers
`SIGNUP_CLOSED` with the sentence "There is no account for this address
here, and new accounts cannot be created. Sign in with the account you
already have, or ask your administrator." — nothing was created.

### 3.3 One rule per account status

| Status | Password / refresh / social / OIDC sign-in | API key, MCP, Desktop, add-in, widget owner, inbound channel | Allowed API |
| ------ | ------------------------------------------ | ------------------------------------------------------------- | ----------- |
| `active` | yes | yes | all |
| `pending_payment` | yes (to reach checkout) | **no** | `/auth*`, `/subscription*`, `/profile*`, `GET /config/runtime`, impersonation exit |
| `suspended`, `banned` | no | no | none |

T3 holds this table as one data-provider test over every authenticator and entry point.

### 3.4 A level is the highest of its grants

Every reason an account has a level is a **grant** with a source:

| Source | Granted by | Taken back by |
| ------ | ---------- | ------------- |
| `stripe` | `StripeEntitlement` on a settled invoice — the plan's tier, which may be lower than before (a plan switch) | `subscription.deleted`, sync with no active subscription |
| `iap` | `MobilePurchaseService` on an active or grace entitlement | the entitlement lapsing |
| `code` | sign-up code redemption (row 28) — the code's tier | never automatically (row 28 §9.3 may add an expiry) |
| `admin` | People → Users, `POST /api/v1/admin/users` — **every** assignment, the same tier included | the administrator removing it |
| `directory` | OIDC admin role → `ADMIN` | the role disappearing from the claims |
| `bootstrap` | `BOOTSTRAP_ADMIN_*`, the first-run wizard, `app:admin:reset-password` → `ADMIN` | an administrator |

`BUSER.BUSERLEVEL` stays the one value every reader uses (rate limits,
features, `isAdmin()`); it is recomputed as the highest live grant, `NEW`
when none is left, in the same transaction as the grant change. One
service owns that write; `setUserLevel()` outside it is an architecture
test failure (T7). A channel only replaces or removes **its own** grant, so:
a settled Pro invoice never lowers an admin-granted Business; a Stripe
cancellation after an administrator re-granted the same Team leaves Team;
a lapsed in-app purchase or a lost admin role leaves the paid tier that
Stripe still grants; channel identities (`ANONYMOUS`) and the WhatsApp
verification step (`ANONYMOUS → NEW`) stay outside the model.

**Existing accounts have no grants (legacy).** They are reconstructed once,
on deploy, by `app:iam:level-grants` (dry run by default, `--apply` writes),
and lazily with the same rule on the first grant change of an account the
command has not reached:

1. A Stripe subscription recorded in `paymentDetails.subscription` with status `active` → a `stripe` grant at its `plan` (recorded since #2714) or, when absent, at the price's tier; same for an IAP record → `iap` grant.
2. The current level higher than every grant from step 1, or no billing record at all → an `admin` grant at the current level: "granted outside billing, never removed by a billing event".
3. An `admin.user_level_change` audit entry dated after the subscription started → an `admin` grant at the level it set, even when it equals the Stripe tier (the same-tier case).
4. `ADMIN` → a `bootstrap` grant, or `directory` when `oidc_roles` carries an admin role.
5. Anything else equal to the Stripe tier → Stripe owns it and a cancellation takes it back.

The dry run lists every account whose reconstruction is not "rule 1 only",
with its email, current level and the grants it would get; the owner
reviews that list on the platform before `--apply`. Running it twice
changes nothing.

## 4. Decisions (ticked by the owner before the step that needs it)

- [ ] **D1 (IC2).** `REGISTRATION_ENABLED=false` closes every self-sign-up door, social and integration wizards included. Matches admission D1. *Alternative:* keep social open and document it — rejected: "registration off" that still creates accounts is the confusion this plan removes. An operator who wants SSO only already uses OIDC (directory kind, unaffected).
- [ ] **D2 (IC2, IC6).** Directory provisioning stays independent of `REGISTRATION_*` and admission; `OIDC_ALLOW_USER_PROVISIONING=false` is the only switch that stops it. Documented and tested, not changed.
- [ ] **D3 (IC0).** In the private note.
- [ ] **D4 (PC2).** In the private note.
- [ ] **D5 (PC3).** In the private note.
- [ ] **D6 (IC5).** Grants live in a table `BUSER_LEVEL_GRANTS` (`BUSERID`, `BSOURCE`, `BLEVEL`, `BREF` — subscription, purchase, code or audit id — `BCREATED`, `BCREATEDBY`, unique per account and source), migration with raw idempotent `addSql` (Galera rule), deleted through the erasure path. *Alternative:* a `grants` map inside `BPAYMENTDETAILS` — no migration, but a webhook and an administrator writing the same JSON blob can lose each other's update, and the admin view cannot list or audit it.
- [ ] **D10 (IC5, IC5b).** What an administrator sets on People → Users is an `admin` grant, not an override: setting Pro on a paying Business customer leaves Business, and the editor says why ("This account also has Business through Stripe; the level shown is the highest."). Removing the admin grant is a separate action on the same row. *Alternative:* an override that pins the level regardless of billing — rejected: it brings back the lost-tier bugs of F6 in reverse (a paying customer stuck below what they pay for).
- [ ] **D7 (IC7).** A "Who can create an account here" card on Admin → System Configuration → Authentication (read-only, one row per door with state and source, link to the switch). In or out of this track.
- [ ] **D8 (IC8).** Turnstile-on E2E with Cloudflare's published test keys (needs `challenges.cloudflare.com` from CI) as a nightly project, or PHPUnit + Vitest only.
- [ ] **D9 (row 28).** #2744 lands as one pull request with the missing Vitest and the `registration-admission.spec.ts` E2E added before merge (recommended — it is open and reviewed), or is split into AS2 and AS4. Either way `REGISTRATION_MODE=admission` is not flipped before AS3 is live.

## 5. Test plan

### 5.1 Coverage today (✓ present, ~ partial, ✗ missing)

| Path | E2E | PHPUnit | Vitest | Docs |
| ---- | --- | ------- | ------ | ---- |
| Password login, logout, refresh | ✓ | ✓ | ✓ | ✓ |
| Register + verify mail | ✓ | ✓ | ✗ `RegisterView` | ~ |
| Password reset | ✗ | ~ | ✓ | ✗ |
| Google / GitHub / Apple callback | ✗ (no IdP in CI) | ~ gate only | ✓ composables | ~ |
| OIDC button, redirect, token exchange, admin role | ✓ `@oidc` | ✓ | ✗ | ✓ |
| OIDC × registration off / admission | ✗ | ✗ | — | ~ |
| Turnstile on | ✗ | ✓ | ✓ | ✓ |
| Sign-up limits behind a proxy | ✗ | ✗ | — | ~ |
| Admission (plan, code, pending) | ✗ | ~ config, codes, gate | ✗ | ✗ |
| `pending_payment` per entry point | ✗ | ~ cookie + a few routes | ✗ | ✗ |
| Stripe downgrade vs ADMIN / granted level | ✗ | ✗ | — | ~ |

### 5.2 New tests (each lands in the step that needs it)

| Id | Layer | What it proves | Step |
| -- | ----- | -------------- | ---- |
| T1 | PHPUnit architecture | Every class that persists a new `User` is listed with its door kind (§3.1); every `PUBLIC_ACCESS` route that reaches one is listed. Unlisted → red. Generalises AS2's `RegistrationModeCoverageTest` | IC3 |
| T2 | PHPUnit data provider | Self-sign-up doors (email, Google, GitHub, Apple web, Apple native, integration wizard) × {enabled=false, paused, open, admission without / with plan / with code, limit hit, human check on without token} → exact outcome and error code for a **new** address; the same matrix for an **existing** account → signs in every time. Directory doors × the same switches → unaffected | IC2, IC3 |
| T3 | PHPUnit data provider | Account status (§3.3) × entry point (password, refresh, each social callback, OIDC browser, OIDC Bearer, API key, MCP, desktop pair, add-in connect, platform-link exchange, widget owner, inbound mail, impersonation start and exit) → allowed / refused + code | IC4 |
| T4 | PHPUnit | Client address: behind a trusted proxy, sign-ups forwarded for address A exhaust A's per-address allowance and the next one from A is refused, while a sign-up forwarded for address B succeeds with the global window still open; from an untrusted hop the forwarded header is ignored. Sign-up and guest sessions use the same resolver | IC1 |
| T5 | PHPUnit sequences | Grants (§3.4), each sequence through webhook **and** sync: (a) admin Business, settled Stripe Pro → Business; cancel → Business. (b) Stripe Team, administrator sets Team again, cancel → Team. (c) Stripe Team, plan switch to Pro → Pro. (d) code Team, checkout opened and abandoned, sync → Team. (e) Stripe Pro, OIDC admin role granted then removed → Pro. (f) admin Team, IAP Pro lapses → Team. (g) `ADMIN` unchanged by every billing event. (h) Legacy, no grants: Stripe Pro only → cancel → `NEW`; level Business with Stripe Pro → Business after cancel; an audit-log same-tier admin change after the subscription start → kept after cancel; the command run twice is a no-op | IC5 |
| T6 | PHPUnit | IC0's security tests, listed in the private note | IC0 |
| T7 | PHPUnit architecture | `setUserLevel()` is called only by the grant service; every other writer goes through a grant | IC5 |
| E1 | E2E `@ci` | Password reset: forgot → MailHog link → new password → sign in | IC8 |
| E2 | E2E `@oidc` | SSO-only: registration off via the admin config endpoint in `beforeAll`, login page shows the IdP button and no register link, first OIDC login provisions, restore in `afterAll` (no CI file change) | IC8 |
| E3 | E2E `@ci` + Vitest | Registration off via the admin config endpoint: `/register` shows the closed state with its sign-in action, `/login` keeps the password form and has no register link, a seeded account still signs in. Vitest: `LoginView` with providers configured keeps every social button when registration is off or paused; `RegisterView` shows the closed state; `OAuthCallback` shows the `SIGNUP_CLOSED` sentence (CI has no social provider, so the buttons are proven in Vitest) | IC2 |
| E4 | E2E nightly | Turnstile on with Cloudflare test keys (D8) | IC8 |
| E5 | E2E `@ci` | Admission J-AS-1 / J-AS-2 (`registration-admission.spec.ts`) — owned by row 28, listed so it is not forgotten (D9) | #2744 |
| X1 | `synaplan-nextcloud` | `make test-contract` against the release that carries IC2–IC5 | after IC5 |
| X2 | `synaplan-charts` | `make all`; `helm template` with an SSO-only and a public values file | IC7 |

### 5.3 Journeys (browser walks, recorded in `STATUS.md`)

| Id | Journey | Profile |
| -- | ------- | ------- |
| J-IAM-1 | Fresh `git clone`, `make up`, sign in as `demo@synaplan.com`, register a new account, confirm in MailHog, sign in. Nothing asks for a key | Developer |
| J-IAM-2 | `--profile oidc`, registration off in Admin → Authentication: `/login` offers the company login and the password form, no register link; `ada` signs in through the company login and lands in the chat; `demo@synaplan.com` still signs in with its password; `/register` says sign-up is closed and offers "Sign in" | Integrator |
| J-IAM-3 | An operator reads `docs/IDENTITY.md` and copies the "public install behind an ingress" block, with `SIGNUP_IP_LIMIT=2` for the walk. From address A the third sign-up within the hour gets the limit sentence; right after, a sign-up from address B succeeds while the global allowance still has room | Operator |
| J-IAM-4 | Admin opens Admin → Authentication and reads in ten seconds which doors are open, where each switch is pinned, and turns one off from the same row (D7) | Operator, hosted |
| J-IAM-5 | Hosted: from one real address the sixth sign-up within the hour gets the limit sentence; right after, a visitor from a second address signs up while the global allowance still has room | Hosted |

## 6. Steps

One step is one pull request from `main`, following
[`AGENTS.md`](../AGENTS.md) of this directory. IC = this repository,
PC = `synaplan-platform`, plus one step in `synaplan-charts`.

| Step | Commit subject | Release class | Depends on | Gate |
| ---- | -------------- | ------------- | ---------- | ---- |
| IC0 | security fix — subject and scope in the private note | backend-only | D3 | `make ci-local`; developed in a temporary private fork from a draft security advisory |
| IC1 | `fix(auth): one client address for sign-up limits, guest sessions and tokens` — one resolver, T4 | backend-only | — | `make ci-local` |
| PC1 | edge fix — private note | platform | IC1 deployed | private note |
| PC2 | platform switch — private note | platform | D4 | `docker compose config` |
| IC2 | `fix(auth): registration off stops new accounts at every self-sign-up door, never a sign-in` — `RegistrationConfig` and the pause checked in `OAuthSignupGate` for a **new** account only (existing accounts return early as today) and in the integration wizard; `SIGNUP_CLOSED` with the §3.2 sentence; `signupPaused` in runtime config; `/login` keeps every configured sign-in method and drops only the register link; `/register` shows the closed state; T2, E3 | backend-only + ota-candidate (`LoginView`, `RegisterView`, `OAuthCallback`; classify with `scripts/mobile-impact.mjs`, `components/auth/**` is store-required) | D1, D2, IC0 | `make ci-local && make test-e2e` |
| IC3 | `refactor(auth): one account-creation path for every self-sign-up door` — Google, GitHub, Apple and the integration wizard through `UserLifecycleService::createSignup(SignupDoor …)`, finishing what #2744 started with `completeNewUser`; T1 | backend-only | IC2, #2744 merged | `make ci-local` (size ratchet: no growth) |
| IC4 | `test(auth): every entry point against every account status` — T3 and the fixes it finds, each named in the PR | backend-only | AS1 (#2740) | `make ci-local` |
| IC5 | `fix(iam): an account's level is the highest of its grants, and billing only takes back its own` — `BUSER_LEVEL_GRANTS` (D6) and one grant service; Stripe (`apply`, sync, `subscription.deleted`), IAP, sign-up codes, People → Users, admin provisioning, OIDC role sync, bootstrap and the reset command write grants instead of levels; `app:iam:level-grants` for the legacy rows (§3.4); T5, T7 | backend-only | D6, D10 | `make ci-local`; on the platform: dry run reviewed by the owner, then `--apply`, then the image |
| IC5b | `feat(admin): show where an account's level comes from` — People → Users lists each grant (source, level, since, reference) and removes an admin grant from the same row with the sentence "The admin grant of Team is removed. The account keeps Business through Stripe." | ota-candidate | IC5, D10 | `make ci-local && make test-e2e` |
| IC6 | `docs(auth): one identity guide for developers, operators and integrators` — new `docs/IDENTITY.md` (the four profiles of the intro with a copy-paste env block each, §3 tables, troubleshooting), `CONFIGURATION.md` Access Policy and quick reference, `.env.example` grouped, `.env.minimal` and `docker-compose.minimal.yml` names, dead variables of F13 removed or marked; `synaplan-docs` `sso-access.md` / `hosting.md` link to it | no-app-impact | IC2, IC5 | `make ci-local`; `make lint-docs` in `synaplan-docs` |
| CH1 | `feat(synaplan): expose sign-up, human check and trusted proxies` in `synaplan-charts` — `REGISTRATION_MODE`, `SIGNUP_*`, `TURNSTILE_*` (secret through the chart's existing secret pattern), social client ids, `SYMFONY_TRUSTED_PROXIES` with the ingress note; README template points to `docs/IDENTITY.md`; X2 | — (chart) | IC6 | `make all` |
| IC7 | `feat(admin): show who can create an account here` — read-only door card on Authentication (D7), each row: door, open/closed, pinned by env or set by admin, the switch | ota-candidate | D7, IC2 | `make ci-local && make test-e2e`; J-IAM-4 walked |
| IC8 | `test(e2e): password reset and the SSO-only login page` — E1, E2 (E4 if D8) | no-app-impact | IC2 | `make test-e2e`, `npm run test:e2e:oidc` |
| PC3 | platform item — private note | platform | D5 | owner |

IC7 carries the five exit bullets of `docs/UX_CONTRACT.md` §6 before its
first `.vue`: J-IAM-4 walked; the card found in ten seconds from Admin →
Authentication; consequence copy per door ("New people can no longer sign
up with Google. People who already have an account still sign in.") in
five locales; empty, error and env-pinned states; dark, V2 and 320 px.
IC2 carries the same bullets for the login and register pages (J-IAM-2),
IC5b for People → Users: the level's source answered on the row (U7 "where
did it come from"), removal from the same row with its consequence sentence
(U3), the no-grant and billing-only states, five locales, dark, V2, 320 px.

## 7. Order and the hacking fix

```text
now          IC0 (unless the hacking fix already contains it) → IC1 → PC1, PC2
row 28       #2744 (D9) → AS3 → AS5/AS6 flip — the flip waits for PC1 and AS3
this track   IC2 → IC3 → IC4 ∥ IC5 → IC5b → IC6 → CH1 → IC7, IC8 → PC3
```

IC0, IC1 and PC1 are security and stability; they go before or with the
deployment. IC2 and IC3 touch the same files as #2744 and start after it
merges, never in parallel. The admission flip (row 28, AS6) waits for PC1.

## 8. Risks

| Risk | Mitigation |
| ---- | ---------- |
| IC2 stops new Google accounts on an install that relied on them with `REGISTRATION_ENABLED=false` | D1 is the owner's call; existing Google accounts keep signing in; the release note names the change; IC7 makes the state visible; the switch back is one env value |
| IC0 needs a client update | private note, D3 |
| IC3 changes defaults for social accounts (model defaults, details) | T1 + T2 assert the created row field by field before and after; characterization snapshot of a created row per door |
| IC5 stops a legitimate downgrade | T5 (c) and (h) keep "plan switch Team → Pro" and "cancel the only Stripe grant → `NEW`" green |
| The legacy reconstruction gives a free tier to someone who should lose it, or takes one from someone who should keep it | Dry run first, reviewed by the owner on production data; rules 2 and 3 only ever keep a tier, rule 5 is the only one that lets billing take it back; the list is kept in the platform STATUS |
| A webhook and an administrator change grants of one account at the same moment | One row per source (D6) and the level recomputed inside the transaction that writes the grant; T5 runs the interleaving |
| PC1 trusts too much and lets a client forge its address | Trust exactly the edge address and Cloudflare ranges; `X-Forwarded-For` is set by the edge, never appended from the client; T4 covers the untrusted hop |
| The plans collide with row 28 | §7 order; IC2/IC3 rebase on #2744; no file of AS3 is touched here |

## 9. Non-goals

- New identity providers, SAML, SCIM.
- Changing OIDC provisioning, directory groups or the IAM sharing model (archived track [`2026-archive/20260903_iam/`](../2026-archive/20260903_iam/)).
- The admission feature itself (row 28).
- Multi-tenant identity.

## 10. Change log

| Date | Change |
| ---- | ------ |
| 2026-10-25 | Plan written from an audit of #2714, #2716, #2740, #2743 and open #2744 against every identity path and the consumers in `synaplan-charts`, `synaplan-platform`, `synaplan-nextcloud`, `synaplan-opencloud`, `synaplan-desktop`, `Synamail`, `synaplan-apps`, `synaplan-docs`. No code changed. Security details kept in the private `synaplan-platform` note. |
| 2026-10-25 | Copilot review round 1 on #2745: §3.4 becomes the grant model (all level writers, same-tier admin grants, legacy reconstruction), D6 a table, new D10, IC5b and T7; switches close account creation, never sign-in (§3.2, IC2, E3, J-IAM-2); per-address isolation proven by exhausting one address (T4, J-IAM-3, J-IAM-5). |
