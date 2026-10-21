# Synaplan roadmap

**Status:** Live, 2026-10-18. **This is the only roadmap file.** It is
edited in place; a change of order is a dated row in §6, not a new dated
file. Earlier roadmaps are frozen under
[`2026-archive/`](./2026-archive/readme.txt) (2026-09-03, 09-10, 09-17,
09-25).
**Owner:** product owner.
**Binding:** [`docs/UX_CONTRACT.md`](../../docs/UX_CONTRACT.md)
(U1–U12), the AGENTS.md "Perfect UX & Stability" bar, and
[`20260913-use-case-research/README.md`](./20260913-use-case-research/README.md) §1.
**Rule for this directory:** only open plans live here; a track moves to
`2026-archive/` the day its last step ships or is superseded, with one
dated line at the top of its STATUS or README saying why.

---

## 1. Order of work

State verified against `main` on 2026-10-15. "Shipped" means merged on
`main`; "walked" means a dated browser walk entry exists in the track's
STATUS.

### Now — the feature window is open (2026-10-18)

**The cleanup window is closed.** Code cleanup V1 (C1–C9,
[#2478](https://github.com/metadist/synaplan/pull/2478)–[#2486](https://github.com/metadist/synaplan/pull/2486))
and V2 (G1–G10, [#2492](https://github.com/metadist/synaplan/pull/2492),
[#2496](https://github.com/metadist/synaplan/pull/2496)–[#2500](https://github.com/metadist/synaplan/pull/2500))
met their exits on 2026-10-14/15 and are archived (§4), together with the
UX overhaul, which shipped in [#2431](https://github.com/metadist/synaplan/pull/2431).
**Code cleanup V3** (row 17) merged on 2026-10-16 and is archived (§4).
**Code cleanup V4** (row 19) merged on 2026-10-18
([#2534](https://github.com/metadist/synaplan/pull/2534)–[#2536](https://github.com/metadist/synaplan/pull/2536),
[#2538](https://github.com/metadist/synaplan/pull/2538),
[#2539](https://github.com/metadist/synaplan/pull/2539),
[#2543](https://github.com/metadist/synaplan/pull/2543),
[#2545](https://github.com/metadist/synaplan/pull/2545)–[#2548](https://github.com/metadist/synaplan/pull/2548),
[#2550](https://github.com/metadist/synaplan/pull/2550)–[#2555](https://github.com/metadist/synaplan/pull/2555))
and is archived (§4). The **six-issue sprint** (row 20) is implemented and
archived the same day. Rows 1–3, 17, 19 and 20 therefore left the tables
below; every other row keeps its number, because other plans cite it.
Feature work resumes with row 8, the prompting flow, which lands on the
chat pipeline V2 extracted (the `ChatHandler` stages and
`ChatStreamOrchestrator`).

Still finishing from before the window:

| # | Track | Plan | State | Exit |
| - | ----- | ---- | ----- | ---- |
| 16 | **Live voice conversations** — speech-to-speech sidecar, delegation into chat, created media shown in the chat | [`20261008-live-voice/`](./20261008-live-voice/00_master_plan.md) | LV0–LV6 and LV9 on `main` ([#2477](https://github.com/metadist/synaplan/pull/2477), follow-ups [#2503](https://github.com/metadist/synaplan/pull/2503), [#2507](https://github.com/metadist/synaplan/pull/2507)); the sidecar joined the production web compose on 2026-10-15 (`synaplan-platform`). **LV7–LV8** (native capability, CarPlay live path) merged in `synaplan-apps` [#122](https://github.com/metadist/synaplan-apps/pull/122) on 2026-10-17. Not in STATUS: J-LV-1…J-LV-5 against a provider (the #2477 description walks one spoken image request), J-LV-6 / J-LV-7 on devices, a self-hosted model on a GPU. | LV7–LV8 merged in `synaplan-apps`; the provider walks entered in STATUS; plan archived. |

### Paused before the window — resume in this order

| # | Track | Plan | State | Next exit |
| - | ----- | ---- | ----- | --------- |
| 4 | **Community test round 5.2.0** — F1–F49, six fixing waves | [`20261007-community-test-findings-5.2.0/`](./20261007-community-test-findings-5.2.0/README.md) | Waves 1–3 on `main` (wave 3 via [#2385](https://github.com/metadist/synaplan/pull/2385) / [#2386](https://github.com/metadist/synaplan/pull/2386)). Open: issues 25, 27, 30–34, 37–41, 44–46, 48 and the leftovers of 26, 29 and 35 (its §0). The open drafts are not filed on GitHub yet. | Every issue in §2 shipped or closed with a reason. |
| 5 | **Secure Compute B4 remainder** | [`20260903_secure_compute/`](./20260903_secure_compute/STATUS.md) | A0–B3 and CS27–CS31 on `main`. Open as of the last STATUS entry: CP22 egress proxy, T2 (gVisor) node, run-image publication, CS24/CS25 external docs, staging rehearsal, J-CP-1 walk. Re-verify in STATUS before the next PR. | B4 ticked in STATUS; J-CP-1 walked. |
| 6 | **Single scheduler — production rollout** | [`20261001-single-scheduler/`](./20261001-single-scheduler/00_master_plan.md) | P1, P2, P4 shipped ([#2302](https://github.com/metadist/synaplan/pull/2302), [#2306](https://github.com/metadist/synaplan/pull/2306)). **P3 open:** scheduler service on every web node and the host crons deleted — `synaplan-platform` (private). Ops work with no code in this repository. Re-checked 2026-10-15: the platform's cluster doc still describes host crons. | P3 done on all three nodes; plan archived. |
| 7 | **Synaplan Federation v1.0** | [`20260929-synaplan-federation/`](./20260929-synaplan-federation/00_master_plan.md) | First slice shipped: open a server to partners ([#2265](https://github.com/metadist/synaplan/pull/2265)). Reviewed; next sprint is the opt-in knowledge link ([`01_review.md`](./20260929-synaplan-federation/01_review.md)). | Knowledge link walked between two dev instances. |

### Queued

| # | Track | Plan | State | Next exit |
| - | ----- | ---- | ----- | --------- |
| 8 | **Prompting flow — work on files the way an IDE assistant does** — a visible working set per chat, proposed changes shown as a diff with accept / reject, a docked result pane, one-click restore to a revision, a verify step, per-folder instructions | plan not yet written (`20261014-prompting-flow/` reserved); the building blocks already on `main` are listed in the §6 decision of 2026-10-14 | Requested 2026-10-14. First feature track now that the cleanup window is closed: V2 has extracted the chat pipeline it reshapes. Order relative to rows 9–13 to be confirmed by the owner. | Master plan with §0 decisions, journeys J-PF-1…n and the five exit bullets per step; then per its own step table. |
| 9 | **Cloud file sharing** — Nextcloud, ownCloud.online and OpenCloud on one level: copy Synaplan files into the cloud, one identity model (organisation login), the bird indicator, watched folders and automations that run on files picked up from the cloud (CF0–CF6, three phases) | [`20261013-cloud-file-sharing/`](./20261013-cloud-file-sharing/00_master_plan.md) | Plan merged 2026-10-14 ([#2467](https://github.com/metadist/synaplan/pull/2467)); all 46 §3 decision rows agreed; no product code yet. Absorbs Nextcloud S3 (row 13) and the folder-watch item of the Q-list. Position among rows 8–11 to be confirmed by the owner. | P1 (core + Nextcloud, CF0–CF5) walked and the core API frozen as contract v1. |
| 10 | **Wave 6 backend integrations** — one public API story, "Connect an app" catalog (BI1), developer clients + office | [`20260917-backend-integrations/`](./20260917-backend-integrations/README.md) | The 2026-09-25 row 2 (openDesk meeting notes) **shipped** — plugin in [metadist/synaScriber](https://github.com/metadist/synaScriber), transcriber sidecar and live captions here ([#2252](https://github.com/metadist/synaplan/pull/2252), [#2441](https://github.com/metadist/synaplan/pull/2441)). Catalog page and editor clients not started. | A developer points Claude Code, one VS Code-family client and Neovim at Synaplan without reading three docs; the Integrations page lists every shipped consumer. |
| 11 | **Decision routing** — decision models before the LLM sorter | [`20261003-decision-routing/`](./20261003-decision-routing/00_master_plan.md) | D0 done; D1–D2 spike + go / no-go first. | Go / no-go recorded in STATUS with the §4 metrics. |
| 12 | **UX and walk leftovers** (from the 2026-09-25 plan §3, and the UX overhaul) | [frozen 09-25 §3](./2026-archive/20260925_roadmap/20260925_roadmap.md), [archived UX overhaul](./2026-archive/20261009-ux-overhaul/00_master_plan.md) | Not silently dropped. Verified still open on `main`: `UX1` custom-tool share (`ShareDialog kind="tool"` is not mounted anywhere). Unverified since 09-25: the §3.1 walk list, `UX2a` activation migration, `UX3a` banned words. Added 2026-10-15: the UX overhaul's UX14 exit, J-UX-1 walked from a fresh database — automated as `@ci` in `ux-journeys.spec.ts`, never entered as a dated walk. | Owner decides whether these get a small sprint of their own or ride with the next UI track; either way the result is STATUS walk entries, not a chat message. |
| 13 | **Partner repos** — Nextcloud S3 parity & fallbacks; Collabora epics 0–4 | [`20260903_more_nextcloud/`](./20260903_more_nextcloud/STATUS.md), [`20260902-collabora-integration/`](./20260902-collabora-integration/STATUS.md) | Nextcloud S1 on `main`, S2 `link` mode merged in `synaplan-nextcloud`; **S3 is absorbed by row 9** and closes with it. Collabora all epics planned. May run in parallel once the row 10 integration contract is ticked. | Per track STATUS. |

### Not started

| # | Track | Plan | State |
| - | ----- | ---- | ----- |
| 14 | **App accounts** — apps like SISmass create Synaplan accounts for their customers (AA1–AA8) | [`20261004-app-accounts/`](./20261004-app-accounts/00_master_plan.md) | Plan only. |
| 15 | **Config pyramid** | [`20260907-config-pyramid/`](./20260907-config-pyramid/STATUS.md) | §0 checklist unticked. |
| 18 | **Out-of-process plugins** — tools and plugins in any language, started together with Synaplan: MCP servers administrators add for everyone (both MCP revisions, organisation network, a signed call token), then plugin folders that start with Synaplan through one `include:` line on their own network, then plugins that are a manifest plus a service, then isolated views (OP0–OP20, four phases) | [`20261016-out-of-process-plugins/`](./20261016-out-of-process-plugins/00_master_plan.md) | Plan only (2026-10-16), reviewed and updated 2026-10-18 ([`05_review.md`](./20261016-out-of-process-plugins/05_review.md)); §0 unticked. The spikes proved Synaplan's `McpClient` works unchanged with the official Python, TypeScript and Go SDKs and with a stdio tool behind a bridge; it fails on a modern-only (MCP 2026-07-28) server, which OP15 fixes ([`STATUS.md`](./20261016-out-of-process-plugins/STATUS.md)). |
| 21 | **Research quality** — agentic research with a model team, Desktop first: measure (desktop use cases, eval corpus), quick wins on today's paths (full web answers, page reading on every model, knowledge search as a tool, PDFs), a server-side research run (plan → gather → read → reflect → write → fact-check) with reading and fact-checking model slots, the Desktop research surface, then the web chat (RQ0–RW3, five phases) | [`20261017-research-quality/`](./20261017-research-quality/00_master_plan.md) | Plan only (2026-10-17); §0 unticked. Phase B (quick wins) can run beside other tracks. |
| 22 | **Architecture update** — an external architect's four directions (one AI path with hooks, providers as self-contained units, central egress, one tool path) plus the owner's runtime remarks: long waits leave PHP (a Go watchdog holds client streams, a PHP stream pump holds provider streams), every request timed from start to end, a capacity page and Kubernetes probes and autoscaling that see the real limit (AU0–AU35, seven sprints) | [`20261021-architecture-update/`](./20261021-architecture-update/00_master_plan.md) | AU0 merged ([#2623](https://github.com/metadist/synaplan/pull/2623)). Pre-flight AU0b merged ([#2627](https://github.com/metadist/synaplan/pull/2627)): §0 ticked; prompt handling is out of scope and plugin development is AU22 only. AU1 merged ([#2628](https://github.com/metadist/synaplan/pull/2628)): `make perf-runtime`. AU2 in review ([#2626](https://github.com/metadist/synaplan/pull/2626)): health probe cache and `GET /api/health/ready`. |
| 23 | **Per-user budget** — a company-login group carries a monthly usage budget (OIDC assigns membership, Synaplan assigns the amount); everyone else can have one account's budget set from People → Users and can buy more for the period with the Stripe top-up that already exists (UB1–UB5) | [`20261021-per-user-budget/`](./20261021-per-user-budget/00_master_plan.md) | Plan only (2026-10-21). Position among the other not-started rows is the owner's call. |

Also open and tracked in [`20260913-use-case-research/README.md`](./20260913-use-case-research/README.md) §5,
not as separate tracks: Q2 calendar reminders, Q3 honest "into Outlook"
copy, Q4 publish ≠ activated, Q5 "For you" landing, Q6 "Help me write",
Q7 approval bound to tool version, Q8 sovereignty as a job-wide setting,
Q9 `PL37`, folder watch, the three assistant packs.

---

## 2. Shipped since the 2026-09-25 roadmap

Releases v5.1.3 → v5.4.2. `feat` commits on `main`, grouped; fixes are not
listed. The last row is the cleanup window, which shipped as `refactor:` /
`test:` / `chore:` commits.

| Area | What | PRs |
| ---- | ---- | --- |
| Speech | In-app dictation and voice notes with local whisper.cpp fallback; openDesk meeting notes for Jitsi and Element; live captions; Piper MP3/WAV; Muse Voice Transcribe | [#2213](https://github.com/metadist/synaplan/pull/2213), [#2252](https://github.com/metadist/synaplan/pull/2252), [#2303](https://github.com/metadist/synaplan/pull/2303), [#2396](https://github.com/metadist/synaplan/pull/2396), [#2441](https://github.com/metadist/synaplan/pull/2441), [#2470](https://github.com/metadist/synaplan/pull/2470) |
| Live voice | Live conversations through a voice sidecar (GPT-Live, Gemini Live, GPT Realtime, Grok Voice, a self-hosted realtime socket); the live model is picked under AI settings and starts from an empty composer | [#2477](https://github.com/metadist/synaplan/pull/2477), [#2507](https://github.com/metadist/synaplan/pull/2507) |
| Channels | Per-user Telegram bot with web-chat parity | [#2256](https://github.com/metadist/synaplan/pull/2256), [#2270](https://github.com/metadist/synaplan/pull/2270) |
| Chat | Streaming with the active model named; reasoning level per model; knowledge sources, task details, honest file-work follow-ups; versions, previews, archive, prompts, ask-the-user; rotating greeting | [#2236](https://github.com/metadist/synaplan/pull/2236), [#2247](https://github.com/metadist/synaplan/pull/2247), [#2380](https://github.com/metadist/synaplan/pull/2380), [#2385](https://github.com/metadist/synaplan/pull/2385), [#2391](https://github.com/metadist/synaplan/pull/2391) |
| Search | Ctrl/Cmd+K palette over pages, files, chats and settings (Smart Search S1–S6); web search queries built from the thread context | [#2307](https://github.com/metadist/synaplan/pull/2307), [#2491](https://github.com/metadist/synaplan/pull/2491) |
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
| Architecture (cleanup window) | deptrac layer gate; `Service/` root sorted into domain folders; size ratchet; characterization snapshots; `ConfigController`, `ChatHandler` and `StreamController` split; `docs/ARCHITECTURE.md` and `make -C backend metrics` | [#2478](https://github.com/metadist/synaplan/pull/2478)–[#2486](https://github.com/metadist/synaplan/pull/2486), [#2492](https://github.com/metadist/synaplan/pull/2492), [#2496](https://github.com/metadist/synaplan/pull/2496)–[#2500](https://github.com/metadist/synaplan/pull/2500) |

---

## 3. Live plans

Everything in this directory, one line each. The README of this directory
mirrors this table.

| Path | What | State 2026-10-18 |
| ---- | ---- | ---------------- |
| [`ROADMAP.md`](./ROADMAP.md) | This file | live |
| [`20260913-use-case-research/`](./20260913-use-case-research/README.md) | Ten jobs, Perfect-UX bar, Q-list | binding; Q2–Q9 open |
| [`20261008-live-voice/`](./20261008-live-voice/00_master_plan.md) | Live voice conversations: speech-to-speech sidecar, delegation into chat, created media shown in the chat | finishing (row 16) — web, backend and `synaplan-apps` #122 on their mains; provider and device walks not in STATUS |
| [`20261007-community-test-findings-5.2.0/`](./20261007-community-test-findings-5.2.0/README.md) | 5.2.0 external test triage, 50 issue drafts | paused (row 4); waves 4–6 open |
| [`20260903_secure_compute/`](./20260903_secure_compute/00_master_plan.md) | Secure Compute; B4 remainder | paused (row 5) |
| [`20261001-single-scheduler/`](./20261001-single-scheduler/00_master_plan.md) | One scheduler; P3 production rollout | ops (row 6) |
| [`20260929-synaplan-federation/`](./20260929-synaplan-federation/00_master_plan.md) | Federation v1.0; knowledge link next | paused (row 7) |
| `20261014-prompting-flow/` (reserved, not yet written) | Prompting flow — work on files like an IDE assistant | queued (row 8) |
| [`20261013-cloud-file-sharing/`](./20261013-cloud-file-sharing/00_master_plan.md) | Cloud file sharing CF0–CF6 (Nextcloud → OpenCloud → ownCloud.online) | queued (row 9) |
| [`20260917-backend-integrations/`](./20260917-backend-integrations/README.md) | Wave 6 catalog, clients, office | queued (row 10) |
| [`20261003-decision-routing/`](./20261003-decision-routing/00_master_plan.md) | Decision models before the sorter | queued (row 11) |
| [`20260903_more_nextcloud/`](./20260903_more_nextcloud/00_master_plan.md) | Nextcloud S3 — absorbed by row 9 | queued (row 13, partner) |
| [`20260902-collabora-integration/`](./20260902-collabora-integration/00_master_plan.md) | Collabora epics 0–4 | queued (row 13, partner) |
| [`20261004-app-accounts/`](./20261004-app-accounts/00_master_plan.md) | App accounts AA1–AA8 | not started (row 14) |
| [`20260907-config-pyramid/`](./20260907-config-pyramid/00_master_plan.md) | Config pyramid | not started (row 15) |
| [`20261016-out-of-process-plugins/`](./20261016-out-of-process-plugins/00_master_plan.md) | Tools and plugins in any language, out of process, started together with Synaplan | not started (row 18), reviewed 2026-10-18 |
| [`20261017-research-quality/`](./20261017-research-quality/00_master_plan.md) | Agentic research with a model team: quick wins on today's paths, a server-side research run, the Desktop research surface, then the web chat | not started (row 21) |
| [`20261021-architecture-update/`](./20261021-architecture-update/00_master_plan.md) | One AI path with hooks, providers as units, central egress, one tool path, long waits out of PHP, capacity page and Kubernetes signals | AU1 merged, AU2 in review (row 22) |
| [`20261021-per-user-budget/`](./20261021-per-user-budget/00_master_plan.md) | Company-login groups carry the usage budget; other accounts use a per-account amount plus the existing Stripe period top-up | not started (row 23) |

---

## 4. Archived

Moved to [`2026-archive/`](./2026-archive/readme.txt) with a dated note at
the top of each. Archived means shipped or superseded, not closed for
corrections — STATUS files there still take walk entries.

### 2026-10-18

| Path | Why | Evidence |
| ---- | --- | -------- |
| `20261016-code-cleanup-V4/` | S0–S11 merged: account erasure, the chat-view stream handler, one buffered and streamed turn, one topic / intent rule, the widget, email and WhatsApp turns, the media turn, the classifier cascade, system settings per tab, one MIME map, the thin-controller gate, the Google and OpenAI provider splits, strict types on the message turn. Ledger 50 / 65 / 126 | [#2534](https://github.com/metadist/synaplan/pull/2534)–[#2536](https://github.com/metadist/synaplan/pull/2536), [#2538](https://github.com/metadist/synaplan/pull/2538), [#2539](https://github.com/metadist/synaplan/pull/2539), [#2543](https://github.com/metadist/synaplan/pull/2543), [#2545](https://github.com/metadist/synaplan/pull/2545)–[#2548](https://github.com/metadist/synaplan/pull/2548), [#2550](https://github.com/metadist/synaplan/pull/2550)–[#2555](https://github.com/metadist/synaplan/pull/2555) |
| `20261016_6-issues/` | The six issues are implemented. #2459 and #2461 confirmed and closed. Widget sources, visitor-chrome tests and "what was sent" merged. Two leftovers, recorded on the archive note: #2504 is still open on GitHub, and the #2460 CI-image repeat was never run | [#2525](https://github.com/metadist/synaplan/pull/2525)–[#2527](https://github.com/metadist/synaplan/pull/2527), [#2529](https://github.com/metadist/synaplan/pull/2529), [#2531](https://github.com/metadist/synaplan/pull/2531) |

### 2026-10-16

| Path | Why | Evidence |
| ---- | --- | -------- |
| `20261015-code-cleanup-V3/` | K0–K10 merged in order: `MessageProcessor` 2,109 → 303 lines, `FileAnalysisHandler` 2,046 → 161, `SeedAllCommand` and `UserDeletionService` out of the constructor ledger; ledger 57 / 69 / 147; §7 became code cleanup V4 (row 19) | [#2512](https://github.com/metadist/synaplan/pull/2512)–[#2514](https://github.com/metadist/synaplan/pull/2514) |

### 2026-10-15

| Path | Why | Evidence |
| ---- | --- | -------- |
| `20261014-code-cleanup-V1/` | C1–C9 merged: `Service/` root 73 → 4 files, deptrac green in CI, baseline 10 files / 54 edges | [#2478](https://github.com/metadist/synaplan/pull/2478)–[#2486](https://github.com/metadist/synaplan/pull/2486) |
| `20261014-code-cleanup-V2/` | G1–G10 merged: the three god classes left the size ledger; `docs/ARCHITECTURE.md` and `make -C backend metrics` on `main`; closed the cleanup window | [#2492](https://github.com/metadist/synaplan/pull/2492), [#2496](https://github.com/metadist/synaplan/pull/2496)–[#2500](https://github.com/metadist/synaplan/pull/2500) |
| `20261009-ux-overhaul/` | UX01–UX14 shipped in one pull request; J-UX-1…5 run as `@ci` journeys; the fresh-database walk was never entered (row 12) | [#2431](https://github.com/metadist/synaplan/pull/2431), [#2452](https://github.com/metadist/synaplan/pull/2452) |
| `20260907_ux_user_flows.md` + `20260907_ux_user_flows/` → `2026-archive/20260907_ux_user_flows/` | The rules (U1–U12, patterns, sprint-file contract, criteria) moved to [`docs/UX_CONTRACT.md`](../../docs/UX_CONTRACT.md) with the same section numbers; what stays in the archive is the 2026-09 verdict, the 2026-09 journeys, IAM-UX and the wireframes | IAM-UX [#1726](https://github.com/metadist/synaplan/pull/1726); AGENTS.md re-pointed |

### 2026-10-14

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
| 2026-10-15 | **Cleanup window closed.** V1 and V2 met their exits and are archived (§4); feature work resumes with row 8. Rows 1–3 leave §1; every other row keeps its number because other plans cite it. `MessageProcessor` (chat path, ~2,100 lines) stays frozen in the size ratchet as V2's next tier, split when a feature PR opens it. |
| 2026-10-15 | `20261009-ux-overhaul/` archived: UX01–UX14 shipped in #2431 without a STATUS file. The J-UX-1 fresh-database walk joins row 12, as the early-intake walks did on 2026-10-14. |
| 2026-10-15 | **Live voice stays live** as row 16: web, backend and sidecar are on `main`, but LV7–LV8 are unmerged in `synaplan-apps`. It is archived when they merge and the provider walks are in its STATUS. |
| 2026-10-15 | `20260903_more_nextcloud/` stays live although S3 is absorbed by row 9: cloud file sharing still links to it, CF0.6 corrects its STATUS, and the pairing-code fallback stays in its S3. |
| 2026-10-15 | **The UX contract leaves the planning directory.** Its plan parts were done (IAM-UX shipped in #1726; its §5 journeys belong to archived tracks, except J-CP and J-NC), but U1–U12 is a standing rule set that AGENTS.md cites. The rules move to `docs/UX_CONTRACT.md` with the same section numbers; the dated file and its wireframes go to the archive. Live plans that cite "UX contract §4.x / §6 / §8" keep meaning the same text; citations of §5 journeys and of a wireframe point into the archive. |
| 2026-10-15 | **Code cleanup V3 added as row 17**, after the window closed. An audit of `main` after V2 found the chat path continuing below `ChatStreamOrchestrator` into `MessageProcessor` (2,111 lines, 21 deps) and handlers of ~2,000 lines, with the buffered / streamed twins and copied helpers V2 removed one layer up. At the owner's request V3 splits `MessageProcessor` now instead of when a feature opens it (superseding that part of the window-closed entry above), fixes three bad ideas only (copied helpers, twin pipelines, hand-wired lists) and orders the other findings as V4 (V3 §7). Implemented the same day; in review as [#2512](https://github.com/metadist/synaplan/pull/2512) → [#2513](https://github.com/metadist/synaplan/pull/2513) → [#2514](https://github.com/metadist/synaplan/pull/2514). |
| 2026-10-16 | **Out-of-process plugins registered as row 18** (not started) from the research question "could a Python developer add a tool, written in Python, Node.js or Go?". Answer: as an MCP tool yes — the OP0 spike ran Synaplan's real `McpClient` unchanged against servers built with the official Python, Node.js and Go SDKs — but not as a plugin: the SSRF guard refuses internal addresses, MCP connections are per person, and a manifest cannot declare a service. The plan keeps PHP plugins as they are and adds an out-of-process path (MCP for tools, a manifest plus a service for plugins, isolated views); it replaces the open-plugin-platform trust model (curation only) for code in other languages. Position among rows 8–15 is the owner's call. |
| 2026-10-16 | **Code cleanup V3 archived; code cleanup V4 added as row 19.** #2512–#2514 merged in order the same morning. At the owner's request the twelve V3 §7 findings become one plan of twelve sprints (S0–S11), in V3's order, on one branch chain that merges one pull request at a time. Each sprint ends with a sprint test (proof, regression, journey) and a chore that answers the Copilot review and turns CI green. S0 (account deletion leaves 12 tables behind) is privacy work and goes first. Row 18 is out-of-process plugins ([#2518](https://github.com/metadist/synaplan/pull/2518)), so V4 is row 19. |
| 2026-10-16 | **Six-issue sprint added as row 20.** The six newest product issues get one plan (`20261016_6-issues/`, the owner's folder name). #2459 and #2461 are confirm-and-close against `main` at `9ca56ccb1`; #2460 stays open until a CI-image repeat names a cause. #2519, #2504 and #2515 are a stacked branch chain. Row 18 stays with out-of-process plugins ([#2518](https://github.com/metadist/synaplan/pull/2518)) and row 19 with code cleanup V4 ([#2521](https://github.com/metadist/synaplan/pull/2521)). Other rows were not re-verified. |
| 2026-10-17 | **Research quality registered as row 21** (not started) from the owner's question whether Synaplan Desktop can reach the research quality of an IDE agent with agentic looping, better prompting and a combination of models. Finding: yes for research — the gap is the harness, not the models: the gateway caps web research at two searches and a two-sentence answer, reads pages only on Anthropic routes, Desktop sees five knowledge passages per turn, and nothing re-plans. The plan measures first, removes those ceilings, then builds one server-side research run for Desktop and later the web chat, with cheap fast models for reading and sorting and frontier models for thinking, writing and a second-provider fact-check. Position among rows 8–20 is the owner's call; with row 8 (prompting flow) it shares the result pane and per-folder instructions instead of building them twice. Other rows were not re-verified. |
| 2026-10-18 | **Row 18 reviewed and widened** at the owner's request: start external tools together with Synaplan, in any language. The review ([`05_review.md`](./20261016-out-of-process-plugins/05_review.md), evidence in `spike-2/`) found the plan pinned MCP 2025-11-25 while 2026-07-28 is current (a modern-only server already refuses Synaplan), that the draft pseudonym is reversible by the service, that a self-hosted install cannot receive a plugin and an included compose file can rewrite Synaplan's own services, and that a hanging shared server would slow every turn. The plan now adds a dual-era client and stability (OP15, OP16), replaces the HMAC headers with a signed call token checked against published keys, and inserts a "start together" phase (OP17–OP20: a `plugins` network, one `include:` line, registration on start, a compose check). §0 unticked; its position stays the owner's call. Other rows were not re-verified. |
| 2026-10-18 | **Code cleanup V4 archived** (row 19). S0–S11 merged the same morning ([#2534](https://github.com/metadist/synaplan/pull/2534)–[#2555](https://github.com/metadist/synaplan/pull/2555), skipping the dependency pulls). `NOT_ERASED_YET` is gone. The folder moved to `2026-archive/`. |
| 2026-10-18 | **Six-issue sprint archived** (row 20). The product is on `main` ([#2525](https://github.com/metadist/synaplan/pull/2525)–[#2527](https://github.com/metadist/synaplan/pull/2527), [#2529](https://github.com/metadist/synaplan/pull/2529), [#2531](https://github.com/metadist/synaplan/pull/2531)). Same treatment as early intake: the shipped folder leaves, the leftovers stay named. [#2504](https://github.com/metadist/synaplan/issues/2504) is still open because its test pull request did not use a closing keyword. #2460 was closed by the merge of #2525 before the CI-image 20-repeat the plan required. |
| 2026-10-18 | **Live voice stays live** (row 16). LV7–LV8 merged in `synaplan-apps` [#122](https://github.com/metadist/synaplan-apps/pull/122). The 2026-10-15 exit still wants the provider walks and J-LV-6 / J-LV-7 in STATUS; those lines say the walks were not done. |
| 2026-10-21 | **Architecture update registered as row 22** (not started) from an external system architect's four directions and the owner's runtime remarks of the same day. A spike on the dev stack measured the open question: a server serves as many simultaneous AI streams as it has PHP workers, each worker also holds a database connection, and a held stream uses about 4 % CPU; separate worker pools keep the rest of the API at 19 ms p95 while streams are saturated, and both a Go relay (2,000 streams, 147 MB) and one PHP process with Symfony HttpClient (1,000 provider streams, ≈ 134 MB resident) can hold streams cheaply (numbers as re-measured after the Copilot review of [#2623](https://github.com/metadist/synaplan/pull/2623)). The plan therefore starts with S0 (two pools, a fast "busy", a capacity page, Kubernetes probes that see PHP), then one AI path, central egress, providers as units, one tool path, and finally the watchdog and stream pump. Its position among rows 8–21 is the owner's call; S0 is production relief and can start at once. Other rows were not re-verified. |
| 2026-10-21 | **Per-user budget registered as row 23** (not started). A Team account needed a standing €250 while the shared Team plan stayed €40. The product can only add a `BUSER_TOPUPS` row, and that row counts only in the period that contains its date, so the allowance was pre-dated month by month. Stripe Checkout for a €100 period top-up, the webhook credit, and the blocked-state dialog already exist; People → Users cannot set a budget, and statistics shows a percent with no way to buy more. The plan adds a nullable per-account monthly amount (null inherits the plan; purchases still add for the period) and puts **Add €100** on the statistics card. Named pay-as-you-go packages are out of scope. Other rows were not re-verified. |
| 2026-10-21 | **Row 23 extended before any code.** Company-login groups carry the usage budget. OIDC already assigns membership (`DirectoryGroupSync`, claim `groups`) and refuses hand-editing those members. The euro amount is new, on People → Policies, and only for `kind=directory`. Several groups: the higher amount wins, and "no monthly limit" wins over an amount. `RATELIMITS.TIER` stays the message and storage tables and does not set the euros. A directory member is not offered Stripe, including on an install that still sells plans to local accounts. An account exception still replaces the group for one person. |
