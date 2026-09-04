# Sprint S3 — More kinds

**Track 1 (IAM), sprint 3 of 5.** Steps `IAM21`–`IAM28`.

**Goal:** Three more shareable kinds on the S2 rails — `assistant`, `saved_task`, `widget` — plus plugin-declared kinds via
manifest v2. An admin publishes a system-like assistant to **one** group only; nobody outside the group sees it.
**Depends on:** S2 (`BSHARES`, `ShareService`, `ShareDialog.vue`, "Shared with me" filters). Coordinates with track 2 S3
(`202609_agent_builder/`): until `BAGENTS` exists the `assistant` kind targets `BPROMPTS` rows.
**Unlocks:** Track 2 publishing ("publish to group" = a share of kind `assistant`), track 4 (approval policies per group).
**Repos:** `synaplan/` only; plugin repos consume the manifest field later.
**Flag:** `IAM.SHARING_ENABLED` (no new flag). New kinds register in the registry but are unreachable when sharing is off.

---

## 0. Why this sprint exists

S2 proved the mechanics on two kinds. This sprint shows the registry earns its keep: each new kind is one class, one
`ShareDialog` entry point and one list filter — no new tables, no new API. Every kind starts with a **refactor with identical
behavior** (`IAM21`): today's owner checks in `PromptController`, `SavedTaskController` and `WidgetController` move behind
`IamVoter` before any share can change their answer.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Controller/PromptController.php` (`/api/v1/prompts`, `/{id}`, `/{topic}/files*`), `backend/src/Repository/PromptRepository.php` (`findAllForUser`, `getTopicsWithDescriptions`, `findPromptsWithSelectionRules`) | Owner checks and the lists that decide which assistants a user sees and the classifier may pick |
| `backend/src/Service/Message/MessageSorter.php`, `MessageClassifier.php` | **Read, do not edit** — candidate topics come from `PromptRepository`; C5 |
| `backend/src/Entity/Prompt.php` (`BOWNERID`, `0` = system), `backend/src/Entity/PromptMeta.php` | System prompts are "shared with everyone by construction" — not migrated into `BSHARES` |
| `backend/src/Controller/SavedTaskController.php`, `backend/src/Service/SavedTask/SavedTaskService.php` (`getOwned`, `create`, `listForOwner`), `backend/src/Entity/SavedTask.php` (`BPROMPTID`, `BGRAPH`, `BTRIGGERCONFIG`, `BALLOWUNATTENDED`) | "Run a copy" = `create()` + copied graph, trigger reset to `manual` |
| `backend/src/Controller/WidgetController.php` (`/api/v1/widgets`, `/{widgetId}`, `/{widgetId}/embed`), `backend/src/Entity/Widget.php` (`BOWNERID`) | Owner checks; `embed` and every public widget route stay untouched (C7) |
| `backend/src/Service/Plugin/PluginManifest.php`, `PluginManager.php`, `backend/src/Entity/PluginData.php`, `backend/src/Repository/PluginDataRepository.php` | Manifest parsing (`chatCommands` today) and per-user plugin rows — the owner of a plugin-declared resource |
| `_devextras/planning/20260822-open-plugin-platform/README.md` (`provides.*` table) | Manifest v2 shape; `provides.resourceKinds` is added here |
| `frontend/src/components/config/TaskPromptsConfiguration.vue`, `frontend/src/components/config/SavedTaskCard.vue`, `SavedTasksOverview.vue`, `frontend/src/components/widgets/WidgetList.vue`, `WidgetEditor.vue` | The cards that get a Share action and a "Shared with me" chip |
| `frontend/src/components/iam/ShareDialog.vue` (S2) | Same component, `kind` passed in — no per-kind dialog |

---

## 2. Developer steps

### 2.1 `IAM21` — Owner checks behind `IamVoter` (refactor, identical behavior)

`PromptController::get/update/delete` and `/{topic}/files*`, `SavedTaskController` (`getOwned` call sites),
`WidgetController::get/update/delete` evaluate `IAM_READ` / `IAM_EDIT` / `IAM_MANAGE` on `ResourceRef` instead of comparing
ids. Kinds registered with owner-only logic: `AssistantKind` (`assistant`, id `BPROMPTS.BID`, system prompts `BOWNERID = 0`
report `ownerId() = 0` and are never shareable), `SavedTaskKind` (`saved_task`, id `BSAVEDTASKS.BID`), `WidgetKind`
(`widget`, id `BWIDGETS.BID`). 404 semantics for foreign ids stay exactly as today (Saved Tasks: 404, not 403).

### 2.2 `IAM22` — `assistant`: `read` / `use` / `edit`

`use` = appears in my assistant list and the classifier may pick it. `PromptRepository::findAllForUser` and
`getTopicsWithDescriptions(ownerId, lang, userId, excludeTools)` union `BPROMPTS` rows reachable through `BSHARES`
(`assistant`, `use`+) **only when sharing is on and at least one share exists**; the SQL for a user without shares is unchanged
(characterization). Response rows carry `owner { id, name }`, `shared: true`, `access`. `edit` lets a member change
instructions and model binding; `manage` = re-share/delete stays owner + admins. Knowledge folder `TASKPROMPT:{topic}` of a
shared assistant is included in `RagScopeResolver` when the assistant is shared with `use` — the folder rides with the assistant.
Coordination: when track 2 introduces `BAGENTS`, `AssistantKind::ownerId()` switches lookup; `BSHARES.BRESOURCEID` values are
rewritten by track 2's migration, not here.

### 2.3 `IAM23` — `saved_task`: `read` / `use` = run a copy

`read` = card visible under "Shared with me" (name, trigger, last run — no run output). `use` = `POST
/api/v1/saved-tasks/{id}/copy` → `SavedTaskService::copyForOwner(task, me)`: `create(me, promptId, name)` + copied `BGRAPH`,
`BTRIGGERTYPE = manual`, `BALLOWUNATTENDED = 0`, `BCHATID = null`. Requires `IAM_USE` on the task **and** on its `BPROMPTID`
assistant (owner, system, or shared `use`) — otherwise `409` with `iam.assistantNotShared`. Runs of the original stay private.
`supportedPermissions() = [read, use]`.

### 2.4 `IAM24` — `widget`: `read` / `edit` co-editing

`read` = open the widget detail read-only (config, stats; **no** visitor session transcripts). `edit` = the `WidgetEditor.vue`
saves through `PUT /api/v1/widgets/{widgetId}` with `IAM_EDIT`; the widget keeps its owner, its embed code and its billing
account. `manage` = share/delete. `supportedPermissions() = [read, edit, manage]`. `WidgetKind::onShareChanged()` invalidates
the owner's widget list cache. Public routes (`/embed`, guest chat, sessions) do not consult the gate.

### 2.5 `IAM25` — Plugin-declared kinds via manifest v2

`PluginManifest` parses `provides.resourceKinds`; `PluginResourceKind` is a generic adapter over `plugin_data` rows:

```json
{
  "provides": {
    "resourceKinds": [
      { "key": "synaform:form", "dataType": "form", "labelKey": "synaform.kind.form", "permissions": ["read", "use", "edit"] }
    ]
  }
}
```

Resource id = `plugin_data.BID`; `ownerId()` = the row's user; `describe()` = `label` from the row's JSON `name` or the id;
`onShareChanged()` no-op. Boot-time validation: `key` must be `{pluginId}:{name}`, permissions ⊆ the four levels, else the
plugin fails to load with a message naming the field. `PluginContextProviderInterface` receives shared rows through
`PluginDataRepository::findSharedWith(userId, pluginId, dataType)`.

### 2.6 `IAM26` — Share entry points on each card (ota-candidate)

Share action (opens `ShareDialog.vue` with `kind`) on assistant cards in `TaskPromptsConfiguration.vue` (hidden for system
prompts), `SavedTaskCard.vue`, `WidgetList.vue` rows and `WidgetEditor.vue` header; "shared" icon on cards I shared; owner
avatar on cards shared to me. Five locales: `iam.kind.assistant|saved_task|widget`, `iam.runCopy`, `iam.assistantNotShared`.

### 2.7 `IAM27` — "Shared with me" filters + read-only views (ota-candidate)

Chip on `/ai/instructions`, `SavedTasksOverview.vue` and `/channels/widgets` backed by `/api/v1/me/shared?kind=`. Shared
assistant → usable in the model/assistant pickers with owner chip; shared task → **Run as my copy** button; shared widget →
read-only detail or editor by `access`. `useDialog()` before copying a task.

### 2.8 `IAM28` — Publish demo + docs

`_devextras/testing/iam/publish-demo.sh`: admin creates assistant "Sales Helper", shares it `use` with group "Sales"; a Sales
member lists it and gets it classified; a non-member does not see it and a direct `GET /api/v1/prompts/{id}` is 404.
`docs/ADMIN.md` "Publishing an assistant to a group"; plugin docs gain `provides.resourceKinds`.

---

## 3. Tests and invariants

| Invariant | Proof |
| --------- | ----- |
| C1 flags off | `WidgetControllerTest` unchanged after `IAM21`; new `PromptControllerTest` and `SavedTaskControllerTest` (feature tests, written in `IAM21` before the refactor, green before and after); `PromptRepositorySharedTest::testNoSharesYieldsLegacySql`; frontend specs: no Share action / chip when `features.iamSharing` is false |
| C2 isolation | `KnowledgeIsolationTest::testAssistantFolderFollowsAssistantShare` — `TASKPROMPT:{topic}` chunks appear only with an `assistant` `use` share; revoked ⇒ gone (MariaDB + Qdrant mock) |
| C5 snapshots | `RoutingCharacterizationTest` untouched — fixtures have no shares; classifier/sorter files have no diff in this sprint |
| C6 / C7 | `WidgetControllerTest::testEmbed*` and guest chat tests unchanged; `scripts/mobile-impact.mjs`: `IAM21`–`IAM25`, `IAM28` backend-only; `IAM26`–`IAM27` ota-candidate |
| C8 admin read | `PromptControllerTest::testAdminCannotReadForeignPromptWithoutShare` (404), `SavedTaskControllerTest::testAdminCannotSeeForeignRuns`, `WidgetControllerTest::testAdminCannotReadForeignWidgetSessions` |

Also `AssistantKindTest` (system prompt never shareable ⇒ 422 from `ShareService`), `SavedTaskServiceCopyTest` (trigger reset,
unattended off, 409 without assistant access), `PluginManifestResourceKindsTest` (invalid key / permission ⇒ load error naming
the field), `localeParity.spec.ts`, and the unfiltered gate `make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types`.

---

## 4. Exit criteria / demo

1. `publish-demo.sh` green: one group sees "Sales Helper" and the classifier routes to it; others get 404.
2. A shared task runs as my copy with `manual` trigger; a shared widget is co-edited by a second user without changing owner.
3. A plugin manifest with `provides.resourceKinds` loads; an invalid one fails with a field-named error.
4. Flags off: gate green, routing snapshots untouched, widget embed and guest chat identical.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| `IAM21` | `refactor(iam): route prompt, saved task and widget owner checks through IamVoter` | backend-only | `IAM15` |
| `IAM22` | `feat(iam): add assistant kind with shared prompts in lists and classifier candidates` | backend-only | `IAM21` |
| `IAM23` | `feat(iam): add saved_task kind with run-as-copy` | backend-only | `IAM21`, `IAM22` |
| `IAM24` | `feat(iam): add widget kind with read and co-edit permissions` | backend-only | `IAM21` |
| `IAM25` | `feat(plugins): declare shareable resource kinds via manifest provides.resourceKinds` | backend-only | `IAM21` |
| `IAM26` | `feat(iam): add Share actions and badges on assistant, task and widget cards` | ota-candidate | `IAM22`, `IAM23`, `IAM24` |
| `IAM27` | `feat(iam): add Shared with me filters and read-only views for new kinds` | ota-candidate | `IAM26` |
| `IAM28` | `test(iam): add publish-to-group demo script and docs` | backend-only | `IAM27` |
