# IAM — groups, sharing, directory — master plan

**Status:** Draft 2026-09-03. Track 1 of [`../20260903_roadmap.md`](../20260903_roadmap.md).
Nothing below is decided until §0 is ticked.
**Owner surface:** Operate → **People** (admin) and a **Share** action on
resource cards (everyone). No new top-level nav item.
**Flags:** `IAM.GROUPS_ENABLED`, `IAM.SHARING_ENABLED`, `IAM.DIRECTORY_SYNC_ENABLED`
— all default off in code and seeder.
**Related:**

- [`../2026-archive/20260709-hosting-partner-core-requirements/README.md`](../2026-archive/20260709-hosting-partner-core-requirements/README.md)
  — CORE-2/3/5 (provisioning, scopes, per-user isolation contract)
- [`../20260828-interface-streamlining-sprint/README.md`](../20260828-interface-streamlining-sprint/README.md)
  — the Work / Manage / Operate navigation contract and the `canSeeManage` seam
- [`../20260822-open-plugin-platform/README.md`](../20260822-open-plugin-platform/README.md)
  — manifest v2 `provides.*` (plugins contribute shareable resource kinds)
- Track 2 (assistants are the second shareable kind), track 6 (external
  identities table is introduced here and reused there)

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **Instance = organization.** Multi-tenancy inside one database is **not** built. Hosters serve many customers with many instances (the `synaplan-platform` model today). Inside an instance, IAM = people, groups, shares. | One org per instance | |
| 2 | **Ownership never moves.** Every resource keeps exactly one owner (`BOWNERID` / `BUSERID` as today). Sharing is additive; deleting a share never deletes data. Ownership transfer is a v2 admin action (audited), not part of sharing. | Locked | |
| 3 | **Four permission levels, fixed vocabulary:** `read` (see it), `use` (let the AI use it for me / talk to it), `edit` (change it), `manage` (re-share, delete). Owner has all. No custom roles in v1. | Locked | |
| 4 | **Three subject types:** a user, a group, `everyone` (all signed-in users of the instance). Anonymous / widget visitors are never a share subject. | Locked | |
| 5 | **Groups are flat in v1.** `BGROUPS.BPARENTID` is created nullable for v2 nesting (departments) but not exposed. | Flat, column reserved | |
| 6 | **Group roles: `member` and `manager`.** A manager adds/removes members and shares group-owned nothing (groups own nothing — see 2). Instance admins manage all groups. | Two roles | |
| 7 | **Directory groups come from the OIDC `groups` claim** (configurable claim path), upserted at login, membership reconciled per login. Directory groups are read-only in the UI. SCIM 2.0 is v2. Role mapping (`OIDC_ROLE_CLAIMS`, admin promotion) is **unchanged**. | OIDC first, SCIM later | |
| 8 | **Admin privacy: admins manage, they do not read.** Admins see resource *metadata* (name, owner, size, shares) but not content unless (a) it is shared with them, or (b) they use audited impersonation. `IAM.ADMIN_IMPERSONATION` = `audited` (today's behavior + audit row) with an instance option `disabled`. | Metadata only + audited impersonation | |
| 9 | **One decision point:** `AccessGate` service + one Symfony voter (`IamVoter`, attributes `IAM_READ` / `IAM_USE` / `IAM_EDIT` / `IAM_MANAGE`). Controllers and services never compare user ids by hand for shared kinds. Existing `userId === ownerId` checks stay valid (owner wins) and are migrated to the voter kind by kind. | Locked | |
| 10 | **Resource kinds are a registry**, not an enum: `ShareableResourceKindInterface` tagged `app.iam.resource_kind`. v1 kinds: `knowledge_folder`, `conversation`, `assistant`, `saved_task`, `widget`. Plugins add kinds via manifest v2 `provides.resourceKinds`. | Registry | |
| 11 | **MVP = groups + share a knowledge folder and a conversation with a group.** "Add this chat with its files to my team" is the acceptance demo. Assistants, tasks, widgets follow in S3. | Knowledge + conversation first | |
| 12 | **Shared knowledge enters RAG only through an explicit `use` share.** Vector queries filter on `(userId, groupKey)` pairs; there is never a query without an owner filter. CORE-5 stays true: no share row ⇒ no cross-user chunk, ever. | Locked | |
| 13 | **Schema (this plan is the "ask first"):** `BGROUPS`, `BGROUPMEMBERS`, `BSHARES`, `BAUDITLOG`, `BEXTERNALIDENTITIES` (S1), `BGROUPCONFIG` + `BCONFIG.BLOCKED` (S5). Galera-safe `addSql` only. | Ask recorded | |
| 14 | **Group-level policy is a config layer**, resolved user → groups → global, for a small allow-list of settings (default models, allowed models, feature flags, rate-limit tier). `BCONFIG.BLOCKED = 1` on a global row means "user override ignored". Not in MVP (S5). | Config layer, S5 | |
| 15 | **API-key scopes gain `iam:read` / `iam:manage`.** Empty / legacy scopes stay full access (CORE-3 grandfather). No new firewall. | Additive scopes | |
| 16 | **UI words (en):** People, Groups, Share, "Shared with me", Can view / Can use / Can edit / Can manage. Never "ACL", "tenant", "principal", "grant" in primary copy. All five locales fixed in §7 before the first UI PR. | Locked | |
| 17 | **No new top-level nav item.** Operate gains one child **People** (users + groups; the `users` tab of `AdminView.vue` moves there). Manage lists gain a "Shared with me" filter, not a new page. | Lean nav | |
| 18 | **Widget, mobile, `/v1` gateways, OIDC login, API keys unchanged.** New PHP paths are `backend-only`; People page + Share dialog are `ota-candidate`. | Locked | |

If a row is rejected, update every section below that assumed the default.

---

## 1. The concept in three sentences

> Everything in Synaplan belongs to exactly one person, its owner, and that
> never changes. A **group** is a named list of people — made by an admin or
> pulled in from your company login — and an owner can **share** a knowledge
> folder, an assistant, a conversation or a task with a group so its members
> can view, use or edit it. Admins manage people and groups and set defaults,
> but they do not see private content unless someone shares it with them.

This paragraph is the acceptance test for every UI text in this track: if a
screen needs more than these three ideas, the screen is wrong.

---

## 2. Why this exists

Today every resource is per user and that is the right default (CORE-5). But
a team cannot:

- give a colleague the knowledge folder they built, without re-uploading;
- hand a good conversation, with its files, to the team to continue;
- let an admin publish one assistant to "Sales" and another to "Support";
- reflect the company directory (who is in which department) at all — the
  OIDC `groups` claim is read as role names only.

Hosters asked for exactly this ("enterprise features on an open-source
stack"). The partner review named it foundation. Every other track needs it.

---

## 3. What already exists (do not rebuild)

| Piece | State | Role here |
| ----- | ----- | --------- |
| `BUSER.BUSERLEVEL` (`NEW/PRO/TEAM/BUSINESS/ADMIN`) | Shipped | Billing tier + admin. **Stays.** IAM adds groups beside it, not a replacement |
| `OidcUserService` + `OIDC_ROLE_CLAIMS` | Shipped | Login and admin promotion unchanged; S4 adds group upsert from a configurable claim |
| `ApiKeyScope` / `ApiKeyScopeSubscriber` | Shipped (partial CORE-3) | Gains `iam:*` scopes; grandfather rule untouched |
| `AdminUserProvisioningController` (`source`/`external_id` in `BUSERDETAILS`) | Shipped (CORE-2) | S1 introduces `BEXTERNALIDENTITIES`; JSON stays as read fallback; provisioning writes both |
| `ImpersonationService` | Shipped | Kept; S4 adds an audit row per impersonation and the `disabled` option |
| `BFILES.BGROUPKEY` / `BRAG.BGROUPKEY` + Qdrant `groupKey` payload | Shipped | The **knowledge folder** identity. A share of kind `knowledge_folder` targets `(ownerId, groupKey)` |
| `BCHATS.BSHARETOKEN` / `BISPUBLIC` | Shipped | Public link stays as-is; group share is a second mechanism, not a replacement |
| `BPROMPTS.BOWNERID = 0` (system prompts) | Shipped | "System" = shared with everyone by construction; not migrated into `BSHARES` |
| `BCONFIG.BOWNERID` 0 / user | Shipped | S5 adds a group layer between the two |
| `useNavItems.ts` `canSeeOperate` / `canSeeManage` seams | Shipped | People page under Operate; the comment "when a real workspace capability exists" is this track |
| `AdminView.vue` `users` tab | Shipped | Moves into People (S1) |

---

## 4. Target architecture

```text
                 ┌───────────────────────────────────────────┐
  request ──────►│ Controller  ──►  #[IsGranted('IAM_USE', r)]│
                 └───────────────────┬───────────────────────┘
                                     ▼
                              IamVoter ─► AccessGate::decide(user, kind, id, level)
                                     │
                  ┌──────────────────┼──────────────────┐
                  ▼                  ▼                  ▼
            owner check      BSHARES lookup       group membership
           (kind->ownerId)  (user | group | everyone)   (BGROUPMEMBERS)
                                     │
                                     ▼
                     ShareableResourceKindInterface (registry)
        knowledge_folder | conversation | assistant | saved_task | widget | plugin:*
```

### 4.1 Schema (S1 unless noted)

| Table | Columns (all `B*`) | Notes |
| ----- | ------------------ | ----- |
| `BGROUPS` | `BID`, `BNAME`, `BSLUG` (unique), `BDESCRIPTION`, `BKIND` (`manual` / `directory`), `BEXTERNALSOURCE`, `BEXTERNALID`, `BPARENTID` (nullable, unused v1), `BCREATED`, `BUPDATED` | Directory groups keyed by `(BEXTERNALSOURCE, BEXTERNALID)` |
| `BGROUPMEMBERS` | `BGROUPID`, `BUSERID`, `BROLE` (`member` / `manager`), `BSOURCE` (`manual` / `directory`), `BCREATED` | PK `(BGROUPID, BUSERID)`; directory rows reconciled at login, manual rows untouched by sync |
| `BSHARES` | `BID`, `BRESOURCEKIND`, `BRESOURCEID` (string; kind decides the format, e.g. `"{ownerId}:{groupKey}"` for folders), `BSUBJECTTYPE` (`user` / `group` / `everyone`), `BSUBJECTID` (0 for everyone), `BPERMISSION`, `BGRANTEDBY`, `BCREATED` | Unique `(kind, resource, subjecttype, subjectid)`; highest permission wins |
| `BAUDITLOG` | `BID`, `BACTORID`, `BACTION`, `BRESOURCEKIND`, `BRESOURCEID`, `BSUBJECT` (JSON), `BIP`, `BCREATED` | Append-only; written for share/unshare, group changes, impersonation, admin metadata views; retention setting |
| `BEXTERNALIDENTITIES` | `BID`, `BUSERID`, `BSOURCE` (`oidc:<issuer>` / `nextcloud` / `owncloud` / `opencloud` / `outlook`), `BINSTANCEID`, `BEXTERNALID`, `BAPIKEYID` (nullable), `BCREATED`, `BLASTSEEN` | Unique `(BSOURCE, BINSTANCEID, BEXTERNALID)`. Introduced here, consumed by track 6 and by directory sync |
| `BGROUPCONFIG` (S5) | mirrors `BCONFIG` with `BGROUPID` instead of `BOWNERID` | Allow-listed settings only |
| `BCONFIG.BLOCKED` (S5) | `TINYINT(1) DEFAULT 0` on global rows | "Admin default, no user override" |

No foreign keys with cascades are relied on (Galera rule 3); the kind
implementation deletes shares when its resource is deleted.

### 4.2 The kind contract

```php
interface ShareableResourceKindInterface
{
    public function key(): string;                                    // 'knowledge_folder'
    public function ownerId(string $resourceId): ?int;                // null = not found
    public function describe(string $resourceId): ResourceCard;       // name, icon, meta for dialogs/lists (no content)
    public function listOwnedBy(int $userId): iterable;               // for "share" pickers
    public function onShareChanged(string $resourceId): void;         // e.g. invalidate caches
    public function supportedPermissions(): array;                    // subset of read|use|edit|manage
}
```

`AccessGate::decide()` is the only place that reads `BSHARES` and
`BGROUPMEMBERS`. It is cached per request. Kinds decide **what a permission
means**: for `knowledge_folder`, `use` means "include in my RAG"; for
`conversation`, `read` means "open read-only", `use` means "continue as a
copy"; for `assistant` (track 2), `use` means "appears in my list and I can
talk to it".

### 4.3 RAG with shared knowledge (S2)

- `RagScopeResolver` returns the list of `(ownerId, groupKey)` pairs the
  current user may search: own folders + folders shared with `use`.
- `MariaDBVectorStorage`: `WHERE (BUID, BGROUPKEY) IN ((…),(…))`.
- `QdrantVectorStorage`: `filter.should = [ {must:[userId=a, groupKey=x]}, … ]`.
- Results carry `owner` and `shared: true` so the UI can badge them.
- Never a query without at least one pair. A characterization test asserts
  the generated filter for zero, one and many shares.

### 4.4 Directory sync (S4)

- Setting `IAM.DIRECTORY_GROUPS_CLAIM` (default `groups`; dotted path
  allowed, same resolver as `OIDC_ROLE_CLAIMS`).
- On each OIDC login: upsert `BGROUPS` (`kind=directory`,
  `source=oidc:<issuer>`, `externalId=<claim value>`), reconcile the user's
  `directory` memberships to the claim; `manual` memberships untouched.
- Name mapping table (optional): claim value → display name.
- SCIM 2.0 (`/scim/v2/Users`, `/scim/v2/Groups`, bearer token) is v2; it is
  PHP in core because it writes `BUSER`. Decision when a hoster needs it.

### 4.5 Admin privacy (S4)

- `AccessGate` grants admins `manage` on every kind (share/unshare/delete)
  but **not** `read`/`use` — content endpoints for other users' resources
  return 403 for admins as for anyone else.
- Impersonation stays the audited exception; `IAM.ADMIN_IMPERSONATION` =
  `audited` (default) | `disabled`.
- "Support access" (user grants an admin time-boxed `read` on a resource) is
  a v2 candidate, listed in §11.

---

## 5. Admin UI and end-user UI

Contract: the current navigation is lean (Work / Manage / Operate) and this
track must not undo that.

### 5.1 Operate → People (admin, `/admin/people`)

| Tab | Content | Origin |
| --- | ------- | ------ |
| **Users** | Today's `users` tab of `AdminView.vue`, moved. Adds columns: groups, external identities (badges: OIDC / Nextcloud / …). | Move, not rewrite |
| **Groups** | List (name, kind badge *manual* / *from login*, member count). Create / rename / delete (manual only). Detail: members with role, add by email/name picker, "what is shared with this group" (metadata list). | New |
| **Policies** (S5) | Per group: allowed models, default models, feature flags, rate-limit tier; global "locked" toggles. | New, later |
| **Audit** (S4) | Filterable `BAUDITLOG` (who shared what with whom, impersonations). | New, later |

`AdminView.vue` shrinks: the `users` tab becomes a link. This is a
simplification, not an addition.

### 5.2 Share (everyone)

- One `ShareDialog.vue`, opened from a **Share** action on: a knowledge
  folder card (`/files`), a conversation (chat header menu), an assistant
  card, a saved task card, a widget card. Same component, kind passed in.
- Content: current shares list (avatar / group chip, permission dropdown,
  remove), an add row (search people and groups; "Everyone in this
  organization" as a pinned entry), permission per row. Public link section
  unchanged where it exists today.
- No new page. "Shared with me" is a filter chip on the existing lists
  (`/files`, `/ai/instructions`, chat history) — S2/S3.
- Badges: a small "shared" icon on cards that are shared, an owner avatar on
  cards shared *to* me.

### 5.3 Five-question check (from `08_ux_and_i18n.md` discipline)

Every screen must answer: Who owns this? Who else can see it? What can they
do? How do I stop it? Where did this group come from? A screen that cannot
answer one of these in its own copy is not done.

---

## 6. API sketch (additive)

All under `/api/v1/`, session or API key (`iam:*` scopes), full OpenAPI,
flag-gated (404 when the flag is off — do not advertise the surface).

| Method | Path | Sprint | Purpose |
| ------ | ---- | ------ | ------- |
| `GET/POST` | `/admin/groups`, `PATCH/DELETE /admin/groups/{id}` | S1 | Group CRUD (admin) |
| `GET/PUT/DELETE` | `/admin/groups/{id}/members/{userId}` | S1 | Membership + role |
| `GET` | `/groups/mine` | S1 | Groups I belong to (for pickers) |
| `GET` | `/iam/subjects?q=` | S2 | People + groups search for the Share dialog |
| `GET/POST/DELETE` | `/shares?kind=&resource=` | S2 | Shares on one resource (owner / manage) |
| `GET` | `/me/shared?kind=` | S2 | Resources shared with me |
| `GET` | `/admin/audit` | S4 | Audit log |
| `GET/PUT` | `/admin/groups/{id}/config` | S5 | Group policy layer |

Existing endpoints gain nothing but the voter: e.g. `GET /chats/{id}`
passes when `IAM_READ` holds, and RAG search reads `RagScopeResolver`.

---

## 7. UX vocabulary (five locales, fixed before the first UI PR)

| en | de | es | fr | tr |
| -- | -- | -- | -- | -- |
| People | Personen | Personas | Personnes | Kişiler |
| Group / Groups | Gruppe / Gruppen | Grupo / Grupos | Groupe / Groupes | Grup / Gruplar |
| From your login (directory group) | Aus Ihrer Anmeldung | De su inicio de sesión | Depuis votre connexion | Girişinizden |
| Share | Teilen | Compartir | Partager | Paylaş |
| Shared with me | Mit mir geteilt | Compartido conmigo | Partagé avec moi | Benimle paylaşılan |
| Everyone in this organization | Alle in dieser Organisation | Todos en esta organización | Tout le monde dans cette organisation | Bu kuruluştaki herkes |
| Can view / Can use / Can edit / Can manage | Darf ansehen / Darf nutzen / Darf bearbeiten / Darf verwalten | Puede ver / Puede usar / Puede editar / Puede administrar | Peut voir / Peut utiliser / Peut modifier / Peut gérer | Görüntüleyebilir / Kullanabilir / Düzenleyebilir / Yönetebilir |
| Owner | Eigentümer | Propietario | Propriétaire | Sahip |

Banned in primary copy: ACL, tenant, principal, grant, RBAC, claim, SCIM
(admin settings may name protocols in helper text).

---

## 8. Compatibility invariants

Named tests live in the sprint files; the list is binding.

| # | Invariant | Proof |
| - | --------- | ----- |
| C1 | **Flags off ⇒ byte-identical behavior.** No nav change, 404 on new routes, voter short-circuits to owner check. | Feature test suite runs once with flags off |
| C2 | **CORE-5 holds.** Without a `BSHARES` row with `use`, no vector query includes another user's `(userId, groupKey)`. | Isolation test for MariaDB and Qdrant, extended with the shared case |
| C3 | **OIDC login unchanged** when `IAM.DIRECTORY_SYNC_ENABLED` is off; role/admin mapping unchanged even when on. | Existing OIDC tests + new sync tests |
| C4 | **API keys:** empty/legacy scopes keep full access; `iam:*` scopes are additive. | `ApiKeyScope` tests |
| C5 | **Routing characterization snapshots untouched.** This track never edits the classifier or sorter. | Snapshot suite |
| C6 | **Public links** (`BSHARETOKEN`, file share tokens) unchanged. | Existing tests |
| C7 | **Widget and mobile unchanged.** New PHP = `backend-only`; People + ShareDialog = `ota-candidate`. | `scripts/mobile-impact.mjs` |
| C8 | **Admin cannot read other users' content** through any new endpoint; metadata only. | Negative tests per kind |
| C9 | **Impersonation behavior unchanged** except for the audit row (and the `disabled` option when set). | Existing tests + audit assertion |

---

## 9. Sprints

| Sprint | Content | Exit |
| ------ | ------- | ---- |
| **S0 — Concept & UI** | Tick §0; wireframes for People and ShareDialog; five-locale vocabulary; sprint files + work breakdown | Product owner signs the three sentences and the wireframes |
| **S1 — Groups core** | Migrations (`BGROUPS`, `BGROUPMEMBERS`, `BAUDITLOG`, `BEXTERNALIDENTITIES`); `AccessGate` + `IamVoter` (owner-only behavior); kind registry with `knowledge_folder` and `conversation` descriptors; admin group API; **People** page (Users moved, Groups new); `iam:*` scopes; flag `IAM.GROUPS_ENABLED` | Admin creates "Sales", adds three people; nothing else changes |
| **S2 — Sharing MVP** | `BSHARES`; ShareDialog; share a **knowledge folder** (`use`) → `RagScopeResolver` + both vector stores; share a **conversation** (`read`, `use` = continue as copy incl. its files); "Shared with me" filters; flag `IAM.SHARING_ENABLED` | Acceptance demo: "add this chat with its files to my team", team member continues it and RAG finds the files |
| **S3 — More kinds** | `assistant` (BPROMPTS — coordinates with track 2 S3), `saved_task` (read/use = run a copy), `widget` (read/edit for co-editing); plugin-declared kinds via manifest v2 | Admin publishes a system-like assistant to one group only |
| **S4 — Directory & privacy** | OIDC groups claim sync; audit log + Audit tab; admin metadata-only enforcement; impersonation audit + `disabled` option | A user logging in via Keycloak lands in the right groups; admin sees who shared what, not the content |
| **S5 — Group policies** | `BGROUPCONFIG`, `BCONFIG.BLOCKED`, resolution user → groups → global for allow-listed settings; Policies tab | "Support may only use these two models" holds; a locked default cannot be overridden |
| **v2 candidates** | SCIM 2.0; nested groups; ownership transfer; support access (time-boxed read grant to an admin); per-group budgets | Decided per hoster demand |

Cut line: if scope slips, cut **S5** first, then **S4 audit tab** (keep the
log). Never cut C2 tests or the ShareDialog polish — a confusing sharing UI
is worse than no sharing.

---

## 10. Rollout

1. Every PR merges to `main` with flags off (C1). Seeder inserts flags off
   for new and existing installs.
2. After S2 acceptance on a dev instance, enable `IAM.GROUPS_ENABLED` +
   `IAM.SHARING_ENABLED` on Synaplan Cloud for one internal team, then
   default-on for **new** installs via seeder. Existing installs: admin
   flips the flags (documented in `docs/ADMIN.md` + a docs-site page).
3. Directory sync stays opt-in forever (an instance without OIDC has no use
   for it).
4. Rollback: flags off. Tables and shares remain; the voter falls back to
   owner-only.

---

## 11. Out of scope (v1)

- In-database multi-tenancy, billing per group, white-label per group.
- Custom roles / permission matrices beyond the four levels.
- Sharing with anonymous or widget visitors (public links stay the tool).
- Cross-instance sharing (federation).
- SCIM, nested groups, ownership transfer, support access (v2 list above).
- Replacing `BUSERLEVEL` — billing tier and IAM stay orthogonal.

---

## 12. Success criteria

1. A non-technical admin explains groups and sharing to a colleague with
   the three sentences in §1 and is not wrong.
2. Share a chat with its files with a group: a member opens it, continues
   as a copy, and RAG answers from the shared files. Remove the share: the
   next RAG query no longer sees them (C2 both stores).
3. An admin cannot open another user's conversation or files through any
   endpoint or UI; the audit tab shows the attempt is not needed because
   there is no path.
4. Keycloak groups appear as directory groups after one login; removing a
   user from the group in Keycloak removes the directory membership at the
   next login; manual memberships are untouched.
5. Flags off on a production-like instance: full gate green, characterization
   snapshots untouched, existing OIDC / API-key / widget / mobile behavior
   identical.
6. The People page replaces the old Users tab; total nav item count in
   Operate grows by exactly one.

---

## 13. Open questions (decide in S0)

1. Should `everyone` shares require admin rights, or may any owner share
   with the whole organization? (Proposed: any owner; admins can disable via
   `IAM.EVERYONE_SHARES = admins_only`.)
2. Do we show shared-knowledge sources in the chat with the owner's name, or
   only "shared"? (Proposed: name — transparency beats anonymity inside one
   organization.)
3. Conversation `use` = "continue as copy" creates a new chat owned by the
   member with copied file *references* (not copies of the binary). Is a
   reference acceptable when the owner later deletes the file? (Proposed:
   yes; the copy shows "file no longer available".)
4. Who owns the audit retention default — 365 days? Hoster-configurable via
   `IAM.AUDIT_RETENTION_DAYS`.
