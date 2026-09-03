# Sprint S3 — Publish and versions

**Track 2 (Agent Builder), sprint 3 of 6.** Steps `AB18`–`AB25`.

**Goal:** The owner publishes an immutable version, shares the assistant through IAM (`assistant` kind, `use` / `edit`), and members see it under "Shared with me". Users always run the latest published version while the owner keeps editing the draft; archiving stops new chats without breaking running ones. The owner sees who uses the assistant — metadata only.
**Depends on:** S1, S2; **track 1 (IAM) S1–S2** merged (`AccessGate`, `IamVoter`, `ShareableResourceKindInterface`, `BSHARES`, `ShareDialog.vue`). The `assistant` kind descriptor is written here and registered together with track 1 S3's other kinds (coordinate in both `STATUS.md` files).
**Unlocks:** S4 (shared folders need the published/`use` path), S5 (widgets bind to a published assistant), S6 (export of published definitions).
**Repos:** `synaplan/` only. **Class:** `backend-only` + `ota-candidate`.
**Flag:** `AGENTS.ENABLED`; group targets additionally require track 1's `IAM.GROUPS_ENABLED` (user / everyone targets work without it).

---

## 0. Why this sprint exists

"Editing changes the live prompt for everyone using it" is the bug this track fixes (master plan §2). Versions make an assistant safe to hand to a department: what people talk to is a snapshot, and the owner's edits are invisible until **Publish**. Publishing is a share, not a second permission model (decision 5).

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Entity/AgentVersion.php` (`AB2`) | The immutable row; only INSERTs from here on |
| `backend/src/Service/Agent/AgentRuntimeResolver.php` (`AB6b`) | Gains "published version or owner draft" and the IAM `use` check |
| `_devextras/planning/202609_iam/00_master_plan.md` §4.2 | `ShareableResourceKindInterface` (`key`, `ownerId`, `describe`, `listOwnedBy`, `onShareChanged`, `supportedPermissions`); for `assistant`, `use` = "appears in my gallery and I can talk to it" |
| Track 1 S1/S2 code: `AccessGate`, `IamVoter` (`IAM_READ` / `IAM_USE` / `IAM_EDIT`), `ShareDialog.vue` | The only decision point; controllers never compare owner ids by hand for shared kinds |
| `backend/src/Entity/UseLog.php` | `BACTION`, `BMODEL`, `BMETADATA` (JSON) — where `agentId` / `agentVersionId` are recorded |
| `backend/src/Service/RateLimitService.php` | `recordUsage(User, string $action, array $metadata)` — the metadata hook |
| `backend/src/Controller/UsageStatsController.php`, `frontend/src/components/admin/UsageChart.vue` | Aggregation and chart style for the owner usage view |
| `frontend/src/components/assistants/AssistantBuilder.vue` (`AB13`) | Gets the Publish section |

---

## 2. Developer steps

### 2.1 Publish flow (`AB18`)

`POST /api/v1/agents/{id}/publish` with `{ changelog: string }` (owner or IAM `edit`), executed by `AgentPublisher` (`final readonly`) in one transaction:

1. `AgentDefinitionValidator` validates `BDRAFT` again — a draft saved before a validator tightening must not publish silently.
2. Inserts `BAGENTVERSIONS` (`BVERSION = max + 1`, `BDEFINITION = BDRAFT`, `BPROMPTTEXT` = current `BPROMPTS` text, `BCHANGELOG`, `BPUBLISHEDBY`).
3. Sets `BAGENTS.BPUBLISHEDVERSIONID` and `BSTATUS = published`; returns the version card.

Publishing an identical definition and prompt text is refused with 409 (`nothing_changed`). No `UPDATE` / `DELETE` on `BAGENTVERSIONS` anywhere: `AgentVersionRepositoryImmutabilityTest` greps the repository for forbidden verbs.

### 2.2 Version list API (`AB19`)

`GET /api/v1/agents/{id}/versions` (owner, `edit`, or `use` — readers see version number, date, changelog, publisher name; never the definition). `GET /api/v1/agents/{id}/versions/{version}` returns the definition for owner / `edit` only (feeds "compare with draft" in the builder). Full OpenAPI, Zod regenerated (`AgentVersionSchema`).

### 2.3 IAM `assistant` kind (`AB20`)

`App\Iam\Kind\AssistantResourceKind implements ShareableResourceKindInterface`, tagged `app.iam.resource_kind`:

```php
public function key(): string { return 'assistant'; }
public function ownerId(string $resourceId): ?int { /* BAGENTS.BOWNERID by BID, null when missing */ }
public function describe(string $resourceId): ResourceCard { /* name, icon, "v{n}" — no definition */ }
public function listOwnedBy(int $userId): iterable { /* published only — drafts are never shareable */ }
public function onShareChanged(string $resourceId): void { /* clear the gallery cache */ }
public function supportedPermissions(): array { return ['read', 'use', 'edit']; }
```

Semantics: `read` = see the card and clone; `use` = read + start a chat; `edit` = use + edit the draft and publish (co-maintainer); `manage` is the owner's (and admins' — who still get no `use`, IAM §4.5).

### 2.4 Resolver: published version, IAM check, archived rule (`AB21`)

`AgentRuntimeResolver::resolve()` becomes:

- owner **and** `draft = true` ⇒ `BDRAFT`; otherwise `IamVoter` `IAM_USE` on `assistant:{id}` (owner passes) ⇒ load `BPUBLISHEDVERSIONID`; no published version ⇒ 404 `not_published`.
- `BSTATUS = archived` ⇒ allowed only when the request carries a `chatId` whose first message already has meta `AGENTID = id` (existing chat continues on the last version); a new chat ⇒ 410 `archived`.
- The profile carries `agentVersionId`; the reply's `BMESSAGEMETA` gets `AGENTVERSIONID`. The check runs on **every** message, not once per chat — an unshare takes effect on the next message (C6 groundwork).

### 2.5 Clone needs `read`; gallery "Shared with me"; Publish section (`AB22`)

- `POST /agents/{id}/clone` (`AB10`) switches from owner-only to `IAM_READ`; it copies the **published** definition and `BPROMPTTEXT`, never the draft.
- `GET /agents/gallery` adds `origin = shared` cards from `AccessGate` ("resources shared with me", kind `assistant`) with `ownerName`, `version`, `sharedVia` (`user` / group name / `everyone`).
- `AssistantGallery.vue`: chip **Shared with me**; cards show owner and version; **Clone** only with `read`, **Edit** with `edit`.
- Builder → **Publish** section (`AssistantPublishSection.vue`): version list, changelog textarea, **Publish** button (confirm via `useDialog`), **Share** opens track 1's `ShareDialog` with kind `assistant`. Word in all five locales: Publish / Veröffentlichen / Publicar / Publier / Yayınla.

### 2.6 Usage metadata and owner usage view (`AB23`)

- `ChatHandler` passes `agentId` / `agentVersionId` from the `RuntimeProfile` into `RateLimitService::recordUsage()` metadata, stored in `BUSELOG.BMETADATA` as `{"agentId": 12, "agentVersionId": 3}`. **No new column** — decision 12 lists only three schema asks; an indexed column is a follow-up if the query proves slow.
- `GET /api/v1/agents/{id}/usage?from=&to=` (owner / `edit`): totals per version (`messages`, `tokens`, `cost`, `distinctUsers`) and per day via `UseLogRepository::aggregateForAgent()` using `JSON_EXTRACT(BMETADATA, '$.agentId')`. **Never** user names, message ids or content — `distinctUsers` is a count.
- `AssistantUsagePanel.vue` inside the Publish section (small table + `UsageChart.vue` style).

### 2.7 Archive and unarchive (`AB24`)

`PATCH /agents/{id}` gains `status: 'archived' | 'published'` (owner only). Archived: the gallery hides the card behind an **Archived** chip for the owner and removes it for everyone else; the chat pill (`AB12`) shows an **archived** badge; **Start chat** is disabled. `DELETE` is allowed for archived assistants and deletes versions before the agent row (no cascade); shares are removed through `AccessGate` on delete (`onShareChanged`).

### 2.8 System assistants — command only (`AB25`)

`app:agents:seed-system` (idempotent) creates read-only assistants (`BSOURCE = system`, owner 0) for the seeded non-`tools:` prompts, publishes v1 of each and shares them with `everyone` (`use`) via `AccessGate`. **Not run by the seeder in this sprint** — rollout step 2 (master plan §9) runs it on the dev instance first; it exists so S4–S6 can test with a full gallery.

---

## 3. Tests and invariants

| Invariant | How this sprint proves it |
| --------- | ------------------------- |
| C1 | Flag off: publish / versions / usage routes 404; `AssistantResourceKind` is registered but `listOwnedBy` returns nothing |
| C2 | Pinned path unchanged for the sorter; `RoutingCharacterizationTest` untouched |
| C3 | `BPROMPTTEXT` is a copy; `BPROMPTS` row and API contract unchanged |
| C6 | `AgentAccessNegativeTest`: user without `use` ⇒ 404 on chat; share revoked between two messages ⇒ second message 404; admin without share ⇒ 404 (metadata via `describe()` still allowed) |
| C8 | New paths `backend/src/Iam/Kind/**`, `backend/src/Service/Agent/AgentPublisher.php` listed `backend-only`; Vue `ota-candidate` |

- Unit: `AgentPublisherTest` (version increments, identical publish ⇒ 409, rollback on validator failure), `AssistantResourceKindTest` (drafts not listed, permission set), `AgentRuntimeResolverVersionTest` (draft vs published, archived + existing chat ⇒ ok, archived + new chat ⇒ 410), `UseLogRepositoryAgentAggregateTest` (aggregation, no user ids in the result).
- Feature: `AgentPublishFlowTest` (member on v1 while owner edits; publish v2 ⇒ member's next message carries `AGENTVERSIONID = v2`), `AgentGallerySharedTest`, `AgentCloneSharedTest` (`read` suffices, draft never copied).
- Frontend: `AssistantPublishSection.spec.ts`, `AssistantGallery.spec.ts` (shared chip, archived chip). Unfiltered backend + frontend gates.

---

## 4. Exit criteria / demo

1. Admin publishes "Contract review" v1 to group "Legal". A Legal member finds it under **Shared with me**, chats, gets grounded answers. A Sales member does not see it and gets 404 with a guessed id.
2. Admin edits the instructions and tests in the panel; the member still gets v1. Admin publishes v2 with a changelog; the member's next message runs v2 (visible in message meta), no action on their side.
3. Owner archives it: the member's open chat continues with an **archived** badge; **Start chat** is gone from the gallery.
4. Owner usage view shows messages and cost per version and a user *count*, nothing else. `AssistantResourceKind` appears in track 1's kind registry test.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| AB18 | `feat(agents): add publish flow creating immutable BAGENTVERSIONS rows` | backend-only | AB8 |
| AB19 | `feat(agents): add version list and version detail endpoints` | backend-only | AB18 |
| AB20 | `feat(iam): register assistant as a shareable resource kind` | backend-only | AB18, IAM S1 |
| AB21 | `feat(agents): resolve published version with IAM use check and archived rule` | backend-only | AB20 |
| AB22 | `feat(assistants): add Publish section, share dialog and shared-with-me gallery` | ota-candidate + backend-only | AB19, AB21, IAM S2 |
| AB23 | `feat(agents): record agent metadata in usage log and add owner usage view` | backend-only + ota-candidate | AB21 |
| AB24 | `feat(assistants): archive and unarchive with existing-chat continuation` | backend-only + ota-candidate | AB21, AB22 |
| AB25 | `feat(agents): add app:agents:seed-system command for read-only system assistants` | backend-only | AB20 |
