# BroGent — Site Packs (WhatsApp Web, Office.com, Dropbox.com)

## What is a “Site Pack”

A site pack is a small, versioned bundle of:

- domain patterns
- readiness checks
- selector helpers and fallbacks
- error heuristics
- task templates

## Selector Strategy

Real sites are brittle. Use a layered approach:

- **Primary**: accessibility roles/labels (where stable)
- **Secondary**: stable attributes (e.g. `data-testid`) if present
- **Fallback**: CSS with structural anchors
- **Last resort**: text selectors (locale-sensitive), guarded by locale detection

Rule: every selector has optional `fallbacks[]`.

## Readiness Checks

Every site pack defines:

- `isLoggedIn()` heuristic
- `isReady()` heuristic (DOM markers)
- `getBlockingReason()`:
  - login required
  - captcha / bot detection
  - consent popup
  - offline

If blocked, the executor should:

- pause run as `waiting_for_user`
- request user action with a short prompt

## WhatsApp Web (initial tasks)

### Target domains

- `https://web.whatsapp.com/*`

### Typical hurdles

- QR login
- dynamic DOM, frequent class changes
- message composer sometimes inside contenteditable
- locale differences

### Task templates (v0)

- Send message. Inputs: `to`, `message`. Risk: high.
- Read unread messages. Input: optional `from`. Risk: medium.

## Office.com (initial tasks)

### Target domains

- `https://www.office.com/*`
- plus app domains (Word/Excel online) as needed

### Task templates (v0)

- Open latest document. Output: URL + title.
- Create new Word document. Input: `title`. Risk: medium.

## Dropbox.com (initial tasks)

### Target domains

- `https://www.dropbox.com/*`

### Task templates (v0)

- Upload file. Input: `fileRef`. Risk: high.
- Create folder. Input: `name`, optional `path`.

## How we add new sites quickly

Definition of done:

- at least 1 deterministic task template
- readiness + login detection
- at least 2 selector fallbacks for critical elements
- mock-site coverage for DSL steps
- Playwright contract tests (mock required, real optional)

## Context windows (coding guide)

### Window A: WhatsApp pack

- Define selectors and readiness checks.
- Add send-message template from `03-TASK-DSL.md`.
- Test against mock site in `07-TESTING-PLAYWRIGHT.md`.

### Window B: Dropbox pack

- Define upload flow and approval gates.
- Add `fileRef` handling in DSL.
- Test with mock upload page.


