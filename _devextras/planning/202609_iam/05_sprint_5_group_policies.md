# Sprint S5 — Group policies

**Track 1 (IAM), sprint 5 of 5.** Steps `IAM39`–`IAM46`. **First cut line** of the track (master plan §9).

**Goal:** "Support may only use these two models" holds, and a locked admin default cannot be overridden by a user. A
group-level config layer sits between the user row and the global row for a small allow-list of settings.
**Depends on:** S1 (`BGROUPS`, `BGROUPMEMBERS`, People page, admin group API). Independent of S2–S4.
**Unlocks:** Track 4 (approval policies per group reuse the layer), per-group budgets (v2).
**Repos:** `synaplan/` only.
**Flag:** `IAM.GROUP_POLICIES_ENABLED` (seeded `0`). **New flag** beyond the three in master plan §0 — record it as a
decision entry in [`STATUS.md`](./STATUS.md) when S5 starts. Off ⇒ every resolver returns exactly `[user, global]` as today.

---

## 0. Why this sprint exists

Today a setting is either global (`BCONFIG.BOWNERID = 0`) or per user, and a user may override anything. Teams need "this
department gets these defaults" and admins need "this default is not negotiable". The seam starts with a **refactor with
identical behavior** (`IAM40`): the five private `resolveFlag()` / `readDefaultModel()` copies collapse into one
`LayeredConfigResolver` whose output is byte-identical with the flag off, before any group row is ever read.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/ModelConfigService.php` (`getDefaultModel` — `foreach ($userId ? [$userId, 0] : [0])`, `readDefaultModel`, `getDefaultProvider`, `setDefaultModel`) | The public API that must not change; the owner loop becomes a resolver chain |
| `backend/src/Service/Desktop/DesktopAgentConfig.php`, `backend/src/Service/SavedTask/SavedTaskConfig.php`, `backend/src/Service/Multitask/MultitaskRoutingConfig.php` (`resolveFlag`), `backend/src/Service/Iam/IamConfig.php` (S1) | Per-user → global flag resolvers, all the same shape — collapse into one |
| `backend/src/Service/RateLimitService.php` (`checkLimit`, `getLimitsForLevel` → `RATELIMITS_{level}`) | Tier comes from `BUSERLEVEL`; a group may override the tier, never the limits themselves |
| `backend/src/Entity/Config.php` (`uniq_config_owner_group_setting`), `backend/src/Repository/ConfigRepository.php` (`getValue`, `getByGroup`, `setValue`) | `BCONFIG` shape mirrored by `BGROUPCONFIG`; `BLOCKED` lands on `Config` |
| `backend/src/Controller/ConfigController.php` (`/api/v1/config/models`, `/models/defaults` GET/POST/reset) | Where allowed models are filtered and locked defaults are rejected |
| `backend/src/Controller/AdminSystemConfigController.php` (`/schema`, `/values`) | Global rows the "locked" toggle attaches to |
| `backend/src/Model/ModelCatalog.php` (`findBidByKey`, `service:providerId:tag`) | Allowed-model lists reference catalog keys, never BIDs |
| `frontend/src/components/config/AIModelsConfiguration.vue`, `frontend/src/components/ModelSelect.vue`, `frontend/src/views/PeopleView.vue` (S1) | User model settings that show the lock; the tab host for Policies |
| `_devextras/planning/20260903_roadmap.md` §8.1 | Portability bundle: group policies are instance-local and **not** exported (`model_preferences` = the user's own rows, track 3) |

---

## 2. Developer steps

### 2.1 `IAM39` — Migration: `BGROUPCONFIG` + `BCONFIG.BLOCKED`

```sql
CREATE TABLE IF NOT EXISTS BGROUPCONFIG (
  BID BIGINT NOT NULL AUTO_INCREMENT, BGROUPID BIGINT NOT NULL, BGROUP VARCHAR(64) NOT NULL, BSETTING VARCHAR(96) NOT NULL,
  BVALUE TEXT NOT NULL, BCREATED BIGINT NOT NULL, BUPDATED BIGINT NOT NULL, PRIMARY KEY (BID),
  UNIQUE KEY uniq_groupconfig_group_setting (BGROUPID, BGROUP, BSETTING), KEY idx_groupconfig_group (BGROUPID)
);
ALTER TABLE BCONFIG ADD COLUMN IF NOT EXISTS BLOCKED TINYINT(1) NOT NULL DEFAULT 0;
```

Entity `GroupConfig` + `GroupConfigRepository` (`getForGroups(groupIds, group, setting)`, `setValue`, `deleteValue`);
`Config` gains `blocked`. `GroupService::delete()` removes the group's rows (no cascade). Seeder: `IAM.GROUP_POLICIES_ENABLED = 0`.

### 2.2 `IAM40` — `LayeredConfigResolver` (refactor, identical behavior)

`backend/src/Service/Config/LayeredConfigResolver.php` (`final readonly`): `chain(?int $userId, string $group, string $setting):
list<string>` returns candidate values in precedence order and `resolve()` / `resolveBool()` / `resolveInt()` take the first.
In this PR the chain is exactly `[user row?, global row?]`. `ModelConfigService::getDefaultModel()` iterates
`chain($userId, 'DEFAULTMODEL', $setting)` instead of `[$userId, 0]`; `readDefaultModel()` stays as the per-owner helper.
`DesktopAgentConfig`, `SavedTaskConfig`, `MultitaskRoutingConfig`, `IamConfig` delegate their private `resolveFlag()` to
`resolveBool()`. Public signatures and return values unchanged; every existing test passes without edits.

### 2.3 `IAM41` — Allow-list + group layer

`backend/src/Service/Iam/Policy/PolicyAllowList.php` — the only settings a group may set:

| Key | Type | Merge rule across a user's groups |
| --- | ---- | -------------------------------- |
| `DEFAULTMODEL.{CHAT,VECTORIZE,PIC2TEXT,SOUND2TEXT,MEM,TOOLS}` | catalog key | first group by `BGROUPS.BID`; the Policies tab flags conflicts |
| `MODELS.ALLOWED` | JSON list of catalog keys | union; empty = no restriction |
| `SAVEDTASKS.ENABLED`, `DESKTOP_AGENT.ENABLED`, `DOCUMENT_TOOLS.ENABLED`, `MULTITASK.*_ENABLED` | bool | OR |
| `RATELIMITS.TIER` | `NEW\|PRO\|TEAM\|BUSINESS` | highest |

`chain()` with the flag on: if the global row has `BLOCKED = 1` → `[global]` only; else `[user?, merged groups?, global?]`.
Group rows are read once per request (`GroupConfigRepository::getForGroups(groupsOf(userId), …)`, memoized). Keys outside
the allow-list never consult `BGROUPCONFIG`. `AccessGate` is not involved — policies are configuration, not access.

### 2.4 `IAM42` — Admin API

`AdminGroupConfigController`: `GET /api/v1/admin/groups/{id}/config` (allow-listed keys with values and effective source),
`PUT /api/v1/admin/groups/{id}/config` (body `{ "DEFAULTMODEL.CHAT": "openai:gpt-4o:chat", "MODELS.ALLOWED": [...] }`;
unknown key or invalid catalog key ⇒ 422 naming the key). `PATCH /api/v1/admin/config/locks` (`{ "DEFAULTMODEL.CHAT": true }`)
flips `BCONFIG.BLOCKED` on the global row; `GET /api/v1/admin/config/values` gains `locked`. Admin only, `iam:manage`,
`guard()` 404 when the flag is off, audit rows `policy.set`, `policy.lock`. Full OpenAPI → `make -C frontend generate-schemas`.

### 2.5 `IAM43` — Enforcement

`ConfigController::/models` filters to `MODELS.ALLOWED` when the resolved list is non-empty (the user's own overrides that
point outside the list are ignored, not deleted); `/models/defaults` POST rejects a blocked setting with `409` and
`iam.settingLocked`; `GET /models/defaults` returns `locked: true` and `source: 'admin' | 'group' | 'user'` per capability.
`RateLimitService::checkLimit()` resolves `RATELIMITS.TIER` and uses it in place of `BUSERLEVEL` for `getLimitsForLevel()`
(billing tier itself untouched). `ModelConfigService::resolveUsableModelId()` honours the allowed list before its fallback.

### 2.6 `IAM44` — Policies tab (ota-candidate)

`components/people/PoliciesTab.vue`: group selector, then sections *Default models* (`ModelSelect` per capability), *Allowed
models* (multi-select from `/api/v1/config/models`, empty = all), *Features* (toggles), *Rate-limit tier*; a conflict hint
when two of the user's groups set different default models. Global *Locked* toggles per setting in the same tab with helper
text "Users cannot change this". `useNotification()` on save. Five locales `people.policies.*`.

### 2.7 `IAM45` — Locked and group-set settings in user UI (ota-candidate)

`AIModelsConfiguration.vue` disables a control when `locked` and shows "Set by your administrator" (`config.setByAdmin`); shows
"Default from your group" (`config.fromGroup`) when `source === 'group'`; models outside `MODELS.ALLOWED` are absent from
`ModelSelect.vue`. Existing behavior when both flags are off: nothing rendered differently.

### 2.8 `IAM46` — Demo + docs

`_devextras/testing/iam/policy-demo.sh`: group "Support" with `MODELS.ALLOWED = [two keys]`; a member lists models (two),
saves a third (409 when `DEFAULTMODEL.CHAT` is locked, silently unused otherwise); admin locks `DEFAULTMODEL.CHAT`, member's
POST is 409. `docs/ADMIN.md` "Group policies and locked defaults"; `docs/CONFIGURATION.md` lists the allow-list.

---

## 3. Tests and invariants

| Invariant | Proof |
| --------- | ----- |
| C1 flags off | `LayeredConfigResolverTest::testChainIsUserThenGlobalWhenOff` (never touches `BGROUPCONFIG`); `ModelConfigServiceCharacterizationTest` — `getDefaultModel()` over a fixture matrix (user/global/none, usable/unusable) identical before and after `IAM40`/`IAM41`; `AdminGroupConfigControllerTest::test404WhenFlagOff`; `PoliciesTab.spec.ts` hidden when `features.iamPolicies` is false |
| C3 / C4 / C6 | Unaffected code; existing suites green as regression evidence |
| C5 snapshots | `RoutingCharacterizationTest` untouched — no classifier/sorter edit |
| C7 mobile | `scripts/mobile-impact.mjs`: `IAM39`–`IAM43`, `IAM46` backend-only; `IAM44`–`IAM45` ota-candidate |
| C8 admin read | `AdminGroupConfigControllerTest::testReturnsSettingsOnly` — policy payloads carry keys and values, never user content |

Also `LayeredConfigResolverTest` (blocked global wins over user and group; union / OR / highest / first-by-BID rules; keys
outside the allow-list skip groups), `RateLimitServiceTierTest` (group tier overrides `BUSERLEVEL`, limits table untouched),
`ConfigControllerModelsTest` (allowed-list filter, 409 on locked), `localeParity.spec.ts`, and the unfiltered gate
`make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types`.

---

## 4. Exit criteria / demo

1. `policy-demo.sh` green: Support sees two models; a locked default cannot be overridden (409 with a clear message).
2. Flag off: `ModelConfigService` characterization identical; all pre-existing config, rate-limit and OIDC tests unchanged.
3. Policies tab edits one group at a time, shows conflicts and locks; user settings show "Set by your administrator".
4. `docs/ADMIN.md` and `docs/CONFIGURATION.md` describe the allow-list, merge rules and the lock.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| `IAM39` | `feat(iam): add BGROUPCONFIG table and BCONFIG.BLOCKED column` | backend-only | `IAM1` |
| `IAM40` | `refactor(config): route per-user config resolvers through LayeredConfigResolver with identical output` | backend-only | — |
| `IAM41` | `feat(iam): add policy allow-list and group config layer behind IAM.GROUP_POLICIES_ENABLED` | backend-only | `IAM39`, `IAM40` |
| `IAM42` | `feat(iam): add admin group config API and global lock toggles` | backend-only | `IAM41` |
| `IAM43` | `feat(iam): enforce allowed models, locked defaults and group rate-limit tier` | backend-only | `IAM41` |
| `IAM44` | `feat(iam): add Policies tab to People` | ota-candidate | `IAM42` |
| `IAM45` | `feat(iam): show locked and group-set defaults in user model settings` | ota-candidate | `IAM43` |
| `IAM46` | `test(iam): add group policy demo script and docs` | backend-only | `IAM44`, `IAM45` |
