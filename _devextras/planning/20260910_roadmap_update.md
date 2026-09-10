# Roadmap update 2026-09-10 — Wave 4, Intermezzo, Wave 5

**Status:** Live plan as of 2026-09-10. Replaces
[`20260903_roadmap.md`](./20260903_roadmap.md) as the overview; the 2026-09-03
text is frozen at
[`20260903_roadmap/20260903_roadmap.md`](./20260903_roadmap/20260903_roadmap.md).
Track directories, sprint files and
[`202609_ux_user_flows.md`](./202609_ux_user_flows.md) stay where they are.
**Owner:** product owner. **No Wave 5 implementation starts until the order
in §1 is done through Intermezzo.**

This update exists because Wave 3 closed, Wave 4 has since landed on `main`
([#1774](https://github.com/metadist/synaplan/pull/1774)), two production bugs
must still ship before Intermezzo, and the 2026-09-10 research said the leaner
architecture should land *before* Wave 5 adds more optional services.

The 2026-09-10 wave-state that Wave 4 wrote onto
[`20260903_roadmap.md`](./20260903_roadmap.md) (W3 closed in #1769; W4
tools/approvals S1–S4 + compute A0–A2 in progress) is superseded here: W4 is
closed in this repo. Flags unchanged (`TOOLS.REGISTRY_ENABLED` on as
kill-switch, `TOOLS.APPROVALS_ENABLED` and `TOOLS.CUSTOM_HTTP_ENABLED` off;
compute sidecar not wired into PHP).

---

## 1. Binding order of work

Do these in this order. Do not start the next row until the exit of the
current row is met.

| # | Name | What it is | Exit |
| - | ---- | ---------- | ---- |
| 1 | **Two bugfixes (first)** | E-mail formatting + chat memory. Production reports, not a new track. | A scheduled result mail renders as HTML. A chat still knows what was said about ten minutes ago. Both on `main`, covered by tests. |
| 2 | **Wave 4** | Tools / approvals / custom tools + the compute sidecar (A0–A2). **Done** on `main` via [#1774](https://github.com/metadist/synaplan/pull/1774) (2026-09-10). | Merged. Flags as shipped (`TOOLS.REGISTRY_ENABLED` on as kill-switch, approvals and custom HTTP off; no PHP compute client yet). |
| 3 | **Intermezzo** | Leaner architecture and faster execution: declared feature modules, lazy registries, dead-weight removal. Plan: [`20260910-feature-modules/`](./20260910-feature-modules/00_master_plan.md). | S1–S4 of that plan done (S5 stays optional). A `minimal` CI variant proves absent modules stay absent. |
| 4 | **Wave 5** | Former Wave 5, plus the 10 Sep partner review. Workflow builder, compute Phase B, reliability / activation, then the complete-workflow experiences. | Named in §7. Not started from this file. |

**The two bugfixes are still the next coding work.** Wave 4 merged to `main`
first ([#1774](https://github.com/metadist/synaplan/pull/1774), 2026-09-10),
so they no longer gate a Wave 4 merge. They still go before Intermezzo and
Wave 5. They do not wait for Intermezzo ticks or Wave 5 design.

Git merge order: Wave 4 is on `main`; this planning branch merges onto that.
Bugfix PRs still target `main` and can land at any time before Intermezzo.

---

## 2. What this update changes (and what it does not)

| Change | Decision |
| ------ | -------- |
| Wave 3 | Closed in this repo. Agent Builder S4–S6 on `main` via [#1769](https://github.com/metadist/synaplan/pull/1769). AI Plugs S1–S6 + URL watch on `main`. Leftover: `PL37` (`model_preferences` bundle section). More Nextcloud S2–S3 remain in partner repos. |
| Wave 4 | Unchanged in *content*: track 4 S1–S4 + track 5 A0–A2. Merged to `main` as [#1774](https://github.com/metadist/synaplan/pull/1774) (2026-09-10), before the two bugfixes. |
| **Two bugfixes** | New. First work. Specified in §4. |
| **Intermezzo** | New named release between Wave 4 and Wave 5 (the "4.5" slot). Not a new track number. Carries the lean-architecture findings so Wave 5 does not add more eager providers and hand-written feature-status blocks. |
| Wave 5 | Same product intent as the 2026-09-03 Wave 5, plus the partner review in [`20260910-wave5-architecture-research/03_architecture_and_steps.txt`](./20260910-wave5-architecture-research/03_architecture_and_steps.txt). Starts only after Intermezzo. |
| Six tracks, principles, UX contract, vocabulary | Unchanged. Principles and the 2026-09-03 decision log live in the archive. U1–U12 in the UX contract still bind every `ota-candidate` step. |
| Secure Compute vs Desktop | Research verdict ([`01_compute_vs_headless_desktop.md`](./20260910-wave5-architecture-research/01_compute_vs_headless_desktop.md)): Desktop is **not** the compute runtime. Wave 4 already put the runner in `sidecars/synaplan-compute`. Product-owner ticks on that file's §7 are still open; they do not block Wave 4. |

Waves remain a capacity plan, not a calendar promise. A wave ends when its
exit is met.

**Wave state (2026-09-10, after #1774):**

| Wave | State | Evidence |
| ---- | ----- | -------- |
| W1 | closed | IAM S0–S2 on `main` (#1708, #1713). |
| W2 | closed | IAM S3–S5 + Agent Builder S1–S3.5 + More Nextcloud S1 (#1745). Flags stay off. |
| W3 | closed in this repo | AI Plugs S1–S6 + URL watch + Agent Builder S4–S6 on `main` (#1769). Leftover: `PL37`. |
| W4 | closed in this repo | Tools/Approval S1–S4 + Secure Compute A0–A2 on `main` ([#1774](https://github.com/metadist/synaplan/pull/1774)). Flags: `TOOLS.REGISTRY_ENABLED` on (kill switch), `TOOLS.APPROVALS_ENABLED` and `TOOLS.CUSTOM_HTTP_ENABLED` off. Compute sidecar is not wired into PHP (Phase B). |
| W5 | not started | After Intermezzo. |

---

## 3. The six tracks (unchanged)

| # | Track | Directory | Wave 4 / Intermezzo / Wave 5 |
| - | ----- | --------- | ---------------------------- |
| 1 | IAM | [`202609_iam/`](./202609_iam/00_master_plan.md) | Shipped through S5. IAM-UX follow-up still open; not in this update. |
| 2 | Agent Builder | [`202609_agent_builder/`](./202609_agent_builder/00_master_plan.md) | S1–S6 on `main`. Wave 5 may tighten publish-as-deployment (partner review). |
| 3 | AI Plugs | [`202609_ai_plugs/`](./202609_ai_plugs/00_master_plan.md) | S1–S6 on `main`. `PL37` leftover. Intermezzo reuses the plug-declaration pattern. |
| 4 | Tools, Approval & Workflows | [`202609_tools_approval_workflows/`](./202609_tools_approval_workflows/00_master_plan.md) | S1–S4 on `main` (#1774). S5 (workflow builder + webhook) = Wave 5. |
| 5 | Secure Compute | [`202609_secure_compute/`](./202609_secure_compute/00_master_plan.md) | A0–A2 on `main` (#1774, `sidecars/synaplan-compute`, no PHP client). A3 + B1–B4 = Wave 5. First PHP feature that must be born as a module (after Intermezzo). |
| 6 | More Nextcloud | [`202609_more_nextcloud/`](./202609_more_nextcloud/00_master_plan.md) | S1 on `main`. S2–S3 stay in partner repos; not a Wave 4/5 blocker. |

Release classes still follow `.github/mobile-impact-policy.json`. No track
here is `store-required`.

---

## 4. Two bugfixes — first

These are the first coding work. Separate, reviewable PRs. Conventional
commits `fix:`. Full pre-commit gate. They are **not** Intermezzo and **not**
Wave 5.

### 4.1 E-mail formatting — scheduled mail arrives as Markdown

**Report:** users receive their daily / scheduled result mails as raw
Markdown, not as HTML.

**Likely seams (starting points, not a diagnosis):**

- `InternalEmailService::sendTaskResultEmail()` / `sendAiResponseEmail()`
  already run the body through Parsedown and set both `text` and `html`. If
  users still see Markdown, a caller is skipping that path or the HTML part
  is dropped in transit.
- `GraphClient::sendMail()` sends Graph `body.contentType = text` and
  `M365MailSender` forwards the Markdown unchanged. `email_me` prefers the
  owner's connected Microsoft 365 mailbox
  (`EmailMeRunner` → `M365MailSender`, SMTP only as fallback). A user with
  M365 connected would get exactly the reported mail.
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

**Likely seams (starting points, not a diagnosis):**

- Is `RefreshConversationSummaryCommand` actually consumed in production
  (worker up, messenger routing, no silent discard)?
- Does a ~10 minute gap with a few long answers already exceed the 30 / 15k
  window *before* a summary exists?
- Is the summary applied on every chat-intent path the user hits (stream,
  non-stream, widget, email-as-chat), or only on `processStream()`?
- Cache / store key: a refresh that never sees the new turns, or a read
  that ignores a valid store.
- Related plan (context only):
  [`2026-archive/20260707-rolling-conversation-summary/`](./2026-archive/20260707-rolling-conversation-summary/README.md).
  Do not rebuild that feature; find why the shipped one drops context.

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
| Tools S2 — interactive approval | Implemented | `TOOLS.APPROVALS_ENABLED` off |
| Tools S3 — unattended pause / resume | Implemented | same approvals flag |
| Tools S4 — custom HTTP / OpenAPI tools | Implemented | `TOOLS.CUSTOM_HTTP_ENABLED` off |
| Compute A0–A2 | Implemented in-repo as `sidecars/synaplan-compute` (Go, Python + Node images, hostile corpus, workspaces). PHP never mounts `docker.sock`. | No `COMPUTE.ENABLED` yet — Phase B is Wave 5 |
| Tools S5 / Compute A3 + B1–B4 | Not shipped | Wave 5 |

**Wave 4 exit (code):** met by #1774. Approvals and custom HTTP remain
default-off until their journeys (J-TL-1…5) are walked on a flag-on install.
The compute sidecar is shippable as a binary / image and is **not** wired
into PHP.

**Still open after Wave 4:** the two bugfixes in §4. They were meant to
precede this merge; they remain the next coding work and still precede
Intermezzo.

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
| [`20260910-wave5-architecture-research/02_conditional_module_loading.md`](./20260910-wave5-architecture-research/02_conditional_module_loading.md) | Measured findings (2026-09-10 `main`) |
| [`20260910-feature-modules/00_master_plan.md`](./20260910-feature-modules/00_master_plan.md) | Implementation plan, S1–S5. **§0 must be ticked before any Intermezzo code.** |
| [`20260910-feature-modules/STATUS.md`](./20260910-feature-modules/STATUS.md) | Step state |

**Why it sits here, not in Wave 5.** Wave 5 adds a PHP compute client, more
tools, and more optional services. Each of those would otherwise grow the
eager `ProviderRegistry`, the 430-line `featuresStatus()` method and the
unconditional route surface. Intermezzo puts one descriptor and a lazy
locator in place first; compute B1 is born as a module.

**Intermezzo exit:** feature-modules S1–S4 done (vendor slimming, registry
and descriptors, gates default-off, lazy locators, `minimal` / `full` CI).
S5 (compile-time exclusion / plugin extraction) stays optional and is cut
first if capacity runs out. Behaviour of configured features is unchanged.
End users see fewer cards, never more.

**Not Intermezzo:** the two bugfixes (§4), Wave 4 product flags, workflow
builder, compute Phase B, sovereignty policies, assistant packs.

---

## 7. Wave 5 — after Intermezzo

Former 2026-09-03 Wave 5, informed by the 10 Sep partner review
([`03_architecture_and_steps.txt`](./20260910-wave5-architecture-research/03_architecture_and_steps.txt)).
The review's "insert a reliability release between 3 and 4" is answered by
§4 (two concrete bugs first) plus this wave's reliability slice — not by
delaying Wave 4.

Allocate roughly as the review asked: **60 % reliability and activation,
30 % complete workflows, 10 % requested integrations.** Keep the form-first
assistant builder and the step-list workflow editor.

### 7.1 Product (already in the 2026-09-03 Wave 5)

| Slice | Track | Notes |
| ----- | ----- | ----- |
| Workflow builder v1 + webhook trigger | Tools S5 | [`05_sprint_5_workflow_builder_and_webhook.md`](./202609_tools_approval_workflows/05_sprint_5_workflow_builder_and_webhook.md). Flag `WORKFLOWS.BUILDER_ENABLED` off. |
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

Tick these as Wave 5 decision rows in the owning track `STATUS.md` before
the first Wave 5 PR. Until ticked they are proposals, not scope.

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

1. Intermezzo S1–S4 are on `main`.
2. Workflow builder v1 can author a Saved Task a non-technical user can
   re-run tomorrow (J-TL-5 walked).
3. `code_run` is a write-class tool behind approvals; compute quotas and
   audit exist; Cloud stays off until T2 on a compute node.
4. At least the resume-time permission re-check and honest run outcomes
   from §7.2 are in. The rest of §7.2–§7.3 is scheduled in track STATUS,
   not silently dropped.

---

## 8. Principles, UX, vocabulary

The eight principles in the 2026-09-03 archive §4 still bind (ports and
adapters, sidecars, default-off, regression as a deliverable, growth
plans, explainable UX, security posture, mobile classification).

The UX contract
([`202609_ux_user_flows.md`](./202609_ux_user_flows.md), U1–U12) still
binds every `ota-candidate` step. A listed screen is not a user-flow.

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
| 4 | Insert **Intermezzo** (not "Wave 6", not Wave 5) for feature modules and faster execution. Tick [`20260910-feature-modules/00_master_plan.md`](./20260910-feature-modules/00_master_plan.md) §0 before Intermezzo code. |
| 5 | Wave 5 keeps Tools S5 + Compute A3/B1–B4 and absorbs the partner review's reliability / activation and complete-workflow ideas as §7.2–§7.3, to be ticked per track before the first Wave 5 PR. |
| 6 | Do not implement Secure Compute as headless Desktop. Add **compute node** to vocabulary. Open ticks remain on the research §7. |
| 7 | Git: Wave 4 is on `main` (#1774). This planning branch merges onto that. Bugfix PRs target `main` and may land before Intermezzo. |
| 8 | No Wave 5 and no Intermezzo implementation from this planning change. |
| 9 | Conflict on `20260903_roadmap.md` vs Wave 4: keep the archive stub at that path; move the 2026-09-10 wave-state (W3 closed, W4 tools/compute) into this live plan and mark W4 closed via #1774. |

---

## 11. Related documents

| Document | Use |
| -------- | --- |
| [`20260903_roadmap/20260903_roadmap.md`](./20260903_roadmap/20260903_roadmap.md) | Frozen original (tracks, inventory, 2026-09-03 decisions) |
| [`202609_ux_user_flows.md`](./202609_ux_user_flows.md) | Binding UX |
| [`20260910-wave5-architecture-research/`](./20260910-wave5-architecture-research/README.md) | Compute vs Desktop; conditional modules; partner review (`03_…`) |
| [`20260910-feature-modules/`](./20260910-feature-modules/00_master_plan.md) | Intermezzo plan of record |
| Track 4 / 5 `STATUS.md` on `main` | Tools S1–S4 and compute A0–A2 as implemented (#1774) |
