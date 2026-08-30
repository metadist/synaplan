# Sprint B2 — Agent Skills runtime

**Phase B (`synaplan-desktop`), sprint 2 of 5.** Steps `DC6`–`DC10`.

**Goal:** The desktop client loads installed `SKILL.md` folders, puts name +
description in the model context, and when the model asks for tools, runs
**Read / Write / Bash** only inside the user allowlist and the skill
directory.
**Depends on:** Sprint B1 chat. Checklist rows 4, 8, 10.
**Unlocks:** Sprint B3 (manager) and Sprint B4 (bundled pptx).
**Repos:** `synaplan-desktop` only — no `synaplan/` PR belongs in this sprint.

This is the first sprint that can execute local programs. Treat it as a
security sprint that happens to enable skills.

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

---

## 2. Developer steps

### 2.1 Local config (authority)

`~/.synaplan-desktop/config.toml` (or OS-specific app config), user-edited
from the **This computer** screen:

```toml
[filesystem]
read  = ["~/Documents", "~/Downloads"]
write = ["~/Synaplan/out"]
deny  = ["**/.ssh/**", "**/.env", "**/*.key", "**/.git/config", "**/id_rsa"]
max_file_bytes = 10000000

[skills]
dir = "~/.synaplan-desktop/skills"

[process]
timeout_seconds = 120
# no unrestricted PATH later — Sprint B4 adds python/node/soffice allowlist
```

Defaults: write = `~/Synaplan/out` created on first run; read empty until
the user adds folders. Deny list is always applied after resolve.

### 2.2 Path confinement (Rust)

One module, unit-tested with tempdirs:

1. Expand `~`.
2. `realpath` / canonicalize.
3. Reject if not under an allowlisted root **or** if it matches deny.
4. Skill directory is implicitly readable (and scripts executable).
5. Write only under `write` roots (plus an explicit “skill working dir”
   under `~/Synaplan/out/{skill}/{runId}`).
6. Symlink that escapes the root → deny (the July bypass).

Do not implement confinement in JavaScript only.

### 2.3 Skill loader

Scan `{skills.dir}/*/SKILL.md` + `skills/bundled/*/SKILL.md`.

- Parse YAML frontmatter (`name`, `description` required).
- `name` must match directory name (spec).
- Validate with the same rules as agentskills.io (length, charset).
- Invalid skill: skip + visible error on the Skills page, do not crash.
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
   | `Bash` | `command`, `workdir?` | See §2.5 |

3. On `stop_reason: tool_use`, execute locally, append `tool_result`,
   continue. Cap iterations (e.g. 16) and wall clock (e.g. 240 s) to
   match the gateway’s own loop bounds.
4. Stream only the final assistant text to the UI (or stream text blocks
   as they arrive; hide raw command strings behind a “Working…” card).

`Edit` can be a later alias (read + write). Do not add a `Skill` server
tool that the model cannot satisfy.

### 2.5 Bash policy (v1)

`Bash` is how Agent Skills call `python scripts/thumbnail.py`. It is
**not** a `skill.run` job type (that is Sprint B5, running the same loop).

v1 rules:

- `workdir` must resolve inside the skill dir or `~/Synaplan/out/...`.
- Command is a string. **No** `server-supplied` command in this sprint
  (only the model, after the user typed a request).
- Timeout + max output bytes.
- Env: pass a cleaned environment; do **not** pass the Synaplan API key
  into subprocesses.
- Sprint B4 tightens the binary allowlist (`python`, `node`, `soffice`,
  `markitdown`). In Sprint B2, tests use a fixture script `echo` / `python -c`.

A confirmation dialog for the **first** Bash in a turn is recommended
(“This skill wants to run a program”). Remember-for-this-skill is OK.
Never auto-allow across all skills.

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

All offline, fixed clock, temp HOME.

| Case | Expected |
| ---- | -------- |
| Parse valid SKILL.md | name + description |
| Mismatched directory / name | skipped |
| Read inside allowlist | contents |
| Read `~/.ssh/id_rsa` | denied, no bytes |
| Read via symlink escape | denied |
| Write outside write roots | denied |
| Bash `workdir` outside | denied |
| Tool loop with mock `/v1/messages` that emits one `Write` | file appears |
| API key absent from subprocess env | assert |
| Iteration cap | stop with a user-visible error |

No live model in CI. The fixture upstream plays a recorded `tool_use`.

---

## 4. Exit criteria

1. Fixture skill can create a file in the out-box through the mock loop.
2. Path-escape tests are in CI and fail if someone “simplifies” canonicalize.
3. User can add a read folder in the UI (four locales).
4. Chat without skills still works (empty catalog).
5. `make ci-local` green.
