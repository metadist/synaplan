# BroGent — Chrome → Firefox Portability

## Goal

Be Firefox-ready from day one.

## Key Choices

### WebExtension API abstraction

Create a tiny wrapper module that maps:

- `chrome.*` ↔ `browser.*`
- promise-based vs callback APIs

Rule: no direct `chrome` imports outside the wrapper.

### Manifest approach

Chrome: MV3 service worker background.

Firefox support varies. Plan for:

- MV3 where supported
- fallback plan to MV2 (if needed) as a build target

Keep architecture compatible:

- content scripts do most work
- background does coordination/polling

### Permissions discipline

Use minimal permissions:

- host permissions only for whitelisted domains
- optional permissions requested as user enables a site pack

Avoid:

- broad `<all_urls>` unless opt-in

## Testing portability

- Use `webextension-polyfill` types.
- Lint forbids direct `chrome.` usage.
- Playwright tests focus on executor behavior.

## Known differences (to watch)

- MV3 service worker lifecycle differences
- background messaging timing
- storage quotas and behavior
- clipboard permissions

## Context windows (coding guide)

### Window A: API wrapper

- Implement a `browserApi` wrapper.
- Replace direct `chrome` calls.
- Test with mocks.

### Window B: Manifest build

- Build MV3 manifest for Chrome.
- Document MV2 fallback plan.

