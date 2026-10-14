# Synaplan roadmap

**Status:** Live, 2026-10-14. **This is the only roadmap file.** It is
edited in place; a change of order is a dated row in §6, not a new dated
file. Earlier roadmaps are frozen under
[`2026-archive/`](./2026-archive/readme.txt) (2026-09-03, 09-10, 09-17,
09-25).
**Owner:** product owner.
**Binding:** [`20260907_ux_user_flows.md`](./20260907_ux_user_flows.md)
(U1–U12), the AGENTS.md "Perfect UX & Stability" bar, and
[`20260913-use-case-research/README.md`](./20260913-use-case-research/README.md) §1.
**Rule for this directory:** only open plans live here; a track moves to
`2026-archive/` the day its last step ships or is superseded, with one
dated line at the top of its STATUS or README saying why.

---

## 1. Order of work

State verified against `main` on 2026-10-14. "Shipped" means merged on
`main`; "walked" means a dated browser walk entry exists in the track's
STATUS.

### Now — the cleanup window (decision 2026-10-14)

**The two cleanup tracks run first. Feature work resumes when row 2 has
met its exit.** During the window the only non-cleanup PRs are bug fixes,
security fixes and release blockers (the community-test fixes in row 4
count as bug fixes). No new feature track opens, and the paused tracks
below finish their current PR and stop. Reason: rows 1–2 move `Service/`
files and split `ChatHandler` / `StreamController` / `ConfigController`;
every feature PR that lands in parallel is a merge conflict and a
re-measurement. The window is also the prerequisite for row 8 — the
prompting flow lands on the extracted chat pipeline, not on a 4,600-line
class.

| # | Track | Plan | State | Exit |
| - | ----- | ---- | ----- | ---- |
| 1 | **Code cleanup V1** — deptrac layer gate with baseline, `Service/` root as plugin contract, 69 loose services into domain folders | [`20261014-code-cleanup-V1/`](./20261014-code-cleanup-V1/00_master_plan.md) | Plan merged; §0 decided (deptrac). C1 starts on the product owner's go. | Root files = 4, deptrac green on `main`, baseline 7 files. |
| 2 | **Code cleanup V2** — break up the god classes | [`20261014-code-cleanup-V2/`](./20261014-code-cleanup-V2/00_master_plan.md) | Queued behind row 1. Size ratchet and characterization snapshots first, then `ConfigController` → `ChatHandler` → `StreamController`. | No chat-path class over 800 lines or 10 constructor deps; `docs/ARCHITECTURE.md` + `make metrics` on `main`. **Opens the feature window.** |

### Paused — resume after the cleanup window, in this order

| # | Track | Plan | State | Next exit |
| - | ----- | ---- | ----- | --------- |
| 3 | **UX overhaul** — rail Chats · Library · Assistants · Apps · Admin, every sub page explained, guided tours | [`20261009-ux-overhaul/`](./20261009-ux-overhaul/00_master_plan.md) | Shell, navigation, Apps directory and tours on `main` ([#2352](https://github.com/metadist/synaplan/pull/2352), [#2375](https://github.com/metadist/synaplan/pull/2375), [#2431](https://github.com/metadist/synaplan/pull/2431)); per-area sprints UX05–UX14 open per the master plan. The track has no `STATUS.md` yet — add one with the next PR. | UX14 close-out: J-UX-1 walked from a fresh database. |
| 4 | **Community test round 5.2.0** — F1–F49, six fixing waves | [`20261007-community-test-findings-5.2.0/`](./20261007-community-test-findings-5.2.0/README.md) | Waves 1–2 shipped; wave 3 on `main` via [#2385](https://github.com/metadist/synaplan/pull/2385) / [#2386](https://github.com/metadist/synaplan/pull/2386); the open issues of waves 3–6 are marked in its §2 table. **Bug-fix issues may continue during the window**; the feature-sized ones (L effort) wait. | Every issue in §2 shipped or closed with a reason. |
| 5 | **Secure Compute B4 remainder** | [`20260903_secure_compute/`](./20260903_secure_compute/STATUS.md) | A0–B3 and CS27–CS31 on `main`. Open as of the last STATUS entry: CP22 egress proxy, T2 (gVisor) node, run-image publication, CS24/CS25 external docs, staging rehearsal, J-CP-1 walk. Re-verify in STATUS before the next PR. | B4 ticked in STATUS; J-CP-1 walked. |
| 6 | **Single scheduler — production rollout** | [`20261001-single-scheduler/`](./20261001-single-scheduler/00_master_plan.md) | P1, P2, P4 shipped ([#2302](https://github.com/metadist/synaplan/pull/2302), [#2306](https://github.com/metadist/synaplan/pull/2306)). **P3 open:** scheduler service on every web node and the host crons deleted — `synaplan-platform` (private). Ops work with no code in this repository, so it **may proceed during the window**. | P3 done on all three nodes; plan archived. |
| 7 | **Synaplan Federation v1.0** | [`20260929-synaplan-federation/`](./20260929-synaplan-federation/00_master_plan.md) | First slice shipped: open a server to partners ([#2265](https://github.com/metadist/synaplan/pull/2265)). Reviewed; next sprint is the opt-in knowledge link ([`01_review.md`](./20260929-synaplan-federation/01_review.md)). | Knowledge link walked between two dev instances. |

### Queued

| # | Track | Plan | State | Next exit |
| - | ----- | ---- | ----- | --------- |
| 8 | **Prompting flow — work on files the way an IDE assistant does** — a visible working set per chat, proposed changes shown as a diff with accept / reject, a docked result pane, one-click restore to a revision, a verify step, per-folder instructions | plan not yet written (`20261014-prompting-flow/` reserved); the building blocks already on `main` are listed in the §6 decision of 2026-10-14 | Requested 2026-10-14. First feature track after the cleanup window because it reshapes the chat pipeline that V2 extracts. Order relative to rows 9–13 to be confirmed by the owner. | Master plan with §0 decisions, journeys J-PF-1…n and the five exit bullets per step; then per its own step table. |
| 9 | **Cloud file sharing** — Nextcloud, ownCloud.online and OpenCloud on one level: copy Synaplan files into the cloud, one identity model (organisation login), the bird indicator, watched folders and automations that run on files picked up from the cloud (CF0–CF6, three phases) | [`20261013-cloud-file-sharing/`](./20261013-cloud-file-sharing/00_master_plan.md) | Plan merged 2026-10-14 ([#2467](https://github.com/metadist/synaplan/pull/2467)); all 46 §3 decision rows agreed; no product code yet. Absorbs Nextcloud S3 (row 13) and the folder-watch item of the Q-list. Position among rows 8–11 to be confirmed by the owner. | P1 (core + Nextcloud, CF0–CF5) walked and the core API frozen as contract v1. |
| 10 | **Wave 6 backend integrations** — one public API story, "Connect an app" catalog (BI1), developer clients + office | [`20260917-backend-integrations/`](./20260917-backend-integrations/README.md) | The 2026-09-25 row 2 (openDesk meeting notes) **shipped** — plugin in [metadist/synaScriber](https://github.com/metadist/synaScriber), transcriber sidecar and live captions here ([#2252](https://github.com/metadist/synaplan/pull/2252), [#2441](https://github.com/metadist/synaplan/pull/2441)). Catalog page and editor clients not started. | A developer points Claude Code, one VS Code-family client and Neovim at Synaplan without reading three docs; the Integrations page lists every shipped consumer. |
| 11 | **Decision routing** — decision models before the LLM sorter | [`20261003-decision-routing/`](./20261003-decision-routing/00_master_plan.md) | D0 done; D1–D2 spike + go / no-go first. | Go / no-go recorded in STATUS with the §4 metrics. |
| 12 | **UX close-out leftovers (from the 2026-09-25 plan, §3)** | [frozen 09-25 §3](./2026-archive/20260925_roadmap/20260925_roadmap.md) | Not silently dropped. Verified still open on `main`: `UX1` custom-tool share (`ShareDialog kind="tool"` is not mounted anywhere). Unverified since 09-25: the §3.1 walk list, `UX2a` activation migration, `UX3a` banned words. | Owner decides whether these ride with row 3's UX14 close-out or get a small sprint of their own; either way the result is STATUS walk entries, not a chat message. |
| 13 | **Partner repos** — Nextcloud S3 parity & fallbacks; Collabora epics 0–4 | [`20260903_more_nextcloud/`](./20260903_more_nextcloud/STATUS.md), [`20260902-collabora-integration/`](./20260902-collabora-integration/STATUS.md) | Nextcloud S1 on `main`, S2 `link` mode merged in `synaplan-nextcloud`; **S3 is absorbed by row 9** and closes with it. Collabora all epics planned. May run in parallel once the row 10 integration contract is ticked. | Per track STATUS. |

### Not started

| # | Track | Plan | State |
| - | ----- | ---- | ----- |
| 14 | **App accounts** — apps like SISmass create Synaplan accounts for their customers (AA1–AA8) | [`20261004-app-accounts/`](./20261004-app-accounts/00_master_plan.md) | Plan only. |
| 15 | **Config pyramid** | [`20260907-config-pyramid/`](./20260907-config-pyramid/STATUS.md) | §0 checklist unticked. |

Also open and tracked in [`20260913-use-case-research/README.md`](./20260913-use-case-research/README.md) §5,
not as separate tracks: Q2 calendar reminders, Q3 honest "into Outlook"
copy, Q4 publish ≠ activated, Q5 "For you" landing, Q6 "Help me write",
Q7 approval bound to tool version, Q8 sovereignty as a job-wide setting,
Q9 `PL37`, folder watch, the three assistant packs.

---

## 2. Shipped since the 2026-09-25 roadmap

Releases v5.1.3 → v5.4.0. `feat` commits on `main`, grouped; fixes are not
listed (172 of them).

| Area | What | PRs |
| ---- | ---- | --- |
| Speech | In-app dictation and voice notes with local whisper.cpp fallback; openDesk meeting notes for Jitsi and Element; live captions; Piper MP3/WAV; Muse Voice Transcribe | [#2213](https://github.com/metadist/synaplan/pull/2213), [#2252](https://github.com/metadist/synaplan/pull/2252), [#2303](https://github.com/metadist/synaplan/pull/2303), [#2396](https://github.com/metadist/synaplan/pull/2396), [#2441](https://github.com/metadist/synaplan/pull/2441), [#2470](https://github.com/metadist/synaplan/pull/2470) |
| Channels | Per-user Telegram bot with web-chat parity | [#2256](https://github.com/metadist/synaplan/pull/2256), [#2270](https://github.com/metadist/synaplan/pull/2270) |
| Chat | Streaming with the active model named; reasoning level per model; knowledge sources, task details, honest file-work follow-ups; versions, previews, archive, prompts, ask-the-user; rotating greeting | [#2236](https://github.com/metadist/synaplan/pull/2236), [#2247](https://github.com/metadist/synaplan/pull/2247), [#2380](https://github.com/metadist/synaplan/pull/2380), [#2385](https://github.com/metadist/synaplan/pull/2385), [#2391](https://github.com/metadist/synaplan/pull/2391) |
| Search | Ctrl/Cmd+K palette over pages, files, chats and settings (Smart Search S1–S6) | [#2307](https://github.com/metadist/synaplan/pull/2307) |
| UX shell | Higher-contrast themes and OLED black; UI shell rework; profile split into pages; settings merged; menu, Apps directory and guided tours | [#2310](https://github.com/metadist/synaplan/pull/2310), [#2324](https://github.com/metadist/synaplan/pull/2324), [#2352](https://github.com/metadist/synaplan/pull/2352), [#2375](https://github.com/metadist/synaplan/pull/2375), [#2386](https://github.com/metadist/synaplan/pull/2386), [#2431](https://github.com/metadist/synaplan/pull/2431) |
| Memories | List, open and delete long-term memory entries | [#2362](https://github.com/metadist/synaplan/pull/2362) |
| Models & admin | Operate grouped by topic; admin chooses guest / new-member models; model filter; new models reported within the hour; default image generation on Flare; Cerebras, Mistral Large 4, GPT-6.1 Sol, Claude Sonnet 5.5, Muse Wonder / Image | [#2257](https://github.com/metadist/synaplan/pull/2257), [#2275](https://github.com/metadist/synaplan/pull/2275), [#2277](https://github.com/metadist/synaplan/pull/2277), [#2330](https://github.com/metadist/synaplan/pull/2330), [#2367](https://github.com/metadist/synaplan/pull/2367), [#2388](https://github.com/metadist/synaplan/pull/2388), [#2389](https://github.com/metadist/synaplan/pull/2389), [#2448](https://github.com/metadist/synaplan/pull/2448) |
| Background jobs | Scheduler with parallel lanes and claimed slots; job status on the system status page | [#2302](https://github.com/metadist/synaplan/pull/2302), [#2306](https://github.com/metadist/synaplan/pull/2306) |
| Auth | OIDC sign-in restricted by organization, role and permission, with a localized denial | [#2401](https://github.com/metadist/synaplan/pull/2401), [#2402](https://github.com/metadist/synaplan/pull/2402) |
| Deploy | Published image from two files; one compose file for CLI or Docker GUI; local TLS proxy | [#2246](https://github.com/metadist/synaplan/pull/2246), [#2263](https://github.com/metadist/synaplan/pull/2263), [#2370](https://github.com/metadist/synaplan/pull/2370) |
| Federation | Open a server to partners | [#2265](https://github.com/metadist/synaplan/pull/2265) |
| Desktop | Choose the computer and skill for "Run on this computer" | [#2245](https://github.com/metadist/synaplan/pull/2245) |
| Multitask | An MCP tool error is explained instead of dropping the plan | [#2377](https://github.com/metadist/synaplan/pull/2377) |
| Mobile | Installed app and web versions shown in the native app | [#2425](https://github.com/metadist/synaplan/pull/2425) |

---

## 3. Live plans

Everything in this directory, one line each. The README of this directory
mirrors this table.

| Path | What | State 2026-10-14 |
| ---- | ---- | ---------------- |
| [`ROADMAP.md`](./ROADMAP.md) | This file | live |
| [`20260907_ux_user_flows.md`](./20260907_ux_user_flows.md) + [`20260907_ux_user_flows/`](./20260907_ux_user_flows/) | Binding UX contract (U1–U12) and journey notes | binding |
| [`20260913-use-case-research/`](./20260913-use-case-research/README.md) | Ten jobs, Perfect-UX bar, Q-list | binding; Q2–Q9 open |
| [`20261014-code-cleanup-V1/`](./20261014-code-cleanup-V1/00_master_plan.md) | Layers enforced, Service root sorted | **cleanup window (row 1)** |
| [`20261014-code-cleanup-V2/`](./20261014-code-cleanup-V2/00_master_plan.md) | God classes | **cleanup window (row 2)** |
| [`20261009-ux-overhaul/`](./20261009-ux-overhaul/00_master_plan.md) | UX overhaul UX00–UX14 | paused (row 3) |
| [`20261007-community-test-findings-5.2.0/`](./20261007-community-test-findings-5.2.0/README.md) | 5.2.0 external test triage, 50 issue drafts | paused; bug fixes continue (row 4) |
| [`20260903_secure_compute/`](./20260903_secure_compute/00_master_plan.md) | Secure Compute; B4 remainder | paused (row 5) |
| [`20261001-single-scheduler/`](./20261001-single-scheduler/00_master_plan.md) | One scheduler; P3 production rollout | ops, may proceed (row 6) |
| [`20260929-synaplan-federation/`](./20260929-synaplan-federation/00_master_plan.md) | Federation v1.0; knowledge link next | paused (row 7) |
| `20261014-prompting-flow/` (reserved, not yet written) | Prompting flow — work on files like an IDE assistant | queued (row 8) |
| [`20261013-cloud-file-sharing/`](./20261013-cloud-file-sharing/00_master_plan.md) | Cloud file sharing CF0–CF6 (Nextcloud → OpenCloud → ownCloud.online) | queued (row 9) |
| [`20260917-backend-integrations/`](./20260917-backend-integrations/README.md) | Wave 6 catalog, clients, office | queued (row 10) |
| [`20261003-decision-routing/`](./20261003-decision-routing/00_master_plan.md) | Decision models before the sorter | queued (row 11) |
| [`20260903_more_nextcloud/`](./20260903_more_nextcloud/00_master_plan.md) | Nextcloud S3 — absorbed by row 9 | queued (row 13, partner) |
| [`20260902-collabora-integration/`](./20260902-collabora-integration/00_master_plan.md) | Collabora epics 0–4 | queued (row 13, partner) |
| [`20261004-app-accounts/`](./20261004-app-accounts/00_master_plan.md) | App accounts AA1–AA8 | not started (row 14) |
| [`20260907-config-pyramid/`](./20260907-config-pyramid/00_master_plan.md) | Config pyramid | not started (row 15) |
| [`20261008-live-voice/`](./20261008-live-voice/00_master_plan.md) | Live voice conversations: speech-to-speech sidecar, delegation into chat, created media shown in the chat | in review — [#2477](https://github.com/metadist/synaplan/pull/2477), opened before the cleanup window, not on `main` yet |

---

## 4. Archived on 2026-10-14

Moved to [`2026-archive/`](./2026-archive/readme.txt) with a dated note at
the top of each. Archived means shipped or superseded, not closed for
corrections — STATUS files there still take walk entries.

| Path | Why | Evidence |
| ---- | --- | -------- |
| `20260925_roadmap.md` → `2026-archive/20260925_roadmap/` | Frozen snapshot; replaced by this file | — |
| `20260903_roadmap.md`, `20260910_roadmap_update.md`, `20260917_roadmap.md`, `20260927_roadmap.md` | Redirect stubs; the originals were already frozen in the archive; links re-pointed | — |
| `20260914-navigation-consolidation/` | NV01–NV09 shipped; NV10–NV23 replaced by the UX overhaul | [#1921](https://github.com/metadist/synaplan/pull/1921), [#1970](https://github.com/metadist/synaplan/pull/1970), [#1973](https://github.com/metadist/synaplan/pull/1973) |
| `20261001-smart-search/` | S0–S6 done | [#2307](https://github.com/metadist/synaplan/pull/2307) |
| `20261007-mcp-tool-errors/` | Merged | [#2377](https://github.com/metadist/synaplan/pull/2377) |
| `20261007_chat_ask_the_user.md` | Merged | [#2385](https://github.com/metadist/synaplan/pull/2385) |
| `20260927_openai_compatible_image_generation.md` | Image generation through OpenAI-compatible endpoints merged; the issue briefs it carried (#2202, #2204–#2206) are closed | [#2208](https://github.com/metadist/synaplan/pull/2208) |
| `20260927-early-intake/` | E1 transcription, the three easy issues and E2 Telegram shipped; J-STT walk entries were never recorded (see row 12) | [#2213](https://github.com/metadist/synaplan/pull/2213), [#2256](https://github.com/metadist/synaplan/pull/2256), [#2270](https://github.com/metadist/synaplan/pull/2270) |
| `20260829-desktop-agent-client/` | Phase A on `main`; Phase B is built in `synaplan-desktop`, which vendors the plan | `Service/Desktop/`, [#2245](https://github.com/metadist/synaplan/pull/2245) |
| `20260919-meta-muse/` | Substance delivered outside the track (Meta provider, reasoning levels, Muse models); §0 never ticked | [#2120](https://github.com/metadist/synaplan/pull/2120), [#2247](https://github.com/metadist/synaplan/pull/2247), [#2470](https://github.com/metadist/synaplan/pull/2470) |

---

## 5. Principles, vocabulary, execution

Unchanged from the frozen 2026-09-25 plan, §9–§10 of
[`2026-archive/20260925_roadmap/20260925_roadmap.md`](./2026-archive/20260925_roadmap/20260925_roadmap.md):
the eight 2026-09-03 principles, the vocabulary table (integration,
catalog, meeting notes, storage space, openDesk chat / meetings, compute
node), the banned-word list, and the execution rules — tick the track
checklist, feature branch, Conventional Commit type matching release
impact, `make ci-local && make test-e2e`, mobile-impact classification,
five locales, STATUS.md as the step log. **A walk is a STATUS entry, not a
chat message.**

This file is the overview. It does not replace track STATUS files.

---

## 6. Decision log

| Date | Decision |
| ---- | -------- |
| 2026-10-14 | **One roadmap file.** `ROADMAP.md` is edited in place; dated roadmap files and redirect stubs are retired. The 2026-09-25 text is frozen in the archive. |
| 2026-10-14 | **Only open plans in the live directory.** Nine entries archived (§4) with a dated note each; `20261001-single-scheduler/` stays live because P3 (production rollout) is open. |
| 2026-10-14 | **Code cleanup V1 and V2 added.** V1 runs now (deptrac decided), V2 queues behind it. Superseded the same day by the cleanup-first decision below, which makes them rows 1–2. |
| 2026-10-14 | `20260927-early-intake/` archived although the J-STT walks were never recorded; the leftover is row 12, not a reason to keep a shipped sprint folder open. |
| 2026-10-14 | `20260919-meta-muse/` archived as delivered elsewhere; its §0 and step table are history, not a plan. |
| 2026-10-14 | `20260829-desktop-agent-client/` frozen here; `synaplan-desktop/_extras/planning/` is the plan of record for Phase B. `docs/DESKTOP.md` points at the archive. |
| 2026-10-14 | Rows 10–13 keep the 2026-09-25 order (integration contract → clients → partner repos). The UX close-out leftovers are an explicit row (12) until an owner assigns them. |
| 2026-10-14 | **Cleanup first.** Code cleanup V1 and V2 are rows 1–2 and form a window in which no feature work lands: only bug fixes, security fixes and release blockers. Paused tracks finish their current PR and stop; scheduler P3 (ops, no code here) and the community-test bug fixes continue. The window closes when V2 meets its exit. |
| 2026-10-14 | **Cloud file sharing registered** (row 9) after [#2467](https://github.com/metadist/synaplan/pull/2467) merged during this housekeeping; it absorbs Nextcloud S3 and the Q-list folder watch. Its place among the queued rows is the owner's call. |
| 2026-10-14 | **Prompting flow requested** (row 8): work on files the way an IDE assistant does. Queued as the first feature after the window because it reshapes the chat pipeline V2 extracts. Plan folder `20261014-prompting-flow/` reserved, not yet written. Building blocks already on `main` that the plan reuses rather than rebuilds: `@`-mentions in the composer (`FileMentionPalette`), the conversation file catalog (`ConversationFileCatalog`), 30+ document tools for docx / xlsx / pptx behind `DocumentEditCoordinator` + `ChatToolLoop`, per-file revisions (`DocumentRevision`, `FileRevisionsPanel`), the task planner with ~20 runners incl. `CodeRunRunner` (compute sandbox), `AskUserRunner` and `SaveToFolderRunner`, the approval and ask-the-user cards, SSE status events, the `AgainHandler`, desktop jobs for files on the user's computer. What is missing is listed in the owner's brief of 2026-10-14 and goes into the plan's §0. |
