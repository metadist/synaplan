# Sprint S4 — Directory & privacy

**Track 1 (IAM), sprint 4 of 5.** Steps `IAM29`–`IAM38`.

**Goal:** A user logging in through Keycloak lands in the right groups; every share, group change and impersonation leaves
an audit row an admin can read on People → **Audit**; admins manage everything and read nothing they were not given.
**Depends on:** S2 (`BSHARES`, `ShareService`); S1 `BAUDITLOG`, `AuditLogWriter`, `BEXTERNALIDENTITIES`, People page.
**Unlocks:** Track 4 (approval audit reuses `BAUDITLOG`), track 6 (external identities carry linked accounts), SCIM (v2).
**Repos:** `synaplan/` only; `synaplan-opencloud/` is exercised by the regression check, not changed.
**Flag:** `IAM.DIRECTORY_SYNC_ENABLED` (seeded `0`) gates the claim sync. Audit writes and admin metadata-only enforcement
follow `IAM.GROUPS_ENABLED`. Settings: `IAM.DIRECTORY_GROUPS_CLAIM = groups`, `IAM.ADMIN_IMPERSONATION = audited`,
`IAM.AUDIT_RETENTION_DAYS = 365`.

---

## 0. Why this sprint exists

Groups an admin types by hand do not scale past one department; the company directory already knows who belongs where.
And an IAM without an audit trail, or with admins who can read everything, is not one a hoster can sell. The seam starts with
a **refactor with identical behavior** (`IAM29`): the claim resolver `OidcUserService` already has is extracted so role
mapping and group sync read the same claims through one code path — with sync off, the login is byte-identical (C3).

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/OidcUserService.php` (`findOrCreateFromClaims`, `syncRoles`, `resolveClaimPath`, `parseRoleClaims`) | The dotted-path claim resolver to reuse; `OIDC_ROLE_CLAIMS` default already lists `groups` — as role names, which stays |
| `backend/src/Controller/KeycloakAuthController.php` (`findOrCreateFromClaims($userInfo, $refreshToken)`), `backend/src/Security/OidcBearerAuthenticator.php` (`findOrCreateFromClaims($claims)`) | Both entry points — browser login and the token-exchanged bearer — resolve to the same `BUSER` by `sub`; sync must hook both |
| `backend/src/Service/OidcTokenService.php` (`validateBearerToken`, `resolveLoginClaims`) | Audience + issuer check for bearers; the issuer string becomes `BSOURCE = 'oidc:<issuer>'` |
| `/wwwroot/synaplan-opencloud/backend/internal/tokenexchange/exchange.go` | RFC 8693 exchange that mints the bearer OpenCloud sends — read only, for the regression scenario |
| `backend/src/Service/ImpersonationService.php` (`startImpersonation`, `stopImpersonation`), `backend/src/Controller/AdminImpersonationController.php` (`POST /api/v1/admin/impersonate/{userId}`, `/exit`) | Audit row insertion points; `disabled` option |
| `backend/tests/Controller/AdminImpersonationControllerTest.php`, `backend/tests/Unit/Service/ImpersonationServiceTest.php`, `backend/tests/Controller/ProfileUpdateImpersonationFullFlowTest.php` | C9 baseline — must pass unchanged plus one audit assertion |
| `backend/tests/Service/OidcLoginClaimsTest.php`, `OidcTokenServiceTest.php` | C3 baseline |
| `backend/src/Command/ReapDesktopJobsCommand.php`, `ReapEphemeralFilesCommand.php` | Reaper command shape for the retention job |
| `backend/src/Controller/AdminSystemConfigController.php`; `docker-compose.yml` (`profiles: [oidc]`, Keycloak test realm) | Settings exposure; the local realm the regression check runs against |
| `frontend/src/views/PeopleView.vue` (S1), `frontend/src/components/ImpersonationBanner.vue` | Audit tab host; banner text when impersonation is disabled |

---

## 2. Developer steps

### 2.1 `IAM29` — Extract `OidcClaimResolver` (refactor, identical behavior)

`backend/src/Service/Auth/OidcClaimResolver.php`: `paths(string $spec, string $clientId): list<list<string>>` (today's
`parseRoleClaims`) and `values(array $claims, list<list<string>> $paths): list<string>` (today's `resolveClaimPath` loop).
`OidcUserService::syncRoles()` uses it; `OidcLoginClaimsTest` passes without edits. Adds `issuer()` extraction from `iss`.

### 2.2 `IAM30` — Directory group sync

`backend/src/Service/Iam/DirectoryGroupSync.php`: `sync(User $user, array $claims): void`, called from
`findOrCreateFromClaims()` after `syncRoles()` when `IAM.DIRECTORY_SYNC_ENABLED` is on. Reads `IAM.DIRECTORY_GROUPS_CLAIM`
(dotted path, same resolver), upserts `BGROUPS` (`BKIND = directory`, `BEXTERNALSOURCE = 'oidc:<issuer>'`,
`BEXTERNALID = <claim value>`, `BNAME` from the optional mapping `IAM.DIRECTORY_GROUP_NAMES` JSON `{ "<value>": "<display>" }`,
`BSLUG = dir-<slug>`), then reconciles the user's `BGROUPMEMBERS` rows with `BSOURCE = directory`: insert missing, delete those
no longer in the claim; rows with `BSOURCE = manual` are never touched. The bearer path runs per request, so sync is skipped
when `BEXTERNALIDENTITIES.BLASTSEEN` for `(oidc:<issuer>, sub)` is younger than 300 s. Role/admin mapping unchanged (C3).

### 2.3 `IAM31` — Audit coverage

`AuditLogWriter::record(actor, action, kind, resourceId, subject, ip)` now called for `share.grant`, `share.revoke`
(`ShareService`), `group.*` (S1), `directory.sync` (one row per membership change), `impersonation.start`,
`impersonation.stop`, `admin.metadata_view` (an admin opening another user's resource card or share list). `BSUBJECT` JSON:
`{ "subjectType", "subjectId", "permission", "targetUserId" }` as applicable. Never content, never tokens.

### 2.4 `IAM32` — Admin metadata-only enforcement

`AccessGate::decide()`: `BUSERLEVEL = ADMIN` ⇒ `manage` on every kind (share, unshare, delete) — **not** `read` / `use` /
`edit`. Content routes guarded by `IamVoter` (`/api/v1/chats/{id}`, `/{id}/messages`, `/api/v1/files/{id}/content|download`,
`/api/v1/prompts/{id}`, saved-task runs, widget sessions) therefore answer 403/404 to admins exactly as to anyone else.
`GroupDetailPanel` "shared with this group" and the People → Users row expansion use `describe()` only (`ResourceCard`).
`GET /api/v1/admin/users/{id}/resources` (new, admin) lists `ResourceCard`s per kind with share counts — metadata, audited.

### 2.5 `IAM33` — Impersonation: audit row + `disabled`

`ImpersonationService::startImpersonation()` writes `impersonation.start` (actor = admin, `targetUserId`, `BIP`);
`stopImpersonation()` writes `impersonation.stop`. `IAM.ADMIN_IMPERSONATION` = `audited` (default) | `disabled`:
`AdminImpersonationController` returns `403` with `iam.impersonationDisabled` when disabled; the People → Users action and
`ImpersonationBanner.vue` copy follow. Nothing else in the cookie/stash flow changes (C9).

### 2.6 `IAM34` — OpenCloud regression check

`backend/tests/Integration/OidcBearerIdentityTest.php`: two requests with the same Keycloak `sub` — one through
`KeycloakAuthController` login claims, one through `OidcBearerAuthenticator` with a bearer whose `aud` matches `OIDC_CLIENT_ID`
— resolve to one `BUSER` and one `BEXTERNALIDENTITIES` row; with sync on, `GET /api/v1/groups/mine` and
`GET /api/v1/me/shared?kind=knowledge_folder` return the same groups and shares on both paths. Manual runbook
`_devextras/testing/iam/opencloud-regression.md`: `docker compose --profile oidc up -d`, `synaplan-opencloud` dev stack,
user in Keycloak group `sales` opens the OpenCloud Synaplan panel and sees a folder shared with `sales`.

### 2.7 `IAM35` — Retention job

`backend/src/Command/ReapAuditLogCommand.php` (`app:iam:reap-audit`): deletes `BAUDITLOG` rows older than
`IAM.AUDIT_RETENTION_DAYS` (default 365, `0` = keep forever) in batches of 5 000; scheduled beside the other reapers in
`_devextras/backend/` cron. Seeder rows for the four new settings; `AdminSystemConfigController` schema group "People & audit".

### 2.8 `IAM36` — Audit tab (ota-candidate)

`components/people/AuditTab.vue`: table (when, who, action, kind, resource, subject) fed by `GET /api/v1/admin/audit?actor=&action=&kind=&from=&to=&cursor=`
(admin, `iam:read`, cursor pagination, full OpenAPI). Filters as chips; action labels in five locales
(`people.audit.action.share_grant`, …). Never renders content — `BSUBJECT` only.

### 2.9 `IAM37` — Directory groups in the UI (ota-candidate)

Groups tab: kind badge **From your login** with issuer tooltip, members list read-only for directory rows, "managed by your
login — changes happen at the next sign-in" helper text; manual members may still be added to a directory group (they show a
*manual* badge). Users tab identities column shows `oidc:<issuer>` / `nextcloud` / `opencloud` badges from `BEXTERNALIDENTITIES`.

### 2.10 `IAM38` — Docs + sync demo

`_devextras/testing/iam/directory-demo.sh` against the `oidc` profile realm: assign user to Keycloak group, log in, assert
membership; remove, log in, assert gone; a manual membership survives. `docs/ADMIN.md` "Directory groups", "Audit",
"Admin privacy and impersonation"; `docs/CONFIGURATION.md` lists the five `IAM.*` settings.

---

## 3. Tests and invariants

| Invariant | Proof |
| --------- | ----- |
| C1 flags off | `DirectoryGroupSyncTest::testNoopWhenSyncOff` (no `BGROUPS` write, no query); `AdminAuditControllerTest::test404WhenGroupsOff`; Audit tab hidden in `PeopleView.spec.ts` |
| C3 OIDC | `OidcLoginClaimsTest`, `OidcTokenServiceTest` unchanged after `IAM29`; `DirectoryGroupSyncTest::testRolesUnchangedWhenSyncOn` (admin promotion identical with sync on) |
| C8 admin read | `AccessGateTest::testAdminGetsManageNotRead`; negative feature tests per kind: `ChatControllerTest::testAdminCannotReadForeignChat`, `FileControllerTest::testAdminCannotDownloadForeignFile`, `PromptControllerTest::testAdminCannotReadForeignPrompt`, `WidgetControllerTest::testAdminCannotReadForeignWidgetSessions`; `AdminUserResourcesTest::testReturnsCardsOnly` |
| C9 impersonation | `AdminImpersonationControllerTest` + `ProfileUpdateImpersonationFullFlowTest` unchanged, plus `testStartWritesAuditRow`, `testDisabledReturns403` |
| C7 mobile | `scripts/mobile-impact.mjs`: `IAM29`–`IAM35`, `IAM38` backend-only; `IAM36`–`IAM37` ota-candidate |
| C5 snapshots | `RoutingCharacterizationTest` untouched |

Also `DirectoryGroupSyncTest` (upsert by `(source, externalId)`, manual rows untouched, 300 s bearer throttle),
`OidcBearerIdentityTest` (`IAM34`), `ReapAuditLogCommandTest` (boundary day, `0` keeps), `localeParity.spec.ts`, and the
unfiltered gate `make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types`.

---

## 4. Exit criteria / demo

1. `directory-demo.sh` green: Keycloak group appears after one login, disappears after removal, manual membership survives.
2. OpenCloud regression: a token-exchanged user sees the same groups and shares as in the browser (`IAM34` test + runbook).
3. Admin opens People → Audit and sees who shared what with whom and every impersonation; no route returns another user's content to an admin.
4. `IAM.ADMIN_IMPERSONATION = disabled` blocks the action with a clear message; default behavior identical plus the audit row.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| `IAM29` | `refactor(auth): extract OidcClaimResolver from OidcUserService` | backend-only | `IAM7` |
| `IAM30` | `feat(iam): sync directory groups from the OIDC groups claim` | backend-only | `IAM29` |
| `IAM31` | `feat(iam): audit share, group, directory and admin metadata events` | backend-only | `IAM12` |
| `IAM32` | `feat(iam): grant admins manage without read and add metadata-only resource listing` | backend-only | `IAM31` |
| `IAM33` | `feat(iam): audit impersonation and add IAM.ADMIN_IMPERSONATION disabled option` | backend-only | `IAM31` |
| `IAM34` | `test(iam): verify token-exchanged OIDC bearers resolve to the same user, groups and shares` | backend-only | `IAM30` |
| `IAM35` | `feat(iam): add audit retention reaper and IAM settings in system config` | backend-only | `IAM31` |
| `IAM36` | `feat(iam): add Audit tab to People` | ota-candidate | `IAM31`, `IAM35` |
| `IAM37` | `feat(iam): show directory groups and external identities in People` | ota-candidate | `IAM30` |
| `IAM38` | `test(iam): add directory sync demo script and admin docs` | backend-only | `IAM34`, `IAM37` |
