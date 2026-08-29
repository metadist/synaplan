# Work breakdown — PR-sized steps

**Status:** Draft 2026-08-29. The sprint files say *what* and *why*. This
file says *how big*, *in what order*, and what “done” means.

Implement **one ID per PR** unless two S-sized steps are safer together
(say so in the PR). IDs are `D` (Desktop) so they do not collide with
Saved Tasks `E`/`F`/`K`.

---

## 0. Status

Nothing implemented. Tick rows here when a step merges.

| Area | Steps | State |
| ---- | ----- | ----- |
| Sprint 0 | D0–D3 | Not started |
| Sprint 1 | D4–D9 | Blocked on D0–D3 |
| Sprint 2 | D10–D14 | Blocked on D8 |
| Sprint 3 | D15–D19 | Blocked on D13 |
| Sprint 4 | D20–D23 | Blocked on D17 |
| Sprint 5 | D24–D27 | Blocked on D20 |
| Sprint 6 | D28–D35 | Blocked on D8 + D24 |
| Cross-cutting | L1, M0 | L1 before D7 copy freezes |

---

## 1. Step-size rules

Same as Saved Tasks:

| Rule | Test |
| ---- | ---- |
| One PR, one concern | Title without “and” |
| Backend and frontend split unless trivial | Substantial `backend/src` + `frontend/src` → split |
| Client repo vs Synaplan repo | Never one PR across remotes |
| A migration is its own step | D4, D28 |
| New interface before first impl | D0 before D1 |
| Every step ships tests | Gate in [`08_testing_and_documentation.md`](./08_testing_and_documentation.md) |
| Independently revertable | Product still coherent if only this PR reverts |
| Three acceptance bullets or it is not understood | Go back to the sprint file |

Size: **S** a few files, **M** one subsystem, **L** split unless justified.

### 1.1 Definition of done

See testing doc §6. Short version: unfiltered gate, tests, OpenAPI/locales
if needed, empty characterization diff, docs in the same PR.

---

## 2. Cross-cutting (do not skip)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **L1** | Native-speaker pass on [`11_ux_and_i18n.md`](./11_ux_and_i18n.md) §2 | Docs | S | — | DE/ES/TR table ticked or corrected in that file |
| **M0** | Add Desktop paths to `.github/mobile-impact-policy.json` (`backend-only` for PHP, `ota-candidate` for Channels Vue) when the first file is added | synaplan | S | D2 or D7 | `node scripts/mobile-impact.mjs` would classify correctly; policy test green |

---

## 3. Sprint 0 — scopes and flag (`synaplan/`)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **D0** | `ApiKeyScope` constants + `isRestricted()` / `allows(path)` helpers. No listener yet | BE | S | — | Unit tests: empty, legacy webhooks, `*`, `desktop:messages` |
| **D1** | Authenticator stores `api_key` on the request; `ApiKeyScopeSubscriber` enforces the prefix map | BE | M | D0 | Matrix in sprint 0 §2.3 green; existing empty-scope keys still pass `/v1` tests |
| **D2** | `DESKTOP_AGENT.ENABLED` seeder + `DesktopAgentConfig` resolver (per-user → global → false) | BE | S | — | Missing row → false; user 1 beats global 0; insert-if-missing `0` |
| **D3** | Docs paragraph on scoped vs legacy keys (`OPENAI_COMPATIBLE_API.md` / Messages gateway) | Docs | S | D1 | No claim that existing keys broke |

**Sprint 0 exit:** a `desktop:messages` key cannot call admin; grandfather
holds; flag exists and is off.

---

## 4. Sprint 1 — pairing (`synaplan/`)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **D4** | Galera-safe migration `BDESKTOPDEVICES` | BE | S | D2 | Fresh + existing DB; no Schema API; migrate idempotent |
| **D5** | Pairing-code service (Redis TTL 10 min, rate limits) + `POST /pairing-codes` | BE | M | D2, D4 | Flag off → 404; reuse after consume fails; codes not logged at info |
| **D6** | `POST /pair` mints restricted key + device row; `GET/DELETE /devices` | BE | M | D1, D5 | Key scopes exactly the four `desktop:*`; revoke → 401 on `/v1/models`; 404 other user’s id |
| **D7** | Runtime config boolean `desktopAgentEnabled` + OpenAPI + generate-schemas | BE | S | D2 | False by default; true only when resolver says so |
| **D8** | Channels → Desktop Vue: pair dialog, device table, revoke, nav child | FE | M | D6, D7, L1, M0 | Hidden when flag off; four locales; dark + V2 + 320px; `useDialog` |
| **D9** | `_devextras/testing/desktop/pair.sh` against local stack | Test | S | D6 | Script documented; 200 on `/v1/models`, 403 on admin |

**Sprint 1 exit:** pair → scoped key → list → revoke, all flag-gated.

---

## 5. Sprint 2 — extra client repo (`synaplan-desktop`)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **D10** | Create repo: Tauri 2 + Vue 3 + Makefile `ci-local` + CI on Linux + `AGENTS.md` + `docs/DEVELOPMENT.md`. Empty window | Client | M | checklist 1–2 | `make ci-local` green; `main` protected; no secrets |
| **D11** | Pairing screen + keychain storage + host pin | Client | M | D8, D10 | Reject non-https (except loopback); key not in plaintext fixture; wrong code copy |
| **D12** | Fixture Messages / pair server for unit tests | Client | S | D10 | Tests never call a real host |
| **D13** | Chat UI: stream `POST /v1/messages`, default model, 401/404 copy | Client | M | D11, D12 | Mock SSE renders tokens; 401 → pair again |
| **D14** | `docs/DESKTOP.md` stub in **synaplan** + Related link from Messages gateway doc | Docs | S | D13 | No download URL yet; no Claude-as-requirement |

**Sprint 2 exit:** human evidence of one real turn; CI green; SPA not vendored.

---

## 6. Sprint 3 — skills runtime (`synaplan-desktop`)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **D15** | Rust path confinement + config.toml defaults + deny globs | Client | M | D10 | Symlink-escape and `~/.ssh` tests fail the PR if confinement is removed |
| **D16** | SKILL.md scanner + frontmatter validation + fixture `hello-files` | Client | S | D15 | Bad name/dir skipped; valid skill listed |
| **D17** | This-computer UI: add/remove read/write folders (four locales) | Client | M | D15, L1 | Deny list not user-removable in v1 (or confirm if we allow) |
| **D18** | Messages tool definitions `Read` / `Write` / `Bash` + iteration cap | Client | M | D13, D15, D16 | Mock `tool_use` Write creates a file in the out-box |
| **D19** | First-Bash confirm dialog; API key absent from subprocess env | Client | S | D18 | Env test; cancel → no process |

**Sprint 3 exit:** fixture skill writes a file through the mock loop; escape tests on.

---

## 7. Sprint 4 — skills manager (`synaplan-desktop`)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **D20** | Skills page + `skills.json` enable/disable + bundled immutable | Client | M | D16 | Disable drops skill from catalog preface |
| **D21** | Install from folder + zip (zip-slip, symlink, root SKILL.md rejected) | Client | M | D20 | Malicious zip tests in CI |
| **D22** | Install from https Git/GitHub URL, pin SHA, reject `file://` | Client | M | D21 | SHA stored; no auto-update |
| **D23** | Supply-chain confirm copy ×4 | Client | S | D20, L1 | Dialog required before enable |

**Sprint 4 exit:** reviewer installs the fixture from a zip in the UI.

---

## 8. Sprint 5 — first skills (`synaplan-desktop`, tiny synaplan docs)

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **D24** | Vendor official `pptx` at a pinned SHA + `docs/BUNDLED_SKILLS.md` + NOTICE | Client | M | D20 | Parses; license present; not live-fetched |
| **D25** | Doctor: detect python/node/soffice; block skill if python missing | Client | S | D24 | Missing python → not offered to the model |
| **D26** | Tighten Bash allowlist (python, node, soffice, skill scripts); deny curl | Client | S | D18, D24 | Fixture `curl` denied |
| **D27** | Hermetic “write minimal pptx” CI script + manual evidence template | Client | M | D24, D25 | Linux CI green without LibreOffice; PR template lists OS matrix |

**Sprint 5 exit:** bundled pptx ready; Outlook COM not shipped; docs honest.

---

## 9. Sprint 6 — check-in (`synaplan/` then `synaplan-desktop`)

Land **server steps before** client poll.

| ID | Step | Layer | Size | Depends | Acceptance |
| -- | ---- | ----- | ---- | ------- | ---------- |
| **D28** | Migration `BDESKTOPJOBS` | BE | S | D4 | Galera-safe; idempotent |
| **D29** | Job store: enqueue, lease, expire, idempotency (MediaJob-like) | BE | M | D28 | Two check-ins cannot lease the same row; fake clock expiry |
| **D30** | `POST /api/v1/desktop/jobs` + OpenAPI + schemas | BE | M | D6, D29 | Flag off 404; foreign device 404; type enum |
| **D31** | MCP `agent_checkin` + `agent_report_result` (`desktop:jobs`) | BE | M | D29, D1 | `tools/list` superset; bad lease 400; result size cap |
| **D32** | `app:desktop:reap-jobs` + Redis lock | BE | S | D29 | Concurrent ticks: one runner |
| **D33** | Web: “Run on this computer” + waiting card (no planner hook) | FE | M | D30, D8 | Hidden without devices; four locales |
| **D34** | Desktop poll loop + ignore unknown input keys + `unknown_skill` | Client | M | D18, D31 | Mock job without installed skill → failed, no Bash |
| **D35** | Per-skill `allowUnattended` + OS notification | Client | S | D34 | Default false; first job notifies |

**Sprint 6 exit:** manual web queue → desktop pptx → chat file message;
uninstalled name fails closed.

**Not in v1 (follow-ups, do not sneak in):** tray-only daemon, Centrifugo
wake-up, planner-emitted jobs, `file.read` enum, brogent, platform cron
script, git auto-update, pip install button.

---

## 10. Suggested calendar (not a commitment)

| Week | Steps | Note |
| ---- | ----- | ---- |
| 1 | D0–D3 | Security only; shippable alone |
| 2 | D4–D9 | Pairing usable with `pair.sh` |
| 3 | D10–D14 | Extra repo + first chat |
| 4 | D15–D19 | Runtime |
| 5 | D20–D23 | Manager |
| 6 | D24–D27 | pptx vertical |
| 7–8 | D28–D35 | Queue; two PRs sequenced |

If scope slips, **cut Sprint 6** before cutting Sprint 0 or confinement
tests. A desktop that chats and makes slides is already the product.
Polling is the extra.

---

## 11. What was easy to conflate (do not re-merge)

| Temptation | Why it is wrong | Keep split |
| ---------- | --------------- | ---------- |
| “Wrap the web app in Electron” | No local tools; not a skill runtime | D10 thin client |
| “Reuse synaplan-apps” | Store / OTA / IAP rules | New repo |
| “Install Agent37 Cloud” | Third-party agent | Catalog only |
| “Planner calls Bash on the laptop” | Prompt injection | `skill.run` + confirm |
| “Outlook COM in v1” | Linux + Synamail overlap | Docs, not a bundle |
| “Scopes later” | Stolen laptop = full account | D0–D1 first |
