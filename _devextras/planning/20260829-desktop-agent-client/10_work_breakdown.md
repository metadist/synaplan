# Work breakdown — PR-sized steps

**Status:** Draft 2026-08-29, **reordered 2026-08-30 (server-first)**. The
sprint files say *what* and *why*. This file says *how big*, *in what order*,
and what “done” means.

Implement **one ID per PR** unless two S-sized steps are safer together
(say so in the PR).

**IDs changed with the reorder.** They are now prefixed by repo, so an ID can
never be ambiguous about where it lands:

| Prefix | Repo | Range |
| ------ | ---- | ----- |
| `DS` (Desktop Server) | `synaplan/` | `DS1`–`DS18` — **all of Phase A** |
| `DC` (Desktop Client) | `synaplan-desktop` | `DC1`–`DC21` — Phase B |
| `L`, `M`, `X` | cross-cutting | see §2 |

The old flat `D0`–`D35` numbering is retired. If you find a `D12`-style
reference in a stale doc or PR, map it through §12.

---

## 0. Status

Nothing implemented. Tick rows here when a step merges.

| Phase | Area | Steps | State |
| ----- | ---- | ----- | ----- |
| A | Sprint A1 — scopes + flag | `DS1`–`DS4` | Not started |
| A | Sprint A2 — pairing | `DS5`–`DS10` | Blocked on `DS1`–`DS4` |
| A | Sprint A3 — jobs, check-in, freeze | `DS11`–`DS18` | Blocked on `DS7` |
| — | **Phase A gate** | all `DS*` merged | **Blocks every `DC*`** |
| B | Sprint B1 — client repo + chat | `DC1`–`DC5` | Blocked on Phase A gate |
| B | Sprint B2 — skills runtime | `DC6`–`DC10` | Blocked on `DC4` |
| B | Sprint B3 — skills manager | `DC11`–`DC14` | Blocked on `DC7` |
| B | Sprint B4 — bundled pptx | `DC15`–`DC18` | Blocked on `DC11` |
| B | Sprint B5 — poll loop | `DC19`–`DC21` | Blocked on `DC15` + `DS14` |
| — | Cross-cutting | `L1`, `M0` | `L1` before `DS9` copy freezes |

---

## 1. Step-size rules

Same as Saved Tasks:

| Rule | Test |
| ---- | ---- |
| One PR, one concern | Title without “and” |
| Backend and frontend split unless trivial | Substantial `backend/src` + `frontend/src` → split |
| Client repo vs Synaplan repo | Never one PR across remotes |
| A migration is its own step | `DS5`, `DS11` |
| New interface before first impl | `DS1` before `DS2` |
| Every step ships tests | Gate in [`09_testing_and_documentation.md`](./09_testing_and_documentation.md) |
| Independently revertable | Product still coherent if only this PR reverts |
| Three acceptance bullets or it is not understood | Go back to the sprint file |

Size: **S** a few files, **M** one subsystem, **L** split unless justified.

### 1.1 Definition of done

See testing doc §6. Short version: unfiltered gate, tests, OpenAPI/locales
if needed, empty characterization diff, docs in the same PR.

### 1.2 The one hard ordering rule

**No `DC*` step starts before every `DS*` step is merged** (master plan
decision 23). This includes `DC1`, which creates the repository. There is no
“scaffold in parallel” exception; there is no “the queue is nearly done”
exception.

Inside Phase B, the only permitted `synaplan/` changes are the two
documentation steps `DC5` and `DC21`.

---

## 2. Cross-cutting (do not skip)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **L1** | Native-speaker pass on [`12_ux_and_i18n.md`](./12_ux_and_i18n.md) §2 | Docs | S | — | DE/ES/TR table ticked or corrected in that file |
| **M0** | Add Desktop paths to `.github/mobile-impact-policy.json` (`backend-only` for PHP, `ota-candidate` for Channels Vue) when the first file is added | synaplan | S | `DS2` or `DS9` | `node scripts/mobile-impact.mjs` would classify correctly; policy test green |
| **X1** | Retire the old `D0`–`D35` IDs anywhere they leaked (PR templates, issue tracker, other planning docs) | Docs | S | — | `rg -n "\bD[0-9]+\b"` in `_devextras/planning/20260829-*` returns nothing stale |

---

## 3. Phase A · Sprint A1 — scopes and flag (`synaplan/`)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **DS1** | `ApiKeyScope` constants + `isRestricted()` / `allows(path)` helpers. No listener yet | BE | S | — | Unit tests: empty, legacy webhooks, `*`, `desktop:messages` |
| **DS2** | Authenticator stores `api_key` on the request; `ApiKeyScopeSubscriber` enforces the prefix map | BE | M | `DS1` | Matrix in sprint A1 §2.3 green; existing empty-scope keys still pass `/v1` tests |
| **DS3** | `DESKTOP_AGENT.ENABLED` seeder + `DesktopAgentConfig` resolver (per-user → global → false) | BE | S | — | Missing row → false; user 1 beats global 0; insert-if-missing `0` |
| **DS4** | Docs paragraph on scoped vs legacy keys (`OPENAI_COMPATIBLE_API.md` / Messages gateway) | Docs | S | `DS2` | No claim that existing keys broke |

**Sprint A1 exit:** a `desktop:messages` key cannot call admin; grandfather
holds; flag exists and is off.

---

## 4. Phase A · Sprint A2 — pairing (`synaplan/`)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **DS5** | Galera-safe migration `BDESKTOPDEVICES` | BE | S | `DS3` | Fresh + existing DB; no Schema API; migrate idempotent |
| **DS6** | Pairing-code service (Redis TTL 10 min, rate limits) + `POST /pairing-codes` | BE | M | `DS3`, `DS5` | Flag off → 404; reuse after consume fails; codes not logged at info |
| **DS7** | `POST /pair` mints restricted key + device row; `GET/DELETE /devices` | BE | M | `DS2`, `DS6` | Key scopes exactly the four `desktop:*`; revoke → 401 on `/v1/models`; 404 other user’s id |
| **DS8** | Runtime config boolean `desktopAgentEnabled` + OpenAPI + generate-schemas | BE | S | `DS3` | False by default; true only when resolver says so |
| **DS9** | Channels → Desktop Vue: pair dialog, device table, revoke, nav child | FE | M | `DS7`, `DS8`, `L1`, `M0` | Hidden when flag off; four locales; dark + V2 + 320px; `useDialog`; **no download link** |
| **DS10** | `_devextras/testing/desktop/pair.sh` against the local stack | Test | S | `DS7` | Script documented; 200 on `/v1/models`, 403 on admin |

**Sprint A2 exit:** pair → scoped key → list → revoke, all flag-gated, proved
by `pair.sh` because no client exists yet.

---

## 5. Phase A · Sprint A3 — jobs, check-in, contract freeze (`synaplan/`)

The sprint the old plan put last. It is here so the contract is designed
without client deadline pressure (master plan §0.1, decision 22).

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **DS11** | Migration `BDESKTOPJOBS` | BE | S | `DS5` | Galera-safe; idempotent; raw `addSql` only |
| **DS12** | Job store: enqueue, lease, expire, idempotency (MediaJob-like) | BE | M | `DS11` | Two check-ins cannot lease the same row; fake-clock expiry |
| **DS13** | `POST /api/v1/desktop/jobs` + OpenAPI + schemas | BE | M | `DS7`, `DS12` | Flag off 404; foreign device 404; closed `type` enum |
| **DS14** | MCP `agent_checkin` + `agent_report_result` (`desktop:jobs`) | BE | M | `DS12`, `DS2` | `tools/list` superset with flag on, **unchanged** with flag off; bad lease 400; result size cap |
| **DS15** | `app:desktop:reap-jobs` + Redis lock | BE | S | `DS12` | Concurrent ticks: one runner; flag off → immediate no-op |
| **DS16** | Web: “Run on this computer” + waiting card + honest failed state (no planner hook) | FE | M | `DS13`, `DS9` | Hidden without devices; four locales; expired job shows failed, not a forever spinner |
| **DS17** | `fake-device.sh` harness: pair → check-in → lease → report, plus every refusal path in sprint A3 §2.6 | Test | M | `DS14`, `DS10` | All rows of the §2.6 table asserted; runs against local Docker |
| **DS18** | Contract freeze: `protocol: 1`, error-code enum, committed fixtures, `docs/DESKTOP.md` + Messages-gateway “Related” link | BE + Docs | M | `DS14`, `DS17` | Fixtures asserted against live OpenAPI/MCP schema; doc states the client is not released yet |

**Sprint A3 exit = Phase A gate.** Queue works end to end against the
harness; refusals fail closed; flag off is inert (C8); contract frozen (C9).

---

## 6. Phase B · Sprint B1 — client repo and chat (`synaplan-desktop`)

**Do not start any row here until every `DS*` above is merged.**

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **DC1** | **Create the repo**: Tauri 2 + Vue 3 + Makefile `ci-local` + CI on Linux + `AGENTS.md` + `docs/DEVELOPMENT.md`. Empty window | Client | M | **Phase A gate**, checklist 1–2, 23 | `make ci-local` green; `main` protected; no secrets |
| **DC2** | Pairing screen + keychain storage + host pin | Client | M | `DC1` | Reject non-https (except loopback); key not in plaintext fixture; wrong-code copy |
| **DC3** | Fixture Messages / pair server; vendor the frozen `DS18` fixtures | Client | S | `DC1` | Tests never call a real host; vendored fixtures byte-identical + source commit noted |
| **DC4** | Chat UI: stream `POST /v1/messages`, default model, 401/404 copy | Client | M | `DC2`, `DC3` | Mock SSE renders tokens; 401 → pair again |
| **DC5** | `docs/DESKTOP.md` install / pairing walkthrough (docs-only PR in **synaplan**) | Docs | S | `DC4` | Removes “not released yet”; still no download URL |

**Sprint B1 exit:** human evidence of one real turn; CI green; SPA not vendored.

---

## 7. Phase B · Sprint B2 — skills runtime (`synaplan-desktop`)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **DC6** | Rust path confinement + `config.toml` defaults + deny globs | Client | M | `DC1` | Symlink-escape and `~/.ssh` tests fail the PR if confinement is removed |
| **DC7** | SKILL.md scanner + frontmatter validation + fixture `hello-files` | Client | S | `DC6` | Bad name/dir skipped; valid skill listed |
| **DC8** | This-computer UI: add/remove read/write folders (four locales) | Client | M | `DC6`, `L1` | Deny list not user-removable in v1 (or confirm if we allow) |
| **DC9** | Messages tool definitions `Read` / `Write` / `Bash` + iteration cap | Client | M | `DC4`, `DC6`, `DC7` | Mock `tool_use` Write creates a file in the out-box |
| **DC10** | First-Bash confirm dialog; API key absent from subprocess env | Client | S | `DC9` | Env test; cancel → no process |

**Sprint B2 exit:** fixture skill writes a file through the mock loop; escape
tests on.

---

## 8. Phase B · Sprint B3 — skills manager (`synaplan-desktop`)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **DC11** | Skills page + `skills.json` enable/disable + bundled immutable | Client | M | `DC7` | Disable drops skill from catalog preface |
| **DC12** | Install from folder + zip (zip-slip, symlink, root SKILL.md rejected) | Client | M | `DC11` | Malicious zip tests in CI |
| **DC13** | Install from https Git/GitHub URL, pin SHA, reject `file://` | Client | M | `DC12` | SHA stored; no auto-update |
| **DC14** | Supply-chain confirm copy ×4 | Client | S | `DC11`, `L1` | Dialog required before enable |

**Sprint B3 exit:** reviewer installs the fixture from a zip in the UI.

---

## 9. Phase B · Sprint B4 — first skills (`synaplan-desktop`)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **DC15** | Vendor official `pptx` at a pinned SHA + `docs/BUNDLED_SKILLS.md` + NOTICE | Client | M | `DC11` | Parses; license present; not live-fetched |
| **DC16** | Doctor: detect python/node/soffice; block skill if python missing | Client | S | `DC15` | Missing python → not offered to the model |
| **DC17** | Tighten Bash allowlist (python, node, soffice, skill scripts); deny curl | Client | S | `DC9`, `DC15` | Fixture `curl` denied |
| **DC18** | Hermetic “write minimal pptx” CI script + manual evidence template | Client | M | `DC15`, `DC16` | Linux CI green without LibreOffice; PR template lists OS matrix |

**Sprint B4 exit:** bundled pptx ready; Outlook COM not shipped; docs honest.
This is also the gate for seeding `DESKTOP_AGENT.ENABLED = 1` on new installs.

---

## 10. Phase B · Sprint B5 — poll loop (`synaplan-desktop`)

The server half shipped in Sprint A3. Nothing here changes it (C9).

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **DC19** | Poll loop: `protocol: 1` check-in, lease, run, report; ignore unknown input keys; `unknown_skill` / `unknown_type` / `skill_disabled` | Client | M | `DC9`, `DC15`, `DS14` | Mock job without installed skill → failed, no Bash; `command` key never reaches argv |
| **DC20** | Per-skill `allowUnattended` + OS notification | Client | S | `DC19` | Default false; first unattended run notifies |
| **DC21** | End-to-end evidence + `docs/DESKTOP.md` queue walkthrough (docs-only PR in **synaplan**) | Client + Docs | S | `DC20` | Screenshots of both ends, success and refusal |

**Sprint B5 exit:** web queue → desktop pptx → chat file message; uninstalled
name fails closed.

**Not in v1 (follow-ups, do not sneak in):** tray-only daemon, Centrifugo
wake-up, planner-emitted jobs, `file.read` enum, brogent, platform cron
script, git auto-update, pip install button, `protocol: 2`.

---

## 11. Suggested calendar (not a commitment)

| Week | Steps | Repo | Note |
| ---- | ----- | ---- | ---- |
| 1 | `DS1`–`DS4` | synaplan | Security only; shippable alone |
| 2 | `DS5`–`DS10` | synaplan | Pairing usable with `pair.sh` |
| 3 | `DS11`–`DS15` | synaplan | Queue + MCP tools, flag off |
| 4 | `DS16`–`DS18` | synaplan | Web action, harness, **freeze** → Phase A done |
| 5 | `DC1`–`DC5` | desktop | Repo is born; first chat |
| 6 | `DC6`–`DC10` | desktop | Runtime |
| 7 | `DC11`–`DC14` | desktop | Manager |
| 8 | `DC15`–`DC18` | desktop | pptx vertical |
| 9 | `DC19`–`DC21` | desktop | Poll loop against a queue that already works |

Phase A is four weeks of `synaplan/` PRs that a reviewer can read without
context-switching repos. Phase B is five weeks with no server dependency.

**If scope slips, cut the queue** (`DS11`–`DS18` and `DC19`–`DC21`) before
cutting scopes, pairing, or path confinement (decision 24). Because the queue
is the *last* thing in Phase A, cutting it leaves a coherent product: pair a
computer, chat, make slides. Polling is the extra.

Cutting mid-Phase-A is also safe: every step merged with the flag off, so a
half-built queue on `main` is invisible (C8).

---

## 12. Old → new ID map

For PRs, notes, and links written before 2026-08-30.

| Old | New | Old | New |
| --- | --- | --- | --- |
| `D0` | `DS1` | `D18` | `DC9` |
| `D1` | `DS2` | `D19` | `DC10` |
| `D2` | `DS3` | `D20` | `DC11` |
| `D3` | `DS4` | `D21` | `DC12` |
| `D4` | `DS5` | `D22` | `DC13` |
| `D5` | `DS6` | `D23` | `DC14` |
| `D6` | `DS7` | `D24` | `DC15` |
| `D7` | `DS8` | `D25` | `DC16` |
| `D8` | `DS9` | `D26` | `DC17` |
| `D9` | `DS10` | `D27` | `DC18` |
| `D10` | `DC1` | `D28` | `DS11` |
| `D11` | `DC2` | `D29` | `DS12` |
| `D12` | `DC3` | `D30` | `DS13` |
| `D13` | `DC4` | `D31` | `DS14` |
| `D14` | `DC5` (moved: the stub is now `DS18`) | `D32` | `DS15` |
| `D15` | `DC6` | `D33` | `DS16` |
| `D16` | `DC7` | `D34` | `DC19` |
| `D17` | `DC8` | `D35` | `DC20` |

New steps with no old equivalent: `DS17` (harness), `DS18` (contract freeze +
docs), `DC21` (end-to-end evidence), `X1` (ID cleanup).

---

## 13. What was easy to conflate (do not re-merge)

| Temptation | Why it is wrong | Keep split |
| ---------- | --------------- | ---------- |
| “Wrap the web app in Electron” | No local tools; not a skill runtime | `DC1` thin client |
| “Reuse synaplan-apps” | Store / OTA / IAP rules | New repo |
| “Install Agent37 Cloud” | Third-party agent | Catalog only |
| “Planner calls Bash on the laptop” | Prompt injection | `skill.run` + confirm |
| “Outlook COM in v1” | Linux + Synamail overlap | Docs, not a bundle |
| “Scopes later” | Stolen laptop = full account | `DS1`–`DS2` first |
| “Start the repo now, it is only a scaffold” | The scaffold becomes a reason to interleave; server PRs then land under client pressure | `DC1` after the Phase A gate |
| “Tweak the queue while writing the client” | The frozen contract is the only thing stopping a `command` field | `protocol: 2` or nothing (C9) |
