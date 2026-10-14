# Planning

Live plans only. Shipped or superseded tracks move to
[`2026-archive/`](./2026-archive/readme.txt) the day their last step
lands, with a dated note at the top of their STATUS or README.

**Start with [`ROADMAP.md`](./ROADMAP.md).** It is the one roadmap: order
of work, what shipped, an index of every folder here, and the decision
log. It is edited in place — no new dated roadmap files, no redirect stubs.

## Conventions

- Folder and file names are `YYYYMMDD-topic` (a day, not only a month).
  The two binding documents keep their historical `YYYYMMDD_` names.
- A track is a folder with `00_master_plan.md` (decisions, journeys, step
  table, gates), optional sprint files, and `STATUS.md` as the step log.
  A walk is a STATUS entry, not a chat message.
- A step file that touches a user-visible surface names its journey and
  the five exit bullets from the UX contract §6 before implementation.
- Release class per step: `no-app-impact`, `backend-only`,
  `ota-candidate`, `store-required` (`.github/mobile-impact-policy.json`).

## Binding

| Path | Role |
| ---- | ---- |
| [`20260907_ux_user_flows.md`](./20260907_ux_user_flows.md) + [`20260907_ux_user_flows/`](./20260907_ux_user_flows/) | UX contract U1–U12 and journey notes; referenced by AGENTS.md |
| [`20260913-use-case-research/`](./20260913-use-case-research/README.md) | Ten jobs, Perfect-UX bar, Q-list; referenced by AGENTS.md |

## Live tracks

Same order as `ROADMAP.md` §1, plus the live-voice track that is already
in review. **Cleanup first (decision 2026-10-14):** rows 1–2 form a window
in which only bug fixes, security fixes and release blockers land beside
them; feature tracks resume when V2 meets its exit.

| Path | What | State 2026-10-14 |
| ---- | ---- | ---------------- |
| [`20261014-code-cleanup-V1/`](./20261014-code-cleanup-V1/00_master_plan.md) | Backend ordering sprint: deptrac layer gate with baseline, `Service/` root guarded as plugin contract, 69 of the 73 loose services sorted into domain folders in six PRs | **cleanup window** — C1 on go |
| [`20261014-code-cleanup-V2/`](./20261014-code-cleanup-V2/00_master_plan.md) | Break up the god classes: size ratchet, characterization snapshots, `ConfigController` → `ChatHandler` → `StreamController`, `docs/ARCHITECTURE.md` | **cleanup window** — after V1; closes the window |
| [`20261009-ux-overhaul/`](./20261009-ux-overhaul/00_master_plan.md) | UX overhaul: rail Chats · Library · Assistants · Apps · Admin, every sub page explained, guided tours (UX00–UX14) | paused |
| [`20261007-community-test-findings-5.2.0/`](./20261007-community-test-findings-5.2.0/README.md) | Triage of the external 5.2.0 test round (F1–F49), six fixing waves, 50 issue drafts in `issues/` | paused — waves 1–3 shipped; bug-fix issues continue |
| [`20260903_secure_compute/`](./20260903_secure_compute/00_master_plan.md) | Secure Compute; B4 remainder (egress, gVisor node, run images, docs, staging) | paused |
| [`20261001-single-scheduler/`](./20261001-single-scheduler/00_master_plan.md) | One scheduler; P1/P2/P4 shipped, P3 production rollout open (`synaplan-platform`) | ops, may proceed |
| [`20260929-synaplan-federation/`](./20260929-synaplan-federation/00_master_plan.md) | Federation v1.0; first slice shipped, opt-in knowledge link next ([`01_review.md`](./20260929-synaplan-federation/01_review.md)) | paused |
| `20261014-prompting-flow/` (reserved) | Prompting flow — work on files the way an IDE assistant does: working set, proposed changes as a diff with accept / reject, docked result pane, restore, verify step | queued — plan not yet written |
| [`20261013-cloud-file-sharing/`](./20261013-cloud-file-sharing/00_master_plan.md) | Nextcloud, ownCloud.online and OpenCloud on one level: copy Synaplan files into the cloud, one identity model (organisation login), the bird indicator, watched folders and automations that run on files picked up from the cloud. Nextcloud first (P1, core API frozen as contract v1), then OpenCloud (P2), then ownCloud.online (P3); all 46 §3 decision rows agreed. Absorbs More-Nextcloud S3 and the folder watch | queued — plan merged 2026-10-14, no code yet |
| [`20260917-backend-integrations/`](./20260917-backend-integrations/README.md) | Wave 6: Integrations catalog, Connect UI, editor / office clients. openDesk meeting notes shipped via [metadist/synaScriber](https://github.com/metadist/synaScriber) | queued |
| [`20261003-decision-routing/`](./20261003-decision-routing/00_master_plan.md) | Decision models before the LLM sorter; D1–D2 spike + go / no-go first | queued |
| [`20260903_more_nextcloud/`](./20260903_more_nextcloud/00_master_plan.md) | Nextcloud: S1 on `main`, S2 merged in `synaplan-nextcloud`, S3 planned — absorbed by the cloud file sharing plan | queued — partner |
| [`20260902-collabora-integration/`](./20260902-collabora-integration/00_master_plan.md) | Collabora epics 0–4 | queued — partner |
| [`20261004-app-accounts/`](./20261004-app-accounts/00_master_plan.md) | App accounts: apps like SISmass create Synaplan accounts for their customers (AA1–AA8) | not started |
| [`20260907-config-pyramid/`](./20260907-config-pyramid/00_master_plan.md) | Config pyramid; §0 unticked | not started |
| [`20261008-live-voice/`](./20261008-live-voice/00_master_plan.md) | Live voice conversations: speech-to-speech sidecar, delegation into chat, created media shown in the chat, model and voice under AI settings | in review — [#2477](https://github.com/metadist/synaplan/pull/2477), not on `main` yet |

## Archive

Everything else — the frozen roadmaps (2026-09-03, 09-10, 09-17, 09-25),
the completed August/September tracks (IAM, tools, plugs, agent builder,
feature modules, navigation consolidation, smart search, early intake,
desktop Phase A, …), and the April/May topical dump
(`2026-archive/2026-04-05-topic-docs/`) — lives under `2026-archive/` with
its dated name. The 2026-10-14 housekeeping is listed in `ROADMAP.md` §4.
