# Status — Agent Builder

Track 2 of [`../20260903_roadmap.md`](../20260903_roadmap.md). Plan of record:
[`00_master_plan.md`](./00_master_plan.md). **Decision checklist (§0) ticked 2026-09-03; awaiting technical plan review
before the first sprint starts.**

## Steps

| Sprint / step | Branch / repo | State | Notes |
| ------------- | ------------- | ----- | ----- |
| S1 Entity & pinned runtime | `feat/agent-builder-s1` / synaplan | done | AB1–AB8: BAGENTS + BAGENTVERSIONS, `AGENTS.ENABLED` off, owner CRUD, `agentId` pin, Zod schemas regenerated |
| S2 Builder & gallery | `feat/agent-builder-s2` / synaplan | done | AB9–AB13, AB15–AB17; AB14 helper deferred |
| S3 Publish & versions | `feat/agent-builder-s3` / synaplan | done | AB18–AB25; IAM kind `agent` (BPROMPTS keeps `assistant`); share copy reuses assistant strings |
| S4 Knowledge, tools, skills | — | planned | Parameters folded into Models → Advanced settings |
| S5 Triggers: events & schedules | — | planned | J-AB-5, J-AB-7; was "Tasks & channels" until 2026-09-07 |
| S6 Portability & packs | — | planned | adds `api` / `mcp` / `desktop` event kinds to the picker |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-03 | Track created from the September 2026 partner feedback; order fixed in the roadmap. |
| 2026-09-03 | All 14 checklist rows accepted. Row 9 widened: this track owns `synaplan-bundle.v1` (section registry, `agents` + `prompts` sections, Settings → Export & import, admin variant) in S6; S6 is no longer the first cut line. |
| 2026-09-03 | Open questions resolved: user memory only; `BROUTABLE` allowed, off by default (snapshot re-record in a dedicated PR); archived assistant → existing chats continue on the last version, no new chats. |
| 2026-09-03 | UI: word **Assistant**; `/channels/agents` label → **Coding clients**; `/ai/assistants` replaces `/ai/instructions` (redirect); form-first builder with the AI Setup Assistant as optional helper. |
| 2026-09-07 | **UX contract.** Journeys J-AB-1…6 in [`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) are binding. S3 Share waits on IAM-UX. A published assistant a group member cannot find in ten seconds is not done. |
| 2026-09-07 | **Trigger = event or schedule** (decision row 15, product-owner proposal). Channels are **events** (mail with a sender/keyword rule, WhatsApp, website widget, API / coding tools, MCP-connected apps, Synaplan Desktop, web hook); the cron scheduler is the **schedule**; "someone starts a chat" is always on. *Tasks* + *Channels* sections → one **Triggers** section; `agent.v1` `tasks` / `channels` → `triggers.events[]` / `triggers.schedules[]`; `05_sprint_5_tasks_and_channels.md` → [`05_sprint_5_triggers.md`](./05_sprint_5_triggers.md). One additive Saved Tasks contract: `BTRIGGERCONFIG.filter` on `inbound_email`. J-AB-7 added; wireframe [`../202609_ux_user_flows/assistant-triggers.md`](../202609_ux_user_flows/assistant-triggers.md). |
| 2026-09-07 | **Builder: seven sections, not nine.** Parameters folds into Models → Advanced settings (creativity, length, language, response format). Publish confirm names the triggers that switch versions, not just the groups. |

## Review log

**2026-09-03 (first pass):** master plan drafted against the verified
codebase state (see roadmap §5).

**2026-09-03 (second pass):** all §0 rows ticked via the product-owner
questionnaire; open questions converted into the master plan's decisions table;
sprint files written. Next: technical plan review (roadmap §7 step 3).

**2026-09-07 (UX contract):** remaining UI sprints name journeys and
walk them before merge (U1–U12). Publish/share is not a second
permission model and not the S2 stacked dialog.

**2026-09-07 (S1 implemented on `feat/agent-builder-s1`):** BAGENTS / BAGENTVERSIONS,
`AGENTS.ENABLED` seeded off, owner CRUD, `agentId` classifier pin, Zod schemas
regenerated. Gate green without re-recording `routing_classification.json`. Next:
S2 gallery/builder (separate branch).

**2026-09-07 (S3 kind key):** IAM already bound `assistant` to BPROMPTS
ids. BAGENTS uses a new `agent` kind so numeric ids cannot collide.
Share dialog copy reuses the assistant strings (Assistants → Shared
with me).

**2026-09-07 (S2 implemented on `feat/agent-builder-s2`):** gallery + clone
APIs, owner-only draft stream, Assistants gallery/builder (flag-gated),
start-chat pill, Coding clients rename. Prompt API unchanged. AB14
Help-me-write deferred. Gate green. Next: S3 publish/versions/share.

**2026-09-07 (S3 implemented on `feat/agent-builder-s3`):** publish +
immutable versions, IAM kind `agent`, gallery Shared with me, clone of
published snapshot, archive/unarchive, usage aggregates, `app:agents:seed-system`
(not run by `app:seed`). Prompt API / `assistant` kind unchanged. Next: S4
knowledge, tools, skills.

**2026-09-07 (trigger wording, second UX pass):** the plan had spread
"when does this assistant act" over a *Tasks* section (schedules, cron
in primary copy), a read-only *Channels* mirror of bindings configured
elsewhere, and invisible S6 flags for API / MCP. Replaced by the
product owner's model — trigger = event or schedule — with one section,
one row sentence ("what arrives / when · what it does · who runs it"),
the Saved Tasks words plus **Event**, and a mail rule (sender,
keywords) as the first real event. Also found and fixed: nine builder
sections, "Tasks" colliding with Saved Tasks one nav group away, no
visible "runs as", Desktop absent from the assistant picture.
