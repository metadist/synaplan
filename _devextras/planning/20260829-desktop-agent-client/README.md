# Synaplan Desktop — Agent Skills client

**Status:** Plan drafted 2026-08-29. Research only until the decision checklist in
[`00_master_plan.md`](./00_master_plan.md) is ticked. No product code in this
change.
**Product:** a **separate desktop client** (Windows, macOS, Linux) that signs in
with a Synaplan account, runs open [Agent Skills](https://agentskills.io/specification)
(`SKILL.md` folders) on the local machine, and talks **only** to Synaplan APIs.
No Claude.ai, Claude Code, or Agent37 Cloud.
**Builds on:** [`20260731-local-agent-client`](../20260731-local-agent-client/README.md)
(poll + allowlist + scope blocker), [`07-AGENT-SCHEDULING.md`](../20260218-mcp-and-api-enhancements/02-mcp-integration/07-AGENT-SCHEDULING.md)
(`agent_checkin` shape), [`CORE-3`](../20260709-hosting-partner-core-requirements/README.md)
(API-key scopes), Messages gateway (`docs/ANTHROPIC_COMPATIBLE_API.md`),
[`20260822-open-plugin-platform`](../20260822-open-plugin-platform/README.md)
(prompt-pack skills — a **different** skill kind; do not mix the words in UI).

> **The ask:** users install Claude-style skills (for example from public
> indexes such as [Agent37](https://www.agent37.com/skills?q=powerpoint)) and
> use them through their Synaplan account. The laptop is the hands. Synaplan
> is the account, the models, RAG, and (later) the job queue.

---

## Executive recommendation

**Yes, and it must be a new client repository — not an Electron wrap of the
web app, and not a folder inside `synaplan-apps`.**

Synaplan already has the brain: API keys, `POST /v1/messages` (client tools
relayed), `/mcp`, files, RAG, Saved Tasks, Microsoft 365, Synamail. It does
**not** have an Agent Skills runtime. Marketplace PowerPoint / Outlook packages
are folders of markdown plus scripts. They need a local `Read` / `Write` /
`Bash` loop. PHP in Docker cannot be that loop.

Two products share the word “skill”. This epic ships **only** the desktop
Agent Skills runtime. Synaplan’s DAG `SkillDescriptor` and the planned plugin
prompt-packs stay on their own tracks. User-facing copy says **skill**, never
“Claude skill”, “DAG”, or “TaskRunner”.

Ship in this order:

1. **Enforce API key scopes** (independently valuable; blocker for any daemon).
2. **Pair a computer** in the Synaplan web UI → scoped, revocable key.
3. **Create `synaplan-desktop`** and get a signed-in chat that uses `/v1/messages`.
4. **Load and run Agent Skills** behind a local directory allowlist.
5. **Skills manager** (zip / git / GitHub URL). Agent37 is a catalog, not a host.
6. **First vertical:** official `pptx` skill (no PowerPoint app; Win/Mac/Linux).
7. **Poll `agent_checkin`** so web chat / Saved Tasks can queue a **named,
   installed** skill. Never free-form shell from the planner.

---

## How to read this folder

| File | Role |
| ---- | ---- |
| [`00_master_plan.md`](./00_master_plan.md) | Decisions, architecture, two-repo split, non-goals. **Tick the checklist before any code.** |
| [`01_sprint_0_scopes_and_flag.md`](./01_sprint_0_scopes_and_flag.md) | Scope enforcement + feature flag. Synaplan only. |
| [`02_sprint_1_pairing.md`](./02_sprint_1_pairing.md) | Device pairing, scoped keys, Channels UI. |
| [`03_sprint_2_client_repo.md`](./03_sprint_2_client_repo.md) | Create `synaplan-desktop`, sign-in, chat. |
| [`04_sprint_3_skills_runtime.md`](./04_sprint_3_skills_runtime.md) | `SKILL.md` loader + sandboxed tools. |
| [`05_sprint_4_skills_manager.md`](./05_sprint_4_skills_manager.md) | Install / enable / remove skills. |
| [`06_sprint_5_first_skills.md`](./06_sprint_5_first_skills.md) | Bundled `pptx`; Outlook via existing Synaplan mail — not COM. |
| [`07_sprint_6_checkin_jobs.md`](./07_sprint_6_checkin_jobs.md) | Poll loop + out-of-band web jobs. |
| [`08_testing_and_documentation.md`](./08_testing_and_documentation.md) | Gates for **both** repos. Binding. |
| [`09_work_breakdown.md`](./09_work_breakdown.md) | PR-sized D-steps. This is the implementation order. |
| [`10_security_and_compatibility.md`](./10_security_and_compatibility.md) | Allowlist, scopes, invariants, mobile classification. |
| [`11_ux_and_i18n.md`](./11_ux_and_i18n.md) | Canonical terms in EN/DE/ES/TR. Copy before UI. |

**Execute from [`09_work_breakdown.md`](./09_work_breakdown.md).** The sprint
files say why. The breakdown says how big and what “done” means.

---

## Two repositories

| Repo | What it owns | First sprint that touches it |
| ---- | ------------ | ---------------------------- |
| `synaplan/` (this repo) | Scopes, flag, pairing API, device registry, job queue, Channels page, docs | Sprint 0 |
| **`synaplan-desktop`** (new, private sibling of `synaplan-apps`) | Tauri 2 + Vue 3 client, skill loader, sandbox, tray, local audit | Sprint 2 |

Do **not** put this in `synaplan-apps` (Capacitor / store / OTA). Do **not**
vendor Agent37 or Claude Code.

---

## What “done” looks like for the epic

A Synaplan user on Windows, macOS, or Linux can:

1. Enable the feature, pair **this computer**, and see it listed under Channels.
2. Chat in Synaplan Desktop. Tokens and RAG go through their Synaplan account.
   No Claude product is installed.
3. Install the bundled PowerPoint skill (and later a zip / GitHub skill folder).
4. Ask for a slide deck; a `.pptx` appears in an allowlisted folder and can be
   uploaded back into Synaplan Sources.
5. Later: from **web** chat, queue “make slides from this outline” and have the
   paired computer pick it up on the next check-in. The web reply is
   “queued for this computer”, not a same-turn file.

Linux is first-class for portable skills (`pptx`, Graph). Skills that drive
the Outlook **application** are marked incompatible on Linux and are out of v1.

---

## Non-goals (v1) — one screen

- Wrapping `frontend/` in Electron.
- Agent37 Cloud / Hermes / OpenClaw as the shipped runtime.
- Requiring Anthropic or Claude Code.
- Installing arbitrary PHP plugins from the desktop.
- Same-turn DAG suspension (web chat waiting on the laptop).
- Outlook COM / AppleScript automation.
- `shell.exec` job type from the server.
- A public skills marketplace operated by Synaplan.

---

## Workflow for each step

1. Tick any open decision that the step depends on.
2. Implement **one** D-step from the breakdown.
3. Run the gate in [`08_testing_and_documentation.md`](./08_testing_and_documentation.md)
   for the repo you touched.
4. PR on a feature branch. Conventional Commits. No AI attribution. Never `main`.
