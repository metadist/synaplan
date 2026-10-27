# Status — sign in to your own MCP server

Plan: [`00_master_plan.md`](./00_master_plan.md). A walk is a dated entry
here, not a chat message.

| Step | Issue | State |
| ---- | ----- | ----- |
| Plan | [#2823](https://github.com/metadist/synaplan/issues/2823) | merged ([#2829](https://github.com/metadist/synaplan/pull/2829)); §0 ticked 2026-10-27 |
| MO0 | see master plan §4 | merged ([#2830](https://github.com/metadist/synaplan/pull/2830)) |
| MO1 | see master plan §4 | merged ([#2831](https://github.com/metadist/synaplan/pull/2831)) |
| MO2 | [#2823](https://github.com/metadist/synaplan/issues/2823) | merged ([#2832](https://github.com/metadist/synaplan/pull/2832)) |
| MO3 | [#2823](https://github.com/metadist/synaplan/issues/2823) | PR [#2833](https://github.com/metadist/synaplan/pull/2833), `Fixes #2823` |

## Changes to the plan made while building

- **One more failure reason, `server_changed`.** MO0 and MO1 refuse a
  sign-in when the server was edited during it; "the sign-in took too long"
  would have been wrong, so MO2 names it.
- **After Save the sentence is "Saved "{name}". Sign-in did not start:
  {reason}"**, not "you're not signed in yet": an earlier sign-in can still
  work when a new attempt fails (Copilot on #2832).
- **Save signs in only when it has to.** A new sign-in server, a switch to
  sign-in, a server not signed in yet or a new address; a rename of a
  signed-in server just saves.
- **Sign-in start and return live in `useMcpSignInStart`**, the method and
  key fields in `McpServerAuthFields.vue`; `McpServersConfiguration.vue`
  stays under the 800-line ratchet (785).
- **The issuer is kept with the PKCE verifier, not on the row.**
  `McpOAuthState`'s constructor is frozen at 11 parameters by the size
  ratchet; a changed authorization server is recognised by its endpoints.

## Walks

### 2026-10-27 — J-MO-1 (mocked provider, automated)

`frontend/tests/e2e/tests/mcp-config.spec.ts` › "add, sign in, see where,
disconnect, delete" (`@ci`). Custom, `https://wiki.e2e-signin.test/mcp`,
Sign in with your account → no key fields, **Save and sign in** → the
confirmation names `auth.e2e-signin.test` → the consent page answers with
the callback redirect → back on the page: toast "Signed in to …", the row
says Connected and "Signs in at auth.e2e-signin.test" → Disconnect → Not
connected → Delete. Passed locally 2/2.

### Not walked yet

- **J-MO-1 against a real provider.** The SSRF guard refuses local
  addresses, `cloudflared` is not installed on this machine, and no account
  at a public sign-in MCP server is available to the agent. Next: the owner
  walks Notion's address through Custom (D10a), or installs `cloudflared`
  for the fixture (D10b).
- **J-MO-2 and J-MO-3 in the browser.** Covered by Vitest and the
  controller test (reasons, flag-off edit, absent method); the browser walk
  follows with the real-provider walk.

The three templates' live metadata was checked against MO1's rules on
2026-10-27: `resource` equals the address, `issuer` matches, all `https`,
S256 — Atlassian (`auth.atlassian.com/<tenant>`), Notion (same host),
Higgsfield (`clerk.higgsfield.ai`).
