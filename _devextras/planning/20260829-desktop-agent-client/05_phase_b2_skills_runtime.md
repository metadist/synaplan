# Sprint B2 — Agent Skills runtime

**Phase B (`synaplan-desktop`), sprint 2 of 5.** Steps `DC6`–`DC10`.

**Goal:** The desktop client loads installed `SKILL.md` folders, puts name +
description in the model context, and when the model asks for tools, runs
**Read / Write / Bash** only inside the user allowlist and the skill
directory.
**Depends on:** Sprint B1 chat and `app_dirs` (`DC22`). Checklist rows 4, 8, 10,
and **26, 28, 29**.
**Unlocks:** Sprint B3 (manager) and Sprint B4 (bundled pptx).
**Repos:** `synaplan-desktop` only — no `synaplan/` PR belongs in this sprint.
**Platform rules:** [`13_cross_platform.md`](./13_cross_platform.md) §§3 and 6
are binding here and are the detailed specification behind §2.2 and §2.5.

This is the first sprint that can execute local programs. Treat it as a
security sprint that happens to enable skills.

**The confinement module is a three-OS deliverable, not a POSIX one.** The
original draft had exactly one escape test — the POSIX symlink — which covers
roughly a third of the real attack surface and none of the Windows-specific
part. Confinement written and tested only on Linux is not a control on Windows;
it is an assumption. `DC24` is where that is fixed, and it is not optional
scope.

---

## 0. Why this sprint exists

Agent Skills are not prompts you paste. They are progressive disclosure:

1. Always: `name` + `description` (~100 tokens each).
2. On trigger: full `SKILL.md` body.
3. On demand: `references/`, `scripts/`, `assets/`.

The Messages gateway already relays client tools. The desktop must *be*
those tools, with names the ecosystem uses (`Read`, `Write`, `Bash`,
optionally `Edit` / `Glob` / `Grep` if cheap). Implement the minimum the
official `pptx` skill needs: Read, Write, Bash (python, node, soffice).

---

## 1. Current code / specs to read first

| Source | Why |
| ------ | --- |
| [agentskills.io specification](https://agentskills.io/specification) | Frontmatter, progressive disclosure |
| Official `pptx` SKILL.md (Apache-2.0) | Tool names and scripts we must support |
| `docs/ANTHROPIC_COMPATIBLE_API.md` | Client tool loop; mixed turns stay with the client |
| July local-agent §2.1–2.2 | `realpath` then contain; no eval-shaped payload from the *server* |
| [`13_cross_platform.md`](./13_cross_platform.md) §3, §6 | Per-OS confinement hazards and the no-shell execution rule |

---

## 2. Developer steps

### 2.1 Local config (authority)

The config file lives at the `app_dirs` config path (`DC22`) — on Windows
`%APPDATA%\Synaplan\Desktop\config.toml`, on macOS under
`~/Library/Application Support/com.synaplan.desktop/`, on Linux under
`$XDG_CONFIG_HOME`. It is user-edited from the **This computer** screen.

```toml
[filesystem]
# Written by the app as absolute, canonicalized, platform-native paths.
# Shown here in POSIX shorthand for readability only.
read  = ["<known:documents>", "<known:downloads>"]
write = ["<known:home>/Synaplan/out"]
deny  = ["**/.ssh/**", "**/.env", "**/*.key", "**/.git/config", "**/id_rsa",
         "**/.aws/**", "**/.kube/**", "**/AppData/**", "**/Library/Keychains/**"]
max_file_bytes = 10000000

[skills]
dir = "<app_dirs:skills>"

[process]
timeout_seconds = 120
# Binary allowlist arrives in Sprint B4; absolute resolved paths only.
```

Rules:

1. **Roots are stored canonicalized and absolute**, resolved at the moment the
   user picks the folder — not re-expanded at check time. On macOS this is what
   stops a `/tmp/x` root from never matching the canonical `/private/tmp/x`.
2. **Known folders resolve through the OS API**, never by string. Windows
   Known Folder Move puts `Documents` under OneDrive; macOS may relocate
   `Documents` into iCloud Drive; Linux has `user-dirs.dirs`
   ([`13_cross_platform.md`](./13_cross_platform.md) §7). `DC25` owns this.
3. Defaults: the out-box is created on first run; **read roots start empty**
   until the user adds one. The deny list always applies after resolution and
   is not user-removable in v1.
4. Never write `~` into the config, and never render it in the UI on Windows.

### 2.2 Path confinement (Rust) — `DC6` + `DC24`

One module in `src-tauri/src/platform/`, table-driven, executed on all three
runners. Do not implement confinement in JavaScript.

Algorithm, applied to **every** candidate path from the model, a script
argument, an archive entry, or the config:

1. Reject empty paths, NUL bytes, and control characters.
2. Canonicalize with the platform resolver. This resolves POSIX symlinks,
   **Windows junctions and reparse points**, and **macOS firmlinks**.
3. Normalize: Unicode to NFC everywhere; case-fold on Windows and macOS,
   byte-exact on Linux; reject the platform-specific forms in the table below.
4. Contain by **path components**, never string prefix — `Documents2` must not
   match the root `Documents`.
5. Apply deny globs against the canonical path.
6. Writes additionally require a write root; the skill working directory is
   `<out-box>/{skill}/{runId}`.
7. **Re-canonicalize at use, not only at intake**, and prefer opening by handle
   and re-verifying, so a TOCTOU swap between check and open does not win.

The skill's own directory is implicitly readable and its scripts are executable
by an allowlisted interpreter (§2.5) — it is not implicitly writable.

#### The escape corpus (`tests/confinement/cases.toml`)

One shared file; each case names the platforms it applies to and its expected
verdict. Full rationale per row in
[`13_cross_platform.md`](./13_cross_platform.md) §3.

| Case | Platforms | Expected |
| ---- | --------- | -------- |
| POSIX symlink out of the root | mac, linux | deny |
| **Directory junction** out of the root (`mklink /J`, no admin needed) | win | deny |
| Unprivileged symlink with Developer Mode | win | deny |
| UNC path `\\host\share`, `\\?\UNC\…`, `\\.\PhysicalDrive0` | win | deny |
| Mapped network drive | win | deny |
| Drive-relative `C:foo` | win | deny |
| 8.3 short name `DOCUME~1` | win | deny pre-resolution, contained after |
| Alternate data stream `file.txt:evil` | win | deny |
| Reserved device name `CON`, `NUL`, `COM1`, `LPT1` | win | deny |
| Trailing dot / trailing space component | win | deny |
| Path over 260 characters | win | works via `\\?\`, clear error if not |
| `/private` firmlink mismatch (`/tmp` vs `/private/tmp`) | mac | allow (roots canonicalized) |
| NFD vs NFC filename | mac, linux | same file, allowed |
| Case-differing path on a case-insensitive volume | win, mac | allowed |
| Case-differing path on a case-sensitive volume | linux | denied (different file) |
| `/proc/self/environ`, `/dev/fd/…` | linux | deny |
| Sibling-prefix `…/Documents2` against root `…/Documents` | all | deny |
| Deny-glob hit inside an allowed root (`.ssh/id_rsa`) | all | deny, zero bytes read |
| TOCTOU: path swapped for a link between check and open | all | deny |
| Write into a read-only root | all | deny |

A PR that changes the confinement module without touching this corpus is
incomplete (testing doc principle 11).

### 2.3 Skill loader

Scan `{skills.dir}/*/SKILL.md` + `skills/bundled/*/SKILL.md`.

- Parse YAML frontmatter (`name`, `description` required).
- `name` must match the directory name (spec). The spec restricts names to
  lowercase, digits, and hyphens, which conveniently sidesteps most
  case-sensitivity trouble — **enforce it**, and treat a directory that differs
  only by case as invalid rather than silently accepting it on Windows/macOS.
- Validate with the same rules as agentskills.io (length, charset).
- Invalid skill: skip + visible error on the Skills page, do not crash.
- Two skills whose names collide under case folding: both are shown as
  conflicting and neither is loaded. Detect this on every platform, not only
  where the filesystem happens to reject it.
- Enabled flag in a local `skills.json` (Sprint B3 writes this; Sprint B2
  treats all valid bundled + scanned skills as enabled).

### 2.4 Tool loop

On each user message:

1. Build a system preface: list of `{name, description}` for enabled skills
   + “read SKILL.md with the Read tool when a skill applies”.
2. Send to `/v1/messages` with tools:

   | Name | Input | Runs |
   | ---- | ----- | ---- |
   | `Read` | `path` | File if allowlisted or under skill dir |
   | `Write` | `path`, `contents` | Write roots only |
   | `Bash` | `command`, `workdir?` | Tokenized to `{program, args[]}`; no shell — §2.5 |

3. On `stop_reason: tool_use`, execute locally, append `tool_result`,
   continue. Cap iterations (e.g. 16) and wall clock (e.g. 240 s) to
   match the gateway’s own loop bounds.
4. Stream only the final assistant text to the UI (or stream text blocks
   as they arrive; hide raw command strings behind a “Working…” card).

`Edit` can be a later alias (read + write). Do not add a `Skill` server
tool that the model cannot satisfy.

### 2.5 Execution policy (v1) — there is no shell

`Bash` is how Agent Skills call `python scripts/thumbnail.py`. It is
**not** a `skill.run` job type (that is Sprint B5, running the same loop).

The tool keeps the ecosystem name `Bash` so community skills trigger correctly,
but **no shell is ever spawned on any platform** (master plan decision 29, C12).
Windows has no Bash; a command string is a parsing problem everywhere.

1. **Internally the tool is `{program, args[], workdir}`.** The incoming command
   string is tokenized with a strict POSIX-like splitter that understands quotes
   and nothing else. Any of `|`, `&`, `;`, `>`, `<`, `` ` ``, `$(`, `${`, or a
   newline is a hard refusal with a named error — not an escape attempt to
   sanitize, a request to reject.
2. `sh -c`, `bash -c`, `cmd /c`, `powershell -Command`, and `osascript -e` are
   **never constructed**. CI greps for them; a match fails the build (C12).
3. `program` must resolve, after canonicalization, to an entry in the binary
   allowlist (an absolute real path — never a bare name, because `PATH` is
   attacker-influenced) or to a script inside the invoking skill's own
   directory launched **by** an allowlisted interpreter. Note that
   `npm`/`npx` on Windows are `.cmd` shell scripts and therefore need an
   explicit interpreter, never a bare exec.
4. Denied everywhere, including via indirection: `cmd.exe`, `powershell.exe`,
   `pwsh`, `wscript`, `cscript`, `mshta`, `rundll32`, `regsvr32`, `osascript`,
   `sh`, `bash`, `zsh`, `env`, `curl`, `wget`, `ssh`.
5. `workdir` must resolve inside the skill directory or the run scratch dir in
   the out-box, through the same confinement path as any other path.
6. **Timeout kills the whole process tree.** A bare `kill(pid)` orphans
   grandchildren on all three platforms: Windows uses a Job Object with
   `JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE`, POSIX uses a new process group plus
   `killpg` and then `SIGKILL` after a grace period.
7. **The environment is constructed, never inherited.** Start empty; add only
   `PATH` limited to the allowlisted tool directories, a `HOME`/`USERPROFILE`
   pointing at the run scratch dir, `TMPDIR`/`TEMP`, and `LANG`. Never pass the
   Synaplan key or pairing code, and explicitly never `LD_PRELOAD`,
   `LD_LIBRARY_PATH`, `DYLD_INSERT_LIBRARIES`, `PYTHONPATH`, `PYTHONSTARTUP`,
   `NODE_OPTIONS`, or `PSModulePath`.
8. Output: max bytes cap, forced UTF-8 (`PYTHONIOENCODING=utf-8`; do not rely
   on the Windows console code page), CRLF normalized before display or
   re-ingest.
9. No visible console window on Windows (`CREATE_NO_WINDOW`); no dock icon
   flash on macOS.
10. **No server-supplied command in this sprint** — only the model, after the
    user typed a request. Sprint B4 tightens the binary allowlist
    (`python`, `node`, `soffice`); Sprint B2 tests use a fixture script.

A confirmation dialog for the **first** program launch in a turn is required
(“This skill wants to run a program”), naming the resolved program path.
Remember-for-this-skill is OK. Never auto-allow across all skills.

### 2.6 Do not do in this sprint

- Install from zip/git (Sprint B3).
- Vendor `pptx` (Sprint B4) — use a **tiny fixture skill**
  `skills/bundled/hello-files` that writes `hello.txt` via Write or a
  5-line Python script.
- Check-in / web jobs (Sprint B5 — the server side already exists, which is
  not a reason to pull it forward).
- Prompt-pack seeding on the PHP server.

---

## 3. Tests

All offline, fixed clock, temp home (`HOME`, `XDG_*`, `APPDATA`,
`LOCALAPPDATA`, `USERPROFILE`), **on all three runners**.

| Case | Expected |
| ---- | -------- |
| Parse valid SKILL.md | name + description |
| Mismatched directory / name | skipped |
| Read inside allowlist | contents |
| Read the deny-listed key file | denied, no bytes |
| **Every row of the §2.2 escape corpus** | per-platform verdict |
| Write outside write roots | denied |
| `workdir` outside the skill dir / out-box | denied |
| Command containing a shell metacharacter | refused with a named error |
| Grep for `sh -c` / `cmd /c` / `powershell -Command` in the source | no matches (C12) |
| Program not on the binary allowlist | refused before spawn |
| Timeout with a child that spawns a grandchild | whole tree gone |
| Tool loop with mock `/v1/messages` that emits one `Write` | file appears |
| API key and pairing code absent from subprocess env | assert |
| `PYTHONPATH` / `LD_PRELOAD` / `NODE_OPTIONS` absent from subprocess env | assert |
| Iteration cap | stop with a user-visible error |

No live model in CI. The fixture upstream plays a recorded `tool_use`.

**The corpus must not pass with one comparison strategy.** If a single
case-handling implementation makes every row green on Linux, Windows, and
macOS, the test is not exercising the per-OS behaviour — Linux must be
byte-exact while Windows and macOS case-fold.

---

## 4. Exit criteria

1. Fixture skill can create a file in the out-box through the mock loop, on all
   three OSes.
2. The escape corpus runs on Linux, Windows, and macOS in CI and fails the PR
   if someone “simplifies” canonicalization (C11).
3. No shell is constructed anywhere; the CI grep guard is in place (C12).
4. User can add a read folder in the UI (four locales), and the UI shows the
   platform-native path.
5. Chat without skills still works (empty catalog).
6. `make ci-local` green on all three runners.
