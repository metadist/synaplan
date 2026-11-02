# Permission-aware connectors — company knowledge from SharePoint, Google Drive and Confluence, never more than the person may see

Row 40. Umbrella: [`20261102-enterprise-readiness/`](../20261102-enterprise-readiness/00_master_plan.md) (C17).

The company's knowledge is in SharePoint and OneDrive (most German
companies), Google Drive (some), and Confluence (tech and project teams).
An assistant that answers from it must answer **only from what the asking
person may open in the source**. Otherwise one question leaks the
board's folder to an intern. Onyx sells exactly this ("40+ connectors with
permission sync"), and so does Microsoft Copilot inside M365.

Synaplan today indexes files that a person uploads or picks up from
Dropbox, WebDAV, Nextcloud, OpenCloud and ownCloud (row 9). Search is
scoped by owner, folder and shared file list. This track adds:

- **Phase A:** personal sources (my OneDrive, my Google Drive) indexed into my own knowledge. No permission problem: it is mine.
- **Phase B:** organisation sources (SharePoint sites, shared drives, Confluence spaces) indexed once. Every chunk carries the source's permissions as principals, and each search filters by the asking person's principals. It **fails closed** when permissions are stale.

Row 10 (backend integrations, Wave 6 catalog) and row 9 (cloud file
sharing: watches, change queue, quiet period, cursor) are the foundations
this track builds on, not competitors.

## 1. Findings (checked 2026-11-02 against `main` and vendor docs)

| # | Prio | Finding | Evidence |
| - | ---- | ------- | -------- |
| F1 | P1 | No SharePoint, OneDrive, Google Drive or Confluence source. The Microsoft integration is mail only | `Service/Microsoft/GraphClient.php`, `M365MailSender.php`, `MicrosoftConnectionService.php` |
| F2 | P1 | Search scope is owner + group key + an explicit file list; there is no notion of an external principal (an Entra user or group, a Google group, a Confluence group) | `Service/RAG/VectorStorage/DocumentScope.php`, `RagScopeFilter.php` (Qdrant and MariaDB legs) |
| F3 | — | Graph delta (`/drives/{id}/root/delta`) gives content changes cheaply. Permission changes need `Prefer: deltashowsharingchanges, hierarchicalsharing, deltatraversepermissiongaps` and `Sites.FullControl.All`, and even then library-level and group-membership changes are not reported, so a periodic full permission reconcile is required | Microsoft Learn `driveItem: delta`; Microsoft ISE blog on SharePoint ACLs in RAG |
| F4 | — | Google Drive: Changes API for content; ACL changes appear only in some users' change logs, so reconcile periodically; groups need the Admin SDK Directory API | Google Drive API docs; wavect.io permissions-first RAG note |
| F5 | P1 | Row 9's watch machinery (cursor, change queue, quiet period, loop guard, limits) already solves incremental sync for cloud folders | `Service/CloudWatch/` |
| F6 | P2 | Both vector backends (Qdrant, MariaDB VECTOR) must apply the same filter. A keyword leg must never see wider than the vector leg (an existing invariant) | `DocumentScope` docblock |

## 2. What is fine — do not "fix" it

- Personal knowledge, folders, sharing and the "own-only" filter shape (frozen by `RagScopeFilter`).
- Row 9's cloud copy, pickup and watches. Phase A is a watch with a different client.
- Hybrid search (vector + keyword) and its shared scope. The principal filter is added in `DocumentScope`, so both legs get it.

## 3. Target model

### 3.1 Phase A — personal sources

- Knowledge → **Add source** → *My OneDrive / SharePoint files* (delegated Graph, `Files.Read.All` + `offline_access`) or *My Google Drive* (OAuth, `drive.readonly`).
- The person picks folders. A watch indexes them into their own knowledge with the existing owner scope, using delta / changes for updates.
- This is "my copy, my scope". Sharing it in Synaplan follows Synaplan's sharing, as for an upload.
- Each indexed file shows its source and **Open in OneDrive**. Removing the source removes its chunks.

### 3.2 Phase B — organisation sources with permissions

```text
admin connects source ─► crawler (scheduler lane)
  full crawl: items + content + permissions
  delta: content changes          (cursor per drive/space)
  reconcile: permissions, daily    (and on @sharedChanged when available)
        │
        ▼
chunks with payload.principals = ["ms:user:{oid}", "ms:group:{oid}", "ms:tenant:{tid}", ...]
        │
search ─► searcher's principals = own ids + transitive groups (cached 15 min)
          filter: principals ∩ searcher ≠ ∅  AND  source.aclFresh
```

- **Principals, not Synaplan users.** The chunk stores the source's own ids: Entra object ids, Google user and group ids, Confluence account and group ids. At search time Synaplan computes the asking person's principal set:
  - their own external id, via `BEXTERNAL_IDENTITIES` from row 35 EI0;
  - their transitive groups from the source: Graph `transitiveMemberOf`, Google Directory `groups.list?userKey`, Confluence groups;
  - the set is cached 15 min.

  A person without a linked identity for that source sees nothing from it, and the sources list says why.
- **SharePoint specifics:**
  - SharePoint groups (site Owners / Members / Visitors) are expanded at crawl time to Entra principals (SharePoint REST `sitegroups`).
  - Sharing links: "specific people" links count as access for those people; "people in your organisation" links count as `ms:tenant:{tid}`; "anyone" links are not counted (D3).
  - Sensitivity labels with encryption: the item is skipped and counted.
- **Least privilege:** `Sites.Selected` (the admin grants Synaplan read on chosen sites only) plus `GroupMember.Read.All` (D1). `Sites.FullControl.All` is only for faster permission-change detection, opt-in.
- **Fail closed:** each source has `aclReconciledAt`. If it is older than `KNOWLEDGE.ACL_MAX_AGE_HOURS` (default 26), the source is excluded from search until the reconcile succeeds. Everyone who would have searched it sees "SharePoint 'Sales' is being re-checked and is not searched right now."
- **Deletion:** an item deleted or moved out of scope at the source removes its chunks on the next delta. A source removed by the admin removes all its chunks in one job.
- **Storage:** Qdrant gets a keyword-indexed payload field `principals` and a `should` filter. MariaDB gets a table `BRAG_CHUNK_PRINCIPALS` (`BCHUNKID`, `BPRINCIPAL`), joined with `EXISTS`. Both legs are built from the same `SearchQuery` in `RagScopeFilter`. The frozen own-only shape stays byte-identical, and a test proves it.

### 3.3 Where people find it (U1, U7)

- **Knowledge → Sources** lists each organisation source with:
  - its owner (the admin who connected it);
  - its scope (sites or spaces);
  - documents indexed;
  - the last content sync and the last permission check;
  - **Pause** and **Remove**.

  Every person sees only the sources that hold documents they can see, with counts of what *they* can see.
- **In an answer,** each source chip shows the original location and "You can open this in SharePoint". Clicking opens the original, not a copy.
- **Admin → Sources** has a **Check access for…** tool: pick a person and a document, and see "Ada can see this through the group 'Sales DE' (Entra)". It answers "why can she see this" in one line.

## 4. Decisions

- [ ] **D1 (KC3).** Microsoft permissions: `Sites.Selected` + `GroupMember.Read.All` (application), plus daily reconcile. *Alternative:* `Sites.FullControl.All` for near-real-time permission deltas — opt-in per source, never the default.
- [ ] **D2 (KC1).** Search requires row 35 EI0 (external identities) for organisation sources. Without a linked identity, nothing from the source is shown.
- [ ] **D3 (KC3).** "Anyone" sharing links grant nothing in Synaplan; "people in your organisation" links grant the tenant principal.
- [ ] **D4 (KC4).** ACL freshness limit 26 h (daily reconcile + margin), fail closed.
- [ ] **D5 (order).** Phase A (KC2, KC6a) may ship before row 35; Phase B waits for EI0.
- [ ] **D6 (KC7).** Confluence Cloud only (REST v2). Data Center / Server later or never.

## 5. Journeys

| Id | Journey | Profile |
| -- | ------- | ------- |
| J-KC-1 | A user adds "My OneDrive", picks the folder "Projects", and an hour later asks "What did we offer Müller in March?" The answer cites the offer with **Open in OneDrive** | User |
| J-KC-2 | An admin connects SharePoint with `Sites.Selected` and grants two sites. Knowledge → Sources shows "Sales — 1,204 documents — permissions checked 08:10" | Admin |
| J-KC-3 | Ada (member of "Sales DE") asks about the price list and gets it. Bob (not a member) asks the same question and gets "I found nothing you have access to on this." Admin → Check access shows why for each | Users, admin |
| J-KC-4 | Ada is removed from "Sales DE" in Entra. After the group cache expires (≤ 15 min), her next question no longer finds the price list | User |
| J-KC-5 | The reconcile fails for 26 h (expired secret). The source is excluded; users see the re-check sentence; the admin sees "Permission check failing since {date}: Microsoft says the secret has expired" with **Update credentials** | Admin, users |

## 6. Steps

| Step | Commit subject | Release class | Depends on | Gate |
| ---- | -------------- | ------------- | ---------- | ---- |
| KC0 | spike: crawl one SharePoint site with `Sites.Selected`, expand SharePoint groups, record principals, filter in Qdrant and MariaDB; measure crawl rate and reconcile cost on 10,000 items; results in STATUS | no-app-impact | — | STATUS entry |
| KC1 | `feat(rag): filter knowledge by the source's permissions` — `principals` in Qdrant payload + index, `BRAG_CHUNK_PRINCIPALS` for MariaDB (migration), principal resolver interface with a per-source cache, `RagScopeFilter` extended (own-only shape byte-identical, test), fail-closed freshness gate | backend-only | D2, KC0, row 35 EI0 | `make ci-local`; characterization of the legacy filter unchanged |
| KC2 | `feat(knowledge): add my OneDrive and SharePoint files as a source` — delegated Graph connection, folder picker, watch on row 9's machinery with Graph delta, source chip and **Open in OneDrive**, removal; J-KC-1 | backend-only + ota-candidate | D5 | `make ci-local && make test-e2e` |
| KC3 | `feat(knowledge): index SharePoint sites with their permissions` — admin source with `Sites.Selected`, crawler on a scheduler lane, item permissions → principals (D3), SharePoint group expansion, label skip, Entra principal resolver (`transitiveMemberOf`) | backend-only | D1, D3, KC1 | `make ci-local`; real-tenant crawl logged in STATUS |
| KC4 | `feat(knowledge): keep SharePoint content and permissions current` — delta per drive, daily reconcile, optional `deltashowsharingchanges` with FullControl, freshness state and admin mail on failure; J-KC-4, J-KC-5 | backend-only | D4, KC3 | `make ci-local` |
| KC5 | `feat(knowledge): sources page and "check access for…"` — Knowledge → Sources (per-person counts), Admin → Sources with owner, scope, sync and permission dates, pause, remove, **Check access for…**; J-KC-2, J-KC-3 | ota-candidate + backend-only | KC3 | `make ci-local && make test-e2e` |
| KC6a | `feat(knowledge): add my Google Drive as a source` — OAuth `drive.readonly`, Changes API watch | backend-only + ota-candidate | D5 | `make ci-local && make test-e2e` |
| KC6b | `feat(knowledge): index Google shared drives with their permissions` — service account with domain-wide delegation, permissions (user, group, domain; anyone → nothing), Directory API groups resolver, reconcile | backend-only | KC1, KC6a | `make ci-local`; real-workspace crawl logged |
| KC7 | `feat(knowledge): index Confluence spaces with their permissions` — Confluence Cloud REST v2, space permissions + page restrictions with ancestor evaluation, groups resolver, reconcile | backend-only | D6, KC1 | `make ci-local`; real-site crawl logged |
| KC8 | `docs(knowledge): connecting SharePoint, Google Drive and Confluence` — `docs/CONNECTIONS.md` sections with the exact permissions and why, admin consent steps, troubleshooting | no-app-impact | KC4 | `make ci-local` |

KC2, KC5 and KC6a carry the five exit bullets of `docs/UX_CONTRACT.md` §6
before their first `.vue`:

- **Journeys walked:** J-KC-1, J-KC-2 and J-KC-3.
- **Ten-second findability:** sources sit under Knowledge where files already are; the source chip in the answer opens the original.
- **Consequence copy in five locales:** "Removing 'Sales' deletes 1,204 indexed documents from Synaplan. Nothing changes in SharePoint."
- **Empty, error and flag-off states:** no source → "Bring in your company's documents from SharePoint, Google Drive or Confluence." + **Add source**; the stale-permission state; a person without a linked identity → "Sign in once with your company account to search SharePoint."
- **Dark, V2 and 320 px.**

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| A permission bug leaks documents | Fail closed everywhere (no identity, stale ACL, unknown principal type); KC1 property test: for random ACLs and memberships, search never returns a chunk whose principals do not intersect; pentest scope item (ER3) |
| Crawl cost and Graph throttling on large tenants | `Sites.Selected` keeps scope small; crawler honours `Retry-After`; KC0 measures; per-source rate setting |
| Group expansion is slow at query time | 15-min cache per person and source; the cache is warmed on sign-in |
| Content of sensitivity-labelled documents | Encrypted items are skipped and counted, never decrypted |
| Index size | Chunk counts per source on the sources page; storage quota (`StorageQuotaService`) counts organisation sources against the install, not a person |

## 8. Non-goals

- Writing back to SharePoint, Drive or Confluence (that is row 9's copy for Nextcloud-like clouds).
- Mail (Exchange) or chat (Teams messages) as knowledge sources.
- Jira, Notion, Salesforce knowledge — later, on the KC1 principal contract, if customers ask.

## 9. Change log

| Date | Change |
| ---- | ------ |
| 2026-11-02 | Plan written from Microsoft Graph delta and permission documentation, the Microsoft ISE pattern for SharePoint ACLs in RAG, permissions-first RAG notes for Google Drive and Confluence, and a read of the RAG scope, vector storage and cloud-watch code on `main`. No code changed. |
