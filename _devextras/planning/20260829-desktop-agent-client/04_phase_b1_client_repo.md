# Sprint B1 — Create the extra client and sign in

**Phase B (`synaplan-desktop`), sprint 1 of 5.** Steps `DC1`–`DC5`.

**Goal:** A new repository `synaplan-desktop` exists, builds on Windows /
macOS / Linux (CI matrix), pairs against a Synaplan instance, and can send
one streaming chat turn through `POST /v1/messages`.
**Depends on:** **all of Phase A merged** (`DS1`–`DS18`) on a reachable
instance. Checklist rows 1, 2, 5, 9, 17, 22, 23.
**Unlocks:** Sprint B2 (skills need a working Messages loop).
**Repos:** **new `synaplan-desktop`**. `synaplan/` only for the
`docs/DESKTOP.md` download section, as a separate docs-only PR.

Do not import the Synaplan Vue SPA. Do not WebView `https://web.synaplan.com`.

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
  src-tauri/                # Tauri 2
  src/                      # Vue 3 + TS, script setup
  src/i18n/{en,de,es,tr}.json
  tests/
  docs/DEVELOPMENT.md
  .github/workflows/ci.yml
```

House rules to copy in spirit (not files) from `synaplan/AGENTS.md` and
`Synamail/AGENTS.md`:

- Four locales, same commit.
- `make ci-local` is the gate (lint, types, unit tests, production build).
- No `VITE_*` for the Synaplan URL — runtime, from pairing.
- Never commit `sk_*`, keychain dumps, or pairing codes.
- Node version: match Synamail (22 + 24 in CI) or document one LTS.

### 1.1 CI

Linux CI must `npm ci && make ci-local`. macOS/Windows build jobs can be
`workflow_dispatch` in v1 if licenses/signing are not ready; **unit tests
must run on Linux in every PR**.

Signing / notarization is **out of this sprint**. Unsigned local builds
are enough.

---

## 2. Developer steps

### 2.1 Pairing screen

Fields: **Synaplan address** (https, no trailing junk), **pairing code**,
**computer name** (pre-filled from OS hostname).

`POST {address}/api/v1/desktop/pair`. Store:

- `apiBaseUrl`
- `deviceId`
- `apiKey` in the **OS keychain** (Tauri plugin), never in plaintext JSON
  in the user config dir except as a last-resort documented flag for
  headless CI.

Pin the URL. Refuse redirects to a different host.

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

- Pairing URL validation (reject `http://` except loopback).
- Keychain mock: key is not written to the config fixture.
- Chat: mock SSE is rendered; 401 shows the disconnected copy.
- i18n key parity (en/de/es/tr).
- `make ci-local` green on Linux.

### 3.1 Manual (PR evidence, not CI)

On a dev machine with Synaplan + flag on:

1. Pair with the Channels code.
2. Send “Reply with the word PONG only.”
3. Screenshot + note which catalog model answered.

---

## 4. Exit criteria

1. Repo exists, CI runs unit tests on every PR, `main` is protected.
2. Pairing stores a scoped key; a revoked key shows the disconnected state.
3. One streaming chat turn works against a real instance (manual evidence).
4. No Synaplan SPA code was copied in. No Claude Code dependency in
   `package.json` / Cargo.toml.
5. No `synaplan/` PR in this sprint except the `DC5` docs update.
6. Vendored contract fixtures are byte-identical to the Phase A originals and
   name the source commit (C9).
