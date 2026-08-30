# Synaplan Desktop — Agent Skills client

**Status:** Plan drafted 2026-08-29, **reordered 2026-08-30 to server-first**.
Research only until the decision checklist in
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
> is the account, the models, RAG, and the job queue.

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

---

## Order of work — Synaplan first, then the client

Decided 2026-08-30 (master plan §0.1). The epic runs in **two phases, not
interleaved**: everything in `synaplan/` is finished, tested, documented, and
merged **before** the `synaplan-desktop` repository is created.

**Phase A — `synaplan/` (Sprints A1–A3)**

1. **Enforce API key scopes** + the `DESKTOP_AGENT.ENABLED` flag
   (independently valuable; blocker for any daemon).
2. **Pair a computer** in the Synaplan web UI → scoped, revocable key;
   device list; revoke.
3. **Queue and check-in**: `BDESKTOPJOBS`, `POST /api/v1/desktop/jobs`, MCP
   `agent_checkin` / `agent_report_result`, lease reaper, “Run on this
   computer” in web chat — proved by a **fake-device harness** in
   `_devextras/testing/desktop/`, then **frozen** as `protocol: 1`.

**Phase B — `synaplan-desktop` (Sprints B1–B5)**

1. **Create the repo** and get a signed-in chat that uses `/v1/messages`.
2. **Load and run Agent Skills** behind a local directory allowlist.
3. **Skills manager** (zip / git / GitHub URL). Agent37 is a catalog, not a host.
4. **First vertical:** official `pptx` skill (no PowerPoint app; Win/Mac/Linux).
5. **Poll `agent_checkin`** against the already-shipped contract, so web chat
   can queue a **named, installed** skill. Never free-form shell from the
   planner.

### Why this order

| Reason | Consequence |
| ------ | ----------- |
| Scopes must exist before any key reaches a laptop | A stolen laptop is a revoke, not an account takeover |
| The queue contract is designed once, with no client deadline pressure | No `command` field sneaks into `BINPUT` to unblock a client sprint |
| Every Phase A PR merges to `main` with the flag off | Small reviewable PRs; the surface is 404 / hidden until GA |
| The client is written against a frozen, documented contract | Phase B is client work only, not cross-repo negotiation |

**Trade-off accepted:** Phase A ships a feature nothing consumes yet. The
mitigations are invariant **C8** (flag off is inert) and the harness
(decision 20), which is the acceptance demo instead of a real device.

---

## How to read this folder

| File | Role |
| ---- | ---- |
| [`00_master_plan.md`](./00_master_plan.md) | Decisions, phase order, architecture, two-repo split, non-goals. **Tick the checklist before any code.** |
| [`01_phase_a1_scopes_and_flag.md`](./01_phase_a1_scopes_and_flag.md) | **Phase A** — scope enforcement + feature flag. |
| [`02_phase_a2_pairing.md`](./02_phase_a2_pairing.md) | **Phase A** — device pairing, scoped keys, Channels UI. |
| [`03_phase_a3_jobs_and_checkin.md`](./03_phase_a3_jobs_and_checkin.md) | **Phase A** — job queue, MCP check-in, web enqueue, harness, contract freeze. |
| [`04_phase_b1_client_repo.md`](./04_phase_b1_client_repo.md) | **Phase B** — create `synaplan-desktop`, sign-in, chat. |
| [`05_phase_b2_skills_runtime.md`](./05_phase_b2_skills_runtime.md) | **Phase B** — `SKILL.md` loader + sandboxed tools. |
| [`06_phase_b3_skills_manager.md`](./06_phase_b3_skills_manager.md) | **Phase B** — install / enable / remove skills. |
| [`07_phase_b4_first_skills.md`](./07_phase_b4_first_skills.md) | **Phase B** — bundled `pptx`; Outlook via existing Synaplan mail — not COM. |
| [`08_phase_b5_desktop_poll_loop.md`](./08_phase_b5_desktop_poll_loop.md) | **Phase B** — poll loop + unattended opt-in against the frozen contract. |
| [`09_testing_and_documentation.md`](./09_testing_and_documentation.md) | Gates for **both** repos. Binding. |
| [`10_work_breakdown.md`](./10_work_breakdown.md) | PR-sized steps (`DS*` server, `DC*` client). This is the implementation order. |
| [`11_security_and_compatibility.md`](./11_security_and_compatibility.md) | Allowlist, scopes, invariants, mobile classification. |
| [`12_ux_and_i18n.md`](./12_ux_and_i18n.md) | Canonical terms in EN/DE/ES/TR. Copy before UI. |

**Execute from [`10_work_breakdown.md`](./10_work_breakdown.md).** The sprint
files say why. The breakdown says how big and what “done” means.

---

## Two repositories

| Repo | What it owns | Touched in |
| ---- | ------------ | ---------- |
| `synaplan/` (this repo) | Scopes, flag, pairing API, device registry, job queue, MCP check-in, Channels page, harness, docs | **All of Phase A** (`DS1`–`DS18`); afterwards docs only |
| **`synaplan-desktop`** (new, private sibling of `synaplan-apps`) | Tauri 2 + Vue 3 client, skill loader, sandbox, poll loop, local audit | **Phase B** (`DC1`–`DC21`); created at `DC1`, never earlier |

Do **not** put this in `synaplan-apps` (Capacitor / store / OTA). Do **not**
vendor Agent37 or Claude Code. Do **not** create the client repo — not even an
empty scaffold — while Phase A steps are open (master plan decision 23).

---

## What “done” looks like

### Phase A (server, no client yet)

1. A `desktop:messages` key reaches `/v1` and nothing else; legacy keys are
   untouched.
2. A user can pair a computer, see it, and revoke it — flag-gated.
3. `_devextras/testing/desktop/fake-device.sh` pairs, checks in, runs a
   `skill.run` job, reports a result, and the chat shows the completion.
4. The same harness proves the refusal paths (unknown skill, extra
   `command` key, foreign device).
5. Flag off: nothing is visible, `/api/v1/desktop/*` is 404, `tools/list`
   is unchanged.
6. `docs/DESKTOP.md` describes the contract; fixtures freeze it.

### Phase B (client)

A Synaplan user on Windows, macOS, or Linux can:

1. Enable the feature, pair **this computer**, and see it listed under Channels.
2. Chat in Synaplan Desktop. Tokens and RAG go through their Synaplan account.
   No Claude product is installed.
3. Install the bundled PowerPoint skill (and later a zip / GitHub skill folder).
4. Ask for a slide deck; a `.pptx` appears in an allowlisted folder and can be
   uploaded back into Synaplan Sources.
5. From **web** chat, queue “make slides from this outline” and have the
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
- A shipped reference daemon inside `synaplan/` (the harness is a test script).

---

## Workflow for each step

1. Tick any open decision that the step depends on.
2. Implement **one** step from the breakdown — `DS*` in `synaplan/`,
   `DC*` in `synaplan-desktop`.
3. Run the gate in [`09_testing_and_documentation.md`](./09_testing_and_documentation.md)
   for the repo you touched.
4. PR on a feature branch. Conventional Commits. No AI attribution. Never `main`.
