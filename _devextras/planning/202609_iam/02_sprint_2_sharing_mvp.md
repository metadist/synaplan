# Sprint S2 — Sharing MVP

**Track 1 (IAM), sprint 2 of 5.** Steps `IAM11`–`IAM20`.

**Goal:** "Add this chat with its files to my team." An owner shares a knowledge folder (`use`) or a conversation
(`read` / `use`) with a group; a member continues the chat as a copy and RAG answers from the shared files.
**Depends on:** S1 (`IAM1`–`IAM10`): tables, `AccessGate`, kind registry, People page, `iam:*` scopes.
**Unlocks:** S3 (more kinds reuse `BSHARES`, `ShareDialog.vue`, the filters), track 2 (publishing an assistant is a share).
**Repos:** `synaplan/` only.
**Flag:** `IAM.SHARING_ENABLED` (seeded `0`; effective only when `IAM.GROUPS_ENABLED` is also on). Share routes 404 when
off; RAG queries stay byte-identical; no Share action, chip or badge is rendered.

---

## 0. Why this sprint exists

The first thing a team can *do* with groups; it also proves the hardest invariants early: C2 (no share row ⇒ no cross-user
chunk, both stores) and C6 (public links untouched). The RAG seam starts with a **refactor with identical behavior** (`IAM13`):
the existing `(userId, groupKey)` becomes a one-element scope list before any share is read.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/RAG/VectorSearchService.php` (`semanticSearch`, `semanticSearchByVector`) | The single search entry; callers: `ChatHandler`, `RagController`, `McpServerFactory`, `ChatRunner`, `FeedbackExampleService`, `PlatformDocsRetriever` |
| `backend/src/Service/RAG/VectorStorage/DTO/SearchQuery.php`, `DTO/SearchResult.php` | `userId` + `?groupKey` today; becomes a scope list (`IAM13`) |
| `backend/src/Service/RAG/VectorStorage/MariaDBVectorStorage.php` (`search()`), `QdrantVectorStorage.php` → `backend/src/Service/VectorSearch/QdrantClientDirect.php` (`searchDocuments`, `must` filter) | Where the `(BUID, BGROUPKEY)` / `(user_id, group_key)` filters are built |
| `backend/src/Controller/ChatController.php` (`/{id}`, `/{id}/messages`, `/{id}/share`, `/shared/{token}`), `backend/src/Repository/ChatRepository.php` | Owner checks to migrate to the voter; public link stays (C6) |
| `backend/src/Entity/File.php` (`BUSERID`, `BGROUPKEY`, `BMESSAGEID`), `backend/src/Entity/Message.php` (`BCHATID`, `BFILE`, `BFILEPATH`), `backend/src/Repository/FileRepository.php` (`findByMessageIds`) | How a chat references its files — the copy keeps these references |
| `backend/src/Controller/FileController.php` (`/groups`, `/{id}/content`, `/{id}/download`, `/{id}/thumb`, `/{id}/share`) | Folder listing; file reads that must accept shared access; file `share_token` stays |
| `backend/src/Controller/AdminSystemConfigController.php` (`/schema`, `/values`) | Where `IAM.EVERYONE_SHARES` is exposed in Operate → System config |
| `frontend/src/views/FilesView.vue`, `frontend/src/components/KnowledgeFolderPicker.vue`, `frontend/src/components/SidebarChatListItem.vue`, `frontend/src/components/ChatBrowser.vue` | Folder cards, folder picker, chat list menus — Share entry points and "Shared with me" chips |
| `frontend/src/components/ChatShareModal.vue`, `frontend/src/components/ShareModal.vue`, `frontend/src/views/SharedChatView.vue` | Public-link UI — untouched, referenced from the dialog's "Public link" section |
| `frontend/src/components/ChatMessage.vue` | RAG source chips gain owner name + avatar for shared hits |
| `backend/tests/Controller/RagControllerTest.php`, `ChatControllerTest.php`, `FileControllerTest.php`; `backend/tests/Characterization/` | Existing coverage to extend; snapshot harness for the new filter characterization |

---

## 2. Developer steps

### 2.1 `IAM11` — Migration: `BSHARES`

```sql
CREATE TABLE IF NOT EXISTS BSHARES (
  BID BIGINT NOT NULL AUTO_INCREMENT, BRESOURCEKIND VARCHAR(64) NOT NULL, BRESOURCEID VARCHAR(191) NOT NULL,
  BSUBJECTTYPE VARCHAR(16) NOT NULL, BSUBJECTID BIGINT NOT NULL DEFAULT 0, BPERMISSION VARCHAR(16) NOT NULL,
  BGRANTEDBY BIGINT NOT NULL, BCREATED BIGINT NOT NULL, PRIMARY KEY (BID),
  UNIQUE KEY uniq_share_subject (BRESOURCEKIND, BRESOURCEID, BSUBJECTTYPE, BSUBJECTID),
  KEY idx_share_lookup (BSUBJECTTYPE, BSUBJECTID, BRESOURCEKIND)
);
```

Entity `Share` + `ShareRepository` (`findForResource`, `findForSubjects(userId, groupIds, kind)`). `UserDeletionService` deletes shares
granted *to* and *by* a deleted user; each kind deletes its shares when its resource is deleted (no cascade). Seeder adds `IAM.EVERYONE_SHARES = any_owner`.

### 2.2 `IAM12` — `ShareService`, share API, gate lookup

`backend/src/Service/Iam/ShareService.php`: `grant(actor, kind, resourceId, subjectType, subjectId, permission)` (owner or
`manage`; `everyone` only if `IAM.EVERYONE_SHARES` allows the actor; permission ∈ `kind->supportedPermissions()`), `revoke`,
`listForResource`, `listSharedWith(userId, kind)`; audit rows `share.grant` / `share.revoke`; calls `kind->onShareChanged()`.
`AccessGate::decide()` S2 body: owner ⇒ true; else highest `BSHARES` permission over subjects `(user, me)`, `(group, each of
groupsOf(me))`, `(everyone, 0)` — `implies($level)`. `ShareController`: `GET/POST/DELETE /api/v1/shares?kind=&resource=`,
`GET /api/v1/iam/subjects?q=` (users by name/email, groups, pinned `everyone`), `GET /api/v1/me/shared?kind=` (returns
`ResourceCard`s + my permission + owner). `requiredScopesForPath()`: `/api/v1/shares` → `iam:manage`; `/api/v1/iam`,
`/api/v1/me/shared` → `iam:read`. `guard()` 404 when sharing is off. Full OpenAPI → `make -C frontend generate-schemas`.

### 2.3 `IAM13` — `RagScope` in `SearchQuery` (refactor, identical behavior)

`RagScope(int $ownerId, ?string $groupKey, array $fileIds = [])`; `SearchQuery` gains `scopes: list<RagScope>` and a
static `SearchQuery::own(userId, groupKey)` that builds the one-element list every current caller uses. `MariaDBVectorStorage`
renders `WHERE (` `r.BUID = :u0 AND r.BGROUPKEY = :g0` `)` per scope joined by `OR` (`BGROUPKEY` term dropped when null;
`r.BMID IN (:f0)` added when `fileIds` set); `QdrantClientDirect::searchDocuments` renders `filter.should = [{must: [user_id,
group_key?, file_id?]}, …]`. **A query with zero scopes throws `EmptyRagScopeException`** — never an unfiltered search.
Characterization test `RagScopeFilterCharacterizationTest` snapshots SQL + Qdrant JSON for the own-only case; the snapshot
must equal today's output.

### 2.4 `IAM14` — `RagScopeResolver` + shared folders in RAG

`backend/src/Service/RAG/RagScopeResolver.php`: `resolve(userId, ?groupKey): list<RagScope>` = own scope + one
`RagScope(ownerId, groupKey)` per `knowledge_folder` share with `use` (or higher) reaching me, only when `groupKey` is null or
equals the shared folder's key or is the picker form `shared:{ownerId}:{groupKey}`. Sharing off ⇒ own scope only.
`VectorSearchService` calls the resolver instead of building the query itself; results carry `owner_id`, `owner_name`,
`shared: bool`. Characterization snapshots extended: zero, one, many shares (MariaDB SQL and Qdrant filter each).

### 2.5 `IAM15` — Conversation `read` through the voter (refactor + share)

`ChatController::get`, `::messages` use `#[IsGranted('IAM_READ', subject: 'ref')]` via `IamVoter`; response gains
`access: 'owner' | 'read' | 'use'` and `owner { id, name }`. `ChatRepository::findSharedWith(userId)` backs
`/api/v1/me/shared?kind=conversation`. `FileController` `/{id}/content`, `/{id}/download`, `/{id}/thumb` accept a file whose
`BMESSAGEID` belongs to a chat I may `read`. Owner-only paths behave exactly as before when no share exists.

### 2.6 `IAM16` — Continue as copy with file references

`POST /api/v1/chats/{id}/continue` (`IAM_USE`) → `ConversationCopyService::copyForUser(chat, user)`: new `BCHATS` row owned by
me, `BMESSAGES` copied with new `BCHATID` / `BTRACKID`, each file-bearing message stores `shared_file_ref = <owner BFILES.BID>`
in `BMESSAGEMETA`; no binary is duplicated. `RagScopeResolver` adds `RagScope(ownerId, groupKey)` for each distinct folder and
`RagScope(ownerId, null, fileIds)` for loose files referenced by chats shared to me with `use`. A missing referenced file
returns `410` from the file routes and renders the `iam.fileUnavailable` state ("file no longer available") in the copy.

### 2.7 `IAM17` — `IAM.EVERYONE_SHARES` + admin config

Setting `any_owner` (default) | `admins_only` in the `AdminSystemConfigController` schema (Operate → System config, group "Sharing"); `ShareService` enforces it; runtime `features.iamSharing` + `useIamFeature.ts` `isIamSharingEnabled()`.

### 2.8 `IAM18` — `ShareDialog.vue` (ota-candidate)

`frontend/src/components/iam/ShareDialog.vue` (props `kind`, `resourceId`, `resourceName`; same component for every kind),
`SubjectPicker.vue` (debounced `/api/v1/iam/subjects`, pinned "Everyone in this organization"), `PermissionSelect.vue`
(Can view / Can use / Can edit / Can manage, filtered by `supportedPermissions`). Shares list with avatar or group chip and
remove via `useDialog()`; "Public link" section embeds the existing `ChatShareModal` / file share flow unchanged. Entry
points: folder card menu in `FilesView.vue`, chat item menu in `SidebarChatListItem.vue` and `ChatBrowser.vue`. Follows the
`IAM8` wireframe; five locales (`iam.dialog.*`); dark + V2 + 320 px.

### 2.9 `IAM19` — "Shared with me", badges, source chips (ota-candidate)

Filter chip `iam.sharedWithMe` on `/files` (folders from `/me/shared?kind=knowledge_folder`, owner avatar on the card) and in
the chat history list (conversations with `access` badge *Can view* / *Can use*; read-only chat view hides the composer and
shows **Continue as my copy** for `use`). Small "shared" icon on cards I shared. `ChatMessage.vue` source chips show owner
avatar + name when `shared` is true (decision §13.2). `KnowledgeFolderPicker.vue` lists shared folders as `shared:{ownerId}:{groupKey}`.

### 2.10 `IAM20` — Acceptance script + docs

`_devextras/testing/iam/share-demo.sh`: two demo users + group; owner uploads two files into a chat and shares it with `use`; member
calls `/continue`, asks a question, asserts a RAG source with `shared: true`; owner revokes; the next search returns no shared source.
`docs/ADMIN.md` "Sharing" section; `docs/RAG.md` gains the scope rule.

---

## 3. Tests and invariants

| Invariant | Proof |
| --------- | ----- |
| C1 flags off | `ShareControllerTest::testRoutesAre404WhenSharingOff`; `RagScopeFilterCharacterizationTest::testOwnOnlyMatchesLegacyFilter` (snapshot equals pre-S2 SQL/JSON); `ChatControllerTest` unchanged results; frontend `ShareDialog.spec.ts` / `FilesView.spec.ts`: no Share action or chip when `features.iamSharing` is false |
| C2 isolation | `KnowledgeIsolationTest` (MariaDB) + `QdrantKnowledgeIsolationTest` (`QdrantClientMock`): no share ⇒ zero foreign chunks; `read`-only share ⇒ zero; `use` share ⇒ hits with `shared: true`; revoke ⇒ zero on the next query; `EmptyRagScopeException` on zero scopes |
| C4 API keys | `ApiKeyScopeTest`: legacy key on `/api/v1/shares`; `iam:read` key 403 on `POST /api/v1/shares` |
| C5 snapshots | `RoutingCharacterizationTest` untouched — `ChatHandler` only passes results through |
| C6 public links | `ChatControllerTest::testSharedToken*`, `FileControllerTest::testShare*` unchanged |
| C7 mobile | `scripts/mobile-impact.mjs`: `IAM11`–`IAM17`, `IAM20` backend-only; `IAM18`–`IAM19` ota-candidate |
| C8 admin read | `ChatControllerTest::testAdminCannotReadForeignChatWithoutShare` (403), `FileControllerTest::testAdminCannotDownloadForeignFile` |

Also `ShareServiceTest` (highest permission wins, `everyone` gate, unsupported permission ⇒ 422, audit row per change), `ConversationCopyServiceTest`
(no `BFILES` duplication, `shared_file_ref` set, deleted file ⇒ 410), `localeParity.spec.ts`, and the unfiltered gate `make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types`.

---

## 4. Exit criteria / demo

1. `share-demo.sh` green on a dev instance with MariaDB **and** with Qdrant as the vector store.
2. Member opens a `read` conversation read-only; a `use` one offers **Continue as my copy**; the copy shows the owner's files and, after the owner deletes one, "file no longer available".
3. "Shared with me" chips on `/files` and chat history; owner-name source chips in answers.
4. Flags off: gate green, characterization snapshots (routing + RAG scope own-only) untouched, public links unchanged.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| `IAM11` | `feat(iam): add BSHARES table and share entity` | backend-only | `IAM4` |
| `IAM12` | `feat(iam): add ShareService, share API and subject search behind IAM.SHARING_ENABLED` | backend-only | `IAM11` |
| `IAM13` | `refactor(rag): express vector search filters as RagScope list with identical output` | backend-only | — |
| `IAM14` | `feat(iam): resolve shared knowledge folders into RAG scopes for MariaDB and Qdrant` | backend-only | `IAM12`, `IAM13` |
| `IAM15` | `refactor(iam): guard conversation reads with IamVoter and expose access level` | backend-only | `IAM12` |
| `IAM16` | `feat(iam): continue a shared conversation as a copy with file references` | backend-only | `IAM14`, `IAM15` |
| `IAM17` | `feat(iam): add IAM.EVERYONE_SHARES setting and sharing runtime flag` | backend-only | `IAM12` |
| `IAM18` | `feat(iam): add ShareDialog with subject picker and permission select` | ota-candidate | `IAM17` |
| `IAM19` | `feat(iam): add Shared with me filters, share badges and owner source chips` | ota-candidate | `IAM18` |
| `IAM20` | `test(iam): add share acceptance script and sharing docs` | backend-only | `IAM16`, `IAM19` |
