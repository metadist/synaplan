# Sprint B4 — First real skills (pptx, not Outlook COM)

**Phase B (`synaplan-desktop`), sprint 4 of 5.** Steps `DC15`–`DC18`.

**Goal:** A user can produce a `.pptx` on Windows, macOS, and Linux using
the official Agent Skill, through Synaplan Desktop only. Outlook stays on
the Synaplan server path we already have.
**Depends on:** Sprints B2–B3. Checklist rows 11, 12, 15, and **25, 31**.
**Unlocks:** Sprint B5 (worth polling once a real skill exists) and the GA
flag flip in master plan §11.
**Repos:** `synaplan-desktop` (bundle). `synaplan/` docs only.
**Platform rules:** [`13_cross_platform.md`](./13_cross_platform.md) §5 (tool
discovery) and §11 (manual acceptance) are binding here.

This is the sprint where "it works on my machine" is most likely to mean "it
works on Linux". `pptx` is the first skill that actually needs third-party
runtimes, and finding those runtimes is a genuinely different problem on each
OS — see §1.2.1.

---

## 0. Why this sprint exists

PowerPoint and Outlook were the motivating examples. They are **different
problems**. Shipping a COM Outlook skill would fail Linux and fight
Synamail / M365. Shipping `pptx` proves the runtime on all three OSes
without Microsoft Office.

---

## 1. PowerPoint — bundled official skill

### 1.1 Vendor, do not live-fetch

- Source: official Anthropic `pptx` skill (Apache-2.0), reviewed commit.
- Copy into `synaplan-desktop/skills/bundled/pptx/` in the same PR as
  `NOTICE` / license text.
- Record the upstream URL + SHA in `docs/BUNDLED_SKILLS.md`.
- Do not rewrite the SKILL.md into a Synaplan prompt-pack.

### 1.2 Runtime dependencies (honest)

The skill expects some of:

- Python 3 + `markitdown[pptx]`, Pillow
- Node + `pptxgenjs` (create-from-scratch path)
- LibreOffice (`soffice`) for PDF/thumbnail (optional)

v1 client:

1. **Detect and probe** Python, Node, and LibreOffice per the discovery order
   in §1.2.1. "On PATH" is a Linux assumption and is only step 3 of 4.
2. Skills page shows a **readiness** line per tool: found / missing / wrong
   version, with the resolved absolute path.
3. Missing required binary: the skill is marked **blocked**, excluded from the
   model's catalog preface, and shows a platform-correct install hint (four
   locales). Never start a doomed tool loop.
4. In-app **Check this computer** (`DC16`, extended by `DC26`), plus a
   `make doctor` for developers.

Do **not** silently `pip install` from the model. A later step may offer
“Install Python packages for this skill” with an explicit button and a
requirements pin — and, because of PEP 668, it must create a per-skill
virtualenv under the skills directory rather than touching the system Python.
Out of the first pptx PR if it bloats.

#### 1.2.1 Discovery per OS (`DC26`)

Order on every platform: (1) a path the user configured, (2) known install
locations for that OS, (3) `PATH` — honouring `PATHEXT` on Windows, (4) probe
`--version` with a short timeout. **A binary that is found but does not answer
is reported missing.** Only the resolved absolute real path goes into the
binary allowlist; a bare name is not allowlistable because `PATH` is
attacker-influenced.

| Trap | Platform | Handling |
| ---- | -------- | -------- |
| `WindowsApps\python.exe` is a **Store placeholder** that opens the Store on a machine with no Python | win | Detect that path family and report **missing**, with the hint that Windows shows a placeholder |
| `py` launcher is present without `python.exe` on PATH | win | Probe `py -3 --version`; use `py -3` as the interpreter |
| `python` without `.exe` does not resolve | win | Resolve through `PATHEXT`, store the full path |
| LibreOffice is `soffice.exe` / `soffice.com` under `C:\Program Files\LibreOffice\program\`, rarely on PATH | win | Probe default install dirs, 64- and 32-bit |
| `npm` is `npm.cmd`, i.e. a shell script | win | Needs an explicit interpreter; never a bare exec (B2 §2.5) |
| Per-user Python at `%LOCALAPPDATA%\Programs\Python\` | win | Probe it too |
| `/usr/bin/python3` is a **Command Line Tools shim** that pops an install prompt | mac | A prompt-triggering shim counts as missing |
| Homebrew is `/opt/homebrew` (arm64) or `/usr/local` (x64) | mac | Probe both regardless of host arch |
| LibreOffice lives at `/Applications/LibreOffice.app/Contents/MacOS/soffice` | mac | Probe the bundle path |
| GUI apps do not inherit the login shell `PATH` | mac | Never rely on `.zshrc`; the order above handles it |
| **PEP 668 externally-managed** Python refuses `pip install` | linux | Never attempt it; virtualenv or nothing |
| `python3` present but `python3-venv` missing | linux | Probe `python3 -m venv --help`, name the package in the hint |
| Flatpak LibreOffice has no `soffice` on PATH | linux | Detect it or report "not detected" honestly — do not claim support we do not have |

Spaces in install paths (`C:\Program Files\…`, `/Applications/…`) are the norm,
not an edge case. This is why execution is argv-based (B2 §2.5); a quoting bug
here would be a command-injection bug.

### 1.3 Binary allowlist (tighten Sprint B2 execution policy)

Allow only, as **absolute resolved paths** produced by the doctor:

- the detected Python (`python3`, `python`, or `py -3`)
- the detected Node (`npm` only if we accept the create-from-scratch path;
  otherwise skip it in v1 and document “create via the python-pptx path”)
- the detected LibreOffice (`soffice`, `soffice.exe`, `soffice.com`,
  `soffice.bin`, or the `.app` bundle binary)
- the skill’s own `scripts/*` launched by one of the above

Everything else is denied, including `curl`, `powershell`, `cmd`, `osascript`,
and any shell (B2 §2.5, C12). Network from scripts: default **off**; `pptx`
does not need the internet.

### 1.4 Acceptance utterance

User in Synaplan Desktop:

> Create a three-slide presentation about Synaplan Desktop. Save it in
> my Synaplan out folder.

Expect: a `.pptx` in the out-box that opens in LibreOffice / PowerPoint /
Keynote. The UI reports the file with its **platform-native path**
(`C:\Users\anna\Synaplan\out\…`), and offers to reveal it in Explorer / Finder /
the desktop file manager. Optional “Upload to Synaplan Sources” button (uses
`desktop:files` + the existing upload API).

### 1.5 Manual matrix (PR evidence) — all three are required

This table is filled in by a human and pasted into the `DC18` PR. A blank
Windows or macOS row means the sprint is not done; there is no "we will check
the other platforms later" (C10).

| OS | Python | Node | LibreOffice | Result |
| -- | ------ | ---- | ----------- | ------ |
| Linux CI runner (headless) | yes | optional | no | hermetic “create pptx via python” fixture |
| Linux desktop | | | | screenshot + file |
| macOS (arm64) | | | | screenshot + file |
| Windows 11 | | | | screenshot + file |
| **Windows without Python** | no | — | — | skill shows **blocked** with a correct hint; no tool loop starts |
| **macOS with only the CLT shim** | shim | — | — | reported missing, not "found" |

The two negative rows matter as much as the positive ones: the most common real
Windows experience is a machine with no Python, and the most common clean-macOS
experience is the Command Line Tools shim. Both must produce an honest,
actionable message rather than a failed run.

CI must run a **hermetic** fixture on **all three runners**: a stub `SKILL.md`
that writes a minimal pptx via a vendored tiny script, **without** LibreOffice,
so PR CI stays green everywhere. The full official skill with LibreOffice is
manual plus an optional Linux nightly.

---

## 2. Outlook — do not bundle a marketplace skill

Document in `docs/DESKTOP.md` and in the Skills empty-state:

| Need | Use |
| ---- | --- |
| Read / search mailbox | Synaplan web: Connections → Microsoft 365 or IMAP. Chat / Saved Tasks (`email_search`) |
| Draft / send from Outlook UI | Synamail add-in |
| Calendar write | Synaplan Phase M Graph path (when shipped), not a desktop COM skill |
| “Control Outlook.exe” (COM) | Out of v1. Windows-only, and Linux has no Outlook application at all |
| “Control Outlook via AppleScript” | Out of v1. macOS-only, same reason |

A community Graph+curl skill may be **user-installed** (Sprint B3) at their
risk. We do not vendor it, do not put Microsoft tokens in the desktop
keychain in v1, and do not add a second OAuth stack. Note that such a skill
would also hit the binary allowlist — `curl` is denied (§1.3) — so it cannot
work as written without an explicit future decision.

This is the clearest case of the parity rule (C10) doing real work: an
OS-specific automation skill would make the product mean something different on
each platform. Mail and calendar stay on the server path, which is identical
everywhere.

---

## 3. Tests

Discovery is tested against **fixture filesystems**, not the runner's real
installation — otherwise the test asserts what happens to be installed on the
GitHub image.

- Bundled `pptx` parses (frontmatter valid, name `pptx`).
- Doctor: missing python → skill shows blocked, not offered to the model.
- Doctor: fixture `WindowsApps\python.exe` placeholder → reported **missing**.
- Doctor: only `py -3` available → interpreter resolves to `py -3`.
- Doctor: `PATHEXT` resolution finds `python.exe` from a bare `python`.
- Doctor: LibreOffice found in the Program Files / `.app` bundle fixture, not
  on `PATH`.
- Doctor: macOS CLT shim fixture → reported missing, not found.
- Doctor: a binary that never answers `--version` → missing after the timeout.
- Execution deny: `curl https://example.com` from a fixture skill → denied.
- Execution deny: a program not produced by the doctor → refused before spawn.
- Upload-to-Sources: mock `/api/v1/files` 201 (if the button ships).
- Docs: `BUNDLED_SKILLS.md` lists license + SHA.

---

## 4. Exit criteria

1. Bundled pptx is visible, licensed, and SHA-pinned.
2. Hermetic create-pptx test green on **all three** CI runners.
3. **Every row of the §1.5 manual matrix is filled in**, including the two
   negative rows — a readable deck on Windows, macOS, and Linux.
4. Doctor reports honestly on a machine without the runtimes, with a
   platform-correct hint in four locales.
5. UI and docs never claim “Outlook automation” for the desktop app on any
   platform.
6. `make ci-local` green on all three runners.
