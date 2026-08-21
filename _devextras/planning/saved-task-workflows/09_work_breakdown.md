# Work breakdown — PR-sized steps

**Status:** Draft 2026-08-15. This file is the anti-hand-waving layer: it takes the sprint documents and cuts them into steps a developer (or an agent) can finish, test and merge in one sitting.

The sprint files say *what* and *why*. This file says *how big*, *in what order*, and *what "done" means for each piece*.

---

## 0. Status (2026-08-16, branch `feat/saved-task-workflows` — **merged to `main` as PR #1497; WebDAV/CalDAV delivery followed as PR #1502**)

Recorded per the working agreement (§7.5). The branch was built as one feature branch rather than
one PR per step — noted here so the deviation is explicit, not repeated.

> **Next phase (2026-08-18):** the open K3c remainder, K4a/K4b, K5a and K11 rows below are re-cut as
> steps **M0–M9** in [`10_m365_actions_and_destinations.md`](./10_m365_actions_and_destinations.md)
> (branch `feat/m365-flow`), together with the new scope-tier/incremental-consent work and the DOCX
> TOC step. That file is the authoritative order for the current work; this section stays as the
> merge record.

**Done on this branch:**

| Area | Steps | Notes |
| ---- | ----- | ----- |
| Foundations | F0, F1a–F1d, F2a, F4a–F4d | Locale-parity CI; connections registry/UI incl. mailbox+MCP adapter; AES vault with rotate/forget; destination seam with email + share-link providers and shared failure vocabulary |
| Sprint 0 | E0a–E0c | Executed-plan viewer + "Save as task" affordance |
| Sprint 1 | E1–E5d | E1's lock is unit-level (`RunnersTest::testChatRunnerUsesTopicIdSystemPrompt` + fallback + pinned-model tests); characterization snapshots unchanged and green |
| Sprint 2 | E6–E9, E11 | Graph schema/validator/compiler/summary; inbound-email trigger via `app:saved-tasks:process-mailbox` |
| Sprint 3 | E13–E16, E18, E19 | Tick command self-locks via `LockFactory` (`saved-tasks-tick`, 120 s TTL); schedule picker, auto-pause + resume UI |
| Pulled forward (checklist row 10 revised) | F3/K3a (OAuth2 framework), K3b partial, K3c partial | M365 consent, token store/refresh, `GraphClient` mail read, connection UI. **Not** yet a trigger source; no calendar/write |
| Connectors (2026-08-16) | K10a, K10b, K10c, K12a + CalDAV destination | `WebDavClient` (PROPFIND/MKCOL/PUT, SSRF guard, HTTPS-only, secret-safe errors), `webdav` + `caldav` destination providers on the F4 seam, `DavConnectionTester`, Nextcloud-preset connection form (`DavConnectionForm.vue`, ×4 locales). CalDAV delivery is idempotent per S13: deterministic UID + REPORT UID query + create-only PUT. Vocabulary extended by `unsupported` (non-.ics → calendar) |

**Open (not on this branch):**

| Steps | Blocker / note |
| ----- | -------------- |
| E10 (chat trigger hook) | `chat` trigger currently equals today's topic-match behaviour; an authored graph on a chat trigger does not short-circuit the planner yet |
| E12 (advanced-steps editor UI) | Graph JSON is API-only; no editor component |
| E17 (`cron-saved-tasks.sh`) | Separate `synaplan-platform` PR — **scheduled tasks have no production trigger until this lands** |
| E20–E24 (plugin nodes, outbound webhook, events, `docs/N8N.md`) | Sprint 4 n8n interface untouched. The `webhook` trigger type is rejected by the entity until E22's ingress exists |
| F2b (port inbound-email credentials onto the vault) | Existing handlers still use their own storage |
| K10d (live Nextcloud verification), K12b/K12c (runner-integrated `calendar_query` read node + mutating write action with confirmation/`allow_unattended`) | WebDAV/CalDAV delivery works through `POST /files/{id}/send`; wiring them as run-step nodes needs the S6 mutating-action machinery. Live-instance verification per S5 still pending |
| K11 (OpenCloud), K4/K5a/K13 (Graph write, OneDrive, Dropbox), K7 | Release checkpoints 2 and 4 not reached |

---

## 1. Step-size rules

A step that violates any of these is too big — split it before starting.

| Rule | Test |
| ---- | ---- |
| **One step = one PR = one reviewable concern** | Can you write the PR title without the word "and"? |
| **Backend and frontend are separate steps** unless the diff is trivially small | Does the PR touch both `backend/src` and `frontend/src` substantially? Split |
| **A migration is its own step** | Schema changes merge before the code that depends on them |
| **A new interface merges before its first implementation** | The seam gets reviewed on its own merits, not through one use case |
| **Every step ships its own tests** and leaves the unfiltered gate green | `make lint && make -C backend phpstan && make test && …` |
| **Every step is independently revertable** | If we revert only this PR, is the product still coherent? |
| **No step depends on a connector that does not exist yet** | Check the dependency column below |
| **A step that cannot be described in three acceptance bullets is not understood yet** | Go back to the sprint doc |

**Rough size guide:** if a step looks like more than ~400 changed lines excluding tests and generated files, split it. Size labels below: **S** (a few files), **M** (one subsystem), **L** (split unless justified in the PR).

### 1.1 Definition of done (every step)

1. Unfiltered gate green — never a `--filter` run as the final check.
2. Tests for the new branches; a bug fix ships a regression test.
3. OpenAPI annotations updated if the HTTP surface changed → `make -C frontend generate-schemas` → `vue-tsc`.
4. All four locales in the same commit if user-facing strings changed.
5. The PR states which [compatibility invariants](./00_master_plan.md#70-named-compatibility-invariants--synaplan-must-stay-compatible-with-its-earlier-self) the diff can touch, with links to the green tests.
6. Characterization snapshots re-recorded **and reviewed line by line** if the sorter/planner/classifier changed.
7. Docs updated in the same PR — not "in a follow-up".

---

## 2. Steps that were too vague in the earlier plan (now cut)

Recorded so the same mistake is not reintroduced.

| Was | Problem | Now |
| --- | ------- | --- |
| "Known gap to fix inside Sprint 1: `ChatRunner` `params.topic_id` binding" | A one-line aside in the master plan, but it is the difference between a Saved Task honouring its prompt and silently ignoring it | **Step E1** — its own PR, characterization-locked, ahead of everything else |
| Sprint 2 "authored graph + triggers" | Four subsystems (storage shape, validation, compilation, editor) in one sprint | **E6–E12** |
| Sprint 3 "user-facing scheduler" | Mixes distributed locking, claim semantics, platform ops and UI | **E13–E19** |
| Sprint 4 "connectors, plugins, n8n" | Three independent epics | **K-series**, **E20–E21**, **E22–E23** |
| "Results go to Nextcloud/OpenCloud" | Assumed a write path that does not exist | **F4-series + K-series**, gated by [`07_connectors.md`](./07_connectors.md) |
| "All four locales" as a per-sprint reminder | Unenforced convention | **Step F0** — parity is a CI gate |

---

## 3. Phase F — Foundations

Start here. These unblock everything and are the cheapest place to get the architecture right. Gated by [`07_connectors.md` §7](./07_connectors.md#7-sign-off-gate-tick-before-any-connector-code) rows S1, S12.

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **F0** | **i18n locale-parity test.** First verify whether one already exists; if not, add it under `frontend/tests/unit/i18n/`. Fails on missing keys, mismatched placeholders, and untranslated-identical values in the new namespaces | FE | S | – | Deliberately deleting a `de.json` key fails `make -C frontend test`; placeholder mismatch fails; opt-out list documented |
| **F1a** | `Connection` entity + Galera-safe migration (raw `addSql`, `IF NOT EXISTS`) | BE | S | – | Table created on a fresh DB and on an existing one; no Schema API use; `make -C backend migrate` idempotent |
| **F1b** | Connection CRUD service + API with full OpenAPI annotations; secrets masked on read | BE | M | F1a, F2a | Create/list/update/delete; a test asserts the secret is never in a response body |
| **F1c** | Connections list UI + shared **status pill** and **Test connection** components (this *is* F5) | FE | M | F1b | All five states render; four locales; dark + V2 + 320px checked |
| **F1d** | **Adapter only**: surface existing mail handlers and MCP servers in the connections list without moving their data | BE+FE | M | F1c | Existing screens keep working unchanged (invariant C5); no data migration in this step |
| **F2a** | Credential vault interface + AES implementation + rotate/forget | BE | M | – | Round-trip test; a logger-spy test asserts the secret never reaches logs or exception messages |
| **F2b** | Port `InboundEmailHandler` password + SMTP credentials onto the vault behind the existing accessors | BE | M | F2a | Existing handlers keep connecting; no user-visible change; migration is reversible |
| **F4a** | `ShareableFile` DTO + `DestinationProvider` interface + registry (**no providers**) | BE | S | – | Registry resolves by id; unknown id fails with a typed exception; PHPStan level clean |
| **F4b** | `POST /api/v1/files/{id}/send` + first provider: **email** (reuses shipped `email_me`) | BE | M | F4a | Owner check enforced; unauthorized file returns 403; OpenAPI + generated schemas updated |
| **F4c** | Second provider: **share link** (reuses `/files/{id}/share`) | BE | S | F4b | Two providers prove the seam; no provider-specific branching in the endpoint |
| **F4d** | Shared **failure vocabulary** (`unauthorized`, `not_found`, `quota_exceeded`, `too_large`, `unreachable`, `conflict`) + translated messages ×4 | BE+FE | S | F4b, F0 | A new provider adds zero translation keys; every code has a test |

**Phase F exit criteria:** a file can be delivered to two destinations through one endpoint, every credential lives in the vault, the connections screen exists in four languages, and locale parity is enforced by CI.

---

## 4. Phase E — Engine (the sprint files, re-cut)

### Sprint 0 — Observe

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **E0a** | Read-only API to fetch an executed `TaskPlan` for a message (from `BMESSAGE_TASKS`) | BE | S | – | Owner-scoped; returns the stored plan; no planner changes |
| **E0b** | Executed-DAG viewer component (history/debug view) | FE | M | E0a | Renders a multi-node plan and a single-node plan; four locales; empty state |
| **E0c** | Non-functional "Save as task" affordance + empty-state copy | FE | S | E0b, F0 | Copy reviewed (L1); no backend call; hidden when the flag is off |

### Sprint 1 — Saved Task model

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **E1** | **Fix `ChatRunner` topic binding** so an intermediate `chat` node actually uses `params.topic_id`'s prompt | BE | M | – | Characterization test proves the Task Prompt's system text reaches the model; existing snapshots reviewed. **Do this before anything depends on it** |
| **E2** | `SAVEDTASKS.ENABLED` flag: `BCONFIG` seeder + resolution chain, default off | BE | S | – | Flag off ⇒ no new behaviour anywhere; per-user override honoured |
| **E3** | `saved_tasks` + `saved_task_runs` migration, including the Sprint 3 columns (`next_run_at`, `last_run_at`, `consecutive_failures`, `chat_id`) so Sprint 3 needs no second migration | BE | M | – | Galera-safe raw SQL; children deleted before parents; runs on a fresh and an existing DB |
| **E4a** | `SavedTask` entity + repository + CRUD service | BE | M | E3 | Owner scoping; validation rejects a trigger the columns cannot express |
| **E4b** | CRUD API + OpenAPI + generated schemas | BE | M | E4a | Flag-gated; 404 (not 403) for another user's task |
| **E5a** | `SavedTaskRunner`: **execution identity by owner id**, no session, no OIDC | BE | M | E1, E4a | A test runs it with an empty security context; model resolution follows the owner's chain (invariant C2) |
| **E5b** | Run now: dedicated conversation per task (`chat_id` created on first run), rate-limit accounting, failure increment | BE | M | E5a | Rate-limited run records `failed` with a readable reason; 3rd consecutive failure sets `enabled = 0` |
| **E5c** | Task card UI: on/off, Run now, last-run line, all states from [`08_ux_and_i18n.md` §3.2](./08_ux_and_i18n.md#32-the-task-card-the-whole-feature-for-most-users) | FE | M | E4b, E5b, F0 | Every state has copy in four locales; L2 five-question test passes |
| **E5d** | Runs list UI + retention notice | FE | S | E5c | Failed rows show the plain-language reason, never a code |

### Sprint 2 — Authored graph and triggers

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **E6** | Graph JSON schema + versioned document shape (no editor, no execution) | BE | S | E3 | Schema documented; forward-compatible version field; invalid shapes rejected |
| **E7** | Graph validator: allowed node kinds, no cycles, size limits, trigger agreement with the columns | BE | M | E6 | A graph whose trigger disagrees with `trigger_type` is rejected; cycle test; depth/size caps |
| **E8** | Graph → `TaskPlan` compiler (authored graph produces a fixed plan; planner not involved) | BE | M | E7, E1 | Compiled plan executes through the existing `DagExecutor`; unknown node kind fails honestly |
| **E9** | Plain-language summary generator (graph → the sentence on the card) | BE | S | E8 | Summary is generated, translated, and correct for the flagship story |
| **E10** | Chat trigger hook in front of `TaskPlanner`, **flag-gated**, with the C3 invariant guard | BE | M | E8, E2 | Characterization snapshots for plain chat, single-node and combo requests are **unchanged**; short-circuit is communicated to the user |
| **E11** | Inbound-email trigger adapter (reuses existing pickup; no new cron) | BE | M | E8, F1d | Existing `cron-gmail.sh` behaviour unchanged (invariant C4); a task can be driven by a mailbox |
| **E12** | Advanced-steps editor UI (canvas interaction, `graph` JSON storage) | FE | L → split | E7, E9 | **Split at implementation**: read-only render, then editing, then trigger sync. Leaving without changes preserves the simple card |

### Sprint 3 — Scheduler

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **E13** | Schedule model + parser (interval/daily/weekly + timezone) → `next_run_at` | BE | M | E3 | DST transitions tested for Europe/Berlin; minimum interval 15 min enforced |
| **E14** | `app:saved-tasks:tick` command with a **cross-node Redis lock** (mirrors `app:media:reap-jobs`) | BE | M | E13 | Two concurrent ticks: exactly one runs; lock released on crash (TTL); no-op while the flag is off |
| **E15** | Claim loop with database compare-and-set | BE | M | E14, E5a | Concurrency test proves a task cannot be claimed twice; a crashed run is re-claimable after its lease |
| **E16** | Failure handling: notification, `consecutive_failures`, auto-pause at 3, resume path | BE | M | E15 | Auto-paused task stops scheduling; user is notified; `Resume` clears the counter |
| **E17** | `cron-saved-tasks.sh` in `synaplan-platform` (**separate repo PR**): web1 crontab, logrotate coverage, same shape as `cron-gmail.sh` | Ops | S | E14 | Installable while the feature is off (no-op tick); does not touch existing cron scripts (invariant C4) |
| **E18** | Schedule picker UI incl. explicit timezone and plain-language sentence | FE | M | E13, E5c | No cron string in primary copy; DE length checked; four locales |
| **E19** | Auto-pause notice + resume UI | FE | S | E16, E18 | L3 failure walkthrough passes in all four locales |

### Sprint 4 — Plugins and n8n interface

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **E20** | Plugin `graphNodes` manifest key + palette union for installed plugins | BE | M | E7 | No plugin ⇒ no node; uninstalling fails dependent runs honestly rather than skipping |
| **E21** | First plugin node end-to-end (Synasort `sortx_classify` as the pilot) | BE+FE | M | E20 | Works when installed, absent when not; confirmation rules respected |
| **E22** | `outbound_webhook` action node (user URL + HMAC, SSRF-guarded) | BE | M | F4a | Signature verified by a fixture receiver; private-IP URL rejected; secret never logged |
| **E23** | Platform outbound events (`saved_task.completed`, …) | BE | M | E22 | Opt-in per user; retry/backoff documented; no event when the flag is off |
| **E24** | `docs/N8N.md` + recipes refresh | Docs | S | E22 | Copy-paste recipes verified against the shipped endpoints |

---

## 5. Phase K — Connectors

Each connector runs its readiness checklist ([`07_connectors.md` §7](./07_connectors.md#7-sign-off-gate-tick-before-any-connector-code)) **before** its first step.

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **K10a** | WebDAV client (pure class: `PROPFIND` / `MKCOL` / `PUT`), no wiring | BE | M | – | Unit-tested against a fake HTTP client incl. every error code; SSRF guard applied; secret-masking test |
| **K10b** | `WebDavDestinationProvider` + `webdav` connection type | BE | M | K10a, F4a, F1a | Maps every error onto the shared vocabulary (F4d); per-run file/byte caps enforced |
| **K10c** | Connections UI for WebDAV + **Nextcloud preset** | FE | M | K10b, F1c | Preset fills the DAV path from a base URL; test-connection round-trip; four locales |
| **K10d** | Live verification against a real Nextcloud + `docs/CONNECTIONS.md` page | Docs+QA | S | K10c | Evidence in the PR; conflict/quota/permission cases exercised manually |
| **K11a** | **OpenCloud write spike** (timeboxed): decide WebDAV+app-token vs CS3 upload vs reversed token exchange; **also verify whether OCIS offers any CalDAV target** | Spike | M | K10a | One page in [`07_connectors.md` §4.3](./07_connectors.md#43-c11--opencloud--ocis-write-spike-before-committing) recording endpoint, auth artefact and lifetime, verified against a live instance |
| **K11b** | OpenCloud destination provider per the spike result | BE | M | K11a | Plugs into F4 as one more provider; no bespoke UI |
| **K11c** | OpenCloud connection UI + docs (+ note in the `synaplan-opencloud` README) | FE+Docs | S | K11b | Sovereignty note shown (S7); four locales |
| **K12a** | CalDAV client (pure class: `REPORT` calendar-query with time-range, `PUT` VEVENT), no wiring | BE | M | K10a | Fake-client unit tests incl. every error code; reuses `CalendarEventService` for VEVENT generation; deterministic `UID` from task id + source message id |
| **K12b** | Calendar read step (`calendar_query`) + duplicate check in the runner | BE | M | K12a | Re-running the same task does **not** create a duplicate event (fixture round-trip: create → query finds it → skip); read-only, planner-visible per data-node contract |
| **K12c** | Calendar write via CalDAV as a mutating action (`.ics` stays the no-connection fallback) | BE | M | K12b, S6 | Confirmation on interactive runs; `allow_unattended` on schedules; audit record; `conflict` (existing UID) treated as success |
| **K12d** | Calendar connection UI (reuses the WebDAV connection; calendar picker) + docs | FE+Docs | S | K12c, F1c | Test-connection lists calendars; four locales; not offered on connections without CalDAV (OpenCloud caveat) |
| **K3a** | OAuth2 framework: client, PKCE, token storage in the vault, **refresh without a session** | BE | L → split | F2a | Split into: consent flow / token store / refresh-in-cron. Expired-refresh sets `reauth_required` |
| **K3b** | Microsoft Graph client + `Mail.Read` | BE | M | K3a | Fake-Graph unit tests; 429 + `Retry-After` honoured |
| **K3c** | M365 mailbox as a connection + trigger source | BE+FE | M | K3b, F1c | Live-tenant verification; consent-revoked path auto-pauses with a readable reason |
| **K4a** | Graph calendar **read** (`Calendars.Read`) + the same duplicate check as K12b | BE | M | K3b | Shares the dedup logic with K12b (one implementation, two backends) |
| **K4b** | Graph calendar **write** (mutating) | BE | M | K4a, S6 | Confirmation on interactive runs; `allow_unattended` required for scheduled; audit record per call |
| **K5a** | OneDrive / SharePoint Online document-library drop (Graph drive API) | BE+FE | M | K3b, F4a | Scope per S14: file drop only; `Sites.Selected` admin-grant path documented; live-tenant verification |
| **K13a** | Dropbox OAuth app + API client (`/files/upload`, ≤150 MB, `autorename`) | BE | M | K3a, F4a | Fake-client unit tests incl. refresh + 429 `Retry-After`; per-run byte cap below the chunked-session threshold |
| **K13b** | Dropbox connection UI + docs (US-cloud label per S7) | FE+Docs | S | K13a, F1c | Live-account verification; self-host app-registration path documented |
| **K7a** | Jira/Confluence via MCP connection (read: check tickets / read pages) | BE+FE | S | F1c | No new credential type; documented recipe |
| **K7b** | `mcp_action` mutating capability (planner-invisible) | BE | M | S6 | A test asserts the planner never emits it; confirmation enforced |

---

## 6. Merge order

**Decided 2026-08-15: foundations first** ([`07_connectors.md`](./07_connectors.md) row S1). Phase F is not run in parallel with the engine — E3 onward waits on the seams it needs. Sprint 0 (E0a–E0c) is independent and may run at any point.

```
F0 ─ E1 ─ E2 ─┬─ E3 ─ E4a ─ E4b ─ E5a ─ E5b ─ E5c ─ E5d      (usable "Run now")
              │
              ├─ F2a ─ F2b
              ├─ F1a ─ F1b ─ F1c ─ F1d
              └─ F4a ─ F4b ─ F4c ─ F4d
                              │
                              └─ K10a ─ K10b ─ K10c ─ K10d     (results land in Nextcloud)
                                              │
E6 ─ E7 ─ E8 ─ E9 ─ E10 ─ E11 ─ E12 ──────────┤                (authored graph)
                                              │
E13 ─ E14 ─ E15 ─ E16 ─ E17 ─ E18 ─ E19 ──────┤                (schedules)
                                              │
                                    K12a ─ K12b ─ K12c ─ K12d  (CalDAV calendar, no OAuth)
                                    K11a ─ K11b ─ K11c         (OpenCloud)
                                    K3a ─ K3b ─ K3c            (M365 mail — needs F3)
                                            ├─ K4a ─ K4b       (M365 calendar read+write)
                                            ├─ K5a              (OneDrive/SharePoint drop)
                                            └─ K13a ─ K13b      (Dropbox — needs F3)
                                    E20 ─ E21 / E22 ─ E23 ─ E24
```

**Release checkpoints:**

1. **After E5d** — "Run this instruction on demand, results in its own conversation." Shippable behind the flag; no new connectors.
2. **After K10d** — "…and file the result in my Nextcloud." The first genuinely new capability for users.
3. **After E19** — "…every weekday at 07:00, and tell me when it breaks." The core agent feature is complete.
4. **After K12d** — "…and put the meetings in my Nextcloud calendar, without duplicates." The sovereign story (files + calendar) is complete with **no OAuth anywhere**.
5. **The OAuth family (F3 → K3, K4, K5a, K13) is its own epic** — M365 mail/calendar, SharePoint drop and Dropbox all unlock together once F3 exists. It is the single largest remaining unknown; plan it after checkpoint 4.

---

## 7. Working agreement for implementation sessions

1. Re-read the master plan, the sprint file, this file's step row, and [`06_testing_and_documentation.md`](./06_testing_and_documentation.md) before touching code.
2. Take the **lowest unfinished step** whose dependencies are merged. Do not start a step whose connector checklist is unticked.
3. Smallest possible diff for that one step. If the diff grows past the size guide, stop and split — a split mid-step is a good outcome, not a failure.
4. Run the **unfiltered** gate before every commit.
5. Update this file's status column in the same PR.
6. **Stop after two failed approaches.** Write `WIP: blocked because …` into the sprint file and ask, rather than widening scope or adding a `setTimeout`.
