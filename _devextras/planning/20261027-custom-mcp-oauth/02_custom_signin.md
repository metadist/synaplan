# MO3 — The Custom form offers sign-in

**Issue:** [#2823](https://github.com/metadist/synaplan/issues/2823).
**Commit:** `feat(mcp): let people sign in to their own MCP server`
**Class:** `ota-candidate` (`backend/src/**`, `frontend/src/**`, `frontend/tests/**`).
**Branch:** `feat/20261027-custom-mcp-signin`
**PR body:** `Fixes #2823`.
**Needs:** MO0, MO1 merged and deployed (D1); MO2 merged.

## Problem

Apps → MCP Servers → Add server → Custom shows an address, an auth header
name and an auth header value. There is no way to choose a sign-in.
`auth_mode` is `bearer` unless one of the three sign-in templates set it,
and an `oauth` row's address becomes read-only once it has one, so the
person can neither choose sign-in for their own server nor correct its
address.

While `MCP.OAUTH_CONNECTORS_ENABLED` is off, editing an existing sign-in
row fails: the form always sends `auth_mode: oauth`, and
`McpServerConfigController::validate()` refuses it (`:398-406`).

## Goal

Custom offers three methods (D5). Choosing Sign in with your account hides
the key fields, keeps the address editable, and Save starts the existing
sign-in. The row tells where it signs in. With the flag off the method is
absent and an existing sign-in row can still be renamed, switched off,
disconnected and deleted (D6).

## User-flow

Pointer: Apps → MCP Servers → Add server → Custom. Journeys **J-MO-1** and
**J-MO-3** (master plan §3).

```text
Add a server
[ Templates: Custom ✓ | GitHub | n8n | Jira & Confluence | Notion | … ]

Name     [ Company wiki                         ]
Address  [ https://wiki.example.com/mcp         ]

How Synaplan connects
 ( ) No sign-in            For servers anyone may use.
 ( ) Access key            A key or token from the server. Stored
                           encrypted, never shown again.
 (•) Sign in with your     After you save, you sign in on the server's
     account               own page. Synaplan keeps the sign-in, never
                           your password.

[x] Enabled
[ ] Allow write actions (create/update)

[ Save and sign in ]  [ Cancel ]
```

The key fields (header name, header value) appear only under Access key.
The primary button reads **Save and sign in** while the third method is
chosen, **Save** otherwise.

Row after sign-in:

```text
Company wiki                         Enabled   Connected
https://wiki.example.com/mcp
Signs in at auth.example.com
                      Reconnect  Disconnect  Test  Edit  Delete
```

Exit (UX contract §6):

1. J-MO-1 and J-MO-3 walked in the browser, recorded in STATUS (D10).
2. Back from the server's page the toast names the server and its row is
   scrolled into view with Connected — no other place to look.
3. The method sentences, the read-only sentence and the sign-in host line
   in all five locales; the consequence of each method is its own
   sentence.
4. Empty state unchanged (templates + Custom). Error states are MO2's
   sentences. Flag off: the third method is absent, not greyed.
5. Light, dark, `design-v2`, 320 px: the three options stack, the key
   fields stay readable, the button row wraps.

## Change

### Frontend

- New `frontend/src/components/config/McpServerAuthFields.vue` (D8):
  props `mode`, `authHeader`, `authToken`, `hasSavedToken`,
  `oauthEnabled`, `lockedOAuth` (existing sign-in row while the flag is
  off); emits `update:*`. A `role="radiogroup"` of three options with the
  house form chain, the key fields from today's form moved here
  unchanged.
- `McpServersConfiguration.vue`: replaces the inline key fields with the
  component; drops the `readonly` binding on the address except while a
  template with a fixed address is selected in the add form; the Save
  label; sends `auth_mode` on update only when it changed (D6); after
  `?connected=<id>` scrolls that row into view. The file shrinks.
- `frontend/src/config/mcpServerTemplates.ts`: no new template. Custom's
  `hint` mentions the sign-in method.

### Backend

- `McpServerConfigController::validate()`: refuse `auth_mode: oauth`
  while the flag is off only when the stored mode is not already `oauth`
  (needs the stored server in `update`, D6).
- `serialize()`: `oauth_sign_in_host` — the host of the stored
  authorization endpoint, `null` before the first sign-in or for other
  methods. OpenAPI on list, create and update; create and update also
  document `auth_mode` and `oauth_status`, which they already return.
- `make -C frontend generate-schemas`.

## Copy (en — reviewed before the Vue file)

| Key | Text |
| --- | ---- |
| `mcpServers.authMethodLabel` | How Synaplan connects |
| `mcpServers.authMethod.none.label` | No sign-in |
| `mcpServers.authMethod.none.hint` | For servers anyone may use. |
| `mcpServers.authMethod.bearer.label` | Access key |
| `mcpServers.authMethod.bearer.hint` | A key or token from the server. Stored encrypted, never shown again. |
| `mcpServers.authMethod.oauth.label` | Sign in with your account |
| `mcpServers.authMethod.oauth.hint` | After you save, you sign in on the server's own page. Synaplan keeps the sign-in, never your password. |
| `mcpServers.authMethodOffByAdmin` | This server uses a sign-in, which an administrator has turned off. You can still disconnect or delete it. |
| `mcpServers.saveAndSignIn` | Save and sign in |
| `mcpServers.signInHost` | Signs in at {host} |
| `mcpServers.oauthConnectedToast` | Signed in to {name}. (was: "Signed in successfully") |
| `mcpServers.templates.custom.hint` | Paste the https address of any MCP server. Then choose how Synaplan connects: no sign-in, an access key, or signing in with your account. |

`de`, `es`, `fr`, `tr` in the same pull request.

## Tests

- `frontend/tests/unit/components/config/McpServerAuthFields.spec.ts` —
  three options with the flag on, two with it off; key fields only under
  Access key; locked row shows the read-only sentence and no radio.
- `frontend/tests/unit/components/McpServersConfiguration.spec.ts` —
  Custom + Sign in with your account: no token fields, the address stays
  editable, Save sends `auth_mode: oauth` without `auth_token` and calls
  start; editing a sign-in row with the flag off sends no `auth_mode`;
  `?connected=<id>` scrolls that row. Red on today's code: there is no
  method control and the address is read-only.
- Backend controller test — update of an existing `oauth` row with the flag
  off and no `auth_mode` succeeds; a switch to `oauth` with the flag off is
  refused; `oauth_sign_in_host` serialised.
- `frontend/tests/e2e/tests/mcp-config.spec.ts` (`@ci`) — J-MO-1 with the
  list, start and callback mocked: add, choose sign-in, save, land on
  `/apps/mcp?connected=<id>`, Connected, Disconnect.

## Gate

`make ci-local`, `make -C frontend generate-schemas`, `vue-tsc`,
`make test-e2e`, `make -C frontend test-e2e-layout`. Then J-MO-1 and
J-MO-3 walked and recorded in STATUS.
