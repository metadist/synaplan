# Sprint 4 — Skills manager

**Goal:** Users can see, enable, disable, install, and remove Agent Skills
without touching a terminal. Install sources: local folder, zip, git/GitHub
URL. No Synaplan-operated marketplace.
**Depends on:** Sprint 3 loader.
**Unlocks:** Sprint 5 (bundled pptx appears in this UI).
**Repos:** `synaplan-desktop`. Optional later: a read-only “recommended
skills” JSON on docs — not required.

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

Empty state copy from [`11_ux_and_i18n.md`](./11_ux_and_i18n.md).
Compatibility warning when `compatibility` mentions Claude Code only —
still installable; show “written for another assistant; it may not work”.

### 1.2 Install flows (three buttons)

1. **From a folder** — pick a directory that contains `SKILL.md`. Copy
   (not move) into `{skills.dir}/{name}/`.
2. **From a zip** — must contain `{name}/SKILL.md`, not a bare SKILL.md
   at zip root. Reject `..` entries and symlinks in the archive.
3. **From a Git URL** — `https://github.com/org/repo` or a path to a
   subdirectory (`…/tree/main/skills/pptx`). Shallow clone or download
   a GitHub zipball. Pin to a commit SHA in `skills.json`.

After copy: validate frontmatter; if invalid, delete the copy and error.
Then show the **code execution** confirm dialog (file list + license).

Enable only after confirm.

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

The loader in Sprint 3 already scans disks; this file is enablement +
provenance. Disable = leave files, drop from the model catalog.

### 1.4 Trust copy (required)

Every install dialog must say, in the user’s language:

- This skill can run programs and read files you allow.
- Synaplan did not write or review community skills.
- You can disable or remove it at any time.

No “Claude” in that paragraph.

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

- Zip with `../` entry: rejected, nothing written outside skills dir.
- Zip with symlink: rejected.
- Zip with SKILL.md at root: rejected with a clear message.
- Valid zip: appears in the list, enabled after confirm.
- Disable: name no longer in the mock catalog preface.
- Remove user skill: directory gone; bundled cannot be removed.
- Git URL parser: extracts owner/repo/subdir; rejects `file://` and
  non-https (except test fixture).
- i18n: install dialog keys in all four locales.

---

## 3. Exit criteria

1. A reviewer can install the fixture skill from a zip in the UI.
2. Malicious zip tests are CI-gated.
3. Bundled skills survive “remove” attempts.
4. `make ci-local` green.
