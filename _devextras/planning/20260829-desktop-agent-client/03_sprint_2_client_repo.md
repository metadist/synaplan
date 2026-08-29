# Sprint 2 — Create the extra client and sign in

**Goal:** A new repository `synaplan-desktop` exists, builds on Windows /
macOS / Linux (CI matrix), pairs against a Synaplan instance, and can send
one streaming chat turn through `POST /v1/messages`.
**Depends on:** Sprint 1 merged on a reachable instance. Checklist rows 1, 2,
5, 9, 17.
**Unlocks:** Sprint 3 (skills need a working Messages loop).
**Repos:** **new `synaplan-desktop`**. `synaplan/` only if runtime config or
docs need a download URL.

Do not import the Synaplan Vue SPA. Do not WebView `https://web.synaplan.com`.

---

## 0. Why this sprint exists

The extra client is the product. Server pairing without an app is a
dead-end. This sprint proves **account-only inference**: no Anthropic
dashboard, no Claude Code binary, no Agent37.

---

## 1. Repo bootstrap (do this as several D-steps)

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

### 2.3 Fake upstream for CI

The desktop tests must **not** hit a real Synaplan. Ship
`tests/fixtures/messages-gateway` (or a tiny mock server) that:

- accepts pair
- streams two SSE events then `message_stop`
- returns 403 on `/admin`

Same idea as `synaplan/_devextras/testing/messages-gateway/`.

### 2.4 Synaplan docs touch (tiny, own PR if needed)

`docs/DESKTOP.md` (new, short): what it is, pairing, flag, “not Claude
Code”. Link from `docs/ANTHROPIC_COMPATIBLE_API.md` “Related”.

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
5. The Synaplan gate was not required unless `docs/DESKTOP.md` landed there
   (docs-only path is fine).
