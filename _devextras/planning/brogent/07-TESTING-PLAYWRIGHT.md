# BroGent — Testing & Automation (Playwright-first)

## Strategy

We do not rely on real WhatsApp/Office/Dropbox in CI. Instead:

- Create **mock site fixtures** that mimic key UI interactions
- Run Task DSL via a **Playwright executor** against those fixtures
- Keep selectors compatible with:
  - mock sites (`data-testid`)
  - real sites (site pack fallbacks)

This gives fast tests and a stable DSL contract.

## Test Pyramid

- Unit: DSL parsing, interpolation, retries
- Integration: Playwright on mock sites
- Manual smoke: real sites

## Mock Sites (“fixtures”)

Create small local pages for:

- “Fake WhatsApp”
  - chat list
  - search contact
  - message composer
  - send action
- “Fake Dropbox”
  - upload dialog simulation
  - folder tree
- “Fake Office”
  - doc list
  - create doc

Each page exposes stable `data-testid`.

## Playwright Executor

Implement a runtime that:

- loads a task (steps + inputs)
- executes steps using Playwright APIs
- emits the same event types as the extension executor

Output:

- run timeline
- artifacts (screenshots)
- JSON result

## CI Approach

E2E in CI should validate:

- DSL executor correctness
- protocol schema validity
- core tasks on mock sites

Real-site tests are:

- optional
- manual flag
- not required for merge

## Extension Testing (later)

Once DSL is stable:

- add small extension tests:
  - extension loads
  - content script executes steps
  - messaging works

These can run under Playwright with extension loading.

## Context windows (coding guide)

### Window A: Mock sites

- Build Fake WhatsApp, Dropbox, Office.
- Add stable `data-testid`.
- Test: selectors resolve.

### Window B: Playwright executor

- Map DSL steps to Playwright calls.
- Emit events for each step.
- Test: one task per mock site.

### Window C: Contract tests

- Validate protocol and DSL schemas.
- Fail build on schema drift.

