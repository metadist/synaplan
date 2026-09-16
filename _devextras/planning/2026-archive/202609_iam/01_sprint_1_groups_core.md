# Sprint S1 — Groups core

**Track 1 (IAM), sprint 1 of 5.** Steps `IAM1`–`IAM10`.

**Goal:** An admin creates "Sales" and adds three people on a new **People** page under Operate. Nothing else changes: `AccessGate` exists but answers "owner only"; nothing can be shared yet.
**Depends on:** [`00_master_plan.md`](./00_master_plan.md) §0 ticked; plan review with no open S1 objection in [`STATUS.md`](./STATUS.md).
**Unlocks:** S2 (`BSHARES` needs gate + kind registry), track 6 S1 (`BEXTERNALIDENTITIES`), track 2 (`assistant` kind).
**Repos:** `synaplan/` only.
**Flag:** `IAM.GROUPS_ENABLED` (BCONFIG group `IAM`, setting `GROUPS_ENABLED`, seeded `0`). New routes 404 when off; nav unchanged.

---

## 0. Why this sprint exists

Every later track asks "who may use this?". This sprint builds the one answer (owner, group, gate, kind) without
letting anybody share anything yet, so the seam merges to `main` flags-off (C1). The first PR on the authorization
seam (`IAM3`) is a **refactor with identical behavior**: the voter says exactly what today's `userId === ownerId` checks say.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/Desktop/DesktopAgentConfig.php`, `backend/src/Seed/DesktopAgentConfigSeeder.php` | Flag resolver (user row → global row → code default OFF) and seeded-off pattern for `IamConfig` |
| `backend/src/Controller/SavedTaskController.php` (`guard()`) | 404-when-disabled pattern for every new route |
| `backend/src/Controller/DesktopController.php`, `backend/tests/Controller/DesktopControllerTest.php` | Newest CRUD controller with full OpenAPI; feature-test style (`AuthenticatedTestTrait`, flag on/off) |
| `backend/src/Security/ApiKeyScope.php`, `ApiKeyScopeSubscriber.php`, `backend/tests/Unit/Security/ApiKeyScopeTest.php` | Scope vocabulary, `requiredScopesForPath()` prefix map, grandfather rule (C4) |
| `backend/src/Controller/AdminUserProvisioningController.php`, `backend/src/Service/OidcUserService.php` | Today's `source`/`external_id` JSON and `oidc_sub` writes — both gain a `BEXTERNALIDENTITIES` row |
| `backend/src/AI/Service/ProviderRegistry.php`; `backend/migrations/Version20260903000000.php`, `docs/MIGRATIONS.md` | `#[AutowireIterator('app.ai.*')]` registry shape; Galera-safe raw `addSql`, no `Schema` API |
| `frontend/src/views/AdminView.vue` (`activeTab === 'users'`), `frontend/src/composables/useNavItems.ts` (`canSeeOperate`) | The Users tab that moves; where People becomes an Operate child |
| `frontend/src/composables/useSavedTasksFeature.ts`, `backend/src/Controller/ConfigController.php` (`features`) | Runtime-config flag delivery without touching `stores/config.ts` (a store-required path) |
| `frontend/tests/unit/i18n/localeParity.spec.ts`; `backend/src/Security/Voter/` | Five-locale parity gate; **new** — no Symfony voter exists yet |

---

## 2. Developer steps

### 2.1 `IAM1` — Migration: four tables

One migration, raw `addSql`, `CREATE TABLE IF NOT EXISTS`; `down()` drops `IF EXISTS`. No foreign keys (Galera rule 3); `UserDeletionService`
removes a deleted user's `BGROUPMEMBERS` / `BEXTERNALIDENTITIES` rows. Entities `Group`, `GroupMember`, `AuditLogEntry`, `ExternalIdentity` plus repositories; `BPARENTID` mapped but unused (decision 5).

```sql
CREATE TABLE IF NOT EXISTS BGROUPS (
  BID BIGINT NOT NULL AUTO_INCREMENT, BNAME VARCHAR(128) NOT NULL, BSLUG VARCHAR(128) NOT NULL, BDESCRIPTION VARCHAR(512) NOT NULL DEFAULT '',
  BKIND VARCHAR(16) NOT NULL DEFAULT 'manual', BEXTERNALSOURCE VARCHAR(191) NULL, BEXTERNALID VARCHAR(191) NULL, BPARENTID BIGINT NULL,
  BCREATED BIGINT NOT NULL, BUPDATED BIGINT NOT NULL, PRIMARY KEY (BID), UNIQUE KEY uniq_group_slug (BSLUG),
  UNIQUE KEY uniq_group_external (BEXTERNALSOURCE, BEXTERNALID), KEY idx_group_kind (BKIND)
);
CREATE TABLE IF NOT EXISTS BGROUPMEMBERS (
  BGROUPID BIGINT NOT NULL, BUSERID BIGINT NOT NULL, BROLE VARCHAR(16) NOT NULL DEFAULT 'member', BSOURCE VARCHAR(16) NOT NULL DEFAULT 'manual',
  BCREATED BIGINT NOT NULL, PRIMARY KEY (BGROUPID, BUSERID), KEY idx_groupmember_user (BUSERID)
);
CREATE TABLE IF NOT EXISTS BAUDITLOG (
  BID BIGINT NOT NULL AUTO_INCREMENT, BACTORID BIGINT NOT NULL, BACTION VARCHAR(64) NOT NULL, BRESOURCEKIND VARCHAR(64) NOT NULL DEFAULT '',
  BRESOURCEID VARCHAR(191) NOT NULL DEFAULT '', BSUBJECT JSON NULL, BIP VARCHAR(45) NOT NULL DEFAULT '', BCREATED BIGINT NOT NULL,
  PRIMARY KEY (BID), KEY idx_audit_actor_created (BACTORID, BCREATED), KEY idx_audit_resource (BRESOURCEKIND, BRESOURCEID), KEY idx_audit_created (BCREATED)
);
CREATE TABLE IF NOT EXISTS BEXTERNALIDENTITIES (
  BID BIGINT NOT NULL AUTO_INCREMENT, BUSERID BIGINT NOT NULL, BSOURCE VARCHAR(191) NOT NULL, BINSTANCEID VARCHAR(191) NOT NULL DEFAULT '',
  BEXTERNALID VARCHAR(191) NOT NULL, BAPIKEYID BIGINT NULL, BCREATED BIGINT NOT NULL, BLASTSEEN BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (BID), UNIQUE KEY uniq_extid_source (BSOURCE, BINSTANCEID, BEXTERNALID), KEY idx_extid_user (BUSERID)
);
```

### 2.2 `IAM2` — `IamConfig`, seeder, runtime flag

`backend/src/Service/Iam/IamConfig.php` (new): `CONFIG_GROUP = 'IAM'`, `isGroupsEnabled(?int $userId)`, `isSharingEnabled()`,
`isDirectorySyncEnabled()` — resolver copied from `DesktopAgentConfig`. `IamConfigSeeder` inserts `GROUPS_ENABLED`,
`SHARING_ENABLED`, `DIRECTORY_SYNC_ENABLED` = `0` via `BConfigSeeder::insertIfMissing`. `ConfigController` adds runtime
`features.iamGroups` (OpenAPI boolean, default false) → `frontend/src/composables/useIamFeature.ts` (`isIamGroupsEnabled()`).

### 2.3 `IAM3` — `AccessGate` + `IamVoter` (refactor, identical behavior)

`backend/src/Service/Iam/Permission.php`: enum `read|use|edit|manage`, `implies()` with `manage > edit > use > read`.
`backend/src/Service/Iam/AccessGate.php` (`final readonly`): `decide(User $user, string $kind, string $resourceId, Permission $level): bool`;
the S1 body is `kind->ownerId($resourceId) === $user->getId()`, and when `isGroupsEnabled()` is false it returns before touching an
IAM table; per-request memo keyed `(kind, resourceId)`. `backend/src/Security/Voter/IamVoter.php`: attributes `IAM_READ`, `IAM_USE`,
`IAM_EDIT`, `IAM_MANAGE`, subject `ResourceRef(kind, id)`. No controller adopts it yet — S2 migrates `ChatController` and `/files` group routes kind by kind.

### 2.4 `IAM4` — Kind registry with two descriptors

`backend/src/Service/Iam/ResourceKind/`: `ShareableResourceKindInterface` (master plan §4.2 verbatim), `ResourceCard` DTO
(`name`, `icon`, `meta`; never content), `ResourceKindRegistry` (`#[AutowireIterator('app.iam.resource_kind')]`, `get()` throws
`UnknownResourceKindException` naming the key). `KnowledgeFolderKind`: key `knowledge_folder`, resource id `"{ownerId}:{groupKey}"`,
`ownerId()` parses the prefix and verifies a `BFILES` row with that `BUSERID`/`BGROUPKEY`; `listOwnedBy()` reuses the
`/api/v1/files/groups` data. `ConversationKind`: key `conversation`, id `BCHATS.BID`, owner via `ChatRepository`, permissions `[read, use]`.

### 2.5 `IAM5` — Group service + admin API

`backend/src/Service/Iam/GroupService.php`: create (unique slug), rename, delete (manual only; `directory` ⇒ 409),
`setMember(groupId, userId, role)`, `removeMember`, `groupsOf(userId)`; every write appends a `BAUDITLOG` row via
`AuditLogWriter::record()` (`group.create`, `group.rename`, `group.delete`, `group.member_set`, `group.member_remove`).
`AdminGroupController`: `/api/v1/admin/groups` (`GET`, `POST`), `/{id}` (`PATCH`, `DELETE`), `/{id}/members` (`GET`), `/{id}/members/{userId}`
(`PUT`, `DELETE`); admin only; `guard()` 404 when the flag is off. `GroupController`: `GET /api/v1/groups/mine`. Full OpenAPI (`GroupSchema`, `GroupMemberSchema`) → `make -C frontend generate-schemas`.

### 2.6 `IAM6` — `iam:*` scopes

`ApiKeyScope::IAM_READ = 'iam:read'`, `IAM_MANAGE = 'iam:manage'`; `requiredScopesForPath()`: `/api/v1/groups` → `iam:read`,
`/api/v1/admin/groups` → `iam:manage` (implies `iam:read`). Empty and legacy scope lists stay full access (C4).
`APIKeysConfiguration.vue` scope picker lists both (five locales).

### 2.7 `IAM7` — External identities write path (refactor)

`AdminUserProvisioningController` inserts a `BEXTERNALIDENTITIES` row (`BSOURCE = source`, `BEXTERNALID = external_id`) **and**
keeps the `BUSERDETAILS` JSON; lookups read the table first, JSON as fallback. `OidcUserService::findOrCreateFromClaims()`
upserts `BSOURCE = 'oidc:<issuer>'`, `BEXTERNALID = sub`, bumps `BLASTSEEN`. Roles and admin promotion untouched (C3).

### 2.8 `IAM8` — People page shell + wireframes (ota-candidate)

Route `/admin/people` (name `admin-people`, `requiresAdmin`); Operate child in `useNavItems.ts` only when `isIamGroupsEnabled()`.
`frontend/src/views/PeopleView.vue` with `TabNav` tabs `users` / `groups`; `components/people/UsersTab.vue` receives the table moved out
of `AdminView.vue` (the old tab becomes a link to People when the flag is on, unchanged when off) and adds *groups* and *identities* badge
columns. Wireframes for People (Users, Groups, detail) and the S2 ShareDialog land under `_devextras/planning/202609_iam/wireframes/`, reviewed in this PR.

### 2.9 `IAM9` — Groups tab + five-locale vocabulary

`GroupsTab.vue` (name, kind badge *manual* / *From your login*, member count; create / rename / delete via `useDialog()`),
`GroupDetailPanel.vue` (members with role, add by email/name picker, "shared with this group" list — empty until S2),
`frontend/src/services/api/iamApi.ts` on `httpClient` with generated Zod. i18n namespaces `people` (`people.title`, `people.tabs.users`,
`people.tabs.groups`, `people.groups.fromLogin`, …) and `iam` (`iam.permission.read|use|edit|manage`, `iam.share`, `iam.sharedWithMe`, `iam.everyone`, `iam.owner`) — master plan §7 words in `en/de/es/fr/tr`.

### 2.10 `IAM10` — Mobile-impact classification + docs

`backend/**` and `frontend/src/**` are already allow-listed in `.github/mobile-impact-policy.json`; add fixture cases to
`tests/mobile-impact.test.mjs` (`backend/src/Service/Iam/AccessGate.php` → backend-only, `frontend/src/views/PeopleView.vue` →
ota-candidate) and run `node scripts/mobile-impact.mjs --base main --head HEAD` on every S1 PR. `docs/ADMIN.md` gains "People and groups".

---

## 3. Tests and invariants

| Invariant | Proof |
| --------- | ----- |
| C1 flags off | `AdminGroupControllerTest::testRoutesAre404WhenFlagOff`, `GroupControllerTest::testMineIs404WhenFlagOff`; `PeopleView.spec.ts` + `useNavItems.spec.ts`: no Operate child when `features.iamGroups` is false; `AccessGateTest::testFlagOffNeverQueriesIamTables` |
| C3 OIDC | `OidcLoginClaimsTest` unchanged; new `OidcUserServiceExternalIdentityTest`: one row per `sub`, `BLASTSEEN` bumps, roles identical |
| C4 API keys | `ApiKeyScopeTest`: legacy key reaches `/api/v1/admin/groups`; `iam:read` key 403 on `POST`; `iam:manage` passes |
| C5 snapshots | `RoutingCharacterizationTest` untouched — no edit to `MessageClassifier` / `MessageSorter` |
| C7 mobile | `scripts/mobile-impact.mjs` per PR; new `tests/mobile-impact.test.mjs` cases |
| C8 admin read | `AccessGateTest::testAdminIsNotOwner`: admin, someone else's `conversation`, `IAM_READ` / `IAM_USE` ⇒ false |

Also `ProductionMigrationSafetyTest` on the new migration, `GroupServiceTest` (slug uniqueness, directory delete ⇒ 409, one audit row per
write), `localeParity.spec.ts` with no new ledger entry, and the unfiltered gate `make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types`.

---

## 4. Exit criteria / demo

1. Flag off on a seeded install: Operate children unchanged, `/api/v1/admin/groups` 404, `AdminView.vue` Users tab as before.
2. Flag on: admin creates "Sales", adds three users by email (one as manager); `GET /api/v1/groups/mine` for a member lists "Sales".
3. Restricted key with `iam:read` lists groups and cannot create one.
4. OpenAPI → Zod regenerated; gate green; snapshots untouched; wireframes approved.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| `IAM1` | `feat(iam): add BGROUPS, BGROUPMEMBERS, BAUDITLOG and BEXTERNALIDENTITIES tables` | backend-only | — |
| `IAM2` | `feat(iam): add IamConfig flags, seeder and runtime feature bit` | backend-only | `IAM1` |
| `IAM3` | `refactor(iam): introduce AccessGate and IamVoter with owner-only decisions` | backend-only | `IAM2` |
| `IAM4` | `feat(iam): add resource kind registry with knowledge_folder and conversation` | backend-only | `IAM3` |
| `IAM5` | `feat(iam): add group service and admin group API` | backend-only | `IAM1`, `IAM2` |
| `IAM6` | `feat(iam): add iam:read and iam:manage API key scopes` | backend-only | `IAM5` |
| `IAM7` | `refactor(iam): write external identities beside BUSERDETAILS JSON` | backend-only | `IAM1` |
| `IAM8` | `feat(iam): add People page under Operate and move the Users tab` | ota-candidate | `IAM2`, `IAM5` |
| `IAM9` | `feat(iam): add Groups tab, group detail and five-locale IAM vocabulary` | ota-candidate | `IAM8` |
| `IAM10` | `test(iam): classify IAM paths in mobile-impact tests and document People` | backend-only | `IAM8` |
