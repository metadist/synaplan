# MO2 — Say why a sign-in could not start

**Issue:** [#2823](https://github.com/metadist/synaplan/issues/2823) ("The interface explains discovery or sign-in failures in user-friendly language").
**Commit:** `fix(mcp): name the reason when a server sign-in cannot start`
**Class:** `ota-candidate` (`backend/src/**`, `frontend/src/**`, `frontend/tests/**`).
**Branch:** `fix/20261027-mcp-signin-errors`
**PR body:** `Refs #2823`.

## Problem

Connect on a sign-in row calls `POST /api/v1/mcp-servers/{id}/oauth/start`.
When discovery or registration fails, the controller returns the
exception text (`McpOAuthController::start`, `:77-83`) and the page shows
it as the toast (`McpServersConfiguration.vue`, `startOAuth`):
"Could not load protected resource metadata (HTTP 404)", "This server does
not support automatic app registration". English only, implementation
words, no next step.

The HTTP status is chosen by matching the English message
(`str_contains($e->getMessage(), 'disabled by an administrator')`), and
the callback chooses its `oauth_error` reason the same way (`:123`).
Rewording a message changes the API.

After Save on a new sign-in row the page saves, then starts the sign-in.
If the start fails the user sees "Saved" and then the raw error, and is
not told that the row exists but is not signed in.

## Goal

Every way a sign-in can fail to start or finish ends in one sentence a
non-technical person understands, in five locales, with what to do next.
After Save it says both halves: saved, and not signed in.

## Change

### Backend

- `App\Service\Mcp\McpOAuthFailure` — a string-backed enum. Cases:

  | Case | When |
  | ---- | ---- |
  | `disabled` | `MCP.OAUTH_CONNECTORS_ENABLED` is off |
  | `calls_off` | `MCP.CLIENT_ENABLED` is off for this person |
  | `address_not_allowed` | private, loopback or invalid address |
  | `https_required` | an address in the sign-in path is not `https` |
  | `unreachable` | no answer, timeout, transport error |
  | `no_signin` | no protected-resource metadata, or no authorization server named |
  | `metadata_invalid` | metadata unreadable, incomplete or inconsistent |
  | `no_registration` | no registration endpoint |
  | `unsupported_method` | no PKCE S256 |
  | `registration_refused` | the registration endpoint answered with an error |
  | `expired` | the signed state or the PKCE verifier is gone or does not match |
  | `exchange_failed` | the token endpoint refused the code |
  | `denied` | the person cancelled on the server's page |
  | `missing_code` | the callback arrived without code or state |

- `McpOAuthException` carries a `McpOAuthFailure $reason` (constructor
  argument, default `metadata_invalid`). Every `throw` in
  `McpOAuthDiscovery`, `McpOAuthRegistration` and `McpOAuthConsentService`
  names its case.
- `McpOAuthController::start` answers
  `{ success: false, code: <case>, error: <English text for logs> }`, 403
  for `disabled` and `calls_off`, 400 otherwise. No `str_contains`.
- `McpOAuthController::callback` puts the case into `oauth_error`.
  `OAuthException` from the token exchange maps to `exchange_failed`.
- OpenAPI: `code` with its enum on the start endpoint's 400 and 403
  responses. `make -C frontend generate-schemas`.

### Frontend

- `McpServersConfiguration.vue` reads `ApiError.code` in `startOAuth` and
  the `oauth_error` query in `consumeOAuthCallback`, and maps both through
  one function to `mcpServers.signInError.<case>`. An unknown or missing
  code shows `signInError.unknown`. The server's `error` text is never
  shown.
- `save()`: when the row was saved and the start failed, one toast:
  `mcpServers.savedNotSignedIn` with the reason sentence. The row stays in
  the list with Connect.
- The existing `mcpServers.oauthError.*` keys move under
  `mcpServers.signInError.*` (same text for `denied`, `missing_code`,
  `exchange_failed`, `disabled`). Old keys are removed in all five locales
  and from the locale-parity ledger if listed there.

## Copy (en — reviewed before the Vue change)

| Key | Text |
| --- | ---- |
| `signInError.disabled` | Connecting with a sign-in is turned off by an administrator. |
| `signInError.calls_off` | Calls to MCP servers are turned off. An administrator can turn them on in System configuration. |
| `signInError.address_not_allowed` | This address can't be used. Enter the server's public address. |
| `signInError.https_required` | Sign-in needs a secure address. Use an address that starts with https://. |
| `signInError.unreachable` | The server didn't answer. Check the address and try again. |
| `signInError.no_signin` | This server doesn't offer a sign-in. If it gave you a key, choose Access key instead. |
| `signInError.metadata_invalid` | The server's sign-in setup is incomplete, so Synaplan stopped. Ask whoever runs the server to check it. |
| `signInError.no_registration` | This server doesn't let Synaplan register for sign-in. Ask whoever runs it for an access key and choose Access key. |
| `signInError.unsupported_method` | This server uses a sign-in method Synaplan doesn't support. Ask whoever runs it for an access key. |
| `signInError.registration_refused` | The server refused to register Synaplan for sign-in. Try again later or ask whoever runs it. |
| `signInError.expired` | The sign-in took too long. Click Connect to start again. |
| `signInError.exchange_failed` | Could not finish sign-in. Please try again. |
| `signInError.denied` | Sign-in was cancelled. |
| `signInError.missing_code` | Sign-in did not complete. Please try again. |
| `signInError.unknown` | Sign-in could not start. Try again, or ask whoever runs the server. |
| `savedNotSignedIn` | Saved "{name}", but you're not signed in yet: {reason} |

`de`, `es`, `fr`, `tr` in the same pull request, with the canonical terms
from the root `AGENTS.md`.

## User-flow

Pointer: Apps → MCP Servers, a row whose method is Sign in with your
account. Journey **J-MO-2** (master plan §3), here with the Notion template
because Custom offers sign-in only after MO3: break the address by editing
the row through the API, Connect, read the sentence; cancel on Notion's
page, read the sentence.

Exit (UX contract §6):

1. J-MO-2 walked in the browser, recorded in STATUS.
2. The row stays where the person started, with Connect; nothing new to
   find.
3. Every sentence above in all five locales; no HTTP code, no server text.
4. Empty and flag-off states unchanged; the error state is this step.
5. Toasts checked in light, dark and at 320 px.

## Tests

- `backend/tests/Unit/Service/Mcp/McpOAuthDiscoveryTest.php` — every
  discovery failure asserts its `reason`.
- `backend/tests/Unit/Service/Mcp/McpOAuthConsentServiceTest.php` —
  `disabled`, `expired`, registration failures.
- `backend/tests/Integration/Controller/McpServerConfigControllerTest.php`
  or a new `McpOAuthControllerTest` — start answers `code` and 400 / 403;
  the callback redirects with `oauth_error=<case>`.
- `frontend/tests/unit/components/McpServersConfiguration.spec.ts` —
  `ApiError` with `code: 'no_registration'` shows that sentence and not the
  error text; an unknown code shows `unknown`; Save + failed start shows
  `savedNotSignedIn`; `?oauth_error=denied` shows the cancelled sentence.
- `frontend/tests/e2e/tests/mcp-config.spec.ts` (`@ci`) — mocked start
  answering `400 { code: 'no_signin' }` shows the sentence.

Red first: the Vitest cases fail on today's code because the toast is the
raw `error` text.

## Gate

`make ci-local`, `make -C frontend generate-schemas`, `vue-tsc`,
`make test-e2e`. Then J-MO-2 in STATUS.
