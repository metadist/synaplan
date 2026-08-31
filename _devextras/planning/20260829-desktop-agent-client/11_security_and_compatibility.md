# Security and compatibility

Binding for every sprint. The July 2026 local-agent research is the
threat-model source; this file is the checklist used in PR review.

**Platform-specific controls live in
[`13_cross_platform.md`](./13_cross_platform.md)** (§3 confinement, §4 secret
storage, §6 process execution). This file states *what* must hold; that file
states *how* it holds on each OS. A control that only holds on Linux does not
hold.

---

## 1. Threat directions

The dangerous direction is **not** “a bad laptop attacks Synaplan”
(scopes + rate limits handle that). It is:

> The server, or a prompt-injected model, must not make the laptop do
> arbitrary things.

Job payloads and tool_use blocks are model-influenced. Community skill
scripts are attacker-controlled once the user installed them. Design for
both.

---

## 2. API keys

| Rule | Detail |
| ---- | ------ |
| Grandfather | Empty scopes and legacy `webhooks:*` lists remain full access |
| Desktop keys | Pairing mints only `desktop:messages`, `desktop:mcp`, `desktop:files`, `desktop:jobs` |
| Enforcement | Central listener (Sprint A1). Desktop key cannot hit `/api/v1/admin/*`, user admin, webhooks |
| Revoke | Deleting a device revokes the key. 401 on next call; the client **deletes** its stored copy |
| Storage | The OS secret store — Windows Credential Manager, macOS Keychain, Linux Secret Service. Never log the secret. Shown once at pair. Headless Linux: opt-in, warned plaintext fallback only, and the unattended poll loop refuses to run with it |
| Scopes unused today | `hasScope()` is dead code until Sprint A1 — **that sprint is a security fix**, not a feature |

Stolen laptop: user revokes the device on the web. That is the recovery
story. Do not ship pairing before Sprint A1 — and note that the server-first
order makes this automatic: scopes are the first steps of the epic, and no
key can reach a laptop for weeks afterwards because no laptop client exists.

---

## 3. Filesystem allowlist (client authority)

1. Config file / UI on the machine is the source of truth.
2. Server cannot add roots.
3. Canonicalize **then** contain — by path component, never string prefix —
   and re-canonicalize at use, not only at intake (TOCTOU).
4. Deny globs always apply (`.ssh`, `.env`, keys, `.git/config`, `.aws`,
   `.kube`, `AppData`, `Library/Keychains`).
5. Skill dir is readable; writes go to the out-box (or user write
   roots) only.
6. Archive/git install: reject `..`, **backslash separators**, symlinks and
   hardlinks, alternate data streams, reserved device names, case-colliding
   entries, zip bombs, and a bare SKILL.md at archive root. Extraction is
   atomic (temp → validate → rename).

**Escape tests are mandatory and run on all three OSes** (C11, Sprints B2–B3).
The POSIX symlink case alone covers roughly a third of the surface and none of
the Windows part: junctions and reparse points need no admin rights, `..\` is
inert on Linux and an escape on Windows, and on macOS a root stored as `/tmp/x`
never matches the canonical `/private/tmp/x`. Full corpus and expected
verdicts: [`13_cross_platform.md`](./13_cross_platform.md) §3.

---

## 4. What may run locally

| Source | Allowed? |
| ------ | -------- |
| User-typed chat + installed skill + model-emitted `Bash` | Yes, after Sprint B2 policy / confirm |
| `skill.run` job with `{skill, prompt}` for an enabled skill | Yes, Sprint B5, unattended opt-in per skill |
| Server field `command` / `script` / `argv` | **Never.** Ignore extra keys |
| Job type other than `skill.run` | Refuse |
| Skill name not installed / disabled | Refuse, report `unknown_skill` |
| Community skill `install.sh` | Never auto-run |
| A shell — `sh -c`, `cmd /c`, `powershell -Command`, `osascript -e` | **Never constructed, on any platform** (C12) |
| Subprocess environment | No `sk_`, no pairing code, and none of `LD_PRELOAD`, `LD_LIBRARY_PATH`, `DYLD_INSERT_LIBRARIES`, `PYTHONPATH`, `PYTHONSTARTUP`, `NODE_OPTIONS`, `PSModulePath` |

Sprint B4 binary allowlist: the doctor's **resolved absolute paths** for
Python, Node, and LibreOffice, plus the invoking skill's own scripts launched
by one of those. A bare name is never allowlistable, because `PATH` is
attacker-influenced. Default deny everything else, including `curl`, `wget`,
`ssh`, PowerShell, `cmd`, `wscript`/`cscript`, `mshta`, `rundll32`,
`regsvr32`, and `osascript`.

Two execution rules that only become visible on a non-Linux box, and are
therefore easy to miss in review:

- **Timeouts must kill the process tree**, not the direct child. A Windows Job
  Object or a POSIX process group — otherwise a "killed" skill leaves
  grandchildren running with access to the out-box.
- **`npm`/`npx` on Windows are `.cmd` scripts**, i.e. shell scripts. Launching
  them as a bare executable reintroduces the shell the policy just banned.

**Server-first consequence:** the “never execute a server-supplied command”
rule is written into the frozen contract in Sprint A3 (`DS18`) and asserted by
the harness before a device exists, so the client inherits it as a
specification rather than discovering it during review.

---

## 5. Skills as supply chain

- Installing = executing later. Confirm with license + file list.
- Pin git installs to a SHA.
- No auto-update from the network.
- Bundled skills: Apache-2.0 (or compatible), reviewed, SHA in
  `docs/BUNDLED_SKILLS.md`.
- Agent37 / random GitHub: user-initiated, no Synaplan blessing in copy.
- Results re-ingested into RAG: size cap, MIME allowlist, provenance.

---

## 6. Network

- Desktop → Synaplan HTTPS only (http = loopback for dev).
- Pin pairing host; no redirect to another host.
- Skill scripts: network **off** unless a later skill declares it and
  the user opts in (pptx does not need it).
- Do not register the laptop as an inbound MCP server (`SsrfGuard`
  would block it; tunnels are out of v1).

---

## 7. Product compatibility (do not break Synaplan)

| Surface | Rule |
| ------- | ---- |
| Widget | No Desktop code paths |
| Mobile | `backend-only` / `ota-candidate` classification; no store-required |
| `/v1` `/mcp` | Additive tools and headers only |
| Routing snapshots | Empty diff |
| M365 / Synamail | Still the Outlook product; desktop does not steal OAuth |
| Messages gateway | Required, not replaced |
| Plugin prompt-packs | Separate epic; no shared installer |

---

## 8. Privacy and logging

- Do not log file contents, prompts, or pairing codes at info.
- Local audit (July §2.7): device-side log of paths touched and
  commands run (hashes / argv, not file bodies), at the platform log path
  from [`13_cross_platform.md`](./13_cross_platform.md) §2, with rotation.
  Server-side: job id, device, skill name, status — not the deck contents.
- The audit log is written with user-only permissions (`0600` on POSIX, the
  user's ACL on Windows) — it records which files a skill touched, which is
  itself sensitive.
- GDPR: revoke + delete device row; local files stay on the user’s
  disk (they own them). Uninstalling removes the app and its autostart entry,
  never the user's out-box.

---

## 9. PR review checklist (paste into the PR)

- [ ] Flag off leaves existing behaviour
- [ ] New API key is restricted (or this PR does not mint keys)
- [ ] No `shell.exec` / server-supplied command
- [ ] Path or zip tests added if I/O changed
- [ ] Characterization diff empty
- [ ] Locales ×4 if copy changed
- [ ] Mobile policy updated if new `synaplan/` paths
- [ ] No secrets in the diff
- [ ] Docs updated in this PR
- [ ] `DS*`: flag-off behaviour asserted; harness updated if device-facing
- [ ] `DC*`: no `synaplan/` files (except `DC5` / `DC21` docs) and no frozen
  fixture was edited
- [ ] `DC*`: green on **all three** runners (C10, C11)
- [ ] `DC*`: no shell constructed; the grep guard still passes (C12)
- [ ] `DC*`: any platform branch is inside `src-tauri/src/platform/`, and any
  deliberate platform difference is named with its reason
- [ ] `DC*`: new I/O added a case to `tests/confinement/cases.toml` with a
  per-platform expected verdict
