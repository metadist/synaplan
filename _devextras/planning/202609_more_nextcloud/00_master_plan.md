# More Nextcloud — link a platform user to an existing Synaplan account — master plan

**Status:** Decisions ticked 2026-09-03 (log in [`STATUS.md`](./STATUS.md)).
Track 6 of [`../20260903_roadmap.md`](../20260903_roadmap.md).
Needs `BEXTERNALIDENTITIES` from track 1 S1; otherwise independent and
small. Can be pulled forward when a hoster asks.
Sprint files: [`01_sprint_1_core_handshake.md`](./01_sprint_1_core_handshake.md) ·
[`02_sprint_2_nextcloud_app.md`](./02_sprint_2_nextcloud_app.md) ·
[`03_sprint_3_parity_and_fallbacks.md`](./03_sprint_3_parity_and_fallbacks.md).
**Owner surface:** Synaplan: Manage → Connections → **Linked platforms**
(new child replacing nothing; see decision 11). Nextcloud: personal settings
**Synaplan** + the existing admin settings.
**Flag:** `PLATFORM_LINKS.ENABLED` — default off in code and seeder.
**Repos:** `synaplan/` (core), `synaplan-nextcloud/` (app),
`synaplan-owncloud-online/` (parity), `synaplan-docs/` (docs).
**Related:**

- [`../2026-archive/20260709-hosting-partner-core-requirements/README.md`](../2026-archive/20260709-hosting-partner-core-requirements/README.md)
  §CORE-2 — admin key → provision + mint (shipped; stays as one mode)
- `frontend/src/views/AddinConnectView.vue` + `/wwwroot/Synamail/docs/AUTH_FLOW.md`
  — the Outlook add-in connects an *existing* account (pattern reused)
- `backend/src/Controller/DesktopController.php` — pairing codes (fallback
  pattern)
- [`../20260902-collabora-integration/05_epic_4_partner_platforms.md`](../20260902-collabora-integration/05_epic_4_partner_platforms.md)
  — assumes the platform app already holds a Synaplan key; this track
  produces that key per user

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **Three modes per Nextcloud instance, admin-chosen:** `shared` (today's default, one key), `provision` (today's per-user: admin key creates accounts), **`link`** (new: each user connects their own existing Synaplan account; optional auto-provision for users who have none). Existing modes are byte-identical. | Add `link`, keep both others | ✅ 2026-09-03 |
| 2 | **Linking is an authorization-code style handshake, not a pasted key.** NC personal settings → "Connect Synaplan" → browser opens Synaplan `/connect/platform?client=nextcloud&instance=…&state=…` → user signs in (existing login incl. OIDC/Google/…) → confirms "Connect Nextcloud at *host* as *uid*" → Synaplan redirects to the NC callback with a one-time `link_code` → **NC server** exchanges the code (server-to-server, with the NC instance secret) for a **scoped per-user API key** → stored in NC user preferences as today. The key never travels through the browser. | Auth-code handshake | ✅ 2026-09-03 |
| 3 | **One generic flow for all partner clients.** `AddinConnectView.vue` is generalized to `PlatformConnectView.vue` with a registered `client` (`outlook`, `nextcloud`, `owncloud`, later `opencloud`); each client has a redirect-URI policy. Synamail keeps working (its relay redirect is one client policy). | Generalize | ✅ 2026-09-03 |
| 4 | **Instance registration.** A Nextcloud instance registers once with Synaplan (`POST /api/v1/platform-links/instances` by the NC admin using the existing admin key **or** a Synaplan admin approving a pending registration in the UI) and receives an `instance_id` + `instance_secret`. Redirect URIs must be `https://<that instance host>/…`; `SsrfGuard` rules apply to the host. | Registered instances | ✅ 2026-09-03 |
| 5 | **External identities are rows, not JSON.** `BEXTERNALIDENTITIES` (track 1 S1): `(source=nextcloud, instance_id, external_id) → user_id, api_key_id`. `BUSERDETAILS.external_*` stays as a read fallback; provisioning writes both until a later cleanup. | Table from IAM | ✅ 2026-09-03 |
| 6 | **A Synaplan user may link many platform identities**; a platform identity links to exactly one Synaplan user. Re-linking replaces the row and revokes the previous key. | 1:n | ✅ 2026-09-03 |
| 7 | **Email conflicts become a link offer**, not a hard failure, in `link` mode: if provisioning finds the email already taken, the NC app shows "An account with this email exists — connect it" and starts the handshake. `provision` mode keeps today's conflict exception (C2). | Offer to link | ✅ 2026-09-03 |
| 8 | **Minted key scopes = today's** (`chat`, `files`, `rag`) plus `memories` only if the NC admin enabled memories. Keys are labelled `Nextcloud: <host> (<uid>)` and appear in the user's API keys list with a *linked platform* badge; revoking there disconnects NC. | Same scopes | ✅ 2026-09-03 |
| 9 | **Preserve configuration by construction:** linking never creates or changes a Synaplan user; the user's models, knowledge, memories, assistants stay untouched. There is **no account merge** (moving data from a provisioned `external` account into an existing one) in v1 — the user re-links and the old account stays, admin can delete it. | No merge | ✅ 2026-09-03 |
| 10 | **Synaplan side UI = Connections → Linked platforms:** list of my linked platforms (icon, host, uid, key label, last used), Disconnect. Admin (Operate → People → user detail) sees the same as metadata. | One child | ✅ 2026-09-03 |
| 11 | **NC admin settings shrink for `link` mode:** Synaplan URL + "Register this instance" button; the admin key becomes optional (needed only for auto-provision and the activated-users table). | Simplify | ✅ 2026-09-03 |
| 12 | **ownCloud.online app gets parity in the same track.** Verified 2026-09-03: `synaplan-owncloud-online/lib/Service/UserAccountService.php` is the same shared-key / per-user provisioning model (`source="owncloud"`, `external_id="<instanceId>:<uid>"`), so `link` mode applies 1:1. **OpenCloud is out of this track**: `synaplan-opencloud/backend/internal/tokenexchange/` already gives every user a real identity via RFC 8693 token exchange against the shared Keycloak realm (static API key only as a fallback for single-tenant setups) — that is the stronger model and needs no handshake. Track 1 S4 owns the regression check that token-exchanged users pick up groups and shares. `link` as an OpenCloud Mode C is v2, only on request. | NC + OCO; OpenCloud out | ✅ 2026-09-03 |
| 13 | **Schema (ask recorded):** `BPLATFORMINSTANCES` (S1); `BEXTERNALIDENTITIES` from track 1. Galera-safe `addSql`. | Ask recorded | ✅ 2026-09-03 |
| 14 | **Mobile:** Synaplan PHP `backend-only`; `PlatformConnectView` + Linked platforms page `ota-candidate`; NC/OCO apps are their own release channels. | Locked | ✅ 2026-09-03 |

---

## 1. The concept in three sentences

> If you already have a Synaplan account, your Nextcloud can simply use it:
> click **Connect Synaplan** in your Nextcloud settings, sign in to Synaplan
> once, and confirm. From then on the Nextcloud AI actions run as *you*, with
> your models, knowledge and memories — nothing is copied or created. You can
> disconnect from either side at any time.

---

## 2. Why this exists

Today the Nextcloud app either acts as **one** Synaplan account for the
whole instance (shared key) or **creates** a fresh Synaplan account per
Nextcloud user (admin key provisioning). A person who already uses Synaplan —
on the web, in Outlook, on the desktop — cannot bring that account into
Nextcloud; the provisioning path even refuses when the email is already
taken. Hosters asked for exactly this: "connect Nextcloud to an existing
account, preserve the configuration, simplify onboarding". The Outlook add-in
already proves the UX (`AddinConnectView`); this track makes it a platform
feature instead of a one-off.

---

## 3. What already exists (do not rebuild)

| Piece | State | Role here |
| ----- | ----- | --------- |
| `synaplan-nextcloud` app: `SynaplanClient` (`X-API-Key`), `SynaplanConfig` (`per_user_accounts`), `UserAccountService::provisionAndMint`, `AiConsentGate.vue`, `AdminSettings.vue` | Shipped (v1.5) | Gains `link` mode, personal settings, callback route; existing modes untouched |
| `AdminUserProvisioningController` / `AdminUserProvisioningService` (`POST /admin/users`, `…/api-keys`, `…/usage`, conflict exception) | Shipped (CORE-2) | Stays for `provision` mode; writes `BEXTERNALIDENTITIES` in addition |
| `AddinConnectView.vue` (`/addin/connect`) + `POST /api/v1/apikeys` | Shipped | Generalized to `PlatformConnectView`; the Outlook client policy reproduces today's relay behavior |
| Desktop pairing codes (`POST /desktop/pairing-codes`, `/desktop/pair`) | Shipped | Fallback pattern when a browser redirect is impossible (headless NC admin scripting): a "pairing code" variant of the handshake, S3 |
| `ApiKeyScope` / restricted scopes | Shipped | Minted keys are scoped; grandfather unaffected |
| `BEXTERNALIDENTITIES` | Track 1 S1 | Identity rows |
| `SsrfGuard` | Shipped | Redirect URI and instance host validation |
| `synaplan-docs/docs/plugins.md` | Shipped | Rewritten section: three modes, linking walkthrough, fix the OpenCloud prose that contradicts the repo |
| `synaplan-owncloud-online` (PHP twin) | Shipped | Parity in S3 |

---

## 4. Target architecture

```text
 Nextcloud (user)                 Nextcloud server                Synaplan
 ────────────────                 ────────────────                ────────
 Settings → Connect Synaplan ──►  build state, redirect ─────────► /connect/platform?client=nextcloud
                                                                    &instance=<id>&state=<s>&uid=<nc uid>
                                                                  user signs in (any method), confirms
                                  ◄── 302 <nc>/apps/synaplan_integration/link/callback?code=<link_code>&state=<s>
                                  verify state; POST /api/v1/platform-links/exchange
                                     { instance_id, instance_secret, code } ──────────► mint scoped key,
                                                                                      write BEXTERNALIDENTITIES
                                  ◄── { api_key, user_id, label }                    (source=nextcloud)
                                  store in NC user prefs (as today)
 AI actions ──────────────────►  X-API-Key: <per-user key> ────────────────────────► runs as the linked user
```

### 4.1 Schema

| Table | Columns | Notes |
| ----- | ------- | ----- |
| `BPLATFORMINSTANCES` (S1) | `BID`, `BCLIENT` (`nextcloud` / `owncloud` / `outlook` / `opencloud`), `BINSTANCEID` (opaque, unique), `BHOST`, `BSECRETHASH`, `BREDIRECTURIS` (JSON), `BREGISTEREDBY`, `BSTATUS` (`active` / `pending` / `revoked`), `BCREATED`, `BLASTSEEN` | Outlook gets a built-in pseudo-instance so the existing add-in flow needs no registration |
| `BEXTERNALIDENTITIES` (track 1) | see IAM plan | `BAPIKEYID` links to the minted key |
| Redis | `link_code` → `{ user_id, instance_id, external_id, expires }` TTL 5 min, single use | No table (same as pairing codes) |

### 4.2 Core API (additive, flag-gated)

| Method | Path | Sprint | Purpose |
| ------ | ---- | ------ | ------- |
| `POST` | `/api/v1/platform-links/instances` | S1 | Register an instance (admin key or admin session); returns `instance_id` + one-time `instance_secret` |
| `GET/DELETE` | `/api/v1/admin/platform-links/instances`, `/{id}` | S1 | Admin list / revoke |
| `GET` | `/connect/platform` (frontend route → `PlatformConnectView`) | S1 | Sign in + confirm; issues `link_code` via `POST /api/v1/platform-links/codes` (session) |
| `POST` | `/api/v1/platform-links/exchange` | S1 | Server-to-server: code + instance credentials → scoped key; writes identity row |
| `GET/DELETE` | `/api/v1/me/platform-links`, `/{id}` | S1 | My linked platforms; disconnect (revokes key) |
| `POST` | `/api/v1/platform-links/pairing-codes` (session) · `/pair` (instance) | S3 | Pairing-code variant for headless setups |

`AddinConnectView` behavior is reproduced by `client=outlook` with the relay
redirect policy; `/addin/connect` becomes a redirect to the generic route
(Synamail's `docs/AUTH_FLOW.md` is updated in the same change, per its rule).

### 4.3 Nextcloud app changes (S2)

- `info.xml`: add `<personal>` settings section; new routes
  `link/start`, `link/callback`, `link/disconnect`.
- `SynaplanConfig`: mode enum `shared | provision | link`; `link` implies
  `per_user_accounts` semantics for key lookup.
- `UserAccountService`: in `link` mode, `resolveKeyForUser()` returns the
  linked key or `null` → UI shows the Connect card (`AiConsentGate.vue`
  becomes a two-option gate: *Connect my Synaplan account* / *Create one for
  me* if auto-provision is enabled).
- Migration for existing `provision` installs switching to `link`: users
  keep working with their provisioned key until they choose to connect a
  different account (decision 9).
- Admin settings: mode selector, "Register this instance" (calls the core
  registration with the admin key, stores `instance_id` + secret in NC
  app config), activated-users table shows *linked* vs *provisioned*.

### 4.4 Security notes

- `state` is generated and verified by the NC server (CSRF); `link_code` is
  single-use, 5-minute TTL, bound to the instance that will exchange it.
- The exchange requires the instance secret (hashed at rest); a leaked
  `link_code` alone is useless.
- Redirect URI must match a registered URI prefix for that instance; host
  passes `SsrfGuard` and is `https` (dev exception for `localhost`).
- The confirm screen shows host, uid and the scopes; the user must click.
- Disconnect from Synaplan revokes the key → NC gets 401 → clears prefs and
  shows the Connect card again (existing 401 handling).

---

## 5. UI

### 5.1 Synaplan

- `/connect/platform`: sign-in (existing), then a single confirmation card:
  "Connect **Nextcloud at files.example.org** as **jdoe**? This lets Nextcloud
  use your Synaplan account for: chat, files, knowledge." → Connect / Cancel.
- Manage → Connections → **Linked platforms**: list + Disconnect; empty state
  explains how to connect from Nextcloud.
- Operate → People → user detail: linked platforms as metadata badges.

### 5.2 Nextcloud

- Personal settings → Synaplan: status (not connected / connected as
  *name*, since *date*), Connect / Disconnect.
- Files actions unchanged; first use without a key shows the gate.

Words (en / de / es / fr / tr): Connect Synaplan / Synaplan verbinden /
Conectar Synaplan / Connecter Synaplan / Synaplan'ı bağla; Linked platforms /
Verbundene Plattformen / Plataformas vinculadas / Plateformes liées / Bağlı
platformlar; Disconnect / Trennen / Desconectar / Déconnecter / Bağlantıyı
kes. Never "token exchange", "auth code", "provisioning" in primary copy.

---

## 6. Compatibility invariants

| # | Invariant | Proof |
| - | --------- | ----- |
| C1 | Flag off ⇒ `/addin/connect` and the Outlook flow behave exactly as today; new routes 404; NC app in `shared` / `provision` modes unchanged | Synamail E2E (`docs/AUTH_FLOW.md` steps) + NC app tests |
| C2 | `provision` mode keeps today's email-conflict exception | Provisioning tests |
| C3 | Linking never creates, edits or merges a Synaplan user | Negative tests |
| C4 | Minted keys carry the same scopes as today's provisioned keys; grandfather untouched | Scope tests |
| C5 | A `link_code` cannot be exchanged twice, after expiry, or by another instance | Handshake tests |
| C6 | Redirect targets are only registered URIs on the registered host | Redirect tests incl. open-redirect attempts |
| C7 | `BUSERDETAILS.external_*` readers keep working during the transition | Repository tests |
| C8 | Mobile unaffected (`backend-only` + `ota-candidate`) | mobile-impact script |

---

## 7. Sprints

| Sprint | Content | Exit |
| ------ | ------- | ---- |
| **S1 — Core handshake** | `BPLATFORMINSTANCES`, registration + admin list, `PlatformConnectView` (generalizing `AddinConnectView`, Outlook client policy), `link_code` issue/exchange, identity rows, Linked platforms page, flag; Synamail `AUTH_FLOW.md` update | A scripted fake instance completes the handshake and gets a scoped key; Outlook add-in E2E unchanged |
| **S2 — Nextcloud app** | `link` mode, personal settings, routes, two-option gate, instance registration from admin settings, activated-users badges, app release notes | A real NC user connects an existing Synaplan account and summarizes a file as that account |
| **S3 — Parity & fallbacks** | ownCloud.online app parity; pairing-code variant for headless setups; docs site (`plugins.md` rewrite incl. the OpenCloud correction); Collabora Epic 4 pointer updated | OCO users link; docs describe three modes with screenshots |
| **v2 candidates** | OpenCloud Mode C (`link`) when asked; account merge tooling (admin, audited); SCIM-driven pre-linking by email for OIDC-shared setups | Decided per demand |

Cut line: S3 pairing-code variant first, then OCO parity (if the OCO app has
no active hoster). Never cut C1/C5/C6.

---

## 8. Rollout

1. S1 merges behind `PLATFORM_LINKS.ENABLED = off`; Outlook keeps its
   existing route.
2. Enable on Synaplan Cloud once the NC app release with `link` mode is
   published to the Nextcloud App Store; docs first.
3. Seed flag on for new installs after S2; existing installs flip it.
4. Rollback: flag off; linked keys keep working (they are ordinary API
   keys); only new links are blocked.

---

## 9. Out of scope (v1)

- Merging a provisioned account into an existing one.
- Replacing the shared-key or provisioning modes.
- OIDC federation between Nextcloud's user backend and Synaplan (OpenCloud
  Mode A already covers the shared-IdP case).
- File sync / write-back to Nextcloud (that is Connections → WebDAV, saved
  tasks plan).
- App Store review timelines (tracked in the app repo).

---

## 10. Success criteria

1. A Nextcloud user with an existing Synaplan account connects it in under a
   minute without typing a key; the next Files action runs as that account
   and answers from their existing knowledge.
2. Disconnecting in Synaplan makes Nextcloud show "Connect" again on the next
   action; disconnecting in Nextcloud revokes the key in Synaplan.
3. An admin switches an instance from `provision` to `link`; existing users
   keep working; new users get the two-option gate.
4. Open-redirect, replayed code, and wrong-instance exchange attempts all
   fail with clear errors and audit rows.
5. Outlook add-in sign-in still passes every step in Synamail's
   `docs/AUTH_FLOW.md`.
6. Flag off: gate green in `synaplan/`; NC app tests green in both old modes.

---

## 11. Decisions from the 2026-09-03 review (formerly open questions)

| # | Question | Decision |
| - | -------- | -------- |
| 1 | Instance registration | **Both**: admin API key (automation) and a pending-approval list in Operate → People → Linked platforms (small teams click). |
| 2 | Account picker on the confirm screen | **No** — the signed-in account, with a "Not you? Sign out" link (default stands; not contested). |
| 3 | Auto-provision inside `link` mode | **Admin opt-in** per instance; needs the admin key. |
| 4 | Handshake | **Authorization-code style** confirmed; pairing-code variant only as the S3 headless fallback. |
| 5 | Email conflict in `provision` mode | **Offer link mode** to the user when the instance also has `link` available; pure `provision` installs keep the hard fail (C2). |
| 6 | Account merge | **Not in v1**; documented re-link workaround. |
| 7 | `AddinConnectView` | **Generalize to `PlatformConnectView`**; every step of Synamail's `docs/AUTH_FLOW.md` is an acceptance test of S1, and that document is updated in the same change. |
| 8 | Scope of parity | **Nextcloud + ownCloud Online**; OpenCloud out (row 12). |
