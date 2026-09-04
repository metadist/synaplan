# Sprint 3 — Parity and fallbacks

**Track 6, sprint 3 of 3.** Steps `NC18`–`NC24`.

**Goal:** ownCloud.online users link like Nextcloud users; headless or
redirect-hostile setups link with a short code; the docs describe the three
modes correctly (including the OpenCloud correction); the flag ships on for
new installs.
**Depends on:** S1 and S2 merged; the Nextcloud 1.6.0 App Store release at
least submitted. Master plan decisions 12, 14; §8 rollout; §11 rows 4, 8.
**Unlocks:** track close (directory moves to `2026-archive/` with a note).
**Repos:** `synaplan/` (pairing variant, seeder, Collabora pointer),
`synaplan-owncloud-online/` (parity), `synaplan-docs/` (`docs/plugins.md`).
**Flag:** `PLATFORM_LINKS.ENABLED` — seeder default flips to on for new
installs in `NC23`; existing installs flip by hand.
**Cut line (master plan §7):** pairing-code variant first (`NC18`, `NC19`),
then ownCloud.online parity (`NC20`, `NC21`) if no hoster is waiting. Never
cut C1 / C5 / C6.

## 0. Why this sprint exists

The browser redirect is the right default but not always possible: an admin
scripting a fleet, a partner app served on a host the user's browser cannot
reach back from, or a kiosk browser that blocks cross-site navigation. The
Desktop pairing code already solved "no redirect" for computers; the same
eight characters solve it for platforms. Parity and docs finish the track so
that "three modes" is true in every place a hoster reads.

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/Desktop/PairingCodeService.php` (`ALPHABET`, `CODE_LENGTH = 8`, `MAX_OUTSTANDING = 5`, `MAX_PER_HOUR = 20`, `consume()`) | Shape to copy for `PlatformPairingCodeService` — copy, do not refactor |
| `backend/src/Controller/DesktopController.php` (`pair()`, `allowPairAttempt()`) | Unauthenticated exchange with a per-IP limit |
| `backend/src/Service/PlatformLink/PlatformLinkExchangeService.php` (S1 `NC3`) | The one place that mints and writes identity rows — reused by `/pair` |
| `frontend/src/views/PlatformConnectView.vue`, `frontend/src/platform-connect/clients.ts` (S1 `NC4`) | Gains the `mode=code` display |
| `synaplan-owncloud-online/lib/Service/SynaplanConfig.php` (`per_user_accounts`, `getInstanceId()` from `instanceid`), `lib/Service/UserAccountService.php` (`provisionAndMint()`, `SynaplanClient::SOURCE = 'owncloud'`, `USER_KEY_SCOPES`), `lib/Service/SynaplanClient.php` (`getApiKey()`, 401 path) | The PHP twins of the S2 files |
| `synaplan-owncloud-online/appinfo/info.xml` (`<settings><admin>`, `<settings-sections>`), `appinfo/routes.php`, `lib/Settings/Admin.php` (`getPanel(): Template`), `lib/Settings/Section.php` | ownCloud 11 settings API differs from Nextcloud's `ISettings` |
| `synaplan-owncloud-online/src/components/AdminSettings.vue`, `lib/Controller/ConsentController.php`, `lib/Controller/SettingsController.php` | Twins of the S2 admin and gate changes |
| `synaplan-owncloud-online/Makefile` (`lint`, `check-types`, `package` — no `test` target) | The repo has no `tests/` yet |
| `synaplan-docs/docs/plugins.md` §"Cloud Storage Integrations" (lines "### Nextcloud", "### OpenCloud (ownCloud Infinite Scale / oCIS)", "### ownCloud.online", "Switching environments") | The sections to rewrite |
| `synaplan-opencloud/backend/internal/tokenexchange/` | The real OpenCloud model the docs must describe |
| `_devextras/planning/20260902-collabora-integration/05_epic_4_partner_platforms.md` Step 4.1 | The pointer to update |
| `backend/src/Seed/BConfigSeeder.php`, `backend/src/Command/SeedAllCommand.php` | Flag default flip |

## 2. Developer steps

### 2.1 `NC18` — Pairing-code variant in the core (backend-only)

`PlatformPairingCodeService` (copy of `PairingCodeService`; same alphabet
`23456789ABCDEFGHJKMNPQRSTVWXYZ`, 8 characters):

```text
platform_link:pair:{CODE}              -> JSON {userId, instanceId, expiresAt}, TTL 600 s
platform_link:pair_outstanding:{userId} -> SET, max 5 valid codes
platform_link:pair_hour:{userId}        -> counter, TTL 3600 s, max 20
platform_link:pair_attempt:{sha1(ip)}   -> counter, TTL 3600 s, max 60
```

- `POST /api/v1/platform-links/pairing-codes` (session, flag on) body `{ instance_id }` → `{ code, expiresAt }`; requires an `active` instance whose `BCLIENT !== 'outlook'`. Never logged at info.
- `POST /api/v1/platform-links/pair` (no session, per-IP limit) body `{ instance_id, instance_secret, code, external_id }` → same response as `/exchange`, produced by `PlatformLinkExchangeService::exchange()` after `consume()`; a code minted for another instance is a 400 with the same message as unknown/expired (no enumeration). Identity row and key label identical to the redirect flow.
- `PlatformConnectView` `mode=code`: `/connect/platform?client=nextcloud&instance=<id>&mode=code` shows the code with expiry and "Type this into Nextcloud → Settings → Synaplan" instead of redirecting. No `redirect_uri`, no `state`, no navigation — the partner app opens this URL in a new tab.
- Linked platforms page: no change (the code is requested from the partner side to avoid listing instances to users).
- Full OpenAPI, Zod regenerated, `fake-instance.sh --code` path added.

### 2.2 `NC19` — "I have a code" in the Nextcloud app (partner-app, `synaplan-nextcloud/`)

- Personal settings: link **Get a connection code** (opens `mode=code` in a new tab) + input **Enter code** → `link#pair` (`POST /link/pair`, CSRF-protected) → `PlatformLinkService::pair($code, $externalId)` → same prefs as the callback path.
- `occ synaplan:link-user <uid> <code>` (`lib/Command/LinkUser.php`, `<commands>` in `info.xml`) for admins scripting a fleet.
- App version `1.6.1`; same tests as `LinkControllerTest` for the pair path (`testPairRejectsMalformedCode`, `testPairStoresPrefsOnSuccess`).

### 2.3 `NC20` — ownCloud.online: mode + link routes (partner-app, `synaplan-owncloud-online/`)

Copy the S2 PHP 1:1 into the `OCA\OcoSynaplan` namespace:

- `lib/Service/SynaplanConfig.php`: `getMode()` with the same fallback on `per_user_accounts`; `link_*` app values via `\OCP\Security\ICrypto`.
- `lib/Service/UserAccountService.php`: `resolveKeyForUser()`; `provisionAndMint()` keeps `source => SynaplanClient::SOURCE` (`owncloud`) and `external_id => "<instanceId>:<uid>"`.
- `lib/Service/PlatformLinkService.php` (new), `lib/Controller/LinkController.php` (new): `client=owncloud`, routes `link/start`, `link/callback`, `link/disconnect`, `link/pair`, `api/v1/link/status` in `appinfo/routes.php`; `@NoCSRFRequired` only on `callback`, `state` in `ISession`.
- `lib/Service/SynaplanClient.php`: 401 in `link` mode clears prefs, no re-mint.
- Key label on the Synaplan side is `ownCloud.online: <host> (<uid>)` (S1 already maps `owncloud`).

### 2.4 `NC21` — ownCloud.online: settings UI, gate, tests (partner-app)

- Personal panel: `lib/Settings/Personal.php` (`ISettings::getPanel(): Template`, section `synaplan`) registered under `<settings><personal>` in `info.xml`; `src/personal-settings.ts`, `src/components/PersonalSettings.vue`.
- `src/components/AdminSettings.vue`: mode selector, **Register this instance**, auto-provision toggle, Linked / Provisioned badges — same strings as Nextcloud, brand text "ownCloud.online" spelled in full.
- Gate: the consent UI in `src/components/ChatPanel.vue` / Files actions gains the two options (the repo has no `AiConsentGate.vue`; add `src/components/AiConsentGate.vue` mirroring Nextcloud's).
- `tests/` is **new**: `phpunit.xml`, `tests/bootstrap.php`, `tests/stubs/` (copy the Nextcloud app's stub approach), `tests/Unit/Service/SynaplanConfigTest.php`, `tests/Unit/Controller/LinkControllerTest.php`; add `test` and `test-php` targets to the `Makefile` and run them in CI. App version `1.1.0`.

### 2.5 `NC22` — Docs: `plugins.md` rewrite + Collabora pointer (docs)

`synaplan-docs/docs/plugins.md`:

- §Nextcloud: replace the "Optional per-user accounts" bullet and setup step 3 with a **Modes** table — `shared` (one key), `provision` (admin key creates accounts), `link` (each user connects an existing account; optional auto-provision) — and a **Connect an existing account** walkthrough: admin registers the instance (approval path included), user opens Settings → Synaplan → Connect, signs in, confirms, back in Nextcloud; disconnect from either side; "I have a code" alternative. Screenshots: admin mode selector, personal section, Synaplan confirm card, Linked platforms page (stored with the other docs images).
- §"Switching environments": add `synaplan_link_kind`, `synaplan_link_email`, `synaplan_linked_at`, `synaplan_user_key_id` to the per-user reset loop and `link_instance_id` / `link_instance_secret` to the app-config reset.
- §OpenCloud: **correct the prose**. Delete "Generate an API token or app password in your OpenCloud instance / Configure the connection in Synaplan's admin panel". Write what the repo does: the extension runs inside OpenCloud and obtains a per-user Synaplan identity through **RFC 8693 token exchange** against the shared Keycloak realm (`synaplan-opencloud/backend/internal/tokenexchange/`); a static API key is only a single-tenant fallback; **OpenCloud is not part of link mode** and needs no registration.
- §ownCloud.online: same three-mode text as Nextcloud, brand in full.

`_devextras/planning/20260902-collabora-integration/05_epic_4_partner_platforms.md`
Step 4.1: the Nextcloud bullet's "Synaplan key the `synaplan-nextcloud` app
already holds for that user" gets "(from `shared`, `provision`, or `link`
mode — track 6, `202609_more_nextcloud/`)"; the ownCloud Online bullet stops
pointing at the OpenCloud investigation and references the same PHP-app key.

### 2.6 `NC23` — Rollout (backend-only, master plan §8)

1. `BConfigSeeder`: `PLATFORM_LINKS.ENABLED` default `'1'` for **new** installs; `SeedAllCommand` help text updated. Existing installs are untouched (seeder values are bootstrap-only) — `docs/MIGRATIONS.md` note plus an admin one-liner in the release notes: `UPDATE BCONFIG SET BVALUE='1' WHERE BGROUP='PLATFORM_LINKS' AND BSETTING='ENABLED' AND BOWNERID=0`.
2. Synaplan Cloud: flip after the Nextcloud 1.6.x App Store listing is live and `plugins.md` is published (docs first).
3. Rollback = flag off: linked keys are ordinary API keys and keep working; only new links and exchanges stop (404). Document in the release notes.

### 2.7 `NC24` — Success criteria run + track close (docs)

Run the checklist in §4 against Synaplan Cloud staging with a real Nextcloud
and a real ownCloud.online; record results in `STATUS.md`; move the directory
to `_devextras/planning/2026-archive/` with a closing note; add the "shipped
in vX.Y" entry to `20260903_roadmap.md` §2.

## 3. Tests and invariants

| Invariant | Proof in this sprint |
| --------- | -------------------- |
| C1 | ownCloud.online: `SynaplanConfigTest::testUnsetModeResolvesToLegacyBehaviour`; all pre-existing behaviour covered by the new `tests/` before any `link` code lands (`NC21` tests are written against `NC20`'s untouched shared/provision paths first). Core: pairing routes 404 when the flag is off (`PlatformLinkPairingTest::testRoutes404WhenFlagOff`) |
| C3 | `PlatformLinkPairingTest::testPairNeverTouchesUsers` |
| C4 | `/pair` reuses `PlatformLinkExchangeService` — `testPairMintsSameScopesAsExchange` |
| C5 | `PlatformPairingCodeServiceTest`: `testSecondConsumeReturnsNull`, `testExpiredCodeReturnsNull`, `testCodeBoundToInstance`, `testSixthOutstandingCodeFails`; controller: wrong secret 401, foreign instance 400 |
| C6 | `mode=code` has no redirect: `PlatformConnectView.spec.ts::codeModeNeverNavigates`; the S1 `RedirectUriPolicyTest` corpus stays green |
| C7 | Unchanged provisioning path in both apps still sends `source` + `external_id` |
| C8 | mobile-impact: `backend-only` for the core PRs; `PlatformConnectView` change `ota-candidate`; partner apps are their own channels |

Gates: `synaplan/` full pre-commit gate; `synaplan-nextcloud/` `make lint && make test && make build`;
`synaplan-owncloud-online/` `make lint && make test && make build` (new `test`);
`synaplan-docs/` link check + build.

## 4. Exit criteria / demo — success criteria checklist (master plan §10)

| # | Criterion | How it is shown |
| - | --------- | --------------- |
| 1 | NC user with an existing account connects in under a minute without typing a key; next Files action answers from their knowledge | S2 demo repeated on staging with a stopwatch; request log shows the `Nextcloud: <host> (<uid>)` key |
| 2 | Disconnect in Synaplan → NC shows Connect again; disconnect in NC → key gone in Synaplan | Both directions on Nextcloud **and** ownCloud.online |
| 3 | Admin switches `provision → link`: existing users keep working; new users get the two-option gate | Staging instance upgraded 1.5 → 1.6 with two provisioned users and one new user |
| 4 | Open-redirect, replayed code, wrong-instance exchange fail with clear errors and audit rows | `fake-instance.sh` negative section + `RedirectUriPolicyTest` + `BAUDITLOG` rows (`platform_link.exchange_failed`, `platform_link.redirect_rejected`) |
| 5 | Outlook add-in sign-in passes every step in Synamail's `docs/AUTH_FLOW.md` | Manual run on the `:5174` bridge and on `web.synaplan.com` after deploy; results recorded in `STATUS.md` |
| 6 | Flag off: `synaplan/` gate green; NC and ownCloud.online app tests green in both old modes | CI on all three repos with the flag off in the test env |
| + | Headless: `occ synaplan:link-user` links a user with a code; docs describe three modes and the corrected OpenCloud model | Demo + published docs page |

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| NC18 | `feat(platform-links): add pairing-code variant for headless linking` | backend-only + ota-candidate | S1 |
| NC19 | `feat(link-mode): link with a connection code and occ synaplan:link-user` | partner-app | NC18, S2 |
| NC20 | `feat(link-mode): add link mode, routes and key resolution to the ownCloud.online app` | partner-app | S2 |
| NC21 | `feat(link-mode): personal settings, admin selector, gate and PHPUnit suite for ownCloud.online` | partner-app | NC20 |
| NC22 | `docs(plugins): describe shared, provision and link modes; correct the OpenCloud section` | docs | NC19, NC21 |
| NC23 | `feat(platform-links): enable PLATFORM_LINKS by default for new installs` | backend-only | NC22 |
| NC24 | `docs(planning): close track 6 with success criteria results` | docs | NC23 |
