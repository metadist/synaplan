# Roadmap update 2026-09-10 — Wave 4, Intermezzo, Wave 5

**Status:** Live plan as of 2026-09-13. Replaces
[`20260903_roadmap.md`](./20260903_roadmap.md) as the overview; the 2026-09-03
text is frozen at
[`2026-archive/20260903_roadmap/20260903_roadmap.md`](./2026-archive/20260903_roadmap/20260903_roadmap.md).
Track directories, sprint files,
[`202609_ux_user_flows.md`](./202609_ux_user_flows.md) and
[`20260913-use-case-research/`](./20260913-use-case-research/README.md) stay
where they are.
**Owner:** product owner. **§1 rows 1–3 are closed.** Next coding work is
the remainder of Wave 5 (row 4): finish Tools S5, then Compute A3 + B1.

This update exists because Wave 3 closed, Wave 4 landed on `main`
([#1774](https://github.com/metadist/synaplan/pull/1774)), the two production
bugs shipped ([#1787](https://github.com/metadist/synaplan/pull/1787)), and
the 2026-09-10 research said the leaner architecture should land *before*
Wave 5 adds more optional services. Intermezzo S1–S4 are on `main`. Wave 5
product flags and the Steps editor started on `main` before that exit
([#1821](https://github.com/metadist/synaplan/pull/1821),
[#1823](https://github.com/metadist/synaplan/pull/1823),
[#1827](https://github.com/metadist/synaplan/pull/1827)); that order break is
recorded in §10 #10, not re-litigated.

The 2026-09-10 wave-state that Wave 4 wrote onto
[`20260903_roadmap.md`](./20260903_roadmap.md) (W3 closed in #1769; W4
tools/approvals S1–S4 + compute A0–A2 in progress) is superseded here: W4 is
closed in this repo. Flags as of 4.8: `TOOLS.REGISTRY_ENABLED` on as
kill-switch; `TOOLS.APPROVALS_ENABLED`, `TOOLS.CUSTOM_HTTP_ENABLED` and
`WORKFLOWS.BUILDER_ENABLED` on for new installs (#1827). Compute sidecar is
not wired into PHP.

---

## 1. Binding order of work

Do these in this order. Do not start the next row until the exit of the
current row is met.

| # | Name | What it is | Exit |
| - | ---- | ---------- | ---- |
| 1 | **Two bugfixes (first)** | E-mail formatting + chat memory. Production reports, not a new track. | **Done** on `main` via [#1787](https://github.com/metadist/synaplan/pull/1787) (2026-09-10). Spec stays in §4. |
| 2 | **Wave 4** | Tools / approvals / custom tools + the compute sidecar (A0–A2). **Done** on `main` via [#1774](https://github.com/metadist/synaplan/pull/1774) (2026-09-10). | Merged. Approvals, custom HTTP and the workflow builder later default on for new installs (#1827). No PHP compute client yet. |
| 3 | **Intermezzo** | Leaner architecture and faster execution: declared feature modules, lazy registries, dead-weight removal. Plan: [`2026-archive/20260910-feature-modules/`](./2026-archive/20260910-feature-modules/00_master_plan.md). | **Done.** S1–S4 on `main` (#1815–#1817, [#1843](https://github.com/metadist/synaplan/pull/1843), [#1856](https://github.com/metadist/synaplan/pull/1856)). S5 stays optional. |
| 4 | **Wave 5** | Former Wave 5, plus the 10 Sep partner review. Workflow builder, compute Phase B, reliability / activation, then the complete-workflow experiences. | **In progress.** Finish Tools S5 (TL41, TL45–TL47, J-TL-5), then Compute A3 + B1. Named in §7. |

**Next coding work is Wave 5 remainder**, in that order: close Tools S5,
then the first PHP compute module (A3 + B1). Do not start Compute B2 or
§7.3 packs until those exits are met. The 13 Sep use-case list
([`20260913-use-case-research/`](./20260913-use-case-research/README.md))
feeds the Wave 5 ticks; it does not replace this order.

---

## 2. What this update changes (and what it does not)

| Change | Decision |
| ------ | -------- |
| Wave 3 | Closed in this repo. Agent Builder S4–S6 on `main` via [#1769](https://github.com/metadist/synaplan/pull/1769). AI Plugs S1–S6 + URL watch on `main`. Leftover: `PL37` (`model_preferences` bundle section). More Nextcloud S2–S3 remain in partner repos. |
| Wave 4 | Unchanged in *content*: track 4 S1–S4 + track 5 A0–A2. Merged to `main` as [#1774](https://github.com/metadist/synaplan/pull/1774) (2026-09-10), before the two bugfixes. |
| **Two bugfixes** | Shipped in [#1787](https://github.com/metadist/synaplan/pull/1787). Spec retained in §4. |
| **Intermezzo** | Named release between Wave 4 and Wave 5 (the "4.5" slot). Not a new track number. S1–S4 on `main`; S5 optional. |
| Wave 5 | Same product intent as the 2026-09-03 Wave 5, plus the partner review in [`2026-archive/20260910-wave5-architecture-research/03_architecture_and_steps.txt`](./2026-archive/20260910-wave5-architecture-research/03_architecture_and_steps.txt). Product slices started on `main` before Intermezzo closed; compute Phase B has not. |
| Six tracks, principles, UX contract, vocabulary | Unchanged. Principles and the 2026-09-03 decision log live in the archive. U1–U12 in the UX contract still bind every `ota-candidate` step. The 13 Sep use-case list applies those rules to ten jobs. |
| Secure Compute vs Desktop | Research verdict ([`01_compute_vs_headless_desktop.md`](./2026-archive/20260910-wave5-architecture-research/01_compute_vs_headless_desktop.md)): Desktop is **not** the compute runtime. Wave 4 already put the runner in `sidecars/synaplan-compute`. Research §7 rows 1–4 and 6 are settled; row 5 (launch name) stays open. |

Waves remain a capacity plan, not a calendar promise. A wave ends when its
exit is met.

**Wave state (2026-09-13):**

| Wave | State | Evidence |
| ---- | ----- | -------- |
| W1 | closed | IAM S0–S2 on `main` (#1708, #1713). |
| W2 | closed | IAM S3–S5 + Agent Builder S1–S3.5 + More Nextcloud S1 (#1745). |
| W3 | closed in this repo | AI Plugs S1–S6 + URL watch + Agent Builder S4–S6 on `main` (#1769). Leftover: `PL37`. |
| W4 | closed in this repo | Tools/Approval S1–S4 + Secure Compute A0–A2 on `main` ([#1774](https://github.com/metadist/synaplan/pull/1774)). |
| Intermezzo | closed | Feature-modules S1–S4 on `main` (#1815–#1817, #1843, #1856). S5 optional. |
| W5 | in progress | Tools S5 Steps + webhook (#1821); flags on by default (#1827); admin switches (#1823). Open: TL41, TL45–TL47, J-TL-5; Compute A3 + B1–B4; remainder of §7.2–§7.3. |

---

## 3. The six tracks (unchanged)

| # | Track | Directory | Wave 4 / Intermezzo / Wave 5 |
| - | ----- | --------- | ---------------------------- |
| 1 | IAM | [`2026-archive/202609_iam/`](./2026-archive/202609_iam/00_master_plan.md) | Shipped through S5. IAM-UX Share dialog on `main` via [#1726](https://github.com/metadist/synaplan/pull/1726). |
| 2 | Agent Builder | [`2026-archive/202609_agent_builder/`](./2026-archive/202609_agent_builder/00_master_plan.md) | S1–S6 on `main`. Wave 5 may tighten publish-as-deployment (partner review). |
| 3 | AI Plugs | [`2026-archive/202609_ai_plugs/`](./2026-archive/202609_ai_plugs/00_master_plan.md) | S1–S6 on `main`. `PL37` leftover. Intermezzo reuses the plug-declaration pattern. |
| 4 | Tools, Approval & Workflows | [`2026-archive/202609_tools_approval_workflows/`](./2026-archive/202609_tools_approval_workflows/00_master_plan.md) | S1–S4 on `main` (#1774). S5 started (#1821): Steps + webhook live; TL41, TL45–TL47 and J-TL-5 still open. |
| 5 | Secure Compute | [`202609_secure_compute/`](./202609_secure_compute/00_master_plan.md) | A0–A2 on `main` (#1774, `sidecars/synaplan-compute`, no PHP client). A3 + B1–B4 = Wave 5. First PHP feature that must be born as a module. |
| 6 | More Nextcloud | [`202609_more_nextcloud/`](./202609_more_nextcloud/00_master_plan.md) | S1 on `main`. S2–S3 stay in partner repos; not a Wave 4/5 blocker. |

Release classes still follow `.github/mobile-impact-policy.json`. No track
here is `store-required`.

---

## 4. Two bugfixes — shipped

Landed on `main` in [#1787](https://github.com/metadist/synaplan/pull/1787)
(2026-09-10) together with the other-chats digest. They are **not**
Intermezzo and **not** Wave 5. The acceptance below is the regression
contract; do not reopen the feature.

### 4.1 E-mail formatting — scheduled mail arrives as Markdown

**Report:** users receive their daily / scheduled result mails as raw
Markdown, not as HTML.

**Likely seams (starting points, not a diagnosis):**

- `InternalEmailService::sendTaskResultEmail()` / `sendAiResponseEmail()`
  already run the body through Parsedown and set both `text` and `html`. If
  users still see Markdown, a caller is skipping that path or the HTML part
  is dropped in transit.
- **Fixed:** `MarkdownEmailFormatter` converts the body; SMTP still sends
  `multipart/alternative`; `GraphClient::sendMail()` now sends
  `contentType: html`. `email_me` still prefers the owner's connected
  Microsoft 365 mailbox (`EmailMeRunner` → `M365MailSender`, SMTP fallback).
- URL-watch and Saved Task notify paths must be checked against the same
  rule: every scheduled / unattended result mail is `multipart/alternative`
  with a real `text/html` part.

**Acceptance:**

1. A scheduled Saved Task (and `email_me`) delivers a mail whose HTML part
   is rendered headings / lists / links, not backtick or `**` markup.
2. The plain-text part may stay Markdown.
3. Both the internal SMTP path and the M365 Graph path produce HTML.
4. A regression test covers each sender (Parsedown HTML on SMTP; Graph
   payload `contentType: html` plus converted body on M365).
5. Existing welcome / verification / reset templates are unchanged.

**Out of scope:** redesign of mail layout, new digest product, Wave 4
approval-digest copy (already on `main` via #1774 and checked there).

### 4.2 Chat memory — the thread forgets what was said ~10 minutes ago

**Report:** the chat does not remember what the user said about ten minutes
earlier.

**What already exists:** a rolling conversation summary
(`ConversationSummaryService`, `CONVERSATION_SUMMARY.*`, defaults on). The
hot path is **read-only**: `MessageProcessor::applyRollingSummary()` injects
the stored summary and trims the verbatim window. The worker refreshes the
store *after* the turn (`RefreshConversationSummaryCommand`). A cold store
means the current turn has no summary; the next one should.

`MessageRepository::findChatHistory()` still caps at 30 messages / ~15 000
characters. Once a turn leaves that window, only the stored summary can
carry it. If the refresh never runs, fails, or the summary is not applied
on the next request, the model has nothing.

**What #1787 changed:** rolling summary is applied on stream and
non-stream; `ConversationSummaryRefreshDispatcher` queues the refresh
after persist. Characterization / routing snapshots were left untouched.
Related plan (context only):
[`2026-archive/20260707-rolling-conversation-summary/`](./2026-archive/20260707-rolling-conversation-summary/README.md).
Do not rebuild that feature.

**Still not evidenced:** the §4.2(1) ten-minute / leave-the-raw-window
walk. Unit tests cover dispatch, read, and a missing store; they do not
replace that walk. If production still drops context, start there — do
not open a new summarizer.

**Acceptance:**

1. In one chat, state a concrete fact, wait at least ten minutes, send
   several further turns (enough to leave the raw 30 / 15k window), then
   ask for the fact. The model uses it.
2. The same holds after a worker restart (the store survives; a refresh
   is re-queued if missing).
3. If the summarizer is down, the turn still answers from the raw window;
   it does not fail the request. The next successful refresh restores
   older facts.
4. Tests cover: refresh is dispatched after a persisted chat turn; the
   following turn reads that store; a missing store does not throw.
5. Characterization / routing snapshots stay untouched.

**Out of scope:** a new summarizer, changing the default window constants,
Qdrant user-memories, or Intermezzo module work.

---

## 5. Wave 4 — on `main`

**Merged:** [#1774](https://github.com/metadist/synaplan/pull/1774)
(2026-09-10), from
[`cursor/wave4-tools-approval-compute-469d`](https://github.com/metadist/synaplan/tree/cursor/wave4-tools-approval-compute-469d).
Do not re-implement Wave 4 on this planning branch. Track STATUS files on
`main` are the implementation log; this file does not duplicate them.

| Piece | State on `main` | Flags |
| ----- | --------------- | ----- |
| Tools S1 — registry | Implemented | `TOOLS.REGISTRY_ENABLED` on (kill-switch) |
| Tools S2 — interactive approval | Implemented | On for new installs since #1827 |
| Tools S3 — unattended pause / resume | Implemented | same approvals flag |
| Tools S4 — custom HTTP / OpenAPI tools | Implemented | On for new installs since #1827 |
| Compute A0–A2 | Implemented in-repo as `sidecars/synaplan-compute` (Go, Python + Node images, hostile corpus, workspaces). PHP never mounts `docker.sock`. | No `COMPUTE.ENABLED` yet — Phase B is Wave 5 |
| Tools S5 / Compute A3 + B1–B4 | S5 partial (#1821); compute not started | Wave 5 |

**Wave 4 exit (code):** met by #1774. Approvals and custom HTTP later
defaulted on for new installs (#1827); J-TL-1…5 still need to be walked
on a flag-on install. The compute sidecar is shippable as a binary /
image and is **not** wired into PHP.

---

## 6. Intermezzo — leaner architecture, faster execution

The slot between Wave 4 and Wave 5. Call it **Intermezzo** in release notes
and here; do not mint a Wave 6 and do not call it Wave 5.

It is the research answer to "load optional PHP only when configured": not
lazy-loading files (the runtime already does that), but **declared feature
modules**, **lazy registries**, **dead-weight removal**, and a CI matrix
that proves absent means absent.

| Plan | Role |
| ---- | ---- |
| [`2026-archive/20260910-wave5-architecture-research/02_conditional_module_loading.md`](./2026-archive/20260910-wave5-architecture-research/02_conditional_module_loading.md) | Measured findings (2026-09-10 `main`) |
| [`2026-archive/20260910-feature-modules/00_master_plan.md`](./2026-archive/20260910-feature-modules/00_master_plan.md) | Implementation plan, S1–S5. **§0 must be ticked before any Intermezzo code.** |
| [`2026-archive/20260910-feature-modules/STATUS.md`](./2026-archive/20260910-feature-modules/STATUS.md) | Step state |

**Why it sits here, not in Wave 5.** Wave 5 adds a PHP compute client, more
tools, and more optional services. Each of those would otherwise grow the
eager `ProviderRegistry`, the 430-line `featuresStatus()` method and the
unconditional route surface. Intermezzo puts one descriptor and a lazy
locator in place first; compute B1 is born as a module.

**Intermezzo exit:** **met.** Feature-modules S1–S4 are on `main`
(vendor slimming, registry and descriptors, lazy locators, `minimal` /
`full` CI, twelve gates on for new installs). S5 (compile-time exclusion
/ plugin extraction) stays optional and is cut first if capacity runs
out. Behaviour of configured features is unchanged. End users see fewer
cards, never more.

**Not Intermezzo:** the two bugfixes (§4), Wave 4 product flags, workflow
builder, compute Phase B, sovereignty policies, assistant packs.

---

## 7. Wave 5 — after Intermezzo

Former 2026-09-03 Wave 5, informed by the 10 Sep partner review
([`03_architecture_and_steps.txt`](./2026-archive/20260910-wave5-architecture-research/03_architecture_and_steps.txt)).
The review's "insert a reliability release between 3 and 4" is answered by
§4 (two concrete bugs first) plus this wave's reliability slice — not by
delaying Wave 4.

Allocate roughly as the review asked: **60 % reliability and activation,
30 % complete workflows, 10 % requested integrations.** Keep the form-first
assistant builder and the step-list workflow editor.

### 7.1 Product (already in the 2026-09-03 Wave 5)

| Slice | Track | Notes |
| ----- | ----- | ----- |
| Workflow builder v1 + webhook trigger | Tools S5 | [`05_sprint_5_workflow_builder_and_webhook.md`](./2026-archive/202609_tools_approval_workflows/05_sprint_5_workflow_builder_and_webhook.md). Steps + webhook on `main` (#1821). Seeder default on (#1827). Open: TL41 templates, TL45 `saved_tasks` bundle section, TL46 C7 + five-step run, TL47 docs, J-TL-5 walk. |
| Compute contract freeze | Compute A3 | Fixtures frozen at `protocol: 1`. |
| PHP client + `code_run` + policy | Compute B1–B2 | Born as a feature module. Write-class; unattended default `approve`. |
| Workspaces, egress, hardening | Compute B3–B4 | Default off. T2 (gVisor) on a separate **compute node** before Cloud enable. |

**Compute node** (vocabulary, from the Desktop/compute research): a server
that runs `synaplan-compute`. Never a Synaplan Desktop install. Desktop and
Compute stay two products with opposite trust models; borrow Desktop
patterns (no-shell guard, frozen fixtures, constructed `{program, args[],
workdir}`, audit), do not merge the binaries.

### 7.2 Reliability and activation (from the 10 Sep review)

Do these *in* Wave 5, not as a substitute for §4.

- **Recoverable jobs.** Checkpoints, safe retries, no blind replay of
  external actions, honest per-outcome copy (not "Nothing was sent or
  saved" when something was). Interrupt around each external action in
  tests. Per-run spending and tool-call limits before widening unattended
  execution.
- **Publish ≠ activated.** After publish, show what actually came up
  ("website active, Monday schedule needs attention") and a targeted
  retry. Pin the assistant version for the whole run, including an
  approval pause.
- **Approvals on resume.** Keep consent for the exact approved action;
  re-check current permissions and hard blocks immediately before
  execute. Bind approval to recipient, destination, arguments and tool
  version — a change voids the old approval.
- **Sovereignty as a job-wide setting.** "Local only" / "Approved
  providers only" covering chat, embeddings, extraction, rerank, search,
  tools and fallback. A local assistant must not fall back to the user's
  default cloud model; pause with an explanation. Show where processing
  happened. Synthetic health: upload → retrieve → answer → generate file;
  verified restore on releases.

Track STATUS already ticked the first Wave 5 PR (#1821): honest
per-outcome copy and resume-time permission re-check (approval bound to
tool + arguments) are in. Checkpoints, per-run spend limits, publish ≠
activated, sovereignty, and the §7.3 experiences stay scheduled. The
13 Sep use-case list (`Q1`–`Q11`) is the proposed remainder — tick a
row in the owning STATUS before coding it.

### 7.3 Complete workflows (30 % — proposed)

| Experience | User sees | Depends on |
| ---------- | --------- | ---------- |
| "Turn this into an assistant" | After a good chat: save instructions, knowledge, tools, a sample test; optional schedule | Agent Builder (AB14 was deferred); Saved Tasks |
| Three assistant packs | Install, run the sample, connect own sources | S6 portability; supplier comparison is the compute flagship |
| "For you" landing | "Two reports finished. One action needs approval. One connection needs reconnecting." | Persistence already on `main`; approvals from Wave 4 |
| Same job across channels | Start by mail, review on phone, approve on web, result in Nextcloud — one history | Linked identities (IAM + More Nextcloud) |

First three packs from the review: supplier comparison (Excel + evidence),
service desk (draft + ticket for approval), weekly project brief. Not
started from this file.

### 7.4 Wave 5 exit (first cut)

1. Intermezzo S1–S4 are on `main`. **Met.**
2. Workflow builder v1 can author a Saved Task a non-technical user can
   re-run tomorrow (J-TL-5 walked). **Not met** — Steps editor is on
   `main`; templates, bundle section, C7 proof and the walk are open.
3. `code_run` is a write-class tool behind approvals; compute quotas and
   audit exist; Cloud stays off until T2 on a compute node.
4. At least the resume-time permission re-check and honest run outcomes
   from §7.2 are in. **Met** in #1821. The rest of §7.2–§7.3 is
   scheduled in track STATUS, not silently dropped.

---

## 8. Principles, UX, vocabulary

The eight principles in the 2026-09-03 archive §4 still bind (ports and
adapters, sidecars, default-off, regression as a deliverable, growth
plans, explainable UX, security posture, mobile classification).

The UX contract
([`202609_ux_user_flows.md`](./202609_ux_user_flows.md), U1–U12) still
binds every `ota-candidate` step. The ten-job list
([`20260913-use-case-research/`](./20260913-use-case-research/README.md))
applies those rules; a listed screen is not a user-flow.

Cross-track vocabulary in the archive §6 still binds. **Add:**

| Term (en) | Meaning | Not to be confused with |
| --------- | ------- | ----------------------- |
| **Compute node** | A server that runs `synaplan-compute` | A Synaplan Desktop install, even headless |
| **Intermezzo** | The lean-architecture release between Wave 4 and Wave 5 | A seventh track, Wave 5, or the two bugfixes |

---

## 9. How work is executed

Same as archive §7: tick the track (or Intermezzo) checklist, technical
review, refactor-first on a seam, feature branch, Conventional Commit type
matching release impact, full gate, mobile-impact classification, five
locales, STATUS.md is the step log.

This file is the wave overview. It does not replace track STATUS.

---

## 10. Decision log — 2026-09-10

| # | Decision |
| - | -------- |
| 1 | Archive `20260903_roadmap.md` under `20260903_roadmap/` and keep a stub at the old path so existing links work. |
| 2 | **Two production bugfixes first** (§4): scheduled mail HTML, chat memory. Then Wave 4, then Intermezzo, then Wave 5. |
| 3 | Wave 4 is the tools / approvals / compute-sidecar work. It merged to `main` as [#1774](https://github.com/metadist/synaplan/pull/1774) on 2026-09-10, before the two bugfixes. |
| 4 | Insert **Intermezzo** (not "Wave 6", not Wave 5) for feature modules and faster execution. Tick [`2026-archive/20260910-feature-modules/00_master_plan.md`](./2026-archive/20260910-feature-modules/00_master_plan.md) §0 before Intermezzo code. |
| 5 | Wave 5 keeps Tools S5 + Compute A3/B1–B4 and absorbs the partner review's reliability / activation and complete-workflow ideas as §7.2–§7.3, to be ticked per track before the first Wave 5 PR. |
| 6 | Do not implement Secure Compute as headless Desktop. Add **compute node** to vocabulary. Research §7 rows 1–4 and 6 settled 2026-09-13; row 5 (launch name) stays open. |
| 7 | Git: Wave 4 is on `main` (#1774). This planning branch merges onto that. Bugfix PRs target `main` and may land before Intermezzo. |
| 8 | No Wave 5 and no Intermezzo implementation from this planning change. (Superseded by #10: product PRs landed afterwards.) |
| 9 | Conflict on `20260903_roadmap.md` vs Wave 4: keep the archive stub at that path; move the 2026-09-10 wave-state (W3 closed, W4 tools/compute) into this live plan and mark W4 closed via #1774. |
| 10 | **2026-09-13 housekeeping.** Rows 1–3 closed (#1787, #1774, #1815–#1817/#1843/#1856). Wave 5 product slices started on `main` before Intermezzo S4 (#1821, #1823, #1827) — recorded, not reversed. Next coding: Tools S5 remainder, then Compute A3 + B1. Use-case research is a companion, not a seventh track. |

---

## 11. Related documents

| Document | Use |
| -------- | --- |
| [`2026-archive/20260903_roadmap/20260903_roadmap.md`](./2026-archive/20260903_roadmap/20260903_roadmap.md) | Frozen original (tracks, inventory, 2026-09-03 decisions) |
| [`202609_ux_user_flows.md`](./202609_ux_user_flows.md) | Binding UX |
| [`20260913-use-case-research/`](./20260913-use-case-research/README.md) | Ten jobs + Perfect-UX bar; feeds Wave 5 ticks |
| [`2026-archive/20260910-wave5-architecture-research/`](./2026-archive/20260910-wave5-architecture-research/README.md) | Compute vs Desktop; conditional modules; partner review (`03_…`) |
| [`2026-archive/20260910-feature-modules/`](./2026-archive/20260910-feature-modules/00_master_plan.md) | Intermezzo plan of record (S1–S4 done) |
| [`20260914-navigation-consolidation/`](./20260914-navigation-consolidation/00_master_plan.md) | Nav duplicates, reachability, wording, Operate stacked UI (NV01–NV23); awaits D1–D7 |
| Track 4 / 5 `STATUS.md` on `main` | Tools S1–S5 (S5 open) and compute A0–A2 as implemented |
