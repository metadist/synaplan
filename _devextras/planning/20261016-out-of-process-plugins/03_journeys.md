# Journeys, wireframes and copy

Plan of record: [`00_master_plan.md`](./00_master_plan.md). Binding:
[`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) — U1 (journey before
Vue), U7 (five questions on the open surface), U10 (walk before merge), §6
(sprint-file contract).

Each journey is an acceptance demo, walked in the browser on the dev stack
with the MCP stub (OP4) or the spike servers, and entered in STATUS with the
date. "API green" is not a walk.

---

## 1. Journeys

**J-OP-1 — An administrator offers an internal tool to everyone** (OP1–OP5)
Ada is an administrator. Word tools, a Python MCP server, runs next to
Synaplan at `http://word-tools:8000/mcp`. Apps → MCP → **Add server** → Custom
→ name "Word tools", address `http://word-tools:8000/mcp` → **Who can use it:
Everyone on this Synaplan** (only administrators see this choice) → "Share the
person's email with this service" stays off → **Test** → "Found 1 tool:
word_stats — Count words, characters and sentences in a text. It only reads."
→ **Save** → a card: "Copy this signing secret into Word tools now. It is shown
only once." **Copy**, **Done**. The row appears under **For everyone**.
Undo: **Delete** on the row → "Word tools will no longer be offered to anyone.
Assistants and tasks that use it stop using it." Findable: the row on Apps →
MCP, the MCP card under Apps → Connected, and Admin → Audit ("added Word tools
for everyone").

**J-OP-2 — A person uses it in chat and turns it off for themselves** (OP5)
Ben is not an administrator. In chat: "How many words are in this paragraph:
…" → the answer gives the count; the task details name "Word tools ·
word_stats". Apps → MCP → **For everyone** → "Word tools · Added by your
administrator · Reads only · On for you" → **Turn off for me** → "Synaplan will
not use Word tools in your chats, tasks and assistants. Others still can." The
row says "Off for you" with **Turn on for me**. The same question is now
answered without the tool; turning it back on restores it. A server marked
"May change things" asks first through the approval card and the Approvals
inbox, as for any MCP server today.

**J-OP-3 — The service is down, or its secret was rotated** (OP5)
(a) Word tools is stopped. Ben's question is answered without it; the chat
explains the tool error as it does since #2377, naming Word tools. Ada →
**Test** on the row → "Word tools did not answer at word-tools. Check that the
service is running."
(b) Ada → **New secret** → "Until you copy the new secret into Word tools, it
refuses Synaplan's requests." → the card shows the new secret once. Before she
copies it, **Test** says "Word tools refused the request. If you just changed
its secret, copy the new one into the service."

**J-OP-4 — A plugin that is a manifest plus a service** (OP7–OP12)
The operator places `plugins/word_tools/` (manifest, locales, settings schema;
no PHP), starts the service with `WORD_TOOLS_URL`, `WORD_TOOLS_MCP_URL` and the
secret from `app:plugin:secret word_tools`, restarts Synaplan and runs
`app:plugin:install-verified-users word_tools`. Ben → Apps → **Extensions** →
Word tools → the settings page in his language → **Save** → "Saved." →
**Test** → "Word tools answered." In chat, `/words Ein kurzer Satz.` → the
service's reply. Apps → MCP → For everyone → "Word tools · From the plugin Word
tools · On for you". Ada → Admin → System status → "Word tools · Running".
Undo: **Turn off for me** on the MCP row; uninstalling stays an operator
command, as today.

**J-OP-5 — A tool shows its own view in the chat** (OP13)
Ben uses a tool that ships an MCP App: the result card in the chat shows the
tool's own view, in his theme; **Open full view** opens the side panel. When the
view offers "Use this text", the text lands in the composer and is not sent.
A view that cannot load: "This tool's view could not load. The result is shown
as text below."

**J-OP-6 — A Python developer from zero to a tool in chat** (OP6)
On a fresh dev stack, following only `docs/PLUGINS.md`: write the server, add
the signature check, start it with the compose snippet, add it as an
administrator (J-OP-1), ask in chat (J-OP-2). STATUS records the number of
steps and every place where the page was not enough.

---

## 2. Wireframes

Apps → MCP, administrator:

```text
MCP servers                                                       [ Add server ]

For everyone                                    Administrators add these servers.
┌─────────────────────────────────────────────────────────────────────────────┐
│ Word tools                                          Reads only · On for you │
│ Added by an administrator · Everyone on this Synaplan · word-tools          │
│ 1 tool: word_stats                                                          │
│ [ Test ]  [ Edit ]  [ New secret ]  [ Turn off for me ]  [ Delete ]         │
└─────────────────────────────────────────────────────────────────────────────┘

Only you
┌─────────────────────────────────────────────────────────────────────────────┐
│ Company CRM                                                       Connected │
│ crm.example.com · 4 tools                                                   │
│ [ Test ]  [ Edit ]  [ Delete ]                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

Apps → MCP, a person:

```text
For everyone
┌─────────────────────────────────────────────────────────────────────────────┐
│ Word tools                                          Reads only · On for you │
│ Added by your administrator · word-tools · 1 tool: word_stats               │
│ [ Turn off for me ]                                                         │
└─────────────────────────────────────────────────────────────────────────────┘
```

Add server, administrator, after the template picker:

```text
Name               [ Word tools                                   ]
Address            [ http://word-tools:8000/mcp                   ]
Who can use it     (•) Everyone on this Synaplan    ( ) Only me
                   Synaplan reaches this address from its own network.
                   Only administrators can add such addresses.
Sign-in            ( ) None   (•) Header with a key
May change things  [ ] Let the AI use tools that change things (it asks first)
Share email        [ ] Share the person's email with this service
                   [ Test ]                               [ Cancel ]  [ Save ]
```

The secret card, shown once:

```text
┌ Copy this signing secret into Word tools now ─────────────────────────────┐
│ It is shown only once. Word tools uses it to check that a request comes   │
│ from this Synaplan and for which person.                                  │
│ ••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••    [ Copy ]   │
│                                                                 [ Done ]  │
└───────────────────────────────────────────────────────────────────────────┘
```

Buttons and fields follow AGENTS.md (`btn-primary px-4 py-2.5 text-sm
font-medium`, the full form-control chain); the destructive **Delete** uses
`btn-danger` and `useDialog().confirm({ danger: true })`.

---

## 3. The five questions on the row (U7)

| Question | Added by an administrator | Declared by a plugin |
| -------- | ------------------------- | -------------------- |
| Who owns this? | "Added by your administrator" (administrators see who in Admin → Audit) | "From the plugin Word tools" |
| Who else? | "Everyone on this Synaplan" | "Everyone who has Word tools" |
| What can it do? | "Reads only" or "May change things — asks first" | same |
| How do I stop it? | **Turn off for me**; administrators **Delete** | **Turn off for me** |
| Where from, what does it touch? | host name, tool list, "Shares your email with this service" when on | host name, tool list |

---

## 4. Copy (English is binding; the PR adds de, es, fr, tr)

Keys go into the namespace that already owns `mcpServers.*`.

| Key (proposal) | English |
| -------------- | ------- |
| `mcpServers.everyone.title` | For everyone |
| `mcpServers.everyone.adminHint` | Administrators add these servers. |
| `mcpServers.everyone.addedByAdmin` | Added by your administrator |
| `mcpServers.everyone.addedByAnAdmin` | Added by an administrator |
| `mcpServers.everyone.fromPlugin` | From the plugin {plugin} |
| `mcpServers.everyone.audience` | Everyone on this Synaplan |
| `mcpServers.everyone.pluginAudience` | Everyone who has {plugin} |
| `mcpServers.scope.label` | Who can use it |
| `mcpServers.scope.everyone` | Everyone on this Synaplan |
| `mcpServers.scope.me` | Only me |
| `mcpServers.scope.networkHint` | Synaplan reaches this address from its own network. Only administrators can add such addresses. |
| `mcpServers.shareEmail.label` | Share the person's email with this service |
| `mcpServers.shareEmail.on` | Shares your email with this service |
| `mcpServers.forMe.on` | On for you |
| `mcpServers.forMe.off` | Off for you |
| `mcpServers.forMe.turnOff` | Turn off for me |
| `mcpServers.forMe.turnOn` | Turn on for me |
| `mcpServers.forMe.offConsequence` | Synaplan will not use {name} in your chats, tasks and assistants. Others still can. |
| `mcpServers.secret.title` | Copy this signing secret into {name} now |
| `mcpServers.secret.body` | It is shown only once. {name} uses it to check that a request comes from this Synaplan and for which person. |
| `mcpServers.secret.rotate` | New secret |
| `mcpServers.secret.rotateConsequence` | Until you copy the new secret into {name}, it refuses Synaplan's requests. |
| `mcpServers.delete.everyoneConsequence` | {name} will no longer be offered to anyone. Assistants and tasks that use it stop using it. |
| `mcpServers.class.readsOnly` | Reads only |
| `mcpServers.class.mayChange` | May change things — asks first |
| `mcpServers.everyone.emptyAdmin` | Offer a tool to everyone: add an MCP server and choose "Everyone on this Synaplan". |

Banned in primary copy (UX contract §8 plus this plan): sandbox, container,
node, provisioning, tenant, SSRF, HMAC, pseudonym, loopback, RFC 1918. "MCP"
appears only on its own row (the page and its cards).

---

## 5. Empty, error and flag-off states

| State | What the person sees |
| ----- | -------------------- |
| `MCP.INSTANCE_SERVERS_ENABLED` off | No "For everyone" section, no scope choice, no "Turn off for me"; `/api/v1/admin/mcp-servers` and `…/for-me` answer 404 (U11) |
| No install-wide server — a person | The section is absent; the personal empty state is unchanged |
| No install-wide server — an administrator | `mcpServers.everyone.emptyAdmin` + **Add server** (U5) |
| A person types an organisation-network address | "This address is on your organisation's network. Only an administrator can add it, for everyone." Nothing is saved. |
| Kill switch set | "This installation only accepts public addresses for MCP servers (MCP_PUBLIC_SERVERS_ONLY is set). Nothing was saved." |
| Loopback, metadata or reserved address | "This address points at Synaplan itself or at a reserved network and was refused. Nothing was saved." |
| **Test**: not reachable | "{name} did not answer at {host}. Check that the service is running." |
| **Test**: refused (401 / 403) | "{name} refused the request. If you just changed its secret, copy the new one into the service." |
| **Test**: not an MCP server | "{host} answered, but not as an MCP server. Check that the address ends in /mcp." |
| Chat: the server is down | Unchanged: the tool error is explained in the answer (#2377); the walk checks that the server's name appears |
| Plugin service down — settings **Test** | "{plugin} did not answer. Nothing was changed." |
| Plugin service down — slash command | "{plugin} did not answer. Try again in a moment." |
| Plugin env URL unset | No card for people; `app:plugin:list` says "not configured: WORD_TOOLS_URL is not set"; status page "Word tools · Not configured" |
| `PLUGINS.SERVICES_ENABLED` off | Plugin services, settings pages from a schema and `/svc/` routes are absent (404); PHP plugins unchanged |
