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
- Implementation of a plan follows the repository root
  [`AGENTS.md`](../../AGENTS.md): use the local stack and its provider
  keys to walk a journey, and take one branch, commit, push and pull
  request per topic, green before the next. A finished plan moves into
  `YYYY-archive/`, and that pass also moves older plans that are already
  implemented. The previous planning rules are
  [`../bak_AGENTS_planning.md`](../bak_AGENTS_planning.md).

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
| [`20261007-community-test-findings-5.2.0/`](./20261007-community-test-findings-5.2.0/README.md) | Triage of the external 5.2.0 test round (F1–F49), six fixing waves, 50 issue drafts in `issues/` | paused — waves 1–3 shipped; waves 4–6 open, drafts not filed on GitHub |
| [`20260903_secure_compute/`](./20260903_secure_compute/00_master_plan.md) | Secure Compute; B4 remainder (egress, gVisor node, run images, docs, staging) | paused |
| [`20260929-synaplan-federation/`](./20260929-synaplan-federation/00_master_plan.md) | Federation v1.0; first slice shipped, opt-in knowledge link next ([`01_review.md`](./20260929-synaplan-federation/01_review.md)) | paused |
| `20261014-prompting-flow/` (reserved) | Prompting flow — work on files the way an IDE assistant does: working set, proposed changes as a diff with accept / reject, docked result pane, restore, verify step | queued — plan not yet written |
| [`20261013-cloud-file-sharing/`](./20261013-cloud-file-sharing/00_master_plan.md) | Nextcloud, ownCloud.online and OpenCloud on one level: copy Synaplan files into the cloud, one identity model (organisation login), the bird indicator, watched folders and automations that run on files picked up from the cloud. Nextcloud first (P1, core API frozen as contract v1), then OpenCloud (P2), then ownCloud.online (P3); all 46 §3 decision rows agreed. Absorbs More-Nextcloud S3 and the folder watch | in progress — copy, pickup, source events and watches on `main` as of 2026-10-28; `cloud_files` off; defects RV4–RV6 are row 31; RV7 locks that an inbound watch upload is not an own write |
| [`20260917-backend-integrations/`](./20260917-backend-integrations/README.md) | Wave 6: Integrations catalog, Connect UI, editor / office clients. openDesk meeting notes shipped in `plugins/synascriber`; [metadist/synaScriber](https://github.com/metadist/synaScriber) is archived | queued |
| [`20261003-decision-routing/`](./20261003-decision-routing/00_master_plan.md) | Decision models before the LLM sorter; D1–D2 spike + go / no-go first | queued |
| [`20260902-collabora-integration/`](./20260902-collabora-integration/00_master_plan.md) | Collabora epics 0–4 | queued — partner |
| [`20261004-app-accounts/`](./20261004-app-accounts/00_master_plan.md) | App accounts: apps like SISmass create Synaplan accounts for their customers (AA1–AA8) | not started |
| [`20260907-config-pyramid/`](./20260907-config-pyramid/00_master_plan.md) | Config pyramid; §0 unticked | not started |
| [`20261016-out-of-process-plugins/`](./20261016-out-of-process-plugins/00_master_plan.md) | Tools and plugins in Python, Node.js and Go, out of process: MCP servers administrators add for everyone (organisation network, signed identity), then plugins that are a manifest plus a service, then isolated views. Research with a tested Python / Node.js / Go spike | not started (row 18) — plan 2026-10-16 |
| [`20261017-research-quality/`](./20261017-research-quality/00_master_plan.md) | Agentic research with a model team, Desktop first: measure, quick wins on today's paths (full web answers, page reading on every model, knowledge search as a tool), a server-side research run with reading and fact-checking model slots, the Desktop research surface, then the web chat | engine, web chat, admin tab and Desktop shipped (row 21). This branch turns research on by default (2026-10-28, RQ3 skipped, owner override); the review on `main` keeps `RESEARCH.ENABLED` off until row 31 RV1 and RV8. Quick wins and live walks stay open. §0 #5 and #15 superseded by adaptive research (row 32) |
| [`20261021-architecture-update/`](./20261021-architecture-update/00_master_plan.md) | Architecture update: one AI path with hooks, providers as self-contained units, central egress, one tool path, long waits out of PHP (Go watchdog + PHP stream pump), request timeline, capacity page, Kubernetes probes and autoscaling. Measured runtime spike in `spike/` | in progress (row 22) — AU0–AU25 on `main`; AU25b (tool calls in the audit log) is the open step. `EGRESS.MODE` stays `off`. `AI.USAGE_HOOK` stays `legacy` |
| [`20261021-per-user-budget/`](./20261021-per-user-budget/00_master_plan.md) | Per-account monthly budget, a directory-group budget assigned through the company login, and the existing Stripe €100 top-up for everyone else | not started (row 23) — plan 2026-10-21 |
| [`20261022-plugin-platform-quality/`](./20261022-plugin-platform-quality/00_master_plan.md) | Plugin platform quality: static checks over `plugins/` (#2636), the transcriber image published like the app image (#2638), locale files and `t()` for plugin frontends (#2635), bundled plugins tested and documented like the release (PQ0–PQ4); #2619 and #2606 as quick bugs (PQ5, PQ6) | not started (row 24) — plan 2026-10-22 |
| [`20261023-ux-polish-and-naming/`](./20261023-ux-polish-and-naming/00_master_plan.md) | UX polish from a measured visual check (stuck tour, chips below AA, watermark on OLED, a heading scale, 10 px text, internal keys on screen, casing) and the naming fix: the built object is called agent everywhere, collisions fixed first (UP0–UP8); §0 ticked 2026-10-23 — the word is "agent", which also applies to row 25 | not started (row 26) — plan 2026-10-23, UP1 and UP6 next |
| [`20261023-agent-handling/`](./20261023-agent-handling/00_master_plan.md) | Agent handling, from research on Hermes Agent and omp: those clients use Synaplan today (docs + conformance); stop a running Saved Task, Telegram assistants, an assistant from one sentence; then assistant runs on the one agent loop and the shared run kernel, a run card and the Runs page, notes and Agent Skills, proposed improvements, helpers and a double-check (AH0–AH23) | not started (row 25) — plan 2026-10-23, §0 ticked, not ordered |
| [`20261102-enterprise-readiness/`](./20261102-enterprise-readiness/00_master_plan.md) | Enterprise readiness (umbrella): the buyer's checklist from two public tenders and the market analysis with the row that closes each gap; trust center, SBOM and signed images, pentest → ISO 27001 → BSI C5, openDesk and tenders, partners, distribution, packaging (ER1–ER7) | ER1 drafted 2026-11-06 (row 34). D7 ticked. DPA not countersigned. LWL participation kit drafted, not filed |
| [`20261102-enterprise-identity/`](./20261102-enterprise-identity/00_master_plan.md) | SAML 2.0 sign-in, SCIM 2.0 provisioning (Entra ID, Okta), two-factor for password accounts, accounts keyed on their external identity, the chart (EI0–EI9) | not started (row 35) — after row 29 IC2–IC6 |
| [`20261102-audit-and-usage-reporting/`](./20261102-audit-and-usage-reporting/00_master_plan.md) | Audit event catalog and missing events, tamper-evident chain, export, SIEM streaming (HTTPS, Splunk HEC, syslog TLS), cost per group (AE1–AE7) | not started (row 37) |
| [`20261102-service-desk/`](./20261102-service-desk/00_master_plan.md) | Widget: visitor ratings, one outcome per conversation, resolution rate, knowledge gaps, Zendesk / Freshdesk / HubSpot tickets (SD1–SD6) | not started (row 38) |
| [`20261102-workplace-channels/`](./20261102-workplace-channels/00_master_plan.md) | The assistant in Microsoft Teams and Slack: DMs, @mentions, approval cards, Saved Task delivery, guided setup (WC0–WC7) | not started (row 39) — WC0 spike first |
| [`20261102-permission-aware-connectors/`](./20261102-permission-aware-connectors/00_master_plan.md) | OneDrive and Google Drive as personal sources; SharePoint, shared drives and Confluence with the source's permissions, fail closed (KC0–KC8) | not started (row 40) — phase B after row 35 EI0 |
| [`20261102-governance-and-privacy/`](./20261102-governance-and-privacy/00_master_plan.md) | Personal-data masking before cloud models, local only per group, agent review before publish, conversation retention, governance overview (GV1–GV7) | not started (row 41) |
| [`20261104-hosted-plugins/`](./20261104-hosted-plugins/00_master_plan.md) | First-party plugins in `plugins/`, CI, docs, and a way for an administrator to leave one out (HP0–HP10). Synamail profiles first | worked on (row 43) — HP4, 2026-11-05 |
| [`20261106-assistant-onboarding-ux/`](./20261106-assistant-onboarding-ux/00_master_plan.md) | Set up a working assistant without guesswork: deterministic search for scheduled runs, no invented answers, search status surfaced, sticky truthful test panel, Activate for personal assistants | not started (row 44) — plan 2026-11-06, §0 unticked |
| [`20261109-coding-backend/`](./20261109-coding-backend/00_master_plan.md) | Developer backend: editor chooses (default) or one model per account, on its own Apps page, with the window and setup for every editor on the two APIs, and usage by model, editor, and who paid (CB0–CB6) | not started (row 45) — plan revised 2026-11-09, §8 unticked |

## Archive

Everything else — the frozen roadmaps (2026-09-03, 09-10, 09-17, 09-25),
the completed August–October tracks (IAM, tools, plugs, agent builder,
feature modules, navigation consolidation, smart search, early intake,
desktop Phase A, code cleanup V1–V4, the six-issue sprint, UX overhaul, …),
and the April/May topical dump (`2026-archive/2026-04-05-topic-docs/`) —
lives under `2026-archive/` with its dated name. The 2026-10-14, 2026-10-15,
2026-10-16, 2026-10-18, 2026-10-24, 2026-11-12 and 2026-11-13 housekeeping is listed in `ROADMAP.md` §4.
