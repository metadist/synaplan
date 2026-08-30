# Sprint B3 — Skills manager

**Phase B (`synaplan-desktop`), sprint 3 of 5.** Steps `DC11`–`DC14`.

**Goal:** Users can see, enable, disable, install, and remove Agent Skills
without touching a terminal. Install sources: local folder, zip, git/GitHub
URL. No Synaplan-operated marketplace.
**Depends on:** Sprint B2 loader and its confinement module.
**Unlocks:** Sprint B4 (bundled pptx appears in this UI).
**Repos:** `synaplan-desktop`. Optional later: a read-only “recommended
skills” JSON on docs — not required.
**Platform rules:** [`13_cross_platform.md`](./13_cross_platform.md) §3 applies
to every archive entry and every extracted path. An archive is untrusted input
from the internet; it gets the *same* canonicalize-then-contain treatment as a
model-supplied path, plus the archive-specific rules in §1.2.1.

---

## 0. Why this sprint exists

“Skills managing way” is this sprint. Agent37 is treated as **a website the
user found** — they paste a GitHub URL or drop a zip. We do not embed
Agent37, scrape it in the client, or call Agent37 Cloud.

---

## 1. Developer steps

### 1.1 Skills page

List rows: name, description (one line), source (`bundled` / `user`),
enabled toggle, license if present, **Remove** (hidden for bundled).

Empty state copy from [`12_ux_and_i18n.md`](./12_ux_and_i18n.md).
Compatibility warning when `compatibility` mentions Claude Code only —
still installable; show “written for another assistant; it may not work”.

### 1.2 Install flows (three buttons)

1. **From a folder** — pick a directory that contains `SKILL.md`. Copy
   (not move) into `{skills.dir}/{name}/`.
2. **From a zip** — must contain `{name}/SKILL.md`, not a bare SKILL.md
   at zip root. See §1.2.1 for the full entry rules.
3. **From a Git URL** — `https://github.com/org/repo` or a path to a
   subdirectory (`…/tree/main/skills/pptx`). Pin to a commit SHA in
   `skills.json`. **Download the zipball over HTTPS rather than shelling out to
   `git`**: `git` is not installed by default on Windows or a clean macOS, and
   spawning it would contradict the no-shell rule (B2 §2.5). The zipball then
   goes through exactly the §1.2.1 path.

After copy: validate frontmatter; if invalid, delete the copy and error.
Then show the **code execution** confirm dialog (file list + license).

Enable only after confirm.

#### 1.2.1 Archive extraction rules (all platforms)

Extraction is the single most likely place to write outside the skills
directory. Every entry is validated **before** anything is written, and the
whole install is atomic: extract to a temporary directory next to the target,
validate, then rename into place; on any failure delete the temporary tree.

| Rule | Why |
| ---- | --- |
| Reject `..` in any component, absolute paths, and drive letters (`C:\`, `\\server\`) | Classic zip-slip, plus its Windows variants |
| Reject **backslashes as separators** in entry names | A `..\..\evil` entry is harmless on Linux and an escape on Windows. Testing extraction only on Linux misses it entirely |
| Reject symlink and hardlink entries, and Windows reparse-point attributes | A link entry turns a later write into an escape |
| Reject entries containing `:` (alternate data streams) | Windows-only escape, must be rejected on every platform so a Linux-built package cannot carry it |
| Reject reserved device names (`CON`, `NUL`, `COM1`…) and components with a trailing dot or space | These are unrepresentable or aliasing on Windows |
| Reject two entries that collide under Unicode NFC + case folding | On Windows/macOS the second silently overwrites the first; a package that is one file on Windows and two on Linux is a review-evasion trick |
| Reject a resulting path longer than the platform limit, and use `\\?\` on Windows | A truncated path is an unpredictable write |
| Cap entry count, per-entry size, and total uncompressed size | Zip bomb |
| Preserve no execute bits from the archive | Execution is decided by the interpreter allowlist, not by the package |
| After extraction, re-canonicalize every written path and assert containment | Defence in depth against a bug in the rules above |
| macOS: strip `com.apple.quarantine` only after the user confirms the install | Otherwise scripts silently fail to run; stripping it before consent hides the origin |

The same rules apply to the folder-install flow, since a picked directory can
itself contain links pointing elsewhere.

### 1.3 `skills.json` (local)

```json
{
  "skills": [
    {
      "name": "hello-files",
      "enabled": true,
      "source": "bundled",
      "version": "1.0.0"
    },
    {
      "name": "some-community-skill",
      "enabled": true,
      "source": "git",
      "url": "https://github.com/example/skill",
      "sha": "abc123",
      "installedAt": 1700000000
    }
  ]
}
```

The loader in Sprint B2 already scans disks; this file is enablement +
provenance. Disable = leave files, drop from the model catalog.

### 1.4 Trust copy (required)

Every install dialog must say, in the user’s language:

- This skill can run programs and read files you allow.
- Synaplan did not write or review community skills.
- You can disable or remove it at any time.

No “Claude” in that paragraph.

The dialog also shows what the skill needs to run (Python, Node, LibreOffice)
and whether this computer has it, using the doctor from Sprint B4 once it
exists. Installing a skill that cannot run here is allowed, but it is stated up
front rather than discovered halfway through a tool loop.

### 1.5 Optional: catalog helper (not Agent37 API)

A static `docs` link “Find public skills” pointing at
[agentskills.io](https://agentskills.io) or a Synaplan docs page that
explains zip/git. **Do not** ship an Agent37 API client.

If we later want in-app search, add a **Synaplan-owned** `index.json`
(like the plugin registry). That is a follow-up epic.

### 1.6 Do not do

- Auto-update skills from git on a timer (prompt-injection + supply chain).
  “Check for updates” as an explicit button is OK later.
- Running install scripts from the zip (`install.sh`) — copy files only.
- Server-side storage of skill bodies in v1.

---

## 2. Tests

The malicious-archive fixtures are committed as **byte-exact test data**, not
generated by the platform's zip tooling — a generator would refuse to produce
half of them, which is exactly why the entries are dangerous. All of these run
on **all three runners**.

- Zip with `../` entry: rejected, nothing written outside the skills dir.
- Zip with a **backslash** separator (`..\..\evil`): rejected on every OS, not
  only Windows.
- Zip with a symlink entry, a hardlink entry, or a reparse-point attribute:
  rejected.
- Zip with an alternate-data-stream entry (`a.txt:b`): rejected.
- Zip containing `CON`, `NUL`, `COM1`, or a component with a trailing dot or
  space: rejected.
- Zip with two entries colliding under case folding / NFC: rejected.
- Zip bomb (entry count, per-entry size, total size): rejected at each limit.
- Zip producing a path over the platform length limit: rejected with a clear
  message, nothing partially written.
- Zip with SKILL.md at root: rejected with a clear message.
- Failed install is atomic: temporary tree removed, skills dir unchanged.
- Valid zip: appears in the list, enabled only after confirm.
- macOS: quarantine attribute present before confirm, stripped after.
- Disable: name no longer in the mock catalog preface.
- Remove user skill: directory gone; bundled cannot be removed.
- Git URL parser: extracts owner/repo/subdir; rejects `file://`, `git://`, SSH
  remotes, and non-https (except the test fixture); no `git` binary is spawned.
- i18n: install dialog keys in all four locales.

---

## 3. Exit criteria

1. A reviewer can install the fixture skill from a zip in the UI on each OS.
2. Malicious archive tests are CI-gated on Linux, Windows, and macOS.
3. Bundled skills survive “remove” attempts.
4. Git installs work without a `git` binary present.
5. `make ci-local` green on all three runners.
