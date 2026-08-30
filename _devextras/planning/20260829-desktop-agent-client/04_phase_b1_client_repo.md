# Sprint B1 — Create the extra client and sign in

**Phase B (`synaplan-desktop`), sprint 1 of 5.** Steps `DC1`–`DC5`.

**Goal:** A new repository `synaplan-desktop` exists, **builds and unit-tests
on Windows, macOS, and Linux in every PR**, pairs against a Synaplan instance,
stores the key in that OS's secret store, and can send one streaming chat turn
through `POST /v1/messages`.
**Depends on:** **all of Phase A merged** (`DS1`–`DS18`) on a reachable
instance. Checklist rows 1, 2, 5, 9, 17, 22, 23, and **25–27, 30, 32**.
**Unlocks:** Sprint B2 (skills need a working Messages loop).
**Repos:** **new `synaplan-desktop`**. `synaplan/` only for the
`docs/DESKTOP.md` download section, as a separate docs-only PR.
**Platform rules:** [`13_cross_platform.md`](./13_cross_platform.md) §§1, 2, 4,
9, 10 are binding here.

Do not import the Synaplan Vue SPA. Do not WebView `https://web.synaplan.com`.

**This sprint sets the platform foundations.** The bundle identifier, the
vendor directory names, and the `app_dirs` layout are effectively permanent:
changing them after the first user installs orphans that user's config, skills,
and stored key. Decide them in `DC1`/`DC22`, not later.

---

## 0. Why this sprint exists — and why it starts now, not earlier

The extra client is the product. This sprint proves **account-only
inference**: no Anthropic dashboard, no Claude Code binary, no Agent37.

Under the server-first order (master plan §0.1) this is the **first line of
client code in the epic**. `DC1` is therefore also the moment the repo is
created — decision 23 forbids an earlier scaffold, so that “we already have
the repo” cannot become a reason to start client work while a server step is
open.

What the client can rely on, already shipped and frozen:

| Available from | What |
| -------------- | ---- |
| Sprint A1 | Scoped keys; a `desktop:*` key that cannot touch admin |
| Sprint A2 | `POST /api/v1/desktop/pair`, device list, revoke |
| Sprint A3 | `POST /api/v1/desktop/jobs`, `agent_checkin`, `agent_report_result`, `protocol: 1`, committed fixtures |

Sprints B1–B4 do not use the A3 endpoints; Sprint B5 does. They exist anyway,
which means **no client sprint is ever blocked on a server PR**.

---

## 1. Repo bootstrap (`DC1`)

Create `synaplan-desktop` (private, `main` protected, PR-only):

```
synaplan-desktop/
  AGENTS.md                 # English, conventional commits, gate, no AI footer
  README.md                 # pair against a Synaplan URL
  Makefile                  # ci-local, lint, test, build
  package.json
  src-tauri/
    src/platform/           # app_dirs, secret_store, process, confinement
  src/                      # Vue 3 + TS, script setup
  src/i18n/{en,de,es,tr}.json
  tests/
    confinement/cases.toml  # shared corpus, three runners (DC24)
  docs/DEVELOPMENT.md
  docs/PLATFORMS.md         # per-OS install + build prerequisites
  .github/workflows/ci.yml
```

House rules to copy in spirit (not files) from `synaplan/AGENTS.md` and
`Synamail/AGENTS.md`:

- Four locales, same commit.
- `make ci-local` is the gate (lint, types, unit tests, production build).
- No `VITE_*` for the Synaplan URL — runtime, from pairing.
- Never commit `sk_*`, keychain dumps, or pairing codes.
- Node version: match Synamail (22 + 24 in CI) or document one LTS.
- **All platform divergence lives in `src-tauri/src/platform/`.** No `#[cfg]`
  or `process.platform` branch anywhere else. A reviewer must be able to read
  one directory to know what differs per OS.

### 1.1 CI — three runners from the first commit

Supersedes the earlier "macOS/Windows can be `workflow_dispatch`". The matrix
is [`13_cross_platform.md`](./13_cross_platform.md) §10; the short version:

| Job | Runners | When | Blocking |
| --- | ------- | ---- | -------- |
| Lint + `vue-tsc` | Linux | Every PR | Yes |
| **Unit tests (Rust + TS), incl. the confinement corpus** | **`ubuntu-latest`, `windows-latest`, `macos-latest`** | **Every PR** | **Yes** |
| Debug build | All three | Every PR | Yes |
| Release build + installers | All three | Tag / dispatch | Release only |
| Sign + notarize | Windows, macOS | Tag | Release only |

Why this is not negotiable: path confinement (Sprint B2) is the only control
between a community skill and `id_rsa`, and its behaviour differs most on the
platforms the original plan tested least. A green Linux run says nothing about
a Windows junction.

If runner minutes become a real constraint, drop the **debug build** on
Windows/macOS to `push`-only. Never the confinement corpus.

### 1.2 Platform prerequisites (document in `docs/PLATFORMS.md`)

| OS | Toolchain needed to build |
| -- | ------------------------- |
| Windows | MSVC Build Tools + WebView2 runtime (ship the evergreen bootstrapper in the installer) |
| macOS | Xcode Command Line Tools; universal target needs both `aarch64-apple-darwin` and `x86_64-apple-darwin` |
| Linux | `webkit2gtk`, `libsoup`, `libsecret`, `patchelf` (Ubuntu 22.04 baseline for glibc) |

### 1.3 Signing — deferred here, procured now

Signing and notarization are **out of this sprint**; unsigned local builds are
enough to develop against. They are **not** out of the epic: they gate GA in
Sprint B6 (master plan decision 32). Certificate procurement (Authenticode OV
or EV, Apple Developer ID) has a multi-week lead time and starts during Phase A
so it is not the thing that delays the release.

Until an installer is signed, no download link goes anywhere near the Synaplan
web UI or docs.

---

## 2. Developer steps

### 2.0 Platform directories (`DC22`) — do this before the pairing screen

One `app_dirs` module owns every path. Nothing else in the codebase expands a
home directory, and `~/…` in these planning files is shorthand for
documentation, never an instruction.

| Purpose | Windows | macOS | Linux |
| ------- | ------- | ----- | ----- |
| Config | `%APPDATA%\Synaplan\Desktop\` | `~/Library/Application Support/com.synaplan.desktop/` | `$XDG_CONFIG_HOME/synaplan-desktop/` |
| Skills | `%LOCALAPPDATA%\Synaplan\Desktop\skills\` | same bundle dir `/skills/` | `$XDG_DATA_HOME/synaplan-desktop/skills/` |
| Out-box | `%USERPROFILE%\Synaplan\out\` | `~/Synaplan/out/` | `~/Synaplan/out/` |
| Audit log | `%LOCALAPPDATA%\Synaplan\Desktop\logs\` | `~/Library/Logs/com.synaplan.desktop/` | `$XDG_STATE_HOME/synaplan-desktop/` |

Fixed at `DC1` and never changed: bundle identifier `com.synaplan.desktop`,
Windows vendor path `Synaplan\Desktop`. The out-box deliberately sits in the
user's home on all three platforms so a deck can be found in Explorer or Finder
without knowing what `%LOCALAPPDATA%` means. Full rules and the test
requirements: [`13_cross_platform.md`](./13_cross_platform.md) §2.

### 2.1 Pairing screen (`DC2`)

Fields: **Synaplan address** (https, no trailing junk), **pairing code**,
**computer name** (pre-filled from the OS hostname — sanitize it; a hostname can
contain characters the server device list should not render raw).

`POST {address}/api/v1/desktop/pair`. Store:

- `apiBaseUrl` and `deviceId` in the config file,
- `apiKey` through the `SecretStore` abstraction (`DC23`), never in the config.

Pin the URL. Refuse redirects to a different host.

#### Secret storage per OS (`DC23`)

| OS | Backing store |
| -- | ------------- |
| Windows | Credential Manager, generic credential `Synaplan Desktop` (DPAPI, per user) |
| macOS | login Keychain, item `com.synaplan.desktop` — note the ACL is bound to the signing identity, so **re-verify after `DC28` signing lands** |
| Linux | Secret Service (`libsecret`) via GNOME Keyring or KWallet |

One trait, three implementations, one in-memory double for tests. Headless
Linux has no Secret Service: fail with a named error and an explicitly opt-in
`SYNAPLAN_DESKTOP_ALLOW_PLAINTEXT_KEY=1` fallback that writes `0600`, warns at
every start, and is refused by the unattended poll loop (Sprint B5). A silent
downgrade to plaintext is a security bug, not a convenience.

On 401 the stored key is **deleted**, not left behind.

### 2.2 Chat screen

Minimum:

- Text input, send, streaming tokens from `POST /v1/messages` (`stream: true`).
- Auth: `x-api-key` or `Authorization: Bearer` (one, matching
  `docs/ANTHROPIC_COMPATIBLE_API.md`).
- Model: omit and use the account default, or `GET /v1/models` and a
  simple picker. Do not hardcode `claude-*`.
- Error states: 401 → “This computer was disconnected. Pair again.”;
  404 on `/api/v1/desktop` → “Desktop access is turned off”;
  gateway disabled → reuse Messages gateway wording, not “Claude is down”.

No skill loop yet. A plain text turn is the acceptance test.

### 2.3 Fake upstream for CI (`DC3`)

The desktop tests must **not** hit a real Synaplan. Ship
`tests/fixtures/messages-gateway` (or a tiny mock server) that:

- accepts pair
- streams two SSE events then `message_stop`
- returns 403 on `/admin`

Same idea as `synaplan/_devextras/testing/messages-gateway/`.

**Copy the pair / job fixtures from Phase A instead of inventing them.**
`synaplan/_devextras/testing/desktop/fixtures/` (`DS18`) is the frozen
contract; vendor those JSON files into `tests/fixtures/` with a note naming
the source commit. If a fixture does not match what the client wants, the
client is wrong — or it is a `protocol: 2` conversation with the server (C9).

### 2.4 Synaplan docs touch (`DC5`, docs-only PR in `synaplan/`)

`docs/DESKTOP.md` already exists (`DS18`). This step only adds what could not
be written before the client existed:

- how to install / run a local build,
- the pairing walkthrough with real screenshots,
- removal of the “the client is not released yet” sentence.

This is the **only** kind of `synaplan/` change a Phase B step may make.
Do not add a download URL until binaries exist.

---

## 3. Tests (client repo)

All of these run on **all three runners** (§1.1), with `HOME`, `XDG_*`,
`APPDATA`, `LOCALAPPDATA`, and `USERPROFILE` pointed at a tempdir so a
developer's real installation is never touched.

- Pairing URL validation (reject `http://` except loopback).
- Secret store: key is not written to the config fixture; the in-memory double
  is used in CI; deletion on 401 is asserted.
- Plaintext fallback: refused unless the env flag is set, and asserted to warn.
- `app_dirs` returns the documented path on each OS (three expectations, one
  test) and honours `XDG_*` overrides on Linux.
- Chat: mock SSE is rendered; 401 shows the disconnected copy.
- i18n key parity (en/de/es/tr).
- Hostname sanitization for the pre-filled computer name.
- `make ci-local` green on Linux, Windows, and macOS.

### 3.1 Manual (PR evidence, not CI)

On a dev machine with Synaplan + flag on:

1. Pair with the Channels code.
2. Send “Reply with the word PONG only.”
3. Screenshot + note which catalog model answered.

Do this on **one** OS for `DC4`; the three-OS manual table
([`13_cross_platform.md`](./13_cross_platform.md) §11) is first required at
Sprint B4, and in full at every release.

---

## 4. Exit criteria

1. Repo exists, `main` is protected, and CI runs unit tests **on Linux,
   Windows, and macOS** in every PR (C11 infrastructure is in place before
   there is anything to confine).
2. Pairing stores a scoped key **in the OS secret store** on all three; a
   revoked key shows the disconnected state and the stored key is gone.
3. One streaming chat turn works against a real instance (manual evidence).
4. `app_dirs` is the only place a platform path is constructed; a grep for a
   hardcoded `~/.synaplan-desktop` outside it returns nothing.
5. No Synaplan SPA code was copied in. No Claude Code dependency in
   `package.json` / Cargo.toml.
6. No `synaplan/` PR in this sprint except the `DC5` docs update.
7. Vendored contract fixtures are byte-identical to the Phase A originals and
   name the source commit (C9).
8. `docs/PLATFORMS.md` lists the build prerequisites per OS, and the signing
   plan (Sprint B6) is recorded with the certificate order already placed.
