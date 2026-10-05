# App accounts — a standard path for apps that bring their own customers

| | |
| - | - |
| **Status** | Plan, 2026-10-04. No product code yet. First consumer: **SISmass** (care documentation for German home-care services, `metadist/SISmass-web`, plan of record `docs/planning/v1.0-plan.md` M4). |
| **Branch** | `feat/app-accounts` (one PR per step, AA1 … AA8) |
| **User-facing name** | **Apps** (Operate → Apps) and **Signed up via** (People list). Code: `App\Module\Commerce\AppAccountsModule`, `App\Service\AppAccounts\*`. The word **Partner** is taken by Federation (`20260929-synaplan-federation/05_partners.md`) and must not appear here. |
| **Binding contracts** | UX rules U1–U12 ([`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md)), AGENTS.md "Perfect UX & Stability", Galera migration rules, API-key scope enforcement ([`backend/src/Security/ApiKeyScope.php`](../../../backend/src/Security/ApiKeyScope.php)). |
| **Feature flag** | `APP_ACCOUNTS_ENABLED` (env, default off). Flag off ⇒ routes 404, no nav child, no column (U11). |

---

## 0. The pitch

> A nurse who runs a small home-care service signs up at SISmass with Google,
> records three clients by dictation, likes it, clicks **PRO**, pays at Stripe
> and gets the invoice by mail. She never sees Synaplan. Behind the scenes
> SISmass created a Synaplan account for her care service, got a key that
> worked immediately with a fixed token allowance, and the purchase moved that
> same account onto the standard PRO plan. The Synaplan operator sees her
> account under **Signed up via: SISmass**, next to every other SISmass
> customer, and can pause the whole app with one switch.

An **app** is an external product that creates Synaplan accounts for its own
customers and lets them buy a Synaplan plan without a Synaplan login.
SISmass is the first; any later vertical product uses the same path.

---

## 1. What exists today, and why it is not enough

| Building block | Where | Gap for apps |
| -------------- | ----- | ------------ |
| Admin provisioning (`POST /api/v1/admin/users`, `…/{id}/api-keys`, `…/{id}/usage`) | `AdminUserProvisioningController`, `AdminUserProvisioningService` | Needs an **admin** key. A compromised app server would hold full admin power over the instance. The app can see and touch every user, not only its own. |
| External identity (`BEXTERNALIDENTITIES`, `source` + `external_id`) | `ExternalIdentity` | Good, reused. Nothing ties an identity to a registered, pausable app. |
| Scoped keys | `ApiKeyScope` (desktop, add-in, platform-link vocabularies) | No scope for "AI only" that is not `desktop:*` (that one changes model behaviour via `isPairedDesktop()`), and no scope for "manage my app's accounts". |
| New-account limits | `RateLimitConfigSeeder`: `RATELIMITS_NEW.MESSAGES_TOTAL = 50`, `TRANSCRIPTION_TOTAL = 10` (lifetime) | Counted in **calls**. One SISmass intake makes dozens of extraction calls, so a free care service would stop in the middle of its first client. There is no lifetime **token** allowance. |
| Monthly cost budget per plan + top-ups | `RateLimitService::checkCostBudget()`, `BUSER_TOPUPS` | **Not enforced on `/v1/chat/completions`** — `OpenAICompatibleController::chatCompletions()` only calls `checkLimit('MESSAGES')`; `MessagesGateway` and `StreamController` do check the budget. Affects every paying user of the OpenAI-compatible API today, not only apps. |
| Stripe checkout and portal | `SubscriptionController` (1 198 lines) | Session-only; `success_url` / `cancel_url` always point at `FRONTEND_URL`; Stripe customer email is the Synaplan login email. An app needs its own return address and the customer's real contact email. |
| Admin user list | `AdminController` (`providerId` only) | No "where did this account come from" column or filter; Nextcloud, Outlook and future apps are indistinguishable from `external`. |

---

## 2. Design

### 2.1 Registered app — `BAPPS`

One row per app, created by an operator.

| Column | Meaning |
| ------ | ------- |
| `BID`, `BSLUG` (unique, e.g. `sismass`), `BNAME` | Identity. The slug is the `ExternalIdentity.source` suffix (`app:sismass`). |
| `BSTATUS` | `active` / `paused`. Paused ⇒ provisioning returns 503 with code `app_paused`, every account key of the app gets 403 `app_paused`. |
| `BOWNERUSERID` | Technical owner of the app credential (see 2.2). |
| `BRETURNORIGINS` (JSON) | Allowed origins for checkout / portal return URLs, e.g. `["https://app.sismass.de"]`. Exact origin match, https only (http only for `localhost` when `APP_ENV=dev`). |
| `BPOLICY` (JSON) | Trial and privacy policy, see 2.4 and 2.5. |
| `BCREATEDBY`, `BCREATED` | Who registered it, when (U7 "where did it come from"). |

### 2.2 App credential

- An `ApiKey` with the **single scope `apps:provision`**, owned by a technical
  owner user created together with the app. That user cannot sign in (no
  password, no OIDC link), is never an admin, and is listed on the Apps page,
  not in People.
- **Why not an admin key:** `ApiKeyAuthenticator` hands a key its owner's roles.
  An app server is a third-party surface; it must never carry `ROLE_ADMIN`.
- `ApiKeyScope::requiredScopesForPath()` gains `/api/v1/apps` → `apps:provision`.
  The key reaches nothing else (restricted-key default deny).
- Shown once on creation and on rotate; stored like every other key.

### 2.3 App account — `BAPPACCOUNTS`

One row per customer of an app (for SISmass: one per care service).

| Column | Meaning |
| ------ | ------- |
| `BID`, `BAPPID` | Identity and owning app. |
| `BUSERID` | Synaplan user. Nullable, **no** `ON DELETE` clause: the delete flow clears the pointer itself (Galera: no cascade). Null on a tombstone, so origin lookup never follows a removed user. |
| `BEXTERNALID` | The app's stable id for its customer (SISmass: tenant `public_id` UUID). Kept on the tombstone. Live uniqueness is `BLIVEEXTERNALID`, not this column. |
| `BDISPLAYNAME`, `BCONTACTEMAIL` | Shown to the operator; contact email is the Stripe customer email and invoice recipient. Both are cleared when the row becomes a tombstone. |
| `BSTATUS` | `active` / `paused` / `deleted`. `deleted` is a tombstone, see 2.7. |
| `BPENDINGUSERID` | Nullable. Set only while a delete has tombstoned the row and the Synaplan user is not gone yet. A retry finishes that delete. |
| `BDELETEDAT` | When the tombstone was written. Null until then. |
| `BLIVEEXTERNALID` | Generated: `BEXTERNALID` while `BSTATUS <> 'deleted'`, otherwise NULL. Unique with `BAPPID`. MariaDB treats NULL as distinct, so tombstones do not block a later account with the same external id. |
| `BTRIALTOKENS` | Lifetime token allowance granted at creation (copied from the app policy, so a later policy change does not silently move existing customers). |
| `BCREATED`, `BLASTSEEN` | Lifecycle. |

The Synaplan user behind it:

- `providerId = external` (existing `AdminUserProvisioningService::PROVIDER_ID`),
  `ExternalIdentity(source = "app:<slug>", externalId = BEXTERNALID)`.
- **Synthetic login email** `app-<slug>-<accountId>@apps.synaplan.invalid`.
  Reasons: an app customer's real address may already own a Synaplan account
  (provisioning would 409); the account must not be reachable via
  "forgot password" on the web app; `.invalid` can never receive mail.
- **Synaplan never emails an app account.** Verification, digests and billing
  notices are suppressed; Stripe mails invoices to `BCONTACTEMAIL`.
- Level `NEW` until a purchase; then `PRO` / `TEAM` / `BUSINESS` via the
  existing Stripe webhook. **Same user, same key** — that is the "move into our
  standard" step. No key swap, no migration.

### 2.4 Customer key and trial policy

**Customer key.** Minted at account creation (plaintext returned once), with the
new scope **`app:ai`**. The scope map in `ApiKeyScope::requiredScopesForPath()`
matches **broad prefixes** (`/v1/*` → `desktop:messages`, and
`/api/v1/messages*`, `/api/v1/tts`, `/api/v1/config/models*` → `chat` /
`messages:*`). Attaching `app:ai` to those branches would also grant image and
video APIs, message history, uploads, extracted memories and model-default
writes. AA2 therefore adds **exact method-and-path branches before** those
prefixes, and does not add `app:ai` to any existing prefix:

| Method and path | Why |
| --------------- | --- |
| `POST /v1/chat/completions` | SISmass extraction (OpenAI-compatible) |
| `GET /v1/models` | List the models the privacy policy allows. Not `/v1/models/catalog` and not `/v1/*`. |
| `POST /v1/audio/transcriptions` | Server-side speech recognition. The existing transcription branch stays; `app:ai` is added to that branch only. |
| `POST /api/v1/messages/stream` | Free-form assistant chat. Not `GET`, not `/attach`, not the rest of `/api/v1/messages*`. |
| `GET /api/v1/config/models` | Read the model list. Not `PUT /config/models/defaults` and not `…/check`. |
| `/api/v1/auth/me` | Already open to any key (self-service); no new grant. |

`ApiKeyScopeTest` pins each allow and representative denials:
`POST /v1/images/generations`, `POST /v1/audio/speech`, `GET /v1/models/catalog`,
`GET /api/v1/messages`, `POST /api/v1/messages/upload`,
`GET /api/v1/messages/stream/attach`, `PUT /api/v1/config/models/defaults`,
`/api/v1/files`, `/api/v1/rag`, `/api/v1/user/memories`, `/api/v1/chats`,
`/api/v1/agents`, `/mcp`, `/api/v1/admin/apps`. No files, RAG, memories, chats,
agents, MCP or admin. `app:ai` is not a `desktop:` scope, so
`DesktopOmittedModel` / `isPairedDesktop()` stay untouched.

**Trial policy** (`BPOLICY.trial`), applied by a new `AppTrialPolicy` service
that `RateLimitService::checkLimit()` and `checkCostBudget()` consult **only for
app accounts at level `NEW`**:

```json
{
  "trial": {
    "tokens_total": 3000000,
    "limits": { "MESSAGES_TOTAL": 2000, "MESSAGES_HOURLY": 300, "TRANSCRIPTION_TOTAL": 300 },
    "app_daily_tokens": 50000000,
    "new_accounts_daily": 500
  }
}
```

- `tokens_total` — **lifetime** token allowance per account, summed from
  `BUSELOG.BTOKENS` for the user. The allowance belongs to the **account, not
  the key**: re-minting a key cannot reset it. Exhausted ⇒ 429 with code
  `trial_budget_exhausted` (OpenAI error shape on `/v1/*`).
- `limits` — replaces the matching `RATELIMITS_NEW.*` values for app accounts
  (50 lifetime messages is wrong for an app that calls the AI per section).
- `app_daily_tokens` — circuit breaker across all trial accounts of the app.
  80 % ⇒ Discord/Slack notice to the operator; 100 % ⇒ 429
  `app_trial_capacity` until midnight (paying accounts are never affected).
- `new_accounts_daily` — provisioning cap per app.
- The trial check applies whether or not Stripe is configured: it is the app's
  contract with the operator, not a billing feature.
- Level `PRO` / `TEAM` / `BUSINESS` ⇒ the overlay is off; standard tier limits
  and the monthly cost budget apply. Downgrade to `NEW` ⇒ the overlay is back
  with the tokens already used, so a cancelled free tier does not refill.
- Starting value for SISmass: 3 M tokens (SISmass M1 measures one full intake
  and sets the final number).

### 2.5 Privacy policy per app

`BPOLICY.privacy` — SISmass handles health data (Art. 9 GDPR):

```json
{
  "privacy": {
    "models": ["ollama:gpt-oss:120b", "whisper:local:large-v3"],
    "default_chat_model": "ollama:gpt-oss:120b",
    "memories": false,
    "session_summary": false,
    "force_incognito": true
  }
}
```

- `models` — allow-list by catalog key (`service:providerId:tag`, resolved via
  `ModelCatalog::findBidByKey`, never raw BIDs). `/v1/models` lists only these;
  any other model ⇒ 403 `model_not_allowed_for_app`. The operator owns the list;
  the app cannot widen it. Keys are illustrative — the real list is the German
  GPU (`g2`) and local Whisper, confirmed against the live catalog in AA5.
- `memories: false`, `session_summary: false` — no memory extraction, no
  conversation summary, regardless of user settings.
- `force_incognito: true` — `/api/v1/messages/stream` behaves as incognito even
  if the flag is missing; `/v1/chat/completions` is verified to persist no
  message content. `BUSELOG` keeps tokens and cost, never content.

### 2.6 Billing

- `POST /api/v1/apps/accounts/{id}/checkout` `{plan, success_url, cancel_url}`
  ⇒ Stripe Checkout URL. Return URLs must match `BRETURNORIGINS`.
  Customer email = `BCONTACTEMAIL`; `subscription_data.description` = app label
  (e.g. "SISmass PRO – up to 30 clients"); `metadata.app = <slug>` next to the
  existing `metadata.user_id` / `plan`. Same Stripe prices as the web plans.
- `POST /api/v1/apps/accounts/{id}/portal` `{return_url}` ⇒ Stripe customer
  portal (payment method, invoices, change plan, cancel).
- **Extract** checkout and portal creation from `SubscriptionController` into
  `App\Service\Billing\StripeCheckoutService` and call it from both the web
  controller and the app controller. Web behaviour stays byte-identical
  (regression tests first).
- The Stripe webhook is **unchanged**: it already resolves the user from
  `metadata.user_id` and sets `BUSERLEVEL`. Invoice and receipt emails are a
  Stripe dashboard setting and already on.

### 2.7 Status and lifecycle

- `GET /api/v1/apps/accounts/{id}` ⇒ level, subscription state
  (`active`, `past_due`, `canceled`, period end, `cancel_at_period_end`),
  trial `{tokens_total, tokens_used, state: plenty|low|exhausted}`.
  The app polls on return from Stripe, on login and hourly. A signed webhook to
  the app is v1.1, not v1.
- `POST /api/v1/apps/accounts/{id}/keys` ⇒ revoke the account's app-minted keys,
  mint a new one (recovery after a lost or leaked key).
- `POST /api/v1/apps/accounts/{id}/pause` / `…/resume`.
- `DELETE /api/v1/apps/accounts/{id}` is the only delete path. It is safe to
  call again. Invoices stay in Stripe (legal retention). Called when a care
  service deletes itself in SISmass.

**Delete, in this order:**

1. The row is already `deleted` and `BPENDINGUSERID` is null → **204**. Stripe
   is not called again and `UserDeletionService` is not called again.
2. The row is `deleted` and `BPENDINGUSERID` is set → skip to step 4. A previous
   call tombstoned the row and stopped before the user was removed.
3. Otherwise cancel the Stripe subscription now. "Already canceled" is success.
   Stripe unreachable → **503** `deletion_incomplete` and **no local change**,
   so the next call repeats the cancel.
4. One database transaction writes the tombstone and commits: `BSTATUS=deleted`,
   `BDELETEDAT=now`, `BPENDINGUSERID` = the current user id, `BUSERID=NULL`,
   `BCONTACTEMAIL` and `BDISPLAYNAME` cleared. `UserDeletionService` stays
   **outside** this transaction (it deletes files and vector points and must
   not hold a Galera transaction open).
5. `UserDeletionService` runs for `BPENDINGUSERID`. A missing user row is
   success (the previous attempt already deleted it). Any other failure →
   **503** `deletion_incomplete`; the row stays `deleted` with
   `BPENDINGUSERID` set, so a retry runs only this step.
6. Clear `BPENDINGUSERID`, write audit `app_account_deleted`, **204**.

`SignupOriginResolver` joins `BAPPACCOUNTS` only where `BUSERID` is not null,
so a tombstone never points at a deleted user and the person disappears from
People. The Apps detail still lists the tombstone: external id, created,
deleted at — no name, no email, no user link. Because `BLIVEEXTERNALID` is
NULL on a tombstone, a later `POST /apps/accounts` with the same `external_id`
creates a **new** account and a new user. The old tombstone remains.

### 2.8 Operator visibility

- **People list:** column and filter **Signed up via** — Web (email), Google,
  Apple, GitHub, Keycloak/OIDC, Nextcloud, ownCloud, OpenCloud, Outlook, and
  each registered app by name. One resolver (`SignupOriginResolver`) derives it
  from `BAPPACCOUNTS` first, then `ExternalIdentity.source`, then `providerId`.
- **User detail:** origin banner — "Created by SISmass on 4 Oct 2026 · care
  service ‘Pflege Sonnenschein’ · Trial: 40 % used" (U7 on the resource).
- **Operate → Apps:** one row per app — name, status pill, accounts
  (trial / paying), conversion, tokens today / this month vs. the daily cap,
  return address, registered by / on. Row actions: **Pause** / **Resume**,
  **Rotate key**. Detail: the account list (each row links to the user) and the
  policy form (trial allowance, limits, allowed models, return origins).

---

## 3. API contract (v1)

All under `/api/v1/apps`, app credential only (`apps:provision`). An app sees
**only its own accounts**: another app's id ⇒ 404, never 403. Full OpenAPI
annotations on every endpoint; `make -C frontend generate-schemas` for the admin
UI.

| Method | Path | Body | Result |
| ------ | ---- | ---- | ------ |
| `POST` | `/apps/accounts` | `{external_id, display_name, contact_email, locale}` | 201 `{account, api_key}` (key once) · 200 `{account}` on idempotent hit (no key) |
| `GET` | `/apps/accounts/{id}` | — | `{account}` incl. level, subscription, trial |
| `POST` | `/apps/accounts/{id}/keys` | — | 201 `{api_key}` (old keys revoked) |
| `POST` | `/apps/accounts/{id}/checkout` | `{plan, success_url, cancel_url}` | `{url, session_id}` |
| `POST` | `/apps/accounts/{id}/portal` | `{return_url}` | `{url}` |
| `POST` | `/apps/accounts/{id}/pause` · `/resume` | — | 204 |
| `DELETE` | `/apps/accounts/{id}` | — | 204 (again: 204, see 2.7). 503 `deletion_incomplete` leaves a retryable row |

Error codes (stable strings the app maps to its own copy): `app_paused`,
`account_paused`, `trial_budget_exhausted`, `app_trial_capacity`,
`model_not_allowed_for_app`, `return_url_not_allowed`, `plan_not_allowed`,
`new_accounts_limit`, `deletion_incomplete`.

### 3.1 Operator API (admin session, not the app credential)

Every path in §3 is app-credential-only and account-scoped, so it cannot list
apps, create one, pause a whole app, rotate the provisioning credential or edit
policy. Those actions are a separate admin API. Session with `ROLE_ADMIN`
only — never `apps:provision`. An app key that calls them is denied (they are
not on the `apps:provision` map, and the restricted-key default is deny).
A signed-in non-admin gets 403.

| Method | Path | Result |
| ------ | ---- | ------ |
| `GET` | `/api/v1/admin/apps` | One row per app: name, status, account counts, tokens, return origins |
| `POST` | `/api/v1/admin/apps` | `{name, slug, return_origins, policy}` → 201 and the credential **once** |
| `GET` | `/api/v1/admin/apps/{id}` | The row, its policy and its accounts (tombstones included, no contact email once deleted) |
| `PUT` | `/api/v1/admin/apps/{id}/policy` | Trial allowance, limits, allowed models, return origins |
| `POST` | `/api/v1/admin/apps/{id}/pause` · `/resume` | 204. Pause stops AI for every account of the app |
| `POST` | `/api/v1/admin/apps/{id}/rotate-key` | 201 and the new credential **once**; the previous credential stops immediately |

AA7 implements this contract and nothing else speaks to the database from the
Vue page. Tests: admin session can pause and rotate; a normal session is 403;
an `apps:provision` key is denied on every row of this table.

Example account:

```json
{
  "id": 4711,
  "external_id": "6f1c0a9e-2b7d-4d0a-9a51-0c8f2f3c9b10",
  "display_name": "Pflege Sonnenschein",
  "status": "active",
  "level": "NEW",
  "subscription": null,
  "trial": { "tokens_total": 3000000, "tokens_used": 1180000, "state": "plenty" },
  "created": 1791115200
}
```

---

## 4. Security rules

1. The app credential has exactly one scope, `apps:provision`; customer keys
   have exactly `app:ai`, granted only on the exact routes in §2.4.
   `ApiKeyScopeTest` pins both maps, including the denials listed there.
   `apps:provision` does not reach `/api/v1/admin/apps`.
2. Every query under `/api/v1/apps` is scoped by `BAPPID` of the presenting key.
   A cross-app id is 404.
3. `POST /apps/accounts` is idempotent on `(app, external_id)`; a replay never
   returns a plaintext key.
4. Provisioning is rate-limited per app (60/min) and capped per day
   (`new_accounts_daily`).
5. Keys are never logged (`sk_…` redaction already exists).
6. Every app action writes an `AuditLogEntry`: `app_created`,
   `app_key_rotated`, `app_paused`, `app_account_created`,
   `app_account_checkout_started`, `app_account_deleted`, …
7. Return URLs: exact origin match against `BRETURNORIGINS`, https only.

---

## 5. Schema and production notes

- Two new tables, `BAPPS` and `BAPPACCOUNTS`, via raw idempotent
  `CREATE TABLE IF NOT EXISTS` in the migration — **no Schema API** (Galera
  rule, AGENTS.md).
- No change to `BUSERS` or `BAPIKEYS` columns. The account ↔ key link is
  `ExternalIdentity.BAPIKEYID` (already exists).
- `AppAccountsModule` is a `FeatureModule`: decisive env `APP_ACCOUNTS_ENABLED`
  listed in `backend/.env.minimal` and `docker-compose.minimal.yml`; the registry
  drives feature status, runtime config and the 404 gate.
- Mobile impact: backend steps are `backend-only`; AA6 and the Vue part of AA7
  are `ota-candidate` (`frontend/**`). AA8 is docs only and stays
  `no-app-impact`. Check with
  `node scripts/mobile-impact.mjs --base <base> --head <head>`; new paths that
  fall outside the existing allow-lists are added to
  `.github/mobile-impact-policy.json` in the same PR.

---

## 6. Steps

| Step | Content | Class |
| ---- | ------- | ----- |
| `AA1` | `BAPPS` / `BAPPACCOUNTS` migration, entities, repositories, `AppAccountsModule`, CLI `app:apps:create <slug>` (prints the credential once) | backend-only |
| `AA2` | `/api/v1/apps/accounts` create / get / keys / pause / resume / delete (tombstone, retry, §2.7); `apps:provision` and exact-route `app:ai` branches in `ApiKeyScope` **before** the existing prefixes; synthetic login email; mail suppression for app accounts | backend-only |
| `AA3` | `AppTrialPolicy` in `RateLimitService` (lifetime tokens, limit overrides, app daily cap, new-accounts cap) **and** the cost-budget check on `/v1/chat/completions` + `OpenAiGatewayToolLoop` for every user | backend-only |
| `AA4` | `StripeCheckoutService` extraction (web regression tests first), app checkout and portal, contact email as Stripe customer email, `subscription_data.description` | backend-only |
| `AA5` | Privacy policy: model allow-list on `/v1/models`, `/v1/chat/completions`, `/v1/audio/transcriptions`, `/api/v1/messages/stream`; forced incognito; memories and summaries off; confirm the German model keys against the live catalog | backend-only |
| `AA6` | People list **Signed up via** column + filter, `SignupOriginResolver`, user-detail origin banner | ota-candidate |
| `AA7` | Admin API §3.1 (`/api/v1/admin/apps`, session `ROLE_ADMIN`) **and** Operate → Apps: list, status pill, Pause / Resume, Rotate key, detail with accounts and policy form, empty state. The page calls only that API | ota-candidate (the Vue page; the controller is backend-only in the same PR) |
| `AA8` | `docs/APP_ACCOUNTS.md` (integrator guide: create app, endpoints, error codes, return URLs, test mode) and a release note. Docs only, so `no-app-impact` (`.github/mobile-impact-policy.json` `**/*.md`). E2E for journeys A1–A4 ships in the AA6 and AA7 PRs, which are the `ota-candidate` steps | no-app-impact |

AA1–AA5 can merge behind the flag before any UI exists. SISmass M5 needs AA1–AA5;
the operator view (AA6, AA7) is required before SISmass goes public (SISmass
plan M8), because the operator must be able to find and pause app accounts.

### 6.1 User-flow block for AA6 and AA7 (UX contract §6)

**Journeys** (walked in the browser, U10):

- **A1 — Register an app.** Operate → Apps → empty state "No apps yet. Apps
  like SISmass create Synaplan accounts for their own customers." →
  **Add app** → name, return address, trial allowance, allowed models →
  **Create** → the credential is shown once with **Copy** and the sentence
  "Put this key into SISmass's server settings. It can only create and manage
  SISmass accounts." → the row appears with status "Waiting for the first
  account".
- **A2 — Find a SISmass customer in ten seconds.** People → filter
  **Signed up via: SISmass** → row → detail shows the origin banner, level and
  trial state (U2, U7).
- **A3 — Pause and undo.** Apps row →   **Pause** → `useDialog().confirm` with
  "Pausing SISmass stops the AI for all {count} SISmass accounts right away.
  Their data in SISmass is not touched. Paid plans keep billing." → pill
  "Paused" →
  **Resume** on the same row (U3).
- **A4 — Rotate the key.** Apps row → **Rotate key** → confirm "The old key
  stops working now. SISmass cannot create new accounts until the new key is in
  its settings." → shown once.

**Five questions on the Apps row / detail** (U7): owner = registered by and
on; who else = account count; what it can touch = "creates accounts and starts
purchases; its customers' keys can only use AI with the allowed models"; how to
stop = Pause; where from = registered date and return address.

**Exit criteria (each UI PR):**

1. Journeys A1–A4 walked in the browser (U10).
2. An app account is findable from People in ten seconds (U2).
3. Pause, Resume and Rotate carry their consequence sentence in all five
   locales (U3).
4. Empty, error and flag-off states exist; flag off removes the nav child, the
   column and the routes (U5, U8, U11).
5. Light, dark, `design-v2` and 320 px checked; WCAG AA (U9).

**Terms** (all five locales in the first UI PR):

| Concept | en | de | es | fr | tr |
| ------- | -- | -- | -- | -- | -- |
| A registered external product | App | App | aplicación | application | uygulama |
| The page | Apps | Apps | Aplicaciones | Applications | Uygulamalar |
| Origin column / filter | Signed up via | Angemeldet über | Registrado mediante | Inscrit via | Kayıt kaynağı |
| Free allowance | Trial allowance | Gratis-Kontingent | Cupo de prueba | Quota d'essai | Deneme kotası |

---

## 7. Tests

- **Unit:** `ApiKeyScopeTest` (both new scopes, deny everything else),
  `AppTrialPolicyTest` (lifetime sum, overrides, daily cap, upgrade lifts,
  downgrade restores), `SignupOriginResolverTest`, return-URL matcher.
- **Integration (PHPUnit, real DB):** idempotent create; two apps cannot see
  each other's accounts (404); replay returns no key; paused app / account;
  `trial_budget_exhausted` on `/v1/chat/completions` and
  `/api/v1/messages/stream`; `model_not_allowed_for_app`; incognito forced (no
  `BMESSAGES` rows); checkout session payload (Stripe client mocked); webhook
  fixture moves `NEW` → `PRO` and the same key keeps working; delete cancels
  Stripe before any local write; a second delete is 204 and does not call
  Stripe again; Stripe down leaves the row unchanged; a row left `deleted`
  with `BPENDINGUSERID` set is finished by a retry; the tombstone has a null
  `BUSERID` and empty contact fields; the same `external_id` can be
  provisioned again afterwards.
- **Regression:** web checkout / portal unchanged after the service
  extraction; cost budget now enforced on `/v1/chat/completions` for a `PRO`
  user over budget.
- **E2E:** A1–A4 (`@ci`), plus the layout job for the Apps page at 320 px.
- Gate as always: `make ci-local && make test-e2e`, unfiltered.

---

## 8. Rollout

1. Merge AA1–AA5 with the flag off; release; roll web1 → web2 → web3
   (`updatewebN.sh`).
2. Set `APP_ACCOUNTS_ENABLED=1` in the NFS `.env`, roll again.
3. Register **SISmass** (CLI or AA7 page): return origin
   `https://app.sismass.de` (SISmass decision E1), trial 3 M tokens, German
   models only.
4. Put the credential into the SISmass deployment environment
   (`SYNAPLAN_APP_KEY`), never into git and never into this public repository.
5. SISmass staging talks to a Synaplan with **Stripe test keys** (local dev
   stack or a staging instance) — never test purchases against live Stripe.
6. AA6/AA7 before SISmass removes its access password.

---

## 9. Open decisions

| # | Question | Recommendation |
| - | -------- | -------------- |
| D1 | Allowance unit: tokens or EUR? | **Tokens** (what the app can reason about; provider-independent). The Apps page shows a EUR estimate next to it. |
| D2 | App accounts sign in on web.synaplan.com? | **No** in v1 (synthetic login email). "Link to an existing Synaplan account" after v1. |
| D3 | Own Stripe products per app? | **No** in v1; same prices, app label in `subscription_data.description`. Revisit when an app needs different prices. |
| D4 | New user level `TRIAL` instead of `NEW` + overlay? | **No.** A new level touches OpenAPI enums, IAP, admin UI and seeders; the overlay is one service consulted in two methods. |
| D5 | Signed webhook to the app on plan change | v1.1. v1 polls `GET /apps/accounts/{id}`. |
| D6 | Migrate Nextcloud / ownCloud provisioning onto app credentials | Later; they keep the admin endpoints until then. |
