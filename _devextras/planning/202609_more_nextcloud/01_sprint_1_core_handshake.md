# Sprint 1 — Core handshake

**Track 6 (`synaplan/`), sprint 1 of 3.** Steps `NC1`–`NC7`.

**Goal:** A registered partner instance sends a signed-in Synaplan user through
`/connect/platform`, receives a one-time `link_code`, and exchanges it
server-to-server for a **scoped per-user API key** bound to an identity row.
The Outlook add-in keeps working through the same view; every step of
Synamail's `docs/AUTH_FLOW.md` is an acceptance test of this sprint.
**Depends on:** IAM S1 migrations `BEXTERNALIDENTITIES` + `BAUDITLOG` (`IAM1`);
master plan decisions 2, 3, 4, 6, 8, 10, 13 and §11 rows 1, 2, 7.
**Unlocks:** S2 (the Nextcloud app has an exchange to call), S3.
**Repos:** `synaplan/` (all code); `Synamail/` (docs only — `docs/AUTH_FLOW.md`).
**Flag:** `PLATFORM_LINKS.ENABLED` (BCONFIG group `PLATFORM_LINKS`, owner 0,
default off in code and seeder). Every `/api/v1/platform-links/*` and
`/api/v1/me/platform-links*` route 404s when off. The `client=outlook` path of
`PlatformConnectView` is **not** flag-gated: it is today's shipped behaviour.

## 0. Why this sprint exists

Pasting a key into a partner app is how the key leaks. The handshake keeps the
key server-to-server: the browser only carries a five-minute, single-use code
that is worthless without the instance secret. `AddinConnectView.vue` already
does the sign-in half for Outlook; generalizing it means one flow, one redirect
policy, one test suite for every partner client.

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `frontend/src/views/AddinConnectView.vue`, `frontend/src/router/index.ts` (`/addin/connect`, `requiresAuth: false, public: true`) | The view to generalize: `bootstrap()` uses `route.fullPath`, `isSafeRedirect()` allow-list, `#payload=` relay, `postPayloadToParent()` fallback |
| `frontend/src/utils/pendingAuthRedirect.ts`, `frontend/src/views/LoginView.vue` (`isSafeRedirectPath`) | The login round-trip that must keep the whole query |
| `/wwwroot/Synamail/docs/AUTH_FLOW.md` | Steps 1–8 and invariants 1–7 are this sprint's acceptance list |
| `backend/src/Controller/ApiKeyController.php` (`create()`: `'sk_'.bin2hex(random_bytes(29))`), `backend/src/Security/ApiKeyScope.php` (`addinScopes()`, `requiredScopesForPath()`, `isSelfRevoke()`) | Minting shape; frozen Outlook scopes; where linked-key scopes must be mapped |
| `backend/src/Service/Desktop/PairingCodeService.php` (`desktop_pair:code:{CODE}` TTL 600, `MAX_OUTSTANDING = 5`, `MAX_PER_HOUR = 20`), `DesktopController.php` (`guard()`, `allowPairAttempt()` 60/h per IP), `DesktopAgentConfig.php` | Redis one-time code, 404-when-off guard, per-IP limit, flag class — all copied |
| `backend/src/Service/Admin/AdminUserProvisioningService.php` (`findByExternalIdentity()`, LIKE on `BUSERDETAILS` JSON), `backend/src/Service/Security/SsrfGuard.php` (`isBlockedHost()`) | The C7 reader that must keep working; host validation for registration |
| `frontend/src/composables/useNavItems.ts` (`grouped('connections', …)`), `frontend/src/components/config/APIKeysConfiguration.vue` (`key_prefix`, `scopes`) | Connections child; linked-platform badge |
| `_devextras/planning/202609_iam/01_sprint_1_groups_core.md` §2.8 (`PeopleView.vue`, `/admin/people`), `_devextras/testing/desktop/pair.sh` | Operate → People (from IAM S1) hosts the admin tab; harness shape to copy |

## 2. Developer steps

### 2.1 `NC1` — Migration `BPLATFORMINSTANCES` + flag (backend-only)

Galera-safe: raw `addSql()`, no `Schema` API, idempotent.

```sql
CREATE TABLE IF NOT EXISTS BPLATFORMINSTANCES (
  BID BIGINT NOT NULL AUTO_INCREMENT,
  BCLIENT VARCHAR(32) NOT NULL,
  BINSTANCEID VARCHAR(64) NOT NULL,
  BHOST VARCHAR(255) NOT NULL,
  BSECRETHASH VARCHAR(255) NOT NULL DEFAULT '',
  BREDIRECTURIS JSON NULL,
  BREGISTEREDBY BIGINT NOT NULL DEFAULT 0,
  BSTATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
  BCREATED BIGINT NOT NULL,
  BLASTSEEN BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (BID),
  UNIQUE KEY uq_platform_instance (BINSTANCEID),
  KEY idx_platform_client_host (BCLIENT, BHOST)
);
INSERT INTO BPLATFORMINSTANCES (BCLIENT, BINSTANCEID, BHOST, BSECRETHASH, BREDIRECTURIS, BREGISTEREDBY, BSTATUS, BCREATED)
SELECT 'outlook', 'outlook-builtin', '*', '', '["https://localhost","https://127.0.0.1","https://addin.synaplan.com","https://*.synaplan.com"]', 0, 'active', UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM BPLATFORMINSTANCES WHERE BINSTANCEID = 'outlook-builtin');
```

`BCLIENT` ∈ `nextcloud | owncloud | outlook | opencloud`; `BSTATUS` ∈
`active | pending | revoked`. The Outlook pseudo-instance has no secret (it
never calls `/exchange`); its `BREDIRECTURIS` is today's `isSafeRedirect()`
allow-list moved server-side. Same PR: entity `PlatformInstance` +
`PlatformInstanceRepository`; `PlatformLinksConfig` (copy of `DesktopAgentConfig`);
`BConfigSeeder::insertIfMissing('PLATFORM_LINKS', 'ENABLED', '0')`;
`features.platformLinksEnabled` on `/api/v1/config/runtime`.

### 2.2 `NC2` — Instance registration + admin list (backend-only)

`PlatformInstanceController` (`/api/v1/platform-links/instances`) and `AdminPlatformInstanceController` (`/api/v1/admin/platform-links/instances`); logic in `PlatformInstanceService`:

- `POST /api/v1/platform-links/instances` body `{ client, host, redirect_uris[] }`. Admin API key or admin session → `BSTATUS='active'`, `BREGISTEREDBY=<admin id>`. Anonymous (rate-limited `platform_link:register_attempt:{sha1(ip)}`, 10/h) → `BSTATUS='pending'`. Both return `{ instance_id, instance_secret }` once; `instance_id = 'pi_'.bin2hex(random_bytes(12))`, secret `bin2hex(random_bytes(32))`, `BSECRETHASH = password_hash(secret, PASSWORD_DEFAULT)`.
- Validation: `host` must be `https` and pass `SsrfGuard::isBlockedHost()` (`localhost` only when `APP_ENV=dev`); every `redirect_uris[]` entry must be `https://<host>/…` without query, fragment, or userinfo. Otherwise 400 with a specific message.
- `GET /api/v1/platform-links/instances/self` (headers `X-Instance-Id` / `X-Instance-Secret`) → `{ status, host, client }`; `GET …/instances/{instance_id}/public` → `{ client, host }` for active instances (the confirm card reads host from here, never from the query alone).
- Admin: `GET …/instances` (all statuses), `POST …/instances/{id}/approve` (`pending → active`), `DELETE …/instances/{id}` (`→ revoked`, revokes every key referenced by that instance's identity rows — revoke is the compromise case).

### 2.3 `NC3` — `link_code` issue, exchange, my links (backend-only)

`LinkCodeService` (Redis, same shape as `PairingCodeService`; `CODE = bin2hex(random_bytes(16))`, never typed by a human; `consume()` is GET+DEL and returns `null` for unknown, expired, or used codes):

```text
platform_link:code:{CODE}                 -> JSON {userId, instanceId, externalId, redirectUri, expiresAt}, TTL 300 s
platform_link:hour:{userId}               -> counter, TTL 3600 s (20 codes / hour)
platform_link:exchange_attempt:{sha1(ip)} -> counter, TTL 3600 s (60 / hour)
```

- `POST /api/v1/platform-links/codes` (session, flag on) body `{ instance_id, external_id, redirect_uri, state }`. Refuses unless the instance is `active`, `BCLIENT !== 'outlook'`, and `redirect_uri` prefix-matches one `BREDIRECTURIS` entry. Returns `{ redirect }` = `redirect_uri?code=<CODE>&state=<state>`; the browser navigates only to this server-built URL (C6 lives in PHP, not JS).
- `POST /api/v1/platform-links/exchange` (no session) body `{ instance_id, instance_secret, code }` → `PlatformLinkExchangeService::exchange()`: verify secret, consume code, require `code.instanceId === instance_id` (foreign code = 400 with the unknown-code message), mint as the **user** via the owner path (admins can link; the key stays restricted), label `Nextcloud: <host> (<uid>)` / `ownCloud.online: …`, scopes `ApiKeyScope::platformLinkScopes($withMemories)`; upsert `BEXTERNALIDENTITIES` on `(BSOURCE, BINSTANCEID, BEXTERNALID)`, revoking a replaced row's previous `BAPIKEYID` (decision 6). Response `{ success, api_key: { id, key, name, scopes }, user: { id, email, display_name }, link_id }`. Never creates or edits a `BUSER` row (C3).
- `platformLinkScopes()` must equal what `POST /api/v1/admin/users/{id}/api-keys` mints for a provisioned Nextcloud user today (`chat`, `files`, `rag`, plus `memories` on request). Read `requiredScopesForPath()` first: if those strings are unmapped, that is a pre-existing `fix(security)` PR before `NC3`, never a widening inside this track (C4).
- `GET /api/v1/me/platform-links` → `[{ id, client, host, external_id, key_id, key_label, created, last_seen }]` (owner-scoped join with `BPLATFORMINSTANCES`); `DELETE /api/v1/me/platform-links/{id}` revokes the key and deletes the row, 404 for another user's id. `GET /api/v1/apikeys` items gain `linked_platform: { client, host } | null`. Full OpenAPI, then `make -C frontend generate-schemas` + `vue-tsc`.
- Audit (master plan §10.4): `BAUDITLOG` rows `platform_link.linked`, `platform_link.disconnected`, `platform_link.exchange_failed`, `platform_link.redirect_rejected`, `platform_instance.registered|approved|revoked` (the last three from `NC2`).

### 2.4 `NC4` — `PlatformConnectView.vue` + client registry + redirect (ota-candidate)

- Route `/connect/platform` (name `platform-connect`, meta as `/addin/connect` today). `/addin/connect` becomes `redirect: (to) => ({ path: '/connect/platform', query: { client: 'outlook', ...to.query } })` — every query param survives; Synamail's `buildDialogUrl` is unchanged.
- `frontend/src/platform-connect/clients.ts`: `PlatformClientPolicy { id, labelKey, delivery: 'fragment-payload' | 'link-code', flagGated }`. `outlook`: fragment payload + `postPayloadToParent` fallback, mints via `createApiKey()` with today's scopes, client-side allow-list kept as defence in depth, `flagGated: false`. `nextcloud` / `owncloud`: `POST /api/v1/platform-links/codes`, then `window.location.assign(response.redirect)`, `flagGated: true`. Unknown `client` → error state, no network.
- `bootstrap()` keeps the unauthenticated branch verbatim: `setPendingRedirect(route.fullPath)` then `/login?redirect=<fullPath>`.
- Confirm card: "Connect **Nextcloud at {host}** as **{uid}**?" + scopes line + "Not you? Sign out" (§11.2). i18n: `addinConnect.*` → `platformConnect.*` with `{client}`, `{host}`, `{uid}` in `en`, `de`, `es`, `fr`, `tr`; update `localeParityBaseline.json`. Mobile: add `frontend/src/platform-connect/**` to `.github/mobile-impact-policy.json`, extend `tests/mobile-impact.test.mjs`, run `node scripts/mobile-impact.mjs --base <base> --head <head>` (C8).

### 2.5 `NC5` — Linked platforms pages (ota-candidate)

- Manage → Connections → **Linked platforms**: route `/channels/platform-links` (name `channels-platform-links`), `LinkedPlatformsConfiguration.vue`, `useNavItems` child under `grouped('connections', …)` shown only when `features.platformLinksEnabled`. List (client icon, host, uid, key label, last used) + Disconnect via `useDialog().confirm({ danger: true })`; empty state explains connecting from Nextcloud.
- Operate → People (from IAM S1, `PeopleView.vue`) → tab **Linked platforms**: `PlatformInstancesTab.vue` — pending registrations with Approve / Reject, active instances with Revoke; a user's linked identities as metadata badges in the user detail.
- Badge "Linked platform" next to the key name in `APIKeysConfiguration.vue` when `linked_platform` is set. Tokens only; dark + V2 + 320 px; mobile-impact classified as in `NC4`.

### 2.6 `NC6` — Synamail `docs/AUTH_FLOW.md` update (partner-app)

Same change set as `NC4` (the document's own rule): rename the bridge to
`PlatformConnectView.vue` under "Files in the flow", add the `/addin/connect`
redirect to steps 2–3, keep every invariant, add a triage row "dialog lands on
`/connect/platform` without `client=outlook`". No Synamail code changes;
`tests/unit/useAuth.test.ts` (`buildDialogUrl` targets `/addin/connect`) stays green.

### 2.7 `NC7` — Fake instance harness (backend-only)

`_devextras/testing/platform-links/fake-instance.sh` (+ `lib.sh` copied from
`_devextras/testing/desktop/`): register an instance with the demo admin key, log
in as demo, request a code with `redirect_uri=https://fake.example/cb`, exchange
it, call `GET /api/v1/auth/me` (200) and `GET /api/v1/admin/users` (403) with the
minted key, replay the code (400), disconnect via `DELETE /api/v1/me/platform-links/{id}`,
verify the key is 401. S1 acceptance demo and the S2 developer's local target.

## 3. Tests and invariants

| Invariant | Proof in this sprint |
| --------- | -------------------- |
| C1 | `PlatformLinkControllerTest::testAllRoutes404WhenFlagOff`; `PlatformConnectView.spec.ts` Outlook cases pass with `platformLinksEnabled=false`; `useNavItems.spec.ts` hides the child; Synamail steps below |
| C3 | `PlatformLinkExchangeServiceTest::testExchangeNeverTouchesUsers` — `BUSER` row count and the linked user's row are byte-identical before/after |
| C4 | `ApiKeyScopeTest::testPlatformLinkScopesEqualProvisionedScopes`; `testGrandfatherUnchanged` still green |
| C5 | `LinkCodeServiceTest`: `testSecondConsumeReturnsNull`, `testExpiredCodeReturnsNull`, `testCodeBoundToInstance`; controller: replay 400, foreign instance 400, pending instance 403, wrong secret 401 |
| C6 | `RedirectUriPolicyTest` corpus: other host, `files.example.org.evil.example`, `files.example.org@evil.example`, `http://` downgrade, `//evil.example`, `javascript:`, `\` and `%2F` tricks, `/apps/x/../../evil`, port mismatch, trailing dot, mixed case, IDN look-alike — all rejected; exact prefix accepted |
| C7 | `AdminUserProvisioningServiceTest::testFindByExternalIdentityStillReadsUserDetails` unchanged and green |
| C8 | mobile-impact script reports `backend-only` + `ota-candidate` only; i18n parity for `platformConnect` and `linkedPlatforms`; unfiltered backend + frontend gates |

Synamail `docs/AUTH_FLOW.md` steps as acceptance tests (`PlatformConnectView.spec.ts`, `router/addinConnectRedirect.spec.ts`, then a manual run on the `:5174` bridge):

| Step | Test |
| ---- | ---- |
| 1 | Synamail `buildDialogUrl` tests unchanged; dialog URL still `/addin/connect?state&label&redirect` |
| 2–3 | `redirectsUnauthenticatedToLoginWithFullPath`: `/addin/connect?state=a&label=b&redirect=c` → `/login?redirect=%2Fconnect%2Fplatform%3Fclient%3Doutlook%26state%3Da%26label%3Db%26redirect%3Dc` |
| 4 | `LoginView` returns to the full path; `redirect` present after login (existing `isSafeRedirectPath` test + new assertion) |
| 5 | `assignsRelayWithPayloadFragment`: `location.assign('<relay>#payload=<base64>')`; payload has `state`, `apiKey`, `keyId`, `email`, `baseUrl`; scopes equal `ApiKeyScope::addinScopes()` |
| 6–7 | manual: relay calls `messageParent`, taskpane validates `state`, dialog closes (Outlook desktop + OWA) |
| 8 | `DELETE /api/v1/apikeys/{id}` with the key itself → 200 (`isSelfRevoke` test exists) |
| Inv. 6 | `ignoresUnsafeRelayHost`: `https://evil.example/relay` → fallback channel, no `location.assign` |

## 4. Exit criteria / demo

1. Flag off: `fake-instance.sh` gets 404 on every `platform-links` route; the Outlook flow passes steps 1–8 on the local bridge.
2. Flag on: `fake-instance.sh` completes end to end; the key is restricted; the link appears under Connections → Linked platforms and as a badge in API keys; Disconnect revokes it; an anonymous registration exchanges only after Approve in Operate → People.
3. OpenAPI → Zod regenerated; five locales; C1–C8 named in every PR description.

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| NC1 | `feat(platform-links): add BPLATFORMINSTANCES migration, flag and runtime config` | backend-only | IAM1 |
| NC2 | `feat(platform-links): register instances with admin key or pending approval` | backend-only | NC1 |
| NC3 | `feat(platform-links): issue link codes, exchange them for scoped keys, list my links` | backend-only | NC2 |
| NC4 | `feat(platform-links): generalize AddinConnectView into PlatformConnectView` | ota-candidate | NC3 |
| NC5 | `feat(platform-links): add Linked platforms pages under Connections and People` | ota-candidate | NC3, IAM8 |
| NC6 | `docs(auth-flow): describe the PlatformConnectView bridge and /addin/connect redirect` | partner-app | NC4 |
| NC7 | `test(platform-links): add fake instance handshake harness` | backend-only | NC3 |
