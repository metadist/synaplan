# Sign in to your own MCP server — 2026-10-27

**Status:** Plan. §0 unticked.
**Issue:** [#2823](https://github.com/metadist/synaplan/issues/2823) (michael-laemmlein-marini).
**Base:** `main` at `cc9957b41`.
**Goal:** A person adds the address of their own MCP server, chooses
"Sign in with your account", signs in on that server's page, comes back to
Apps → MCP Servers and sees the connection working. Only when an
administrator allows sign-in connectors, and only after the sign-in path is
safe for addresses Synaplan has never seen.

| File | Step | Kind |
| ---- | ---- | ---- |
| (private, see §4) | MO0 — security fix | fix |
| (private, see §4) | MO1 — security fix | fix |
| [`01_signin_errors.md`](./01_signin_errors.md) | MO2 — say why a sign-in could not start | fix |
| [`02_custom_signin.md`](./02_custom_signin.md) | MO3 — the Custom form offers sign-in (#2823) | feat |
| [`STATUS.md`](./STATUS.md) | step log | — |

---

## 1. What the issue asks, and what is already there

The backend runs the whole OAuth path for any address today:
protected-resource discovery (RFC 9728), authorization-server metadata
(RFC 8414), dynamic client registration (RFC 7591), PKCE S256, the
callback, refresh, and reconnect. `POST /api/v1/mcp-servers` accepts
`auth_mode: oauth` for any URL while `MCP.OAUTH_CONNECTORS_ENABLED` is on.
The list on the MCP servers page already shows the sign-in status and
offers Connect, Reconnect, Disconnect and Test for an `oauth` row.

What is missing is in the form only
(`frontend/src/components/config/McpServersConfiguration.vue`, 789 lines):

- Custom has no way to pick the sign-in method. `auth_mode` is set only by
  the Jira & Confluence, Notion and Higgsfield templates.
- An `oauth` row's address is read-only once it has one
  (`:readonly="form.authMode === 'oauth' && !!form.url"`), so a typo in a
  custom address could not be fixed.
- A failed start shows the server's English exception text in a toast.

So the feature itself is one form step (MO3) plus honest failure copy
(MO2). It is not shipped first: opening the form for arbitrary addresses
makes reachable for every user a path that so far only three fixed,
known-good addresses used. The security review of 2026-10-27 found that
path needs two fixes first (MO0, MO1). Their details are private (§4).

---

## 2. Decisions (§0)

Proposed. The owner ticks or changes each row before MO0 starts.

| # | Decision | Why |
| - | -------- | --- |
| D1 | [ ] **Security before the feature.** MO0 and MO1 merge and are deployed on web.synaplan.com before MO3 merges. | MO3 makes the sign-in path reachable for any address. |
| D2 | [ ] **MO0 and MO1 are developed as the private note says** (§4), like row 29's IC0. Their public commit subjects are written in the note. | The repository is public. |
| D3 | [ ] **Keep today's discovery contract.** A custom server must publish RFC 9728 protected-resource metadata and RFC 8414 authorization-server metadata, support RFC 7591 registration and PKCE S256. No OpenID Connect discovery fallback and no hand-entered client id in this track. | The issue says so. A broader discovery order (the MCP spec's OIDC forms) is a follow-up issue, not hidden scope. |
| D4 | [ ] **Sign-in needs `https`.** The server address and every address its sign-in metadata names must be `https`. Access key and No sign-in keep accepting `http` as today. | Tokens over plain `http` can be read on the way. |
| D5 | [ ] **Three methods, plain words:** No sign-in (`none`), Access key (`bearer`), Sign in with your account (`oauth`). The third appears only while `MCP.OAUTH_CONNECTORS_ENABLED` is on. Custom keeps Access key as its default. | U11: flag off ⇒ absent. The words a non-technical user understands. |
| D6 | [ ] **An existing sign-in row while the flag is off** shows its method read-only with one sentence, and can still be renamed, switched off, disconnected and deleted. The backend refuses only a switch *to* `oauth` while the flag is off. | Today the form always sends `auth_mode`, so such a row cannot even be renamed. Undo is a click (bar item 5). |
| D7 | [ ] **Write actions stay off by default** for a custom sign-in server; the person turns them on. | Unchanged default; restated because sign-in servers are often writable. |
| D8 | [ ] **The method and the key fields move into a new `McpServerAuthFields.vue`.** `McpServersConfiguration.vue` does not grow. | It is 789 lines against the 300-line rule. |
| D9 | [ ] **A failed start answers a stable `error_code`.** The page shows one sentence per code in all five locales, never the server's text. Saved-but-not-signed-in says both halves. | U8. The issue's "user-friendly language" criterion. |
| D10 | [ ] **Walks use a real server.** The SSRF guard refuses local addresses, so J-MO-1 runs against (a) Notion's address entered through Custom and (b) a spec-compliant fixture published through a temporary public tunnel (`cloudflared`, installed on the walker's machine only, not a project dependency). A walk that cannot run is a STATUS line that says why. | `_devextras/planning/AGENTS.md`: no invented walks. |

---

## 3. Journeys

Pointer: Apps → MCP Servers (`/apps/mcp`; the sign-in callback answers
with `/channels/mcp`, which the router redirects there), list, Add server,
the Custom card.

- **J-MO-1 — add, sign in, test, undo.** Account `demo@synaplan.com`,
  `MCP.CLIENT_ENABLED` and `MCP.OAUTH_CONNECTORS_ENABLED` on. Add server →
  Custom → name and `https` address → Sign in with your account → the key
  fields disappear → Save. The browser goes to the server's sign-in page,
  consent, back on `/apps/mcp`: toast, the row says Connected and is in
  view. Test lists the tools. Disconnect (one confirm) → Not connected.
  Connect again → Connected. Delete.
- **J-MO-2 — sign-in cannot start.** Same page. An address without
  sign-in metadata, an `http` address, and an address whose server refuses
  registration: each time the row is saved, stays Not connected with
  Connect, and the toast says in one sentence that it was saved, that
  sign-in did not start, and what to do. Cancel on the server's consent
  page → "Sign-in was cancelled", row unchanged.
- **J-MO-3 — the flag.** As `admin@synaplan.com` turn
  `MCP.OAUTH_CONNECTORS_ENABLED` off (System configuration → Channels → MCP
  servers). As the demo user: Sign in with your account is absent from
  Custom and the templates hide as today; the J-MO-1 row (left connected
  for this walk) can be renamed, disconnected and deleted. Turn the flag
  back on: the option is back.
- **J-MO-4 and J-MO-5** belong to MO0 and MO1 and are written in the
  private note.

Ten-second findability: after the sign-in the browser lands on the same
page, the toast names the server, and the row it belongs to is scrolled
into view with its status chip. Nothing new to find elsewhere.

Five questions on the row (U7): the row is the person's own connection
(the page lists only theirs); nobody else uses it; what it can touch is
the address, the sign-in host (new in MO3), the Writes allowed badge and
the tool list after Test; it stops with Disconnect, the enabled switch or
Delete; where it came from is the address.

---

## 4. Security (private)

The security review of 2026-10-27 is recorded in
`synaplan-platform/planning/20261027-mcp-oauth-security-note/README.md`.
It holds MO0 and MO1: what they fix, their tests, their journeys, their
public commit subjects, and the disclosure rule. Nothing from it moves into
this repository — plan, commit message, pull request, issue, test name —
until the rule there allows it. Then this section is replaced by its text.

---

## 5. Order and loop

```
main
 ├── docs/20261027-custom-mcp-oauth          this plan
 ├── MO0                                      see §4
 ├── MO1                                      see §4
 ├── fix/20261027-mcp-signin-errors           MO2
 └── feat/20261027-custom-mcp-signin          MO3   Fixes #2823
```

Each step is one branch from current `main`, one pull request, Copilot
answered, **All Checks Passed** green before the next one starts
([`AGENTS.md`](../AGENTS.md)). MO2 and MO3 reference #2823 (`Refs #2823`
for MO2, `Fixes #2823` for MO3). The owner merges.

Classify before each push:

```bash
node scripts/mobile-impact.mjs --base origin/main --head HEAD --output /tmp/mobile-impact.json
```

MO2 and MO3 are `ota-candidate` (checked 2026-10-27 against the paths
they touch). No step may be `store-required`.

---

## 6. Gates

| Step | Local gate |
| ---- | ---------- |
| Plan | none (markdown only) |
| MO0, MO1 | see §4 |
| MO2 | `make ci-local`, `make -C frontend generate-schemas` then `vue-tsc`, `make test-e2e` |
| MO3 | `make ci-local`, `make -C frontend generate-schemas` then `vue-tsc`, `make test-e2e`, `make -C frontend test-e2e-layout`; J-MO-1…3 walked and recorded in STATUS |

---

## 7. Not in this track

- OpenID Connect discovery, issuers with a path in the RFC 8414 insertion
  form, a hand-entered client id or secret (D3). Follow-up issue after MO3.
- Servers an administrator adds for everyone (row 18, out-of-process
  plugins).
- What a connected server's tools may say to the AI. A custom server can
  steer the model through tool descriptions and results today, with an
  access key as much as with a sign-in; the per-topic opt-in and the
  write switch are the existing limits. This track does not change that.
