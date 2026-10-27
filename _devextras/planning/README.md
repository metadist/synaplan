# Planning

Live plans only. Shipped or superseded tracks move to
[`2026-archive/`](./2026-archive/readme.txt) the day their last step
lands, with a dated note at the top of their STATUS or README.

**Start with [`ROADMAP.md`](./ROADMAP.md).** It is the one roadmap: order
of work, what shipped, an index of every folder here, and the decision
log. It is edited in place — no new dated roadmap files, no redirect stubs.

## Conventions

- Folder and file names are `YYYYMMDD-topic` (a day, not only a month).
- A track is a folder with `00_master_plan.md` (decisions, journeys, step
  table, gates), optional sprint files, and `STATUS.md` as the step log.
  A walk is a STATUS entry, not a chat message.
- A step file that touches a user-visible surface names its journey and
  the five exit bullets from the UX contract (`docs/UX_CONTRACT.md` §6)
  before implementation.
- Release class per step: `no-app-impact`, `backend-only`,
  `ota-candidate`, `store-required` (`.github/mobile-impact-policy.json`).
- Implementation of a plan follows [`AGENTS.md`](./AGENTS.md): use the
  local stack and its provider keys to walk a journey, and take one
  branch, commit, push and pull request per topic, green before the next.
  A finished plan moves into `YYYY-archive/`, and that pass also moves
  older plans that are already implemented.

## Binding

| Path | Role |
| ---- | ---- |
| [`docs/UX_CONTRACT.md`](../../docs/UX_CONTRACT.md) | UX contract U1–U12, shared patterns, sprint-file contract; referenced by AGENTS.md. Moved out of this directory on 2026-10-15; the 2026-09 journeys and wireframes are in [`2026-archive/20260907_ux_user_flows/`](./2026-archive/20260907_ux_user_flows/README.md) |
| [`20260913-use-case-research/`](./20260913-use-case-research/README.md) | Ten jobs, Perfect-UX bar, Q-list; referenced by AGENTS.md |

## Live tracks

Same order as `ROADMAP.md` §1. **The cleanup window closed on
2026-10-15:** code cleanup V1 and V2 met their exits and moved to the
archive with the UX overhaul. Code cleanup V3 merged on 2026-10-16 and is
archived. Code cleanup V4 (its §7) and the six-issue sprint merged and
moved to the archive on 2026-10-18. Feature tracks resume with the
prompting flow.

| Path | What | State 2026-10-18 |
| ---- | ---- | ---------------- |
| [`20261008-live-voice/`](./20261008-live-voice/00_master_plan.md) | Live voice conversations: speech-to-speech sidecar, delegation into chat, created media shown in the chat, model and voice under AI settings | finishing — web, backend and sidecar on `main` ([#2477](https://github.com/metadist/synaplan/pull/2477), [#2507](https://github.com/metadist/synaplan/pull/2507)); LV7–LV8 merged in `synaplan-apps` [#122](https://github.com/metadist/synaplan-apps/pull/122); provider and device walks not in STATUS |
| [`20261007-community-test-findings-5.2.0/`](./20261007-community-test-findings-5.2.0/README.md) | Triage of the external 5.2.0 test round (F1–F49), six fixing waves, 50 issue drafts in `issues/` | paused — waves 1–3 shipped; waves 4–6 open, drafts not filed on GitHub |
| [`20260903_secure_compute/`](./20260903_secure_compute/00_master_plan.md) | Secure Compute; B4 remainder (egress, gVisor node, run images, docs, staging) | paused |
| [`20261001-single-scheduler/`](./20261001-single-scheduler/00_master_plan.md) | One scheduler; P1/P2/P4 shipped, P3 production rollout open (`synaplan-platform`) | ops |
| [`20260929-synaplan-federation/`](./20260929-synaplan-federation/00_master_plan.md) | Federation v1.0; first slice shipped, opt-in knowledge link next ([`01_review.md`](./20260929-synaplan-federation/01_review.md)) | paused |
| `20261014-prompting-flow/` (reserved) | Prompting flow — work on files the way an IDE assistant does: working set, proposed changes as a diff with accept / reject, docked result pane, restore, verify step | queued — plan not yet written |
| [`20261013-cloud-file-sharing/`](./20261013-cloud-file-sharing/00_master_plan.md) | Nextcloud, ownCloud.online and OpenCloud on one level: copy Synaplan files into the cloud, one identity model (organisation login), the bird indicator, watched folders and automations that run on files picked up from the cloud. Nextcloud first (P1, core API frozen as contract v1), then OpenCloud (P2), then ownCloud.online (P3); all 46 §3 decision rows agreed. Absorbs More-Nextcloud S3 and the folder watch | queued — plan merged 2026-10-14, no code yet |
| [`20260917-backend-integrations/`](./20260917-backend-integrations/README.md) | Wave 6: Integrations catalog, Connect UI, editor / office clients. openDesk meeting notes shipped via [metadist/synaScriber](https://github.com/metadist/synaScriber) | queued |
| [`20261003-decision-routing/`](./20261003-decision-routing/00_master_plan.md) | Decision models before the LLM sorter; D1–D2 spike + go / no-go first | queued |
| [`20260902-collabora-integration/`](./20260902-collabora-integration/00_master_plan.md) | Collabora epics 0–4 | queued — partner |
| [`20261004-app-accounts/`](./20261004-app-accounts/00_master_plan.md) | App accounts: apps like SISmass create Synaplan accounts for their customers (AA1–AA8) | not started |
| [`20260907-config-pyramid/`](./20260907-config-pyramid/00_master_plan.md) | Config pyramid; §0 unticked | not started |
| [`20261016-out-of-process-plugins/`](./20261016-out-of-process-plugins/00_master_plan.md) | Tools and plugins in Python, Node.js and Go, out of process: MCP servers administrators add for everyone (organisation network, signed identity), then plugins that are a manifest plus a service, then isolated views. Research with a tested Python / Node.js / Go spike | not started (row 18) — plan 2026-10-16 |
| [`20261017-research-quality/`](./20261017-research-quality/00_master_plan.md) | Agentic research with a model team, Desktop first: measure, quick wins on today's paths (full web answers, page reading on every model, knowledge search as a tool), a server-side research run with reading and fact-checking model slots, the Desktop research surface, then the web chat | not started (row 21) — plan 2026-10-17 |
| [`20261021-architecture-update/`](./20261021-architecture-update/00_master_plan.md) | Architecture update: one AI path with hooks, providers as self-contained units, central egress, one tool path, long waits out of PHP (Go watchdog + PHP stream pump), request timeline, capacity page, Kubernetes probes and autoscaling. Measured runtime spike in `spike/` | AU3 merged ([#2687](https://github.com/metadist/synaplan/pull/2687)); AU4 in progress (row 22) |
| [`20261021-per-user-budget/`](./20261021-per-user-budget/00_master_plan.md) | Per-account monthly budget, a directory-group budget assigned through the company login, and the existing Stripe €100 top-up for everyone else | not started (row 23) — plan 2026-10-21 |
| [`20261022-plugin-platform-quality/`](./20261022-plugin-platform-quality/00_master_plan.md) | Plugin platform quality: static checks over `plugins/` (#2636), the transcriber image published like the app image (#2638), locale files and `t()` for plugin frontends (#2635), bundled plugins tested and documented like the release (PQ0–PQ4); #2619 and #2606 as quick bugs (PQ5, PQ6) | not started (row 24) — plan 2026-10-22 |
| [`20261023-ux-polish-and-naming/`](./20261023-ux-polish-and-naming/00_master_plan.md) | UX polish from a measured visual check (stuck tour, chips below AA, watermark on OLED, a heading scale, 10 px text, internal keys on screen, casing) and the naming fix: the built object is called agent everywhere, collisions fixed first (UP0–UP8); §0 ticked 2026-10-23 — the word is "agent", which also applies to row 25 | not started (row 26) — plan 2026-10-23, UP1 and UP6 next |
| [`20261023-agent-handling/`](./20261023-agent-handling/00_master_plan.md) | Agent handling, from research on Hermes Agent and omp: those clients use Synaplan today (docs + conformance); stop a running Saved Task, Telegram assistants, an assistant from one sentence; then assistant runs on the one agent loop and the shared run kernel, a run card and the Runs page, notes and Agent Skills, proposed improvements, helpers and a double-check (AH0–AH23) | not started (row 25) — plan 2026-10-23, §0 ticked, not ordered |
| [`20261024-open-issues/`](./20261024-open-issues/00_master_plan.md) | Open issues from #2617: the import button, deleting a default model, the ownCloud copy label, honest folder reads, completion incognito, then model housekeeping. Plugin issues #2635, #2636 and #2638 stay on row 24 | in progress (row 27) — plan 2026-10-24, sprint 01 next |
| [`synaplan-platform/planning/20261025-admission-signup/`](https://github.com/metadist/synaplan-platform/blob/main/planning/20261025-admission-signup/00_master_plan.md) | Admission sign-up: web.synaplan.com stops issuing free accounts — a visitor pays for Pro/Team/Business or enters a sign-up code an administrator created (label, tier, validity, max uses, usage list); `REGISTRATION_MODE` defaults to `open` here, the platform sets `admission`; pending-payment accounts, hourly reaper, admin codes tab, the new register page (AS0–AS5; issues [#2733](https://github.com/metadist/synaplan/issues/2733)–[#2737](https://github.com/metadist/synaplan/issues/2737)) | in progress (row 28) — plan of record in `synaplan-platform`, decisions ticked 2026-10-25, AS1 next |
| [`20261025-iam-consolidation/`](./20261025-iam-consolidation/00_master_plan.md) | IAM consolidation after the sign-up changes: four kinds of account door (self-sign-up, directory, operator, channel) with one switch precedence, one creation path and one rule per account status; a security fix first (IC0, details in `synaplan-platform`), Stripe taking back only what it granted, one identity guide, the chart, a "who can create an account here" card (IC0–IC8, PC1–PC3, CH1) | not started (row 29) — plan 2026-10-25, §4 unticked; IC0 goes first |
| [`20261027-custom-mcp-oauth/`](./20261027-custom-mcp-oauth/00_master_plan.md) | Sign in to your own MCP server ([#2823](https://github.com/metadist/synaplan/issues/2823)): the Custom form offers No sign-in, Access key and Sign in with your account; one sentence for every failed sign-in; two security fixes first, details in `synaplan-platform` (MO0–MO3) | not started (row 30) — plan 2026-10-27, §0 unticked; MO0 goes first |

## Archive

Everything else — the frozen roadmaps (2026-09-03, 09-10, 09-17, 09-25),
the completed August–October tracks (IAM, tools, plugs, agent builder,
feature modules, navigation consolidation, smart search, early intake,
desktop Phase A, code cleanup V1–V4, the six-issue sprint, UX overhaul, …),
and the April/May topical dump (`2026-archive/2026-04-05-topic-docs/`) —
lives under `2026-archive/` with its dated name. The 2026-10-14, 2026-10-15,
2026-10-16, 2026-10-18 and 2026-10-24 housekeeping is listed in `ROADMAP.md` §4.
