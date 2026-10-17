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

## Binding

| Path | Role |
| ---- | ---- |
| [`docs/UX_CONTRACT.md`](../../docs/UX_CONTRACT.md) | UX contract U1–U12, shared patterns, sprint-file contract; referenced by AGENTS.md. Moved out of this directory on 2026-10-15; the 2026-09 journeys and wireframes are in [`2026-archive/20260907_ux_user_flows/`](./2026-archive/20260907_ux_user_flows/README.md) |
| [`20260913-use-case-research/`](./20260913-use-case-research/README.md) | Ten jobs, Perfect-UX bar, Q-list; referenced by AGENTS.md |

## Live tracks

Same order as `ROADMAP.md` §1. **The cleanup window closed on
2026-10-15:** code cleanup V1 and V2 met their exits and moved to the
archive with the UX overhaul; feature tracks resume with the prompting flow. Code cleanup V3
merged on 2026-10-16 and is archived; its §7 is code cleanup V4.

| Path | What | State 2026-10-16 |
| ---- | ---- | ---------------- |
| [`20261016-code-cleanup-V4/`](./20261016-code-cleanup-V4/00_master_plan.md) | The twelve findings V3 left, one sprint each (S0–S11) on one branch chain: account deletion that leaves no table behind first, then the chat view's stream handling, the turn alignment, one topic / intent rule, the channel, media, classifier and settings splits, one MIME map, a thin-controller gate, the provider split, strict types. Each sprint closes with a sprint test and its chore (Copilot answered, CI green) | in progress — S0, S1a, S1b and S2 merged; S3 and S4a in review; D1–D3 ticked |
| [`20261016_6-issues/`](./20261016_6-issues/00_master_plan.md) | Six newest product issues. Confirm #2459, #2460, #2461. Then a stacked fix: widget sources (#2519), visitor-chrome Playwright (#2504), owner-only transcript on a reply (#2515). | plan only (row 20) — no product code yet |
| [`20261008-live-voice/`](./20261008-live-voice/00_master_plan.md) | Live voice conversations: speech-to-speech sidecar, delegation into chat, created media shown in the chat, model and voice under AI settings | finishing — web, backend and sidecar on `main` ([#2477](https://github.com/metadist/synaplan/pull/2477), [#2507](https://github.com/metadist/synaplan/pull/2507)); LV7–LV8 on `synaplan-apps` branch `feat/live-voice`, no PR yet |
| [`20261007-community-test-findings-5.2.0/`](./20261007-community-test-findings-5.2.0/README.md) | Triage of the external 5.2.0 test round (F1–F49), six fixing waves, 50 issue drafts in `issues/` | paused — waves 1–3 shipped; waves 4–6 open, drafts not filed on GitHub |
| [`20260903_secure_compute/`](./20260903_secure_compute/00_master_plan.md) | Secure Compute; B4 remainder (egress, gVisor node, run images, docs, staging) | paused |
| [`20261001-single-scheduler/`](./20261001-single-scheduler/00_master_plan.md) | One scheduler; P1/P2/P4 shipped, P3 production rollout open (`synaplan-platform`) | ops |
| [`20260929-synaplan-federation/`](./20260929-synaplan-federation/00_master_plan.md) | Federation v1.0; first slice shipped, opt-in knowledge link next ([`01_review.md`](./20260929-synaplan-federation/01_review.md)) | paused |
| `20261014-prompting-flow/` (reserved) | Prompting flow — work on files the way an IDE assistant does: working set, proposed changes as a diff with accept / reject, docked result pane, restore, verify step | queued — plan not yet written |
| [`20261013-cloud-file-sharing/`](./20261013-cloud-file-sharing/00_master_plan.md) | Nextcloud, ownCloud.online and OpenCloud on one level: copy Synaplan files into the cloud, one identity model (organisation login), the bird indicator, watched folders and automations that run on files picked up from the cloud. Nextcloud first (P1, core API frozen as contract v1), then OpenCloud (P2), then ownCloud.online (P3); all 46 §3 decision rows agreed. Absorbs More-Nextcloud S3 and the folder watch | queued — plan merged 2026-10-14, no code yet |
| [`20260917-backend-integrations/`](./20260917-backend-integrations/README.md) | Wave 6: Integrations catalog, Connect UI, editor / office clients. openDesk meeting notes shipped via [metadist/synaScriber](https://github.com/metadist/synaScriber) | queued |
| [`20261003-decision-routing/`](./20261003-decision-routing/00_master_plan.md) | Decision models before the LLM sorter; D1–D2 spike + go / no-go first | queued |
| [`20260903_more_nextcloud/`](./20260903_more_nextcloud/00_master_plan.md) | Nextcloud: S1 on `main`, S2 merged in `synaplan-nextcloud`, S3 planned — absorbed by the cloud file sharing plan | queued — partner |
| [`20260902-collabora-integration/`](./20260902-collabora-integration/00_master_plan.md) | Collabora epics 0–4 | queued — partner |
| [`20261004-app-accounts/`](./20261004-app-accounts/00_master_plan.md) | App accounts: apps like SISmass create Synaplan accounts for their customers (AA1–AA8) | not started |
| [`20260907-config-pyramid/`](./20260907-config-pyramid/00_master_plan.md) | Config pyramid; §0 unticked | not started |
| [`20261016-out-of-process-plugins/`](./20261016-out-of-process-plugins/00_master_plan.md) | Tools and plugins in Python, Node.js and Go, out of process: MCP servers administrators add for everyone (organisation network, signed identity), then plugins that are a manifest plus a service, then isolated views. Research with a tested Python / Node.js / Go spike | not started (row 18) — plan 2026-10-16 |

## Archive

Everything else — the frozen roadmaps (2026-09-03, 09-10, 09-17, 09-25),
the completed August–October tracks (IAM, tools, plugs, agent builder,
feature modules, navigation consolidation, smart search, early intake,
desktop Phase A, code cleanup V1 and V2, UX overhaul, …), and the April/May
topical dump (`2026-archive/2026-04-05-topic-docs/`) — lives under
`2026-archive/` with its dated name. The 2026-10-14 and 2026-10-15
housekeeping is listed in `ROADMAP.md` §4.
