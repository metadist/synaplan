# Portable sharing + migration — prompts, chat widgets, and their data

| | |
| - | - |
| **Status** | Plan, 2026-09-29. Builds on the federation link ([`00_master_plan.md`](./00_master_plan.md), [`03_verdict_and_orders.md`](./03_verdict_and_orders.md)) and the existing bundle system (`synaplan-bundle.v1`). No product code yet. |
| **Branch** | `feat/synaplan-network` (same branch; separate PRs per step) |
| **Type** | Backend-first, UI behind existing surfaces + `FederationModule` gate. |
| **Scope** | **Share a copy** (prompt / widget + its knowledge) with a peer over a federation link, and **migrate a client** (all prompts, widgets, knowledge data) from instance A to instance B. File download + re-upload already works; this plan makes *the whole working setup* move. |
| **Binding contracts** | UX rules U1–U12 ([`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md)); AGENTS.md "Perfect UX & Stability"; bundle rules in `BundleEnvelopeValidator` (5 MB JSON cap, 200 items/section). |

Files in this folder: start with [`05_partners.md`](./05_partners.md) (binding
plan). This file stays binding for the portable format (§1–§4) and client
migration (§6). The federation share transport in §5 and steps P5–P6 are
replaced by *Can copy* in `05` §4.2 / M4: the partner's admin clicks
**Get a copy** instead of accepting a pushed offer.

---

## 0. The idea in one page

A client runs Synaplan on instance A: prompts (AI assistants), chat widgets on
their website, knowledge folders with files. They want two things:

1. **Share a working copy** — "send my Support widget + its prompt + its FAQ
   folder to our partner's Synaplan so they can run it." Not a screenshot, not
   a docs page: a copy that runs.
2. **Migrate cleanly** — "we move from `old-host.com` to `new-host.com` (or
   from cloud to self-hosted). Everything must come with us and work the next
   morning: prompts, widgets, files, Vectors rebuilt, embed codes updated."

Both are the **same mechanism**: a portable bundle. The federation link from
`03` is one transport for it (send over the link); file download/upload is the
other (migrate without any link). There is exactly one format, and it already
exists:

> **`synaplan-bundle.v1` is the only portability format. Federation sends it;
> Export/Import carries it. No second format, no live object sync.**

What this plan adds to the existing bundle (which already covers `prompts`,
`agents`, `saved_tasks`, `custom_tools`, `mcp_servers`):

| New | Why |
| --- | --- |
| `widgets` bundle section | Widgets are the client's public face; today they cannot move at all. Portability rules in §3 (new `widgetId`, agent slug not BID, secrets stripped, domains carried). |
| `knowledge` bundle section (manifest) + **file archive** (bytes) | Prompts/widgets without their folders are empty shells. The JSON bundle carries the manifest (folders, filenames, hashes); the bytes travel as a separate archive and are **re-vectorized on import** — vectors never cross instances (§4). |
| `POST /federation/share` transport | Send a bundle over an accepted link; the peer previews the same checklist as an uploaded file and imports as drafts (§5). Reuses `BundleImporter::preview/apply` verbatim. |
| Migration flow ("move my setup") | User-scope export-all + archive, import-all with one checklist, conflict strategy, post-migration report with new embed codes (§6). |

What this plan explicitly does **not** do: live sync of prompts/widgets between
instances (copies, not replicas); moving users, shares, chat history, widget
sessions, usage logs, vectors, credentials, or provider keys; merging two
clients into one.

---

## 1. What travels, what never travels

Same rule as `03` §4, extended from "one query" to "one setup":

> **Configuration travels as portable references. File bytes travel once and
> are re-indexed. Secrets, identities, and history never travel.**

| Travels | How | Notes |
| ------- | --- | ----- |
| Prompt text, description, topic | `prompts` section (exists) | `aiModel` already portable via `aiModelKey` (`service:providerId:tag`); `mcp_servers` dropped (exists) |
| Assistant definitions | `agents` section (exists) | Models by key, MCP by name, triggers de-wired, schedules off, folders dropped (exists — §4 changes the folder story) |
| Widget name, config, allowed domains, prompt/agent binding | **new `widgets` section** (§3) | Secrets stripped, `widgetId` regenerated, agent by slug |
| Knowledge folder list + file manifest (name, folder, size, sha256, mime) | **new `knowledge` section** (§4) | JSON manifest only; ≤200 files per bundle at v1 |
| File bytes | **separate archive** (§4) | `.zip` + `manifest.json`, ≤500 MB, uploaded after the JSON preview passes |
| Saved tasks, custom tools (spec only) | sections exist | Unchanged; tools import disabled when they need a credential |

| Never travels | Why | What the importer does instead |
| ------------- | --- | ------------------------------ |
| File/vector embeddings, Qdrant points, `RagDocument` rows | Instance-local index; rebuild is cheap and safe | Re-vectorize every imported file with the target's embedding model |
| Credentials: widget `slackWebhookUrl`/`externalApiToken`, custom-tool creds, MCP passwords, provider keys, API keys | Secrets must not cross instances even between friends | Strip on export; checklist row `needs_credential`; feature imports **disabled** until the owner reconnects |
| Numeric IDs (BIDs): `agentId`, `promptId`, file `BID`, user `BID` | Meaningless on the target | Rewrite to portable keys on export (agent slug, prompt topic, `groupKey`+filename); resolve or drop on import |
| Shares (`BSHARES`), group memberships | Subjects do not exist on the target | Never exported. Import creates **private drafts owned by the importer**; they re-share explicitly |
| Chat history, widget sessions/visitors, memories, usage/cost logs, audit rows | Personal data + history, not setup | Never exported. Migration moves the *setup*, not the past |
| `tools:*` and `agent:*` internal prompts | Instance internals | Skipped (exists in `PromptBundleSection`) |
| Absolute URLs, instance fingerprints (except `sourceInstance` envelope field) | Target has its own host | Embed codes and webhook URLs are **regenerated** and shown in the post-migration report |

---

## 2. Design decisions (tick before code; Ask-First marked)

| # | Decision | Recommendation | Ask-First? |
| - | -------- | -------------- | ---------- |
| 1 | Format | Extend `synaplan-bundle.v1` with `widgets` + `knowledge` sections. No v2 envelope, no second schema | no |
| 2 | File bytes | Separate `.zip` archive + `manifest.json` (sha256 per file), ≤500 MB, second upload step. JSON bundle stays ≤5 MB and previews **before** any bytes move | no |
| 3 | Vectors | Never exported. Import re-vectorizes via the existing upload pipeline (same worker, same Tika/Docling path) | no |
| 4 | Widget identity | `widgetId` **regenerated** on import. Old embed codes keep pointing at the old instance; the report shows the new snippet | no |
| 5 | Agent binding | Export agent **slug**, never `BAGENTID`. Import binds by slug if the same bundle (or the target) has it, else falls back to the prompt topic + `needs_agent` checklist row | no |
| 6 | Conflicts | Per-section `skip` (default) / `overwrite` (exists via `ImportOptions`); widgets add `rename` (import as "Copy of X" with a fresh id) because embed codes make overwrite dangerous | no |
| 7 | Federation transport | `POST /federation/share` sends the JSON bundle over an accepted link; file archives follow only after the peer **accepts** (two-phase, §5). No auto-import, ever | no |
| 8 | Scope | User scope first (migrate *my* setup). Instance scope (admin moves everyone) is a later step, explicitly ordered last | **yes** (admin bulk move) |
| 9 | History | Chat/widget sessions do **not** migrate in v1. If a client needs history, `WidgetExportController` (xlsx/csv/json) is the answer, not the bundle | no |

---

## 3. `widgets` section — portability rules

One item per widget owned by the exporter. Mirrors the proven
`PromptBundleSection::portableMeta` pattern: **rewrite on export, resolve or
drop on import, checklist row for everything dropped.**

Export (`WidgetsBundleSection::export`, new):

```jsonc
{ "kind": "widgets", "version": 1, "items": [
  {
    "key": "support-chat",            // slugified name; stable within the bundle
    "name": "Support Chat",
    "prompt": "customer-support",     // BTASKPROMPT topic (portable; must exist in
                                      // the same bundle's prompts section or on target)
    "agent": "contract-review",       // agent slug or null (never BAGENTID)
    "status": "inactive",             // always inactive on export; owner activates
                                      // after checking the checklist
    "allowedDomains": ["example.com"],
    "config": { /* full BCONFIG minus SECRET_CONFIG_KEYS */ }
  }
]}
```

- Secrets stripped with the same key list as `WidgetController::SECRET_CONFIG_KEYS`
  (`slackWebhookUrl`, `externalApiToken`); any future key added there is
  automatically stripped here (share the constant, do not duplicate the list).
- `BCONFIG.allowedDomains` and `BALLOWED_DOMAINS` collapse to one list on
  export; import writes both (via `setAllowedDomains`, which already syncs).
- Internal-only config (crawl state, setup-chat drafts, stats cursors) is
  dropped by an explicit denylist reviewed in the PR — no carry-over of
  instance-local runtime state.

Preview rows (extend the existing `ChecklistItem` codes, same UI):

| Code | Meaning | User sentence (en; all 5 locales in the PR) |
| ---- | ------- | -------------------------------------------- |
| `conflict` | A widget with this name exists | "“Support Chat” already exists. Skip, overwrite, or import as a copy." |
| `needs_prompt` | `prompt` topic not in bundle and not on target | "Needs the “customer-support” assistant — include it in the export or create it first." |
| `needs_agent` | `agent` slug unresolvable | "The bound assistant “contract-review” is missing — the widget will use its prompt until you rebind it." |
| `needs_credential` | stripped secret existed | "Reconnect Slack / the external API after import — secrets never move." |
| `needs_model` | prompt/agent model key unknown on target | Reuse existing row (prompt/agent sections already emit it). |

Apply (`apply`, per widget, own transaction like other sections):

1. Resolve `prompt`: same-bundle prompt (applied first — `dependsOn:
   ['prompts', 'agents']`) else target-owned prompt with that topic, else fail
   the item with `needs_prompt` (never auto-create a prompt from a widget row).
2. Resolve `agent`: same-bundle/target slug → `BAGENTID`, else null + row kept
   as warning (widget still imports, prompt-backed).
3. Create with **fresh `widgetId`** (`wdg_` + 32 hex, `Widget::__construct`
   default), `status: inactive`, owner = importer, `allowedDomains` as listed.
4. Return per-item result; failures never roll back sibling widgets (existing
   `BundleImporter::apply` semantics: one transaction per section, item-level
   try/catch inside).

Post-import, the results panel links each created widget to its settings page
and shows the new embed snippet (U2 findability: the thing you just moved is
one click away, not "somewhere in the list").

---

## 4. `knowledge` section + file archive — data moves once, vectors never

### 4.1 Why two artifacts

The JSON bundle is capped at 5 MB and must preview instantly. File bytes are
megabytes to hundreds of MB and must not move until the human has seen the
checklist. So:

1. **JSON bundle** carries the `knowledge` manifest (folders + file list with
   hashes). Previews in milliseconds, answers "what will arrive?" before a
   single byte moves.
2. **File archive** (`.zip`) carries the bytes. Uploaded/transferred only after
   preview passes and the user confirms. Verified hash-by-hash, then fed
   through the **normal upload pipeline** (virus/size checks, Tika/Docling,
   chunk, embed with the *target's* model).

### 4.2 Manifest (new section, version 1)

```jsonc
{ "kind": "knowledge", "version": 1, "items": [
  {
    "key": "faq:pricing.pdf",          // groupKey:filename, unique in the bundle
    "folder": "faq",                   // groupKey (created on import if missing)
    "filename": "pricing.pdf",
    "mime": "application/pdf",
    "size": 412009,
    "sha256": "e3b0…",
    "prompt": "customer-support",      // owning prompt topic when known (linkage hint)
    "archivePath": "faq/pricing.pdf"   // path inside the .zip
  }
]}
```

Limits (v1): ≤200 files, archive ≤500 MB, per-file ≤100 MB (same ceiling as
regular upload — the archive is not a backdoor around it). Larger moves are
repeated exports, not a bigger cap.

### 4.3 Archive format

```text
synaplan-files-<date>.zip
  manifest.json     // { bundleKey, files: [{ archivePath, sha256, size }] }
  faq/pricing.pdf
  faq/returns.pdf
  …
```

- `manifest.json` inside the zip must match the JSON bundle's `knowledge`
  items (same sha256 set) or the import refuses the archive with one sentence
  ("This file archive does not belong to this bundle — nothing was imported.").
- Zip-Slip, symlink, absolute-path, and overwrite-outside-target rejections are
  mandatory and unit-tested (treat the archive as untrusted input even from a
  linked peer — `03` §3 threat model applies to transports too).

### 4.4 Import pipeline (reuses, never forks)

For each verified file, in folder batches with progress:

1. Create the `File` row owned by the importer (`groupKey` = manifest folder,
   never the source owner's id).
2. Store bytes via the existing file-store path (same as upload).
3. Dispatch the existing vectorization job (same worker, same embedding model
   resolution as local upload). Import returns immediately with "indexing…";
   the folder shows per-file indexing state like a normal multi-upload (U8:
   "3 of 12 files searchable", not a stuck spinner).
4. Re-run `AgentBundleSection` folder linkage: agents/prompts imported from the
   same bundle that referenced these folders by name are rebound (today
   `stripForExport` drops folders with a `droppedFolders` row — with this
   section present, the row disappears and the binding is restored).

Preview rows: `conflict` (same folder+filename exists → skip/overwrite per
file), `too_large` (over the cap → excluded with a named reason), `needs_archive`
(manifest present but no archive attached yet — JSON-only import creates the
folders empty and says so).

### 4.5 Prompts stay linked to their folders

A prompt's topic doubles as its knowledge address today
(`AssistantKind::knowledgeFolder`, `TASKPROMPT:{topic}`). The bundle already
exports prompts; with `knowledge` present the linkage survives the move:

- Export includes `prompt` on each knowledge item when the folder belongs to a
  prompt/assistant in the same export (best-effort hint, not a foreign key).
- Import rebinds by `(owner=self, groupKey)` after files land — no BID
  rewriting, because folders were always addressed by name. This is why
  knowledge-by-folder-name is the correct v1 scope and per-file-ID grants are
  not.

---

## 5. Federation transport — send a bundle over a link (no auto-import)

Live queries (`POST /federation/query`, `03` Order 4) answer *questions*.
Sharing moves *setups*. Same link, same signatures, different endpoint and a
mandatory human accept in the middle:

```text
POST /api/v1/federation/share        (on the receiving instance)
  auth: instance signature + active link (same as /query)
  body: { protocol: 0, from, linkId, shareId, bundle: <synaplan-bundle.v1 JSON>,
          hasArchive: true, archiveBytes: 41200900, note: "Support setup for review",
          nonce, issuedAt, sig }
→ 202 { shareId, status: "pending" }
```

Flow (two-phase, fail-closed):

1. **Offer.** Sender picks items (prompts + widgets + knowledge manifest —
   same checkboxes as Export) and a link, adds an optional note, sends. Only
   the JSON travels; the archive stays home. Sender sees "Waiting for
   telekom.de to accept — nothing left your server except the file list."
2. **Inbox.** Receiver gets an Approvals-style row (`Manage → Federation →
   Incoming shares`, badge count, U2/U6): who sent it, what is inside (counts
   per kind + archive size), the note, **Preview** (the exact
   `BundleImporter::preview` checklist: conflicts, missing models, stripped
   secrets) and **Accept / Decline**. Decline deletes the offer; nothing is
   stored except an Activity line.
3. **Accept → bytes.** On accept, the receiver pulls the archive from the
   sender (`GET /federation/share/{id}/archive`, signed, resumable, same
   500 MB cap) **or** the sender uploads it if the receiver cannot dial back
   (both directions must work: some instances are behind NAT for inbound —
   the link already requires the *receiver* to be HTTPS-reachable, so pull is
   the default, push the fallback). Hash-verified, then §4.4 pipeline.
4. **Import as drafts.** Widgets inactive, agents/prompts private to the
   accepter, folders owned by the accepter, no shares created. The accepter
   activates explicitly. Activity logs both sides (U7: who sent what to whom,
   what was skipped, where the new drafts live).

Abuse minimum (v1): per-link pending-offer cap (5) + archive-size cap +
sender rate limit; offers expire after 7 days; oversized/expired offers are
declined with one sentence, not stored. The share inbox honors the module
gate (flag off ⇒ no route, no nav, U11).

Why not auto-accept from trusted links? Because "trusted link" + auto-import
is remote code execution with extra steps (a widget config + prompt +
trigger can exfiltrate on first visitor). **Every share imports by human
accept, even between own instances.** Migration (§6) uses download/upload, not
the link, for the same reason — plus it works with zero network trust.

---

## 6. Migration — "move my setup to another Synaplan" (the client promise)

The client-facing flow. Works with **no federation link, no shared network,
no downtime on the source**. Three screens, one checklist, one report.

**J-PM-1 — Export on the old instance.**
`Settings → Export & import` (existing `ExportImportPanel`, extended):
checkboxes now include `widgets` and `knowledge (+ file archive)`. Export
produces **two downloads**: `synaplan-bundle.json` (config) and
`synaplan-files-<date>.zip` (bytes, only if knowledge selected). Copy states
exactly what is inside and what never leaves ("Secrets, shares, chat history,
and visitor data stay on this instance. You will reconnect integrations after
import.").

**J-PM-2 — Import on the new instance.**
Same panel: drop the JSON → instant checklist (conflicts, missing models,
stripped secrets, folders to create, "12 files need the archive") → attach
the zip (hash-checked against the manifest) → choose conflict strategy
(skip / overwrite / rename-copies) → Import. Progress per section, then per
file batch for indexing ("Indexing 8 of 12… you can keep working").

**J-PM-3 — Morning-after report.**
One screen that answers "did it work?": per-kind created/skipped/failed
counts, per-widget new embed snippet + "old snippet still points at the old
instance" warning, per-integration reconnect list (Slack, external API, MCP
servers, mailboxes), per-model fallback list ("3 prompts wanted
`anthropic:claude-opus-5.5`, target has no key — they use your default chat
model until you add one"). Every created item links to its settings page
(U2). Failures name the item + reason + fix, never a stack trace (U8).

**J-PM-4 — Share over a link (federation variant).**
Sender: `Manage → Federation → link row → Share setup…` → same checkboxes →
note → Send. Receiver: Incoming-shares inbox → Preview → Accept → archive
pull → drafts. Same checklist, same report, transported instead of
downloaded. (Requires `03` Orders 3–5 green first: no link, no share button —
U5/U11, never a dead control.)

---

## 7. Exit criteria (per UX-contract §6 + federation gates)

For every `ota-candidate` step: (1) named journey(s) J-PM-1..4 walked in the
browser (U10); (2) every created item findable in ten seconds (widget settings
link, prompt row, folder row — U1/U2); (3) kind-specific consequence copy in
all five locales for export / import / accept / decline / activate (U3);
(4) empty + error + flag-off states (no widgets, bad file, archive mismatch,
module off ⇒ no share UI — U5/U8/U11); (5) dark + V2 + 320px + WCAG AA (U9).

Plus portability-specific gates, asserted in tests:

- Importing a bundle twice with `skip` creates nothing the second time.
- No secret value from the source ever appears on the target (assert against
  a fixture bundle containing all known secret keys).
- No BID from the source is trusted: agent/file/user/model ids in the bundle
  are ignored or resolved via portable keys (fuzz test with colliding ids).
- No vectors cross: target file rows exist only after local re-vectorization;
  a manifest without an archive creates empty folders and says so.
- A tampered archive (one byte flipped) is rejected before any file row is
  written; a zip-slip path is rejected and logged.
- Federation shares never auto-import; expired/oversized offers are declined
  with one sentence; declining leaves zero rows behind except the Activity line.

---

## 8. Steps (build order; each ends green on `make ci-local`)

| # | Type | Deliverable | Journey |
| - | ---- | ----------- | ------- |
| **P0** | plan | This file reviewed; §2 ticked; UX copy for J-PM-1..4 drafted in EN before any Vue (U1) | — |
| **P1** | backend | `WidgetsBundleSection` (export/preview/apply per §3) + shared secret-key constant with `WidgetController`; unit + integration tests (round-trip: export → import on a second user, fresh `widgetId`, inactive, secrets gone) | — |
| **P2** | backend | `knowledge` manifest section + archive build/verify (`ZipArchive` + `manifest.json`, slip/symlink guards, 500/100 MB caps) + import pipeline feeding the existing upload/vectorize path with progress; integration test incl. tampered-byte + slip rejection | — |
| **P3** | backend | Prompt/agent folder rebinding when `knowledge` is in the same bundle (remove the `droppedFolders` dead-end for this path); round-trip test: prompt + folder + files export → import → RAG answers from the new folder | — |
| **P4** | ota-candidate | `ExportImportPanel` extended: `widgets` + `knowledge` checkboxes, two-file download/upload, conflict strategy incl. `rename`, morning-after report with embed snippets + reconnect list (J-PM-1..3, 5 locales, U1–U12) | J-PM-1..3 |
| **P5** | backend | Federation share transport: `POST /federation/share` (offer JSON), archive pull/push, 7-day expiry, per-link caps, Activity rows; integration test over two in-process instances (offer → decline leaves nothing; offer → accept → drafts) | — |
| **P6** | ota-candidate | Share UI: per-link "Share setup…" + Incoming-shares inbox with Preview/Accept/Decline + same report as P4 (J-PM-4, 5 locales, module-gated, U11) | J-PM-4 |
| **P7** | docs | `docs/PORTABLE_SETUP.md`: what moves, what never moves, archive format, checklist codes, migration runbook for operators (DNS/embed-code cutover order) | — |

P1–P3, P5 are backend-only (no Playwright required). P4, P6 need
`make test-e2e` before push. P5/P6 require `03` Orders 3–5 (link + module +
admin UI) green — no link, no share transport. P4 (download/upload migration)
is independent of federation and may ship first: **if the client migration
can't wait, build P1–P4 before touching P5.**

Instance-scope admin bulk move is **not** in P0–P7. It reuses these sections
with an admin-only scope + user-mapping UI and gets its own Ask-First + plan
after user-scope migration has moved ≥2 real clients.

---

## 9. Ask-First before building (AGENTS.md boundaries)

1. **Schema:** none expected — bundle sections need no migration (new code +
   existing tables). If P2 needs an import-jobs table for archive progress,
   that migration is a separate yes.
2. **Dependencies:** none — `ZipArchive` is bundled with PHP; large-file
   handling via existing upload limits + streaming, no new Composer package.
3. **Instance scope:** user-scope only in this plan. Admin bulk move needs its
   own approval (user mapping, cross-user data handling).
4. **Federation share transport (P5):** new peer-facing routes + archive
   transfer — same SSRF/auth bar as `03` Order 4; review the endpoint list
   before code.
5. **Limits:** 200 files / 500 MB archive / 100 MB per file as v1 caps; raising
   them later is an ops decision (worker time, disk, timeouts), not a code tweak.

## 10. Later (explicitly not v1)

- Instance-scope bulk migration (admin moves N users with a mapping table).
- Widget session/visitor history migration (GDPR-sensitive; needs per-record
  consent design — today `WidgetExportController` covers the reporting need).
- Chat history + memories migration (same consent problem, larger).
- Live sync / replicas of prompts/widgets between instances (copies are the
  v1 semantic; sync is a conflict-resolution product).
- Delta bundles (only what changed since export X) and scheduled auto-migration.
- Archive encryption at rest with a user passphrase (transport is already TLS;
  stored archives inherit server disk safety — passphrase-protected archives
  come with the first enterprise request, not before).

---

### One paragraph for the coding agent

Extend `synaplan-bundle.v1` with two sections — **`widgets`** (fresh id,
agent-by-slug, secrets stripped via the shared `WidgetController` key list,
import inactive) and **`knowledge`** (folder/file manifest + separate
hash-verified `.zip`, re-vectorized on the target through the normal upload
pipeline; vectors never move) — then expose them in `ExportImportPanel` with a
checklist-first, morning-after-report migration flow (J-PM-1..3), and add a
two-phase federation share transport (`POST /federation/share` offer-JSON →
human accept → archive pull → drafts, never auto-import, J-PM-4). Everything
imports as **private drafts owned by the importer**: no shares, no secrets, no
BIDs, no history. Keep the U1–U12 contract; backend steps finish on
`make ci-local`, UI steps add `make test-e2e`.
