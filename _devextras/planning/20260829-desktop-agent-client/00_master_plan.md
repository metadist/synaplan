# Synaplan Desktop — master plan

**Status:** Draft 2026-08-29. Do not start Sprint 0 until every row in §0 is
agreed. If a row is rejected, update this file in the same change as the
alternative.
**Owner surface:** Channels (pairing + device list). The extra client is its
own window, not a Synaplan web route.
**Related:**

- [`../20260731-local-agent-client/README.md`](../20260731-local-agent-client/README.md)
  — poll, allowlist, unenforced scopes (the safety half of this epic)
- [`../20260218-mcp-and-api-enhancements/02-mcp-integration/07-AGENT-SCHEDULING.md`](../20260218-mcp-and-api-enhancements/02-mcp-integration/07-AGENT-SCHEDULING.md)
  — `agent_checkin` / `agent_report_result` (dispatch shape; not implemented)
- [`../20260709-hosting-partner-core-requirements/README.md`](../20260709-hosting-partner-core-requirements/README.md)
  §CORE-3 — API-key scope enforcement
- [`docs/ANTHROPIC_COMPATIBLE_API.md`](../../../docs/ANTHROPIC_COMPATIBLE_API.md)
  — Messages gateway (`POST /v1/messages`)
- [`../20260822-open-plugin-platform/README.md`](../20260822-open-plugin-platform/README.md)
  §3.4 — prompt-pack skills (server-side markdown; **not** this runtime)
- [Agent Skills specification](https://agentskills.io/specification)

Sprint files and the binding test contract live beside this file. Implement
from [`09_work_breakdown.md`](./09_work_breakdown.md).

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **New private repo `synaplan-desktop`.** Sibling of `synaplan-apps`. Not a Capacitor app. Not an Electron wrap of the Vue SPA. | **New repo** | |
| 2 | **Stack: Tauri 2 + Vue 3 + TypeScript + Rust sidecar** for path confinement and process spawn. UI conventions follow Synaplan frontend (script setup, four locales, no hardcoded copy). | **Tauri 2** | |
| 3 | **User-facing name: Synaplan Desktop.** Internal code: `desktop`. Never “Claude client”, “local agent”, or “brogent” in UI. | Locked | |
| 4 | **“Skill” in the desktop means an Agent Skills folder** (`SKILL.md`). DAG `SkillDescriptor` and plugin prompt-packs keep their current names in code and stay out of this UI. | Locked | |
| 5 | **No Claude products required.** The client calls Synaplan only (`/v1/messages`, `/mcp`, `/api/v1/*`). Models are whatever the account already has. | Locked | |
| 6 | **Agent37 / public GitHub are discovery sources**, not a runtime. We fetch a skill folder. We never provision Agent37 Cloud. | Locked | |
| 7 | **API key scopes are enforced before pairing exists.** Empty / legacy scopes remain full access (CORE-3 grandfather). Pairing mints a **narrow** key. | Locked | |
| 8 | **The client owns the filesystem allowlist.** Server cannot widen it. Path checks are `realpath` then contain. | Locked | |
| 9 | **v1 chat lives in the desktop window** (Messages gateway). Web → desktop work is Sprint 6 and **out of band** (“queued for this computer”). No DAG suspension. | Locked | |
| 10 | **Sprint 6 job type is a closed enum.** v1 has `skill.run` only, and only for a skill the device has installed and the user enabled. No `shell.exec`, no code payload. | Locked | |
| 11 | **First scripted skill: official `pptx`** (Apache-2.0, no PowerPoint app). Vendor a reviewed copy under `skills/bundled/`. | Locked | |
| 12 | **Outlook in v1 = existing Synaplan M365 + Synamail.** Do not ship COM / AppleScript marketplace skills. Graph-via-curl skills may be documented as advanced, not bundled. | Locked | |
| 13 | **Feature flag `DESKTOP_AGENT.ENABLED`.** Code default **off**. Seeder insert-if-missing **off** for existing and new installs until Sprint 5 is usable. Per-user override allowed. | Off until GA | |
| 14 | **Schema:** `BDESKTOPDEVICES` in Sprint 1; `BDESKTOPJOBS` in Sprint 6. Pairing codes live in Redis (TTL), not a table. Galera-safe `addSql` only. **This plan is the “ask first” for those tables.** | Ask recorded | |
| 15 | **Linux is first-class** for portable skills. OS-bound skills declare `compatibility` and are hidden or refuse to run. | Locked | |
| 16 | **Widget and mobile unchanged.** New PHP paths = `backend-only`. Channels pairing UI = `ota-candidate`. Classify in `.github/mobile-impact-policy.json`. | Locked | |
| 17 | **Messages gateway must be enabled** for the account/instance (existing Channels → AI Agents). Desktop does not invent a second inference path. | Locked | |
| 18 | **One paired device key per computer.** Revoke in the web UI kills that key. Stolen laptop = revoke, not “hope scopes were decorative”. | Locked | |

If a row is rejected, update every sprint file that assumed the old default.

---

## 1. Why this exists

Users can already chat, search their sources, connect Microsoft 365, and run
Saved Tasks **on the server**. They cannot:

- run a public Agent Skill that unpacks a `.pptx` with Python on **their** disk;
- keep using Synaplan models and memory while doing that;
- avoid installing Claude Code or renting someone else’s agent host.

The July 2026 local-agent research answered “can a laptop pull jobs?”. Yes —
and it forbade `shell.exec` because those jobs were **LLM-authored**. This
epic adds a second, explicit trust step: the **user installed a skill folder**.
That is the only reason local scripts run. The server still cannot invent a
shell command.

---

## 2. What already exists (do not rebuild)

| Piece | State | Role here |
| ----- | ----- | --------- |
| `sk_*` API keys + `ApiKeyAuthenticator` | Shipped | Device auth. **Scopes stored, not enforced** — Sprint 0 |
| `POST /v1/messages` + tool relay | Shipped | Desktop inference. Client tools (`Bash`, `Read`, `Edit`) already round-trip |
| `POST /v1/chat/completions` | Shipped | **No tools.** Do not use this as the skill loop |
| `/mcp` + `McpServerFactory` | Shipped | Optional: RAG/files/memories from the desktop as an MCP client |
| Messages gateway flags | Shipped | `MESSAGES_GATEWAY.ENABLED` must be on; desktop does not add a gateway |
| `SkillCatalog` / `SkillDescriptor` | Shipped | **Unrelated.** Server DAG blocks. Do not extend for SKILL.md |
| Plugin prompt-pack plan | Planned | Server markdown prompts. Parallel epic. No shared tables |
| Saved Tasks + `MediaJob` | Shipped | UX pattern for out-of-band work (Sprint 6 copies the “queued” card) |
| M365 + Synamail | Shipped | v1 Outlook path. Do not duplicate OAuth on the laptop |
| Centrifugo `user:{id}` | Shipped | Optional wake-up: “you have a desktop job”. Check-in remains source of truth |
| `SsrfGuard` | Shipped | Blocks localhost MCP. Confirms why the client must **pull** |
| `synaplan-apps` | Shipped | Mobile only. Do not reuse for desktop |

---

## 3. Target architecture

```
  ┌──────── user ────────┐
  │                      │
  ▼                      ▼
Synaplan web          Synaplan Desktop          (new repo)
Channels → Desktop    chat + skills manager
  │                      │
  │  pairing code        │  scoped sk_*
  │  job authoring       │  local allowlist
  │                      │  SKILL.md + scripts
  └──────────┬───────────┘
             ▼
      Synaplan API
      /v1/messages   /mcp   /api/v1/desktop/*
             │
     ┌───────┼────────┬──────────┐
     ▼       ▼        ▼          ▼
   models   RAG    files     BDESKTOPJOBS
```

**Who owns what**

| Owns | Synaplan server | Synaplan Desktop |
| ---- | --------------- | ---------------- |
| Account, budget, models, RAG | Yes | No |
| Pairing, device list, revoke | Yes | Consumes |
| Job *whether* and *when* (Sprint 6) | Yes (`next_call_at`) | Sleeps until then |
| Skill folders on disk | Metadata only (optional later) | Yes |
| Filesystem allowlist | Must not widen | **Authority** |
| Running `scripts/*.py` | Never | Yes, sandboxed |
| Result ingest (file/chat) | Yes, untrusted | Produces |

---

## 4. Two skill words (do not collapse)

| Kind | Where | What it is | This epic? |
| ---- | ----- | ---------- | ---------- |
| **Agent Skill** | Laptop folder | `SKILL.md` + optional scripts | **Yes** |
| **DAG skill** | `SkillDescriptor` | Planner capability / TaskRunner | No |
| **Prompt-pack skill** | Plugin `skills/*.md` | Seeded `tools:{plugin}_*` prompt | No (other plan) |

User-facing: one word, **skill**. Engineers: `AgentSkill` in the desktop
repo, never a PHP class named `Skill` that loads `SKILL.md` on the server.

---

## 5. Trust model (binding)

Full rules: [`10_security_and_compatibility.md`](./10_security_and_compatibility.md).

1. **Empty API-key scopes = legacy full access.** Existing Claude Code / n8n /
   `/v1` keys keep working. New desktop keys get `desktop:messages`,
   `desktop:mcp`, `desktop:jobs`, `desktop:files` (Sprint 6 adds jobs).
2. **The server is not trusted to enlarge the laptop’s powers.** A hostile or
   prompt-injected planner can only enqueue `skill.run` for a name the device
   already enabled. Unknown names are refused locally.
3. **Installing a skill is code execution.** Show license, file list, and
   “this skill may run programs on this computer.” Confirm with `useDialog`
   equivalent in the client.
4. **Irreversible local actions** (overwrite, send mail from the MUA) stay
   confirm-on-device. v1 `pptx` writes only inside the allowlisted out-box.
5. **Results are untrusted** if they re-enter RAG or a prompt. Size-cap,
   MIME allowlist, provenance `source: desktop_skill`.

This is compatible with the July paper’s closed enum. The enum gains
`skill.run`; it does not gain `shell.exec`.

---

## 6. Client product shape (v1)

A small window, not a clone of Synaplan web:

1. **Sign in / pair** — instance URL + pairing code (or paste a scoped key
   for recovery).
2. **Chat** — one conversation at a time against `/v1/messages`, streaming.
   Optional “use my Synaplan sources” via `/mcp` (flag, default on once MCP
   tools are allowed for this key).
3. **Skills** — list installed, enable/disable, install from zip / folder /
   git URL, bundled `pptx`.
4. **This computer** — allowlisted folders, last check-in, revoke hint
   (“revoke from Synaplan on the web”).
5. **Tray** (Sprint 6) — stays running to poll. Until then, the window can
   be the only process.

Do not embed the widget. Do not load `ChatView.vue` from the public repo as
a WebView of the whole app. A thin Vue shell is fine; the SPA is not the
client.

---

## 7. Server product shape (v1)

**Channels → Desktop** (new child of Channels, four locales):

- Flag off: page explains the feature is off (admin) or hidden.
- Pair this computer (code + expiry).
- List devices: name, last seen, status, revoke.
- Sprint 6: “Jobs waiting” count; link into the chat that queued them.

No new top-level nav item. Follow `useNavItems` (desktop rail + mobile).
Mobile users see the pairing page as “install Synaplan Desktop on a
computer” — they cannot pair a phone as this client.

---

## 8. API sketch (additive)

All under `/api/v1/desktop/`, session **or** scoped API key, flag-gated.
Full OpenAPI on every route. Empty list / 404 when the flag is off (same
pattern as Saved Tasks: do not advertise the surface).

| Method | Path | Sprint | Purpose |
| ------ | ---- | ------ | ------- |
| `POST` | `/api/v1/desktop/pairing-codes` | 1 | Create 8-char code, Redis TTL 10 min |
| `POST` | `/api/v1/desktop/pair` | 1 | Code + device name → `sk_*` once + device id |
| `GET` | `/api/v1/desktop/devices` | 1 | Owner’s computers |
| `DELETE` | `/api/v1/desktop/devices/{id}` | 1 | Revoke device + its API key |
| `POST` | `/mcp` tool `agent_checkin` | 6 | Jobs + `next_call_at` |
| `POST` | `/mcp` tool `agent_report_result` | 6 | Untrusted result |
| `POST` | `/api/v1/desktop/jobs` | 6 | Web UI / chat queues `skill.run` |

REST job enqueue is for the web app (cookie session). The daemon only uses
MCP check-in / report so it stays a machine client.

---

## 9. Interaction with other tools

| Tool | Do | Do not |
| ---- | -- | ------ |
| Messages gateway | Require it; reuse admin copy | Fork a third completions API |
| MCP server | Desktop may call `/mcp` with the device key | Register the laptop as an MCP *server* (SSRF) |
| Saved Tasks | Sprint 6+ may enqueue `skill.run` | Run skills inside `DagExecutor` |
| Synamail / M365 | Document as the Outlook path | Bundle COM skills |
| Open plugin platform | Keep prompt-packs server-side | Install PHP plugins from the desktop |
| `synaplan-apps` | Ignore | Share signing / OTA / IAP |
| n8n | Still `/v1` + `/mcp` | Make n8n the skill runner |

---

## 10. Compatibility invariants

Named tests in [`08_testing_and_documentation.md`](./08_testing_and_documentation.md) §3.0.

| # | Invariant | Risk |
| - | --------- | ---- |
| C1 | Existing API keys with empty or legacy `webhooks:*` scopes keep full access after Sprint 0 | Scope listener too eager |
| C2 | `/v1/messages`, `/v1/chat/completions`, `/mcp` contracts stay additive | Pairing firewall |
| C3 | Routing / classifier characterization snapshots unchanged | No planner edits in this epic |
| C4 | Widget bundle never includes Desktop UI or job hooks | Shared i18n keys only if values, never widget namespace misuse |
| C5 | Mobile app behaviour unchanged; new server routes `backend-only` | Unclassified paths fail closed to store-required |
| C6 | OIDC / session login unchanged | `security.yaml` only gains `/api/v1/desktop` on existing API firewalls |
| C7 | M365 / Synamail / Saved Tasks unchanged | No shared table rewrites |

---

## 11. Rollout

1. Sprint 0–1 merge with flag **off**. Scope enforcement is live but
   grandfathered — existing keys do not shrink.
2. Install `synaplan-desktop` locally; pair against a dev instance with the
   flag on for one user.
3. Sprint 5: bundled `pptx` on Win/Mac/Linux (manual evidence in the PR).
4. Sprint 6: check-in behind the same flag.
5. Seed `DESKTOP_AGENT.ENABLED = 1` for **new** installs only after Sprint 5
   is usable. Existing installs stay off until an admin flips the flag.
6. Rollback: flag off. Devices and jobs remain. Daemon idles (check-in
   returns empty + far `next_call_at` or 404).

---

## 12. Out of scope (v1)

- Electron wrap of the Synaplan SPA.
- Agent37 Cloud, Hermes, OpenClaw, Claude Code as the shipped harness
  (pointing a *developer* harness at `/v1/messages` is an allowed spike,
  not a release).
- Same-turn “make a deck in this web reply”.
- Server-side execution of `scripts/`.
- `code_execution_*` Anthropic server tool (still out; see Messages plan
  phase 3).
- Public Synaplan-operated marketplace or paid skills.
- Auto-install of skills the planner invented.
- Linux Outlook application control.
- iOS / Android desktop-agent (use `synaplan-apps` as today).

---

## 13. Success criteria (epic)

1. A user pairs a computer without creating an unscoped key by hand.
2. They chat in Synaplan Desktop using only their Synaplan account.
3. They produce a `.pptx` with the bundled skill on at least two OSes
   (one of them Linux).
4. Revoking the device in the web UI stops the client on the next request.
5. A zip skill with a path-escape (`../`) is refused.
6. A Sprint 6 web-queued `skill.run` for an **uninstalled** name is
   refused on the device and marked failed on the server — no shell.
7. Flag off: web UI hides Desktop; `/api/v1/desktop/*` is 404; existing
   Synaplan behaviour is unchanged.
8. A German / Spanish / Turkish user can answer the five questions in
   [`11_ux_and_i18n.md`](./11_ux_and_i18n.md) §1 without English.

---

## 14. Workflow for each sprint

1. Read this file §0 and the sprint file “code to read first”.
2. Take the next unfinished D-step. One PR, one concern.
3. Gate in [`08_testing_and_documentation.md`](./08_testing_and_documentation.md).
4. Update the breakdown status table when the step merges.

**Do not start Sprint 2 (new repo) before D0–D3 (scopes + flag) are merged.**
A daemon with a full-access key is the failure mode this epic exists to avoid.
