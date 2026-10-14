# Planning

Live plans only. Shipped or superseded folders live in
[`2026-archive/`](./2026-archive/readme.txt).

Names are `YYYYMMDD` (a day, not only a month). Older `YYYYMM_*`
paths were renamed on 2026-09-17.

## Live

| Path | Why it stays here |
| ---- | ----------------- |
| [`20260925_roadmap.md`](./20260925_roadmap.md) | Current roadmap (UX close-out, in-app transcription, Telegram, openDesk meeting notes, Wave 6) |
| [`20261014-code-cleanup-V1/`](./20261014-code-cleanup-V1/00_master_plan.md) | Backend ordering sprint: deptrac layer gate with baseline (C1), `Service/` root guarded as plugin contract (C2), 69 of the 73 loose services sorted into domain folders in six PRs (C3–C8; the 4 classes external plugins import stay). V3 candidates (domain-first layout, PHPStan 6+) listed, not scheduled |
| [`20261014-code-cleanup-V2/`](./20261014-code-cleanup-V2/00_master_plan.md) | Queued behind V1: break up the god classes. Size ratchet (class ≤ 800 lines, ctor ≤ 10, method ≤ 100) in `tests/Architecture/`, characterization snapshots first (golden SSE transcript, prompt assembly, runtime config), then `ConfigController` (50 deps) → `ChatHandler` (one pipeline, two sinks) → `StreamController` (2,008-line method). Seams measured in `01_seams.md`. Ends with `docs/ARCHITECTURE.md` + `make metrics` |
| [`20261007-community-test-findings-5.2.0/`](./20261007-community-test-findings-5.2.0/README.md) | Triage of the external 5.2.0 test round (F1–F49 vs Open WebUI 0.11.4): six fixing waves, finding → issue map, 50 ready-to-create issue drafts in `issues/` + `create-issues.sh` |
| [`20260927-early-intake/`](./20260927-early-intake/README.md) | Sprint files for transcription, the easy issues, and Telegram |
| [`20260917-backend-integrations/`](./20260917-backend-integrations/README.md) | Wave 6: catalog, Connect UI, editor / office clients. Jitsi meeting notes moved to the synaScriber repository |
| [metadist/synaScriber](https://github.com/metadist/synaScriber) | Plan of record for openDesk meeting notes (Jitsi plugin). Replaces the September `opendesk_stt` sketch |
| [`20260927_roadmap.md`](./20260927_roadmap.md) | Stub → folded into the 2026-09-25 plan |
| [`20260917_roadmap.md`](./20260917_roadmap.md) | Stub → live plan + frozen 2026-09-17 text |
| [`20260910_roadmap_update.md`](./20260910_roadmap_update.md) | Stub → live plan + frozen 2026-09-10 text |
| [`20260903_roadmap.md`](./20260903_roadmap.md) | Stub → live plan + frozen 2026-09-03 text |
| [`20260907_ux_user_flows.md`](./20260907_ux_user_flows.md) | Binding UX contract (U1–U12) |
| [`20260907_ux_user_flows/`](./20260907_ux_user_flows/) | Journey notes that apply the contract |
| [`20260913-use-case-research/`](./20260913-use-case-research/) | Ten jobs + Perfect-UX bar (AGENTS.md) |
| [`20261009-ux-overhaul/`](./20261009-ux-overhaul/00_master_plan.md) | UX overhaul: rail Chats · Library · Assistants · Apps · Admin, Apps directory, every sub page explained, guided tours (UX00–UX14). Replaces NV10–NV23 |
| [`20260914-navigation-consolidation/`](./20260914-navigation-consolidation/) | NV01–NV09 shipped; NV10–NV23 replaced by the UX overhaul |
| [`20260903_secure_compute/`](./20260903_secure_compute/) | Compute B3 in progress; B4 planned |
| [`20260903_more_nextcloud/`](./20260903_more_nextcloud/) | S2–S3 still planned |
| [`20260902-collabora-integration/`](./20260902-collabora-integration/) | Editor / partner epics still planned |
| [`20260907-config-pyramid/`](./20260907-config-pyramid/) | Not started |
| [`20260829-desktop-agent-client/`](./20260829-desktop-agent-client/) | Plan of record for the desktop client |
| [`20260919-meta-muse/`](./20260919-meta-muse/00_master_plan.md) | Meta Model API (Muse Spark) + per-model thinking levels (§0 unticked, research only) |
| [`20261003-decision-routing/`](./20261003-decision-routing/00_master_plan.md) | Decision models (Ollama System One): routing layer before the LLM sorter, `/v1/systemone` + `/api/v1/decisions` API, decision mode in chat; D1–D2 spike + go / no-go first |
| [`20261004-app-accounts/`](./20261004-app-accounts/00_master_plan.md) | App accounts: apps like SISmass create Synaplan accounts for their customers, with a token trial and purchase without a Synaplan login (AA1–AA8, not started) |
| [`20261007-mcp-tool-errors/`](./20261007-mcp-tool-errors/issue-mcp-tool-error-fallback.md) | Partner report (Backblaze B2 MCP): a tool error drops the plan to legacy chat or skips the answer step. Implemented on this branch, pending merge |
| [`20260929-synaplan-federation/`](./20260929-synaplan-federation/00_master_plan.md) | Synaplan Federation v1.0, reviewed and queued: next sprint is the opt-in knowledge link ([`01_review.md`](./20260929-synaplan-federation/01_review.md)); data model + 15k storage math in [`02_federation_data_model.md`](./20260929-synaplan-federation/02_federation_data_model.md) |
| [`20261013-cloud-file-sharing/`](./20261013-cloud-file-sharing/00_master_plan.md) | Nextcloud, ownCloud.online and OpenCloud on one level: copy Synaplan files into the cloud, one identity model (organisation login), the bird indicator, watched folders and automations that run on files picked up from the cloud — including file work in the sandbox. Delivered Nextcloud first (P1, core API frozen as contract v1), then OpenCloud (P2), then ownCloud.online (P3); all 46 §3 decision rows agreed. Would absorb More-Nextcloud S3 and the roadmap's folder watch |

## Archive

Everything else — completed September tracks (IAM, tools, plugs, agent
builder, feature modules, …), finished August sprints, the 2026-09-10
roadmap, and the older April/May topic notes — is under `2026-archive/`.
Names stay the dated folder they have; the April/May topical dump is
`2026-archive/2026-04-05-topic-docs/`.
