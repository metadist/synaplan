# Cross-platform parity — Windows, macOS, Linux

**Status:** Added 2026-08-30. **Binding for every `DC*` step.** Where a sprint
file and this file disagree about platform behaviour, this file wins and the
sprint file is corrected in the same change.
**Scope:** Phase B (`synaplan-desktop`) only. Phase A (`synaplan/`) is a PHP
server and has no platform surface; the only Phase A obligation is that
`docs/DESKTOP.md` never describes a POSIX-only install.

**Why this file exists.** The first draft of this epic was written Linux-first:
`~/.synaplan-desktop`, `soffice`, `python3`, symlink-escape tests, and a CI
matrix where Windows and macOS were `workflow_dispatch`. The product promise is
**three first-class desktops**. A path-confinement module that is only exercised
on Linux is not a security control on Windows — it is an assumption. This file
turns every "and it also works on Windows/macOS" hand-wave into a named rule
with a test.

---

## 1. Support tiers

| OS | Minimum | Architectures | Unit tests in CI | Installer | Tier |
| -- | ------- | ------------- | ---------------- | --------- | ---- |
| **Windows** | 10 22H2 / 11 | x64, arm64 (build only in v1) | **Every PR** | Signed MSI or NSIS | 1 |
| **macOS** | 13 Ventura | arm64 + x64 (universal) | **Every PR** | Signed + notarized `.dmg` | 1 |
| **Linux** | glibc 2.31+ (Ubuntu 22.04 baseline) | x64, arm64 (build only in v1) | **Every PR** | AppImage + `.deb` | 1 |

**Tier 1 means a red platform blocks the release**, not "we will look at it
later". There is no Tier 2 in v1. If a platform cannot be supported to this
standard, it is removed from the product promise, the README, and the website —
not quietly degraded.

Windows on ARM and Linux on ARM build in CI but are not part of the manual
acceptance matrix in v1. Say so in `docs/DESKTOP.md` instead of implying full
coverage.

---

## 2. Canonical directories — no `~` in code

Earlier sprint drafts wrote `~/.synaplan-desktop/...`. That is documentation
shorthand, **not** an implementation instruction. Every path comes from one
`app_dirs` module (`DC22`); no other module may concatenate a home directory.

| Purpose | Windows | macOS | Linux |
| ------- | ------- | ----- | ----- |
| Config | `%APPDATA%\Synaplan\Desktop\config.toml` | `~/Library/Application Support/com.synaplan.desktop/config.toml` | `$XDG_CONFIG_HOME/synaplan-desktop/config.toml` (fallback `~/.config/...`) |
| Skills | `%LOCALAPPDATA%\Synaplan\Desktop\skills\` | `~/Library/Application Support/com.synaplan.desktop/skills/` | `$XDG_DATA_HOME/synaplan-desktop/skills/` |
| Out-box (default write root) | `%USERPROFILE%\Synaplan\out\` | `~/Synaplan/out/` | `~/Synaplan/out/` |
| Audit log | `%LOCALAPPDATA%\Synaplan\Desktop\logs\audit.log` | `~/Library/Logs/com.synaplan.desktop/audit.log` | `$XDG_STATE_HOME/synaplan-desktop/audit.log` |
| Run scratch | Out-box `.../out/{skill}/{runId}/` on all three | — | — |

Rules:

1. The **out-box is deliberately in the user's home**, not in an OS app-data
   directory, on all three platforms. Users must be able to find their deck in
   a file manager without knowing what `%LOCALAPPDATA%` means.
2. The bundle identifier is `com.synaplan.desktop` on macOS and the vendor
   folder is `Synaplan\Desktop` on Windows. Fix these at `DC1`; changing them
   later orphans every existing install's config.
3. Respect `XDG_*` when set; do not hardcode `~/.config`.
4. Tests set the platform's home/appdata variables to a tempdir
   (`HOME`, `XDG_*`, `APPDATA`, `LOCALAPPDATA`, `USERPROFILE`) so a developer's
   real installation is never read or written (testing doc §7).
5. In the UI, always render the **platform-native** path
   (`C:\Users\anna\Synaplan\out`), never `~/Synaplan/out` on Windows.

---

## 3. Path confinement per OS

This is the core security control (`DC6`, `DC24`). The July research gave one
rule — *canonicalize, then contain* — and one test, the POSIX symlink escape.
That is roughly a third of the real attack surface.

### 3.1 The universal algorithm

Every candidate path, whether it came from the model, a skill script argument,
a zip entry, or the config file:

1. Reject empty, NUL bytes, and control characters.
2. Resolve to an absolute path using the platform's real canonicalizer
   (`std::fs::canonicalize` on all three; on Windows this yields a `\\?\` path
   and resolves junctions, on macOS it resolves firmlinks).
3. Apply platform normalization from §3.2–§3.4 (case folding, Unicode form,
   stream/short-name rejection).
4. Contain: the canonical candidate must be a **path-component-wise prefix
   match** against a canonicalized allowlist root. Never compare raw strings —
   `C:\Users\anna\Documents2` must not match root `C:\Users\anna\Documents`.
5. Apply deny globs last, against the canonical path.
6. For writes, additionally require a write root; for execution, additionally
   require the binary allowlist (§6).

**Canonicalize before every use, not once at intake.** A TOCTOU swap between
check and open is the classic bypass; open by handle where the platform allows
it, and re-verify after open.

### 3.2 Windows hazards

Each row is a test case, not a footnote.

| Hazard | Attack | Rule |
| ------ | ------ | ---- |
| **Directory junctions / reparse points** | `mklink /J` inside an allowed folder pointing at `C:\Users\anna\.ssh` — no admin rights required, unlike symlinks | Canonicalize resolves them; assert the resolved target is contained. Test with a real junction, not a symlink |
| **Symlinks with Developer Mode** | Unprivileged symlink creation is available on Win10+ | Same as junctions; both must be in the corpus |
| **UNC paths** | `\\attacker\share\x`, `\\?\UNC\...`, `\\.\PhysicalDrive0` | Deny all UNC and device namespaces unless the user explicitly added a UNC root; never reachable via traversal from a local root |
| **Mapped network drives** | `Z:\` resolving off-machine | Treated as UNC after canonicalization; same rule |
| **Drive-relative paths** | `C:foo` means "foo relative to the current directory *on drive C*" | Reject any path that is not fully qualified after step 2 |
| **8.3 short names** | `C:\Users\anna\DOCUME~1` bypasses a string prefix check | Canonicalize expands them; additionally reject `~N` components pre-resolution as defence in depth |
| **Case insensitivity** | `c:\users\ANNA\documents` vs the stored root | Compare with Unicode simple case folding **on Windows and macOS**, byte-exact on Linux. One helper, per-OS behaviour, unit-tested both ways |
| **Alternate Data Streams** | `report.docx:evil.exe`, `dir::$INDEX_ALLOCATION` | Reject any path containing `:` after the drive letter |
| **Reserved device names** | `CON`, `PRN`, `AUX`, `NUL`, `COM1`–`COM9`, `LPT1`–`LPT9`, with or without extension | Reject as a filename component, case-insensitively |
| **Trailing dots and spaces** | `secret.txt.` and `secret.txt ` resolve to the same file but differ as strings | Reject components with trailing dot or space |
| **MAX_PATH / long paths** | Skill writes a deep tree and the write fails at 260 chars, or a truncated path lands outside expectations | Use `\\?\`-prefixed paths for all filesystem calls; surface a clear error, never a truncation |
| **Unicode confusables in roots** | Not a confinement break but a UI lie | Display roots verbatim; do not normalize away in the UI |

### 3.3 macOS hazards

| Hazard | Rule |
| ------ | ---- |
| **Case-insensitive APFS by default, case-sensitive available** | Same case-folded comparison as Windows, but the corpus must also pass on a case-sensitive volume; do not assume either |
| **Firmlinks and `/private`** | `/var`, `/tmp`, `/etc` canonicalize to `/private/...`. An allowlist root stored as `/tmp/x` will never prefix-match a canonical `/private/tmp/x`. Canonicalize roots at **store** time, not only at check time |
| **Unicode normalization (NFD)** | HFS+/APFS may hand back decomposed forms. Normalize both sides to NFC before comparing, on all three platforms |
| **TCC-protected folders** | `~/Desktop`, `~/Documents`, `~/Downloads`, iCloud Drive and removable volumes require user consent. A denied read returns `EPERM`, not "file not found" |
| **Gatekeeper quarantine** | `com.apple.quarantine` on a downloaded skill zip propagates to extracted scripts; a quarantined script may be blocked or prompt | Strip the attribute only for files the user explicitly confirmed at install, and say so in the confirm dialog |
| **App sandbox / hardened runtime** | Notarization requires the hardened runtime; that restricts `dlopen` and JIT and interacts with spawning interpreters | Decide entitlements at `DC28`, test a notarized build before GA |

### 3.4 Linux hazards

| Hazard | Rule |
| ------ | ---- |
| **Bind mounts** | Canonicalization does not see through a bind mount. Contain against the mounted path; document that a user who bind-mounts a secret into an allowed root has widened their own allowlist |
| **`/proc/self/...`, `/dev/fd/...`** | Deny `/proc`, `/sys`, `/dev` outright |
| **Case sensitivity** | Byte-exact comparison; the same corpus must **not** case-fold here. A test that passes on all three OSes with one comparison strategy is testing nothing |
| **Flatpak / Snap portals** | If we ever ship those formats, the sandbox re-maps home. Out of v1; AppImage and `.deb` only |

### 3.5 Shared confinement corpus

One table-driven test corpus (`tests/confinement/cases.toml`) executes on all
three runners. Each case declares the platforms it applies to and its expected
verdict. **The corpus is the deliverable of `DC24`**, and a PR that changes the
confinement module without touching the corpus is incomplete.

Minimum rows: POSIX symlink escape, Windows junction escape, UNC, drive-relative,
8.3, ADS, reserved name, trailing dot, `/private` firmlink, NFD vs NFC,
`Documents2` sibling-prefix, deny-glob hit inside an allowed root, TOCTOU swap,
write attempt in a read-only root, path exceeding 260 characters.

---

## 4. Secret storage

The pairing key (`DC2`, `DC23`) never lands in a plaintext config file.

| OS | Store | Backend |
| -- | ----- | ------- |
| Windows | Credential Manager (generic credential `Synaplan Desktop`) | DPAPI, per user account |
| macOS | Keychain (login keychain, item `com.synaplan.desktop`) | ACL bound to the signed app; **rotates on re-signing with a different identity** — test after `DC28` |
| Linux | Secret Service (`libsecret`) via GNOME Keyring or KWallet | Session-unlocked collection |

Rules:

1. One abstraction (`SecretStore`) with three implementations and one in-memory
   test double. No `#[cfg]` blocks scattered through the pairing code.
2. **Headless Linux has no Secret Service.** Fail with a named error and a
   documented, explicitly opt-in fallback (`SYNAPLAN_DESKTOP_ALLOW_PLAINTEXT_KEY=1`
   writing a `0600` file), never a silent downgrade. The fallback prints a
   warning at every start and is refused when the poll loop runs unattended.
3. The key is never in the audit log, never in a crash report, never in an
   environment variable handed to a skill subprocess (§6).
4. A revoked or rotated key is deleted from the store on 401, not left behind.

---

## 5. Interpreter and tool discovery (the doctor)

`DC16` / `DC26`. "Detect python3, node, soffice on PATH" is a Linux sentence.

### 5.1 Discovery order (all platforms)

1. Explicit path configured by the user in `config.toml` — always wins.
2. Known-good per-OS install locations (§5.2–§5.4).
3. `PATH` lookup, honouring `PATHEXT` on Windows.
4. Every candidate is **probed, not just found**: run `--version` with a short
   timeout and parse it. A binary that does not answer is reported missing.

The resolved absolute real path is what the binary allowlist stores (§6). Never
allowlist a bare name — `PATH` is attacker-influenced.

### 5.2 Windows traps

| Trap | Handling |
| ---- | -------- |
| **Microsoft Store stub** `%LOCALAPPDATA%\Microsoft\WindowsApps\python.exe` exists on a machine with no Python and opens the Store | Detect this exact path family and treat it as **missing**, with the hint "Windows shows a Python placeholder; install Python from python.org or the Store" |
| **`py` launcher** is the idiomatic entry point, often without `python.exe` on PATH | Probe `py -3 --version` and use `py -3` as the interpreter command |
| **`PATHEXT`** — `python` without `.exe` will not resolve | Resolve through `PATHEXT`, store the full `.exe` path |
| **Spaces in paths** (`C:\Program Files\...`) | argv arrays only (§6); a quoting bug here becomes a command-injection bug |
| **LibreOffice** is `soffice.exe` / `soffice.com` under `C:\Program Files\LibreOffice\program\` and is rarely on PATH | Probe the default install dirs for both 64- and 32-bit; `soffice.com` for non-detaching stdout |
| **Node** installs to `C:\Program Files\nodejs\` | Probe it; `npm` is `npm.cmd` — a `.cmd` **is** a shell script, so it must be launched with an explicit interpreter, never as a bare exec |
| **Per-user vs machine installs** | Probe `%LOCALAPPDATA%\Programs\Python\Python3xx\` as well as `C:\Python3xx\` |

### 5.3 macOS traps

| Trap | Handling |
| ---- | -------- |
| `/usr/bin/python3` is a **Command Line Tools shim** that triggers an install prompt on a clean machine | Probe with a timeout; a prompt-triggering shim counts as missing |
| Homebrew prefix differs by architecture: `/opt/homebrew/bin` (arm64) vs `/usr/local/bin` (x64) | Probe both regardless of the host arch (Rosetta) |
| LibreOffice is `/Applications/LibreOffice.app/Contents/MacOS/soffice` | Probe the bundle path, not `soffice` on PATH |
| GUI apps do not inherit a login shell `PATH` | Never rely on the user's `.zshrc`; §5.1 order handles this |

### 5.4 Linux traps

| Trap | Handling |
| ---- | -------- |
| **PEP 668 externally-managed environments** — `pip install` into the system Python is refused on Debian/Ubuntu/Fedora | Never attempt it. If a future step offers dependency installation, it creates a per-skill virtualenv under the skills dir |
| `python3` present, `venv`/`ensurepip` missing (`python3-venv` not installed) | Probe `python3 -m venv --help`, report the package name in the hint |
| Flatpak LibreOffice has no `soffice` binary on PATH | Detect `flatpak run org.libreoffice.LibreOffice`; if we do not support it, say "not detected" honestly |

### 5.5 Doctor output contract

The **Check this computer** screen reports, per tool: found / missing / wrong
version, the resolved absolute path, the detected version string, and a
platform-correct install hint in all five locales. A skill whose
`compatibility` requires a missing tool is shown as **blocked** and is excluded
from the model's skill catalog preface — never offered and then failed halfway
through a tool loop.

---

## 6. Process execution

Sprint B2 says "`Bash` … command is a string". On Windows there is no Bash, and
a command string is a parsing problem on every platform.

1. **argv arrays, never a shell string.** The tool schema takes
   `{ program, args[], workdir }`. If the ecosystem's `Bash` tool name is kept
   for skill compatibility, the client **parses** the command with a strict
   POSIX-like tokenizer and rejects anything containing shell metacharacters
   (`|`, `&`, `;`, `>`, `<`, backticks, `$(`, newlines). No `sh -c`, no
   `cmd /c`, no `powershell -Command` — on any platform.
2. `program` must resolve, after canonicalization, to an entry in the binary
   allowlist (absolute real path) or to a script inside the invoking skill's
   own directory that is executed **by** an allowlisted interpreter.
3. Explicitly denied on every platform, including via indirection:
   `cmd.exe`, `powershell.exe`, `pwsh`, `wscript`, `cscript`, `mshta`,
   `rundll32`, `regsvr32`, `osascript`, `sh`, `bash`, `zsh`, `env`, `curl`,
   `wget`, `ssh`. A skill that needs one of these is refused with a named error,
   not silently downgraded.
4. **Timeout must kill the whole tree.** A bare `kill(pid)` leaves grandchildren
   running on all three platforms. Windows: create the child in a Job Object
   with `JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE`. POSIX: new process group,
   `killpg`, then `SIGKILL` after a grace period.
5. **Environment is constructed, not inherited.** Start from empty; add only
   what is needed (`PATH` limited to the allowlisted tool directories, `HOME`
   or `USERPROFILE` pointed at the run scratch dir, `TMPDIR`/`TEMP`, `LANG`).
   Never pass the Synaplan key, the pairing code, `LD_PRELOAD`,
   `LD_LIBRARY_PATH`, `DYLD_INSERT_LIBRARIES`, `PYTHONPATH`, `PYTHONSTARTUP`,
   `NODE_OPTIONS`, or `PSModulePath`.
6. **Encoding.** Force UTF-8 in child output (`PYTHONIOENCODING=utf-8`, and on
   Windows do not rely on the console code page). Normalize CRLF when a skill's
   output is shown or re-ingested.
7. `workdir` is always the run scratch directory under the out-box, canonicalized
   and contained like any other path.
8. No new console window on Windows (`CREATE_NO_WINDOW`); no dock icon flash on
   macOS.

---

## 7. Cloud-synced and redirected folders

Default read roots cannot be a hardcoded `~/Documents`.

| Platform | Reality |
| -------- | ------- |
| Windows | Known Folder Move puts `Documents`, `Desktop`, and `Pictures` under `%OneDrive%`. Resolve via the Known Folder API, never by string |
| macOS | "Desktop & Documents Folders" sync relocates them into iCloud Drive; files may be **dataless placeholders** that block on first read |
| Linux | Nextcloud/Dropbox clients, plus `XDG_DOCUMENTS_DIR` from `user-dirs.dirs` |

Rules: resolve known folders through the OS API; treat a first read that blocks
or fails as a recoverable, explained error ("this file is stored online and is
being downloaded"), never a crash; and state plainly in the folder picker that
adding a synced folder means results may be uploaded by that sync client.
Default write root stays the local, non-synced out-box.

---

## 8. Autostart and background execution

Needed only by Sprint B5 (poll loop). Opt-in, never enabled at install.

| Platform | Mechanism | Notes |
| -------- | --------- | ----- |
| Windows | Registry `HKCU\...\Run` entry, or a Task Scheduler logon task if we need delay/retry | No service, no `HKLM`, no admin elevation. Must not survive uninstall |
| macOS | `SMAppService` login item (`LaunchAgent` in the app bundle) | The user sees it in System Settings → Login Items and can disable it there — the app must handle "I am disabled" gracefully |
| Linux | XDG autostart `.desktop` in `~/.config/autostart`, or a `systemd --user` unit | AppImage path changes on update; write the resolved path at enable time and re-validate at start |

Additional rules: the tray/background mode is a single instance (named mutex on
Windows, `flock` on POSIX); disabling autostart in the app removes the OS entry
in the same action; and the unattended poll loop refuses to run when the key is
in the plaintext fallback (§4.2).

---

## 9. Packaging, signing, distribution

Sprint B1 defers signing. That is correct **for B1** and wrong for GA: an
unsigned Windows binary is a SmartScreen wall, and an unnotarized macOS app
simply refuses to open for a normal user. This becomes a release blocker at
`DC28`, and the lead time for the certificates is measured in weeks, so the
procurement starts during Phase A.

| Platform | Artefact | Signing | Blocker if missing |
| -------- | -------- | ------- | ------------------ |
| Windows | MSI or NSIS installer + portable zip | Authenticode with an **OV or EV** code-signing certificate (EV avoids the SmartScreen reputation ramp) | SmartScreen "unrecognized app" on every download |
| macOS | Universal `.app` in a `.dmg` | Developer ID Application + hardened runtime + **notarization + stapling** | Gatekeeper refuses to launch; no workaround for normal users |
| Linux | AppImage + `.deb` | Detached GPG signature + published checksums | No hard block, but no integrity story |

Secrets for signing live in the private client repo's CI, never in `synaplan/`
(public). Reproducible-build metadata and SBOM are a follow-up, not v1.

---

## 10. CI matrix (binding)

This replaces `04_phase_b1_client_repo.md` §1.1's "macOS/Windows can be
`workflow_dispatch`".

| Job | Runners | Runs on | Blocking |
| --- | ------- | ------- | -------- |
| Lint + types | Linux | Every PR | Yes |
| **Unit tests incl. the confinement corpus** | **Linux + Windows + macOS** | **Every PR** | **Yes** |
| Debug build | Linux + Windows + macOS | Every PR | Yes |
| Release build + installer | Linux + Windows + macOS | Tag / `workflow_dispatch` | Yes for a release |
| Signing + notarization | Windows + macOS | Tag | Yes for a release |
| Hermetic pptx fixture | Linux + Windows + macOS | Every PR | Yes |
| Full official pptx with LibreOffice | Linux | Nightly | No (report only) |

**Rationale for the cost.** The confinement module is the only thing standing
between a community skill and `id_rsa`. Its per-OS behaviour differs the most
exactly where the tests were weakest. Running three runners on every PR is
cheaper than one incident. If minutes become a real constraint, cut the debug
build on Windows/macOS to `push`-only — never the confinement corpus.

---

## 11. Manual acceptance per release (`P1`)

Automated tests cannot see a Gatekeeper dialog. Each release records this table,
filled in by a human, in the release PR:

| Check | Win | macOS | Linux |
| ----- | --- | ----- | ----- |
| Installer runs without a security warning a normal user cannot pass | | | |
| Pair, chat one turn, key stored in the OS secret store | | | |
| Doctor reports Python/Node/LibreOffice correctly (and correctly on a machine **without** them) | | | |
| Bundled `pptx` produces a deck that opens in the native viewer | | | |
| Out-box path shown in the UI is platform-native and openable from the file manager | | | |
| Junction/symlink escape refused (screenshot of the error) | | | |
| Autostart on and off leaves no OS entry behind (B5 only) | | | |
| Uninstall removes the app; user files in the out-box survive | | | |

---

## 12. Out of scope for v1 (state it, do not imply it)

- Windows on ARM and Linux on ARM manual verification (builds only).
- Flatpak, Snap, winget, Homebrew Cask, and Microsoft Store distribution.
- Auto-update of the client itself (`DC29` follow-up; until then, download the
  new installer).
- Running as a Windows service, a macOS daemon, or a system-wide systemd unit.
- Per-machine (all users) installation.
- Windows COM, AppleScript, and any OS application automation (master plan §12).

---

## 13. Where this lands in the sprints

| Rule | Enforced in |
| ---- | ----------- |
| §2 directories | `DC22` (`app_dirs`), used by `DC2`, `DC6`, `DC7`, `DC11` |
| §3 confinement | `DC6` (algorithm) + `DC24` (per-OS corpus) |
| §4 secrets | `DC23`, consumed by `DC2` |
| §5 doctor | `DC16` + `DC26` |
| §6 execution | `DC9`, `DC10`, tightened by `DC17` |
| §7 known folders | `DC25` |
| §8 autostart | `DC27` |
| §9 packaging | `DC28` |
| §10 CI matrix | `DC1` (three runners from the first commit) |
| §11 manual matrix | `P1`, referenced by `DC18` and every release |
