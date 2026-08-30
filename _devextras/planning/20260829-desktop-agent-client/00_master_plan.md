# Synaplan Desktop — master plan

**Status:** Draft 2026-08-29. **Revised 2026-08-30: server-first order** and
**cross-platform parity (§0.3)**. All `synaplan/` code (Phase A) is finished and
merged **before** the desktop client repository is created (Phase B). Do not
start Phase A until every row in §0 is agreed. If a row is rejected, update this
file in the same change as the alternative.
**Owner surface:** Channels (pairing + device list). The extra client is its
own window, not a Synaplan web route.
**Platforms:** Windows, macOS, and Linux are **all Tier 1**. Platform rules are
binding and live in [`13_cross_platform.md`](./13_cross_platform.md).
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
from [`10_work_breakdown.md`](./10_work_breakdown.md).

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
| 9 | **v1 chat lives in the desktop window** (Messages gateway). Web → desktop work is **out of band** (“queued for this computer”): server half in Sprint A3, client half in Sprint B5. No DAG suspension. | Locked | |
| 10 | **The job type is a closed enum.** v1 has `skill.run` only, and only for a skill the device has installed and the user enabled. No `shell.exec`, no code payload. | Locked | |
| 11 | **First scripted skill: official `pptx`** (Apache-2.0, no PowerPoint app). Vendor a reviewed copy under `skills/bundled/`. | Locked | |
| 12 | **Outlook in v1 = existing Synaplan M365 + Synamail.** Do not ship COM / AppleScript marketplace skills. Graph-via-curl skills may be documented as advanced, not bundled. | Locked | |
| 13 | **Feature flag `DESKTOP_AGENT.ENABLED`.** Code default **off**. Seeder insert-if-missing **off** for existing and new installs until Sprint B4 is usable. Per-user override allowed. | Off until GA | |
| 14 | **Schema:** `BDESKTOPDEVICES` in Sprint A2; `BDESKTOPJOBS` in Sprint A3. Pairing codes live in Redis (TTL), not a table. Galera-safe `addSql` only. **This plan is the “ask first” for those tables.** | Ask recorded | |
| 15 | **Windows, macOS, and Linux are all Tier 1** for portable skills — a red platform blocks the release. OS-bound skills declare `compatibility` and are hidden or refuse to run. Superseded in detail by §0.3. | Locked | |
| 16 | **Widget and mobile unchanged.** New PHP paths = `backend-only`. Channels pairing UI = `ota-candidate`. Classify in `.github/mobile-impact-policy.json`. | Locked | |
| 17 | **Messages gateway must be enabled** for the account/instance (existing Channels → AI Agents). Desktop does not invent a second inference path. | Locked | |
| 18 | **One paired device key per computer.** Revoke in the web UI kills that key. Stolen laptop = revoke, not “hope scopes were decorative”. | Locked | |

### 0.1 Order decisions (added 2026-08-30)

These replace the earlier interleaved order, where the client repo was created
in the middle of the epic and the job queue came last.

| # | Decision | Answer | Agree? |
| - | -------- | ------ | ------ |
| 19 | **Finish Synaplan first.** Every `synaplan/` step — scopes, flag, pairing, device registry, job queue, MCP check-in, reaper, Channels UI, “Run on this computer”, docs — merges **before** any client code exists. | **Server-first (Phase A → Phase B)** | Decided |
| 20 | **How Phase A is proven without a client:** a scripted **fake-device harness** in `_devextras/testing/desktop/` (pair → `agent_checkin` → `agent_report_result`) is its own step and the acceptance demo for Sprint A3. | Harness in `synaplan/` | Decided |
| 21 | **Merge policy:** every Phase A PR merges to `main` with `DESKTOP_AGENT.ENABLED` **off**. No long-lived integration branch. Flag off ⇒ 404 and no nav, so shipping an unused surface is invisible. | Merge to main, flag off | Decided |
| 22 | **The job / check-in contract is locked at the end of Phase A**, not renegotiated when the client is written. `protocol: 1`, closed `type` enum, committed request/response fixtures. Phase B conforms; a client wish is a `protocol: 2` conversation. | Locked + versioned | Decided |
| 23 | **No `synaplan-desktop` repo before Phase A is merged** — not even an empty Tauri scaffold. The repo is created as the first step of Phase B (`DC1`). | Strictly after | Decided |
| 24 | **Cut line unchanged:** if scope slips, cut the **queue** (Sprint A3 + Sprint B5) before cutting scopes, pairing, or path confinement. Because A3 is last in Phase A, cutting it still leaves a coherent product. | Cut the queue first | Decided |

If a row is rejected, update every sprint file that assumed the old default.

---

## 0.2 Phase order (binding)

| Phase | Sprint | Repo | Content | Blocked by |
| ----- | ------ | ---- | ------- | ---------- |
| **A** | A1 | `synaplan/` | API-key scope enforcement + `DESKTOP_AGENT.ENABLED` | — |
| **A** | A2 | `synaplan/` | Pairing, `BDESKTOPDEVICES`, device CRUD, Channels → Desktop UI | A1 |
| **A** | A3 | `synaplan/` | `BDESKTOPJOBS`, job store, enqueue API, MCP `agent_checkin` / `agent_report_result`, reaper, “Run on this computer”, fake-device harness, contract freeze + docs | A2 |
| **B** | B1 | `synaplan-desktop` | Create the repo (3-OS CI from the first commit); platform dirs; OS secret store; pair; streaming chat via `/v1/messages` | **all of Phase A** |
| **B** | B2 | `synaplan-desktop` | `SKILL.md` loader + confined Read / Write / Bash, with the per-OS confinement corpus | B1 |
| **B** | B3 | `synaplan-desktop` | Skills manager (folder / zip / git), archive rules hardened per OS | B2 |
| **B** | B4 | `synaplan-desktop` | Bundled `pptx`, platform-aware doctor, binary allowlist | B3 |
| **B** | B5 | `synaplan-desktop` | Poll loop against the frozen A3 contract; unattended opt-in; per-OS autostart | B4 |
| **B** | B6 | `synaplan-desktop` | Release engineering: installers, Authenticode signing, macOS notarization + stapling. Spec in [`13_cross_platform.md`](./13_cross_platform.md) §9 (no separate sprint file) | B4 |

**Phase A ships a complete, tested, documented server feature with no
consumer.** That is deliberate: the alternative is a client that pushes
server changes under deadline, which is how a job queue grows a
`command` field.

Phase B PRs never touch `synaplan/` except for documentation
(`docs/DESKTOP.md` install section, once binaries exist).

---

## 0.3 Cross-platform decisions (added 2026-08-30)

The first draft was written Linux-first: `~/.synaplan-desktop`, `python3`,
`soffice`, POSIX symlink tests, and a CI matrix where Windows and macOS were
`workflow_dispatch`. The product promise is three desktops. These rows close
that gap; the implementation detail is
[`13_cross_platform.md`](./13_cross_platform.md), which is **binding for every
`DC*` step**.

| # | Decision | Answer | Agree? |
| - | -------- | ------ | ------ |
| 25 | **Three Tier-1 platforms.** Windows 10 22H2+, macOS 13+, Linux glibc 2.31+. A platform that cannot meet the bar is removed from the promise, not silently degraded. | Win = Mac = Linux | Decided |
| 26 | **Unit tests, including the path-confinement corpus, run on all three OSes in every PR** — not `workflow_dispatch`. Confinement behaviour differs most exactly where the old plan tested least. | 3 runners per PR | Decided |
| 27 | **No `~` in code.** One `app_dirs` module owns config / skills / out-box / audit paths per OS. Sprint files use `~/…` only as shorthand. The out-box stays in the user's home on all three so people can find their files. | `app_dirs` (`DC22`) | Decided |
| 28 | **Confinement is per-OS, not "canonicalize and hope".** Windows junctions and reparse points, UNC and device namespaces, drive-relative paths, 8.3 short names, alternate data streams, reserved device names, trailing dot/space, long paths; macOS firmlinks (`/var` → `/private/var`), NFC/NFD, case-insensitive-by-default volumes; Linux byte-exact and `/proc` denial. One shared, table-driven corpus. | `DC6` + `DC24` | Decided |
| 29 | **No shell, on any platform.** Tools take `{program, args[], workdir}`. `sh -c`, `cmd /c`, and `powershell -Command` are never constructed. Timeouts kill the whole process tree (Windows Job Object, POSIX process group). The child environment is constructed, never inherited. | argv only | Decided |
| 30 | **Secrets go to the OS store on all three** (Credential Manager / Keychain / Secret Service) behind one `SecretStore` abstraction. Headless Linux gets a named, opt-in, warned plaintext fallback that the unattended poll loop refuses — never a silent downgrade. | `DC23` | Decided |
| 31 | **The doctor is platform-aware or it is a lie.** Microsoft Store `python.exe` placeholder, the `py` launcher, `PATHEXT`, `soffice.exe` in Program Files, the macOS Command Line Tools shim, both Homebrew prefixes, LibreOffice inside an `.app` bundle, and PEP 668 externally-managed Python are named cases with tests. | `DC16` + `DC26` | Decided |
| 32 | **Signing and notarization are a GA release blocker**, deferred only within Sprint B1. Unsigned on Windows means a SmartScreen wall; unnotarized on macOS means the app will not open at all. Certificate procurement starts **during Phase A** because the lead time is weeks. | `DC28`, procure early | Decided |

**Cost accepted.** Three CI runners per PR and two code-signing identities are
the price of the three-platform promise. The alternative is discovering on a
user's Windows machine that a junction walked out of the allowlist.

**What this costs in schedule:** Phase B grows from five weeks to six
([`10_work_breakdown.md`](./10_work_breakdown.md) §11) — the confinement corpus
(`DC24`) and platform discovery (`DC26`) are real work, and a signed installer
(`DC28`) is its own step. Phase A is unchanged.

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
| `sk_*` API keys + `ApiKeyAuthenticator` | Shipped | Device auth. **Scopes stored, not enforced** — Sprint A1 |
| `POST /v1/messages` + tool relay | Shipped | Desktop inference. Client tools (`Bash`, `Read`, `Edit`) already round-trip |
| `POST /v1/chat/completions` | Shipped | **No tools.** Do not use this as the skill loop |
| `/mcp` + `McpServerFactory` | Shipped | Optional: RAG/files/memories from the desktop as an MCP client |
| Messages gateway flags | Shipped | `MESSAGES_GATEWAY.ENABLED` must be on; desktop does not add a gateway |
| `SkillCatalog` / `SkillDescriptor` | Shipped | **Unrelated.** Server DAG blocks. Do not extend for SKILL.md |
| Plugin prompt-pack plan | Planned | Server markdown prompts. Parallel epic. No shared tables |
| Saved Tasks + `MediaJob` | Shipped | UX pattern for out-of-band work (Sprint A3 copies the “queued” card) |
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
Synaplan web          Synaplan Desktop          (new repo, Phase B)
Channels → Desktop    chat + skills manager
  │                      │
  │  pairing code        │  scoped sk_*
  │  job authoring       │  local allowlist
  │                      │  SKILL.md + scripts
  └──────────┬───────────┘
             ▼
      Synaplan API                    ← all of this is Phase A
      /v1/messages   /mcp   /api/v1/desktop/*
             │
     ┌───────┼────────┬──────────┐
     ▼       ▼        ▼          ▼
   models   RAG    files     BDESKTOPJOBS
```

During Phase A the right-hand column is played by
`_devextras/testing/desktop/` (see [`03_phase_a3_jobs_and_checkin.md`](./03_phase_a3_jobs_and_checkin.md) §2.6).

**Who owns what**

| Owns | Synaplan server | Synaplan Desktop |
| ---- | --------------- | ---------------- |
| Account, budget, models, RAG | Yes | No |
| Pairing, device list, revoke | Yes | Consumes |
| Job *whether* and *when* | Yes (`next_call_at`) | Sleeps until then |
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

Full rules: [`11_security_and_compatibility.md`](./11_security_and_compatibility.md).

1. **Empty API-key scopes = legacy full access.** Existing Claude Code / n8n /
   `/v1` keys keep working. New desktop keys get `desktop:messages`,
   `desktop:mcp`, `desktop:jobs`, `desktop:files`.
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

**Server-first does not weaken this.** The enum, the ignored-extra-keys rule,
and the “device refuses unknown skills” rule are written into the Phase A
contract (decision 22) and re-tested on the client in Sprint B5. The harness
in Sprint A3 exists partly to prove the refusal path before a real device can.

---

## 6. Client product shape (v1) — Phase B

A small window, not a clone of Synaplan web:

1. **Sign in / pair** — instance URL + pairing code (or paste a scoped key
   for recovery).
2. **Chat** — one conversation at a time against `/v1/messages`, streaming.
   Optional “use my Synaplan sources” via `/mcp` (flag, default on once MCP
   tools are allowed for this key).
3. **Skills** — list installed, enable/disable, install from zip / folder /
   git URL, bundled `pptx`.
4. **This computer** — allowlisted folders (shown as **platform-native
   paths**), the out-box, a **Check this computer** readiness report, last
   check-in, revoke hint (“revoke from Synaplan on the web”).
5. **Tray** (Sprint B5) — stays running to poll, with opt-in autostart per OS.
   Until then, the window can be the only process.

Every one of these exists identically on Windows, macOS, and Linux (C10). A
platform difference is either hidden with a stated reason or it is a bug.

Do not embed the widget. Do not load `ChatView.vue` from the public repo as
a WebView of the whole app. A thin Vue shell is fine; the SPA is not the
client.

---

## 7. Server product shape (v1) — Phase A

**Channels → Desktop** (new child of Channels, four locales):

- Flag off: page explains the feature is off (admin) or hidden.
- Pair this computer (code + expiry).
- List devices: name, last seen, status, revoke.
- Sprint A3: “Jobs waiting” count; link into the chat that queued them.

No new top-level nav item. Follow `useNavItems` (desktop rail + mobile).
Mobile users see the pairing page as “install Synaplan Desktop on a
computer” — they cannot pair a phone as this client.

**Honesty while Phase B does not exist:** the pairing page must not offer a
download. Copy says the desktop app is a separate install and, until B1
ships a binary, that it is not available yet
([`12_ux_and_i18n.md`](./12_ux_and_i18n.md) §3.1).

---

## 8. API sketch (additive) — all in Phase A

All under `/api/v1/desktop/`, session **or** scoped API key, flag-gated.
Full OpenAPI on every route. Empty list / 404 when the flag is off (same
pattern as Saved Tasks: do not advertise the surface).

| Method | Path | Sprint | Purpose |
| ------ | ---- | ------ | ------- |
| `POST` | `/api/v1/desktop/pairing-codes` | A2 | Create 8-char code, Redis TTL 10 min |
| `POST` | `/api/v1/desktop/pair` | A2 | Code + device name → `sk_*` once + device id |
| `GET` | `/api/v1/desktop/devices` | A2 | Owner’s computers |
| `DELETE` | `/api/v1/desktop/devices/{id}` | A2 | Revoke device + its API key |
| `POST` | `/api/v1/desktop/jobs` | A3 | Web UI / chat queues `skill.run` |
| `POST` | `/mcp` tool `agent_checkin` | A3 | Jobs + `next_call_at` |
| `POST` | `/mcp` tool `agent_report_result` | A3 | Untrusted result |

REST job enqueue is for the web app (cookie session). The daemon only uses
MCP check-in / report so it stays a machine client.

---

## 9. Interaction with other tools

| Tool | Do | Do not |
| ---- | -- | ------ |
| Messages gateway | Require it; reuse admin copy | Fork a third completions API |
| MCP server | Desktop may call `/mcp` with the device key | Register the laptop as an MCP *server* (SSRF) |
| Saved Tasks | A later epic may enqueue `skill.run` | Run skills inside `DagExecutor` |
| Synamail / M365 | Document as the Outlook path | Bundle COM skills |
| Open plugin platform | Keep prompt-packs server-side | Install PHP plugins from the desktop |
| `synaplan-apps` | Ignore | Share signing / OTA / IAP |
| n8n | Still `/v1` + `/mcp` | Make n8n the skill runner |

---

## 10. Compatibility invariants

Named tests in [`09_testing_and_documentation.md`](./09_testing_and_documentation.md) §3.

| # | Invariant | Risk |
| - | --------- | ---- |
| C1 | Existing API keys with empty or legacy `webhooks:*` scopes keep full access after Sprint A1 | Scope listener too eager |
| C2 | `/v1/messages`, `/v1/chat/completions`, `/mcp` contracts stay additive | Pairing firewall |
| C3 | Routing / classifier characterization snapshots unchanged | No planner edits in this epic |
| C4 | Widget bundle never includes Desktop UI or job hooks | Shared i18n keys only if values, never widget namespace misuse |
| C5 | Mobile app behaviour unchanged; new server routes `backend-only` | Unclassified paths fail closed to store-required |
| C6 | OIDC / session login unchanged | `security.yaml` only gains `/api/v1/desktop` on existing API firewalls |
| C7 | M365 / Synamail / Saved Tasks unchanged | No shared table rewrites |
| C8 | **Phase A on `main` with the flag off is inert:** no nav item, `/api/v1/desktop/*` 404, no new MCP tools in `tools/list`, no cron doing work | Shipping a consumer-less feature (decision 21) |
| C9 | **The A3 contract does not change in Phase B.** Committed fixtures + `protocol: 1` are the client’s only input | Client convenience edits to a shipped queue (decision 22) |
| C10 | **Feature parity across Windows / macOS / Linux.** Any user-visible capability works on all three or is hidden with a stated reason. No “works on Linux, we will fix Windows later” in a merged PR | Linux-first drift (decision 25) |
| C11 | **The confinement corpus passes on all three runners in every PR.** Same cases, per-OS expected verdicts | A control tested on one OS is an assumption on the others (decisions 26, 28) |
| C12 | **No shell is ever constructed.** `sh -c` / `cmd /c` / `powershell -Command` appear nowhere in the client; grep-able CI check | A quoting bug becomes command injection (decision 29) |

---

## 11. Rollout

1. **Phase A merges to `main` incrementally with the flag off** (decision 21).
   Scope enforcement is live but grandfathered — existing keys do not shrink.
   Everything else is 404 / hidden (C8).
2. Sprint A3 exit is proved by the fake-device harness, not by a client.
   The contract is then frozen (`protocol: 1`, committed fixtures).
3. Phase B starts: create `synaplan-desktop`, pair against a dev instance
   with the flag on for one user.
4. Sprint B4: bundled `pptx` on Win/Mac/Linux (manual evidence per OS in the
   PR — [`13_cross_platform.md`](./13_cross_platform.md) §11).
5. Sprint B5: poll loop against the already-shipped A3 endpoints.
6. Sprint B6: signed installers. Certificates are ordered **during Phase A**;
   an unsigned build is a dev artefact, never a download link.
7. Seed `DESKTOP_AGENT.ENABLED = 1` for **new** installs only after Sprint B4
   is usable. Existing installs stay off until an admin flips the flag.
8. Rollback: flag off. Devices and jobs remain. Daemon idles (check-in
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
- Outlook / OS application control on any platform: Windows COM, macOS
  AppleScript, Linux (which has no Outlook application at all).
- iOS / Android desktop-agent (use `synaplan-apps` as today).
- Flatpak, Snap, winget, Homebrew Cask, Microsoft Store distribution, and
  client auto-update ([`13_cross_platform.md`](./13_cross_platform.md) §12).
- Windows-on-ARM and Linux-on-ARM **manual** verification (builds only).
- A permanent reference daemon in `synaplan/`. The harness is a test
  script, not a second product (decision 20).

---

## 13. Success criteria (epic)

**Phase A (server complete, no client):**

1. A `desktop:messages` key cannot call admin or `/mcp`; empty-scope keys
   behave exactly as before.
2. Pairing mints a narrow key; revoking a device 401s it on the next request.
3. The harness pairs a fake device, leases a `skill.run` job, reports a
   result, and the queuing chat shows a completion message.
4. The harness proves the refusal paths: unknown skill name → job failed,
   extra `command` key → ignored, foreign device → 404.
5. Flag off: web UI hides Desktop; `/api/v1/desktop/*` is 404; no new MCP
   tools; existing Synaplan behaviour unchanged (C8).
6. The contract is documented and fixture-frozen (C9).

**Phase B (client):**

7. A user pairs a computer without creating an unscoped key by hand, and the
   key lands in that OS's secret store (Credential Manager / Keychain /
   Secret Service).
8. They chat in Synaplan Desktop using only their Synaplan account.
9. They produce a `.pptx` with the bundled skill **on all three OSes**, each
   with manual evidence in the release PR
   ([`13_cross_platform.md`](./13_cross_platform.md) §11).
10. A zip skill with a path-escape (`../`) is refused — and so is a Windows
    junction escape, a UNC target, an alternate data stream, and a
    `/private`-firmlink mismatch on macOS. Same corpus, three runners.
11. A web-queued `skill.run` for an **uninstalled** name is refused on the
    device and marked failed on the server — no shell.
12. A German / Spanish / Turkish user can answer the five questions in
    [`12_ux_and_i18n.md`](./12_ux_and_i18n.md) §1 without English, with
    platform-native paths shown in their own UI.
13. The installer runs on all three OSes without a security warning a normal
    user cannot pass: Authenticode-signed on Windows, notarized and stapled on
    macOS (decision 32).

---

## 14. Workflow for each sprint

1. Read this file §0 (including §0.1) and the sprint file “code to read first”.
2. Take the next unfinished step from [`10_work_breakdown.md`](./10_work_breakdown.md).
   One PR, one concern.
3. Gate in [`09_testing_and_documentation.md`](./09_testing_and_documentation.md).
4. Update the breakdown status table when the step merges.

**Do not create `synaplan-desktop` before every Phase A step (DS1–DS18) is
merged** (decision 23). Two failure modes this ordering avoids: a daemon
holding a full-access key, and a half-designed queue being reshaped by
client deadlines.
