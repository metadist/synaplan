# BroGent (Browser Agent) — Overview

## Goal

Build an Offline/Online Browser Agent. It runs tasks in the user’s browser. It uses a Synaplan plugin with minimal core changes.

- User flow: Synaplan → task → extension → result.
- Primary runtime: Chrome extension, Firefox-ready.
- Integration style: plugin like SortX, isolated endpoints and config.

## Non-Goals (initial)

- Full workflow editor. We start with task-first.
- Headless cloud browser at MVP. We run in the user’s browser.
- All sites. We start with a few, then add site packs.

## Core Concept

### Task

A **Task** is a reusable automation recipe with:

- **Trigger**: push (Synaplan → extension), pull (extension polls), or inbound channels (WhatsApp/email → Synaplan).
- **Target**: a site/app context (WhatsApp Web, Office.com, Dropbox.com, etc.).
- **Steps**: a deterministic DSL of actions (navigate/click/type/wait/extract).
- **Inputs**: parameters (e.g. “send message to X”, “upload file Y to folder Z”).
- **Outputs**: structured results, screenshots, extracted values, audit log.

### Run

A **Run** is an execution instance with:

- status: queued → claimed → running → waiting_for_user → succeeded/failed/cancelled
- streaming progress events
- artifacts (screens, DOM snapshots, extracted data)

## Architecture (triangle)

1) **Synaplan (hub)** — stores tasks, creates runs, routes inbound channels, provides UI for monitoring and confirmations.
2) **Browser extension (executor)** — performs steps on real websites; sends heartbeats + events; can keep an offline queue.
3) **User** — can interact via Synaplan UI, WhatsApp, email; can also approve “dangerous” actions in-browser.

## Offline vs Online Execution (phased)

### Phase A — Offline-first (Browser executes)

- Tasks run in the user’s browser.
- Synaplan is the orchestrator + log store.
- Works even when Synaplan temporarily unreachable (queued offline actions, later sync).

### Phase B — Hybrid (Browser + server worker)

- Synaplan can run “preprocessing/decisions” server-side (LLM parsing, routing, summarization).
- Browser executes deterministic steps.

### Phase C — Online agent (server Playwright)

- Optional: run tasks in headless browsers for “service accounts” where legal/allowed.
- Same Task DSL and same event protocol.

## Extension Design (Chrome first, Firefox-ready)

Use **WebExtension-compatible** primitives:

- background service worker (MV3 in Chrome; Firefox MV3 is evolving—design to allow MV2 fallback if needed)
- content scripts + site adapters
- messaging: runtime ↔ content scripts
- storage: `chrome.storage.local` (or `browser.storage.local`)

Avoid Chrome-only APIs unless strictly required. Keep a thin browser-specific abstraction layer.

## Minimal Synaplan Core Invasion

Everything lives in a plugin, tentatively:

`plugins/brogent/`
- backend: controller(s) + service(s) + config + (optional) migrations if needed
- frontend: small UI module (task list, runs, pairing status)
- storage: prefer `plugin_data` + `BCONFIG` (like SortX), avoid new tables early

## Security & Safety Principles (Hardened)

- **Cryptographic Pairing**: Extension generates a keypair; Synaplan stores the public key. High-risk tasks are signed by Synaplan.
- **Domain-Scoped DSL**: Extension enforces a manifest of allowed actions/selectors per domain (e.g., no password field access).
- **Explicit pairing** between extension and Synaplan user account.
- **Human-in-the-loop** for risky operations (sending messages, file deletes, payments).
- **Audit logs**: every click/type/navigation recorded with timestamps.
- **No credential theft**: extension never asks for passwords; it acts only within logged-in sessions.

## Testing Strategy (Contract-First)

Key idea: define the **Task DSL** and **Protocol** via Zod/JSON-Schema so it can run:

1) **Simulator**: A CLI tool to test Synaplan logic without a browser.
2) **Extension**: The real content-script executor.
3) **Playwright**: Headless executor against **mock site fixtures** (The "Golden Path").

This yields fast CI and stability, and makes Chrome→Firefox migration easier.

## Deliverable Set (planning docs)

This folder will contain:

- `00-OVERVIEW.md` (this file)
- `01-ARCHITECTURE.md` (components, data flows, plugin boundaries)
- `02-PROTOCOL.md` (Synaplan ↔ extension message contracts)
- `03-TASK-DSL.md` (task definition + step types + selectors)
- `04-SITE-PACKS.md` (WhatsApp/Office/Dropbox adapters, selector strategies)
- `05-SECURITY-PRIVACY.md` (pairing, auth, scopes, approvals, PII handling)
- `06-UX-FLOWS.md` (user journeys, approvals, run monitoring)
- `07-TESTING-PLAYWRIGHT.md` (mock sites, CI setup, golden screenshots)
- `08-XP-DELIVERY-PLAN.md` (iteration plan + stories + spikes + acceptance)
- `09-FIREFOX-PORTABILITY.md` (API choices + MV3/MV2 strategy)

## How we will build

- Build vertical slices end-to-end.
- Keep steps deterministic. Use LLMs only for routing in Phase B.
- Every capability ships with:
  - mock-site tests
  - Playwright runs
  - protocol examples
  - safety guards

## Context windows (coding guide)

Use these in one large coding session. Keep each window small.

### Window 1: Contracts

- Read: `02-PROTOCOL.md`, `03-TASK-DSL.md`
- Output: Zod schemas for protocol and DSL
- Tests: schema parse tests

### Window 2: Plugin skeleton

- Read: `01-ARCHITECTURE.md`
- Output: plugin endpoints and storage stubs
- Tests: API request/response tests

### Window 3: Simulator

- Read: `07-TESTING-PLAYWRIGHT.md`
- Output: CLI simulator that runs a task against a mock site
- Tests: golden JSON output

### Window 4: Extension executor

- Read: `03-TASK-DSL.md`, `04-SITE-PACKS.md`
- Output: executor for navigate/click/type/wait
- Tests: run the same task as the simulator

### Window 5: Mock site packs

- Read: `04-SITE-PACKS.md`
- Output: mock pages with `data-testid`
- Tests: Playwright task runs

### Window 6: Approvals

- Read: `02-PROTOCOL.md`, `06-UX-FLOWS.md`
- Output: approval request and response flow
- Tests: approval happy and reject paths

