# Journeys, wireframes and copy

Plan of record: [`00_master_plan.md`](./00_master_plan.md). Binding:
[`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) — U1 (journey before
Vue), U7 (five questions on the open surface), U10 (walk before merge), §6
(sprint-file contract).

Each journey is an acceptance demo, walked in the browser on the dev stack
with the MCP stub (OP4) or the spike servers, and entered in STATUS with the
date. "API green" is not a walk. The administrator's surfaces follow §0 #27
(Admin → Plugins); if the owner keeps the draft's placement, the same steps
run on Apps → MCP with the administrator's controls inline.

---

## 1. Journeys

**J-OP-1 — An administrator offers an internal tool to everyone** (OP1–OP5)
Ada is an administrator. Word tools, a Python MCP server, runs next to
Synaplan at `http://word-tools:8000/mcp`. Admin → Plugins → **MCP servers** →
**Add server** → Custom → name "Word tools", address
`http://word-tools:8000/mcp` → **Sign-in: Synaplan signs each request** →
"Share the person's email with this service" stays off → **Test** → "Found 1
tool: word_stats — Count words, characters and sentences in a text. It only
reads." → **Save** → a card: "Tell Word tools to trust this Synaplan" with two
values to copy (Synaplan's keys, its own address), "They are not secret, and
you can copy them again here at any time." → **Done**. The row appears under
**For everyone** with "1 tool". Undo: **Delete** on the row → "Word tools
will no longer be offered to anyone. Assistants and tasks that use it stop
using it." Findable: the row in Admin → Plugins, the "For everyone" row on
Apps → MCP, the MCP card under Apps → Connected, and Admin → Audit ("added
Word tools for everyone").

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

**J-OP-3 — The service is down, or refuses Synaplan** (OP5, OP16)
(a) Word tools is stopped. Ben's question is answered without it; the chat
explains the tool error as it does since #2377, naming Word tools. After three
failures the run detail says "Word tools is not answering right now. Synaplan
will try again in a minute." and other people's turns are not slowed. Ada →
**Test** on the row → "Word tools did not answer at word-tools. Check that the
service is running."
(b) Word tools was started without `SYNAPLAN_JWKS_URL`, so it refuses every
call. **Test** says "Word tools refused the request. Check that it reads
Synaplan's keys from http://synaplan/.well-known/synaplan-jwks.json and
expects http://word-tools:8000/mcp." The row's **Trust** link opens the card
of J-OP-1 again.

**J-OP-4 — A plugin that is a manifest plus a service** (OP7–OP11)
Olga, the operator, has started Word tools as in J-OP-7; its manifest now also
declares a service and a settings schema. Ben → Apps → **Plugins** → Word tools
→ the settings page in his language → **Save** → "Saved." → **Test** → "Word
tools answered." In chat, `/words Ein kurzer Satz.` → the service's reply.
Apps → MCP → For everyone → "Word tools · From the plugin Word tools · On for
you". Ada → Admin → System status → "Word tools · Running". Undo: **Turn off
for me** on the MCP row; Ada can **Turn off for everyone** in Admin → Plugins.

**J-OP-5 — A tool shows its own view in the chat** (OP13)
Ben uses a tool that ships an MCP App: the result card in the chat shows the
tool's own view, in his theme; **Open full view** opens the side panel. When the
view offers "Use this text", the text lands in the composer and is not sent.
A view that cannot load: "This tool's view could not load. The result is shown
as text below."

**J-OP-6 — A developer, in any language, from zero to a tool in chat** (OP6, OP20)
On a fresh dev stack, following only `docs/PLUGINS.md`: pick a language, write
the server from the example, add the token check, start it with the compose
snippet, run `app:plugin:check` until it is green, add it as an administrator
(J-OP-1) or as a plugin folder (J-OP-7), ask in chat (J-OP-2). Walked twice:
once in Python, once with an existing stdio tool behind the bridge. STATUS
records the number of steps and every place where the page was not enough.

**J-OP-7 — An operator starts a tool together with Synaplan** (OP17–OP20)
Olga runs a self-hosted Synaplan. She copies the folder `word_tools/` into
`plugins/` next to `compose.yaml`, adds `- plugins/word_tools/compose.yaml`
under `include:` in `compose.override.yaml`, and runs her usual start
(`deploy/scripts/run.sh` with `SYNAPLAN_COMPOSE_OVERRIDE`, which checks the
plugin's compose file first; on a dev stack it is `make up`). Within a minute:
Ben finds "Word tools · From the plugin Word tools · On for you" under Apps →
MCP → For everyone and uses it in chat (J-OP-2); Ada finds it in Admin →
Plugins as "Running · 1 tool · Added from the plugins folder", and Admin →
Audit says "Word tools was registered from the plugins folder". Undo: Ada →
**Turn off for everyone** → "Nobody can use Word tools until you turn it on
again. Its service keeps running." Olga removes the `include:` line and the
folder and starts again: the row becomes "Removed from the plugins folder"
with **Delete**, and people no longer see it. If the plugin's compose file is
refused, the start stops before anything runs: "plugins/word_tools/compose.yaml:
the service "backend" uses a name Synaplan needs. Rename it, for example to
"word-tools". Nothing was started."

**J-OP-8 — A shared server changes its tools** (OP5)
Word tools, added by Ada (J-OP-1), is updated and now lists `word_translate`
and a new description for `word_stats`. Admin → Plugins shows a count on the
MCP servers tab; the row says "2 tool changes wait for you. People keep using
the other tools until you review these." → **Review** lists "New:
word_translate — may change things" and "Changed: word_stats (description)"
with the old and new text → **Accept** both → "Everyone can use the accepted
tools now." Ben's chat uses `word_stats` again; `word_translate` follows the
server's "May change things" setting, like every tool that changes things:
off, it stays unavailable; on, it asks first. Leaving a change pending keeps
that tool off; nothing else changes.

---

## 2. Wireframes

Admin → Plugins, administrator:

```text
Plugins                                         [ Plugins ]  [ MCP servers  (2) ]

MCP servers for everyone                                            [ Add server ]
┌─────────────────────────────────────────────────────────────────────────────┐
│ Word tools                                       Running · Reads only       │
│ Added by Ada · Everyone on this Synaplan · word-tools · 1 tool              │
│ 2 tool changes wait for you. People keep using the other tools until you    │
│ review these.                                                  [ Review ]   │
│ [ Test ]  [ Edit ]  [ Trust ]  [ Turn off for everyone ]  [ Delete ]        │
└─────────────────────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────────────────────┐
│ Ticket search                                    Running · May change things│
│ From the plugin Ticket search · Added from the plugins folder · 3 tools     │
│ [ Test ]  [ Turn off for everyone ]                                         │
└─────────────────────────────────────────────────────────────────────────────┘
```

Apps → MCP, a person (administrators also see **Manage**, a link to Admin →
Plugins):

```text
For everyone
┌─────────────────────────────────────────────────────────────────────────────┐
│ Word tools                                          Reads only · On for you │
│ Added by your administrator · word-tools · 1 tool: word_stats               │
│ [ Turn off for me ]                                                         │
└─────────────────────────────────────────────────────────────────────────────┘

Only you
┌─────────────────────────────────────────────────────────────────────────────┐
│ Company CRM                                                       Connected │
│ crm.example.com · 4 tools                                                   │
│ [ Test ]  [ Edit ]  [ Delete ]                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

Add server, administrator, after the template picker:

```text
Name               [ Word tools                                   ]
Address            [ http://word-tools:8000/mcp                   ]
                   Synaplan reaches this address from its own network.
                   Only administrators can add such addresses.
Sign-in            ( ) None   ( ) Header with a key   (•) Synaplan signs each request
                   Recommended for your own tools: the service learns who is asking
                   and needs no password from you.
May change things  [ ] Let the AI use tools that change things (it asks first)
Share email        [ ] Share the person's email with this service
                   [ Test ]                               [ Cancel ]  [ Save ]
```

The trust card, shown after **Save** and again from **Trust**:

```text
┌ Tell Word tools to trust this Synaplan ───────────────────────────────────┐
│ Set these two values in Word tools. They are not secret, and you can copy │
│ them again here at any time.                                              │
│ Synaplan's keys  SYNAPLAN_JWKS_URL=http://synaplan/.well-known/…  [ Copy ] │
│ Its own address  SYNAPLAN_AUDIENCE=http://word-tools:8000/mcp     [ Copy ] │
│                                                                 [ Done ]  │
└───────────────────────────────────────────────────────────────────────────┘
```

Buttons and fields follow AGENTS.md (`btn-primary px-4 py-2.5 text-sm
font-medium`, the full form-control chain); the destructive **Delete** uses
`btn-danger` and `useDialog().confirm({ danger: true })`.

---

## 3. The five questions on the row (U7)

| Question | Added by an administrator | Started from a plugin folder |
| -------- | ------------------------- | ---------------------------- |
| Who owns this? | "Added by your administrator" (administrators see who, and Admin → Audit) | "From the plugin Word tools" (administrators also see "Added from the plugins folder") |
| Who else? | "Everyone on this Synaplan" | "Everyone on this Synaplan" |
| What can it do? | "Reads only" or "May change things — asks first"; the accepted tools | same |
| How do I stop it? | **Turn off for me**; administrators **Turn off for everyone** or **Delete** | **Turn off for me**; administrators **Turn off for everyone**; the operator removes the folder |
| Where from, what does it touch? | host name, tool list, "Shares your email with this service" when on | host name, tool list |

---

## 4. Copy (English is binding; the PR adds de, es, fr, tr)

Keys go into the namespace that already owns `mcpServers.*`; the Admin page's
keys into the namespace that owns the other Admin pages.

| Key (proposal) | English |
| -------------- | ------- |
| `mcpServers.everyone.title` | For everyone |
| `mcpServers.everyone.adminHint` | Administrators add these servers. |
| `mcpServers.everyone.addedByAdmin` | Added by your administrator |
| `mcpServers.everyone.addedByAnAdmin` | Added by an administrator |
| `mcpServers.everyone.fromPlugin` | From the plugin {plugin} |
| `mcpServers.everyone.audience` | Everyone on this Synaplan |
| `mcpServers.everyone.manage` | Manage |
| `mcpServers.scope.networkHint` | Synaplan reaches this address from its own network. Only administrators can add such addresses. |
| `mcpServers.signIn.synaplan` | Synaplan signs each request |
| `mcpServers.signIn.synaplanHint` | Recommended for your own tools: the service learns who is asking and needs no password from you. |
| `mcpServers.shareEmail.label` | Share the person's email with this service |
| `mcpServers.shareEmail.on` | Shares your email with this service |
| `mcpServers.forMe.on` | On for you |
| `mcpServers.forMe.off` | Off for you |
| `mcpServers.forMe.turnOff` | Turn off for me |
| `mcpServers.forMe.turnOn` | Turn on for me |
| `mcpServers.forMe.offConsequence` | Synaplan will not use {name} in your chats, tasks and assistants. Others still can. |
| `mcpServers.trust.title` | Tell {name} to trust this Synaplan |
| `mcpServers.trust.body` | Set these two values in {name}. They are not secret, and you can copy them again here at any time. |
| `mcpServers.trust.keys` | Synaplan's keys |
| `mcpServers.trust.audience` | Its own address |
| `mcpServers.tools.pending` | {count} tool change waits for you. People keep using the other tools until you review it. \| {count} tool changes wait for you. People keep using the other tools until you review these. |
| `mcpServers.tools.new` | New |
| `mcpServers.tools.changed` | Changed |
| `mcpServers.tools.accepted` | Everyone can use the accepted tools now. |
| `mcpServers.notAnswering` | {name} is not answering right now. Synaplan will try again in a minute. |
| `mcpServers.delete.everyoneConsequence` | {name} will no longer be offered to anyone. Assistants and tasks that use it stop using it. |
| `mcpServers.class.readsOnly` | Reads only |
| `mcpServers.class.mayChange` | May change things — asks first |
| `mcpServers.everyone.emptyAdmin` | Offer a tool to everyone: add an MCP server and choose "Synaplan signs each request". |
| `plugins.admin.fromFolder` | Added from the plugins folder |
| `plugins.admin.turnOffForEveryone` | Turn off for everyone |
| `plugins.admin.turnOffConsequence` | Nobody can use {plugin} until you turn it on again. Its service keeps running. |
| `plugins.admin.removed` | Removed from the plugins folder |
| `plugins.admin.notConfigured` | Not configured: {variable} is not set |
| `plugins.admin.refused` | Not loaded: {reason} |
| `plugins.admin.empty` | Start a tool together with Synaplan: put its folder into plugins and add one line to your override file. |

The 2026-10-16 keys `mcpServers.scope.label`, `.scope.everyone`, `.scope.me`
and `mcpServers.secret.*` are gone: there is no scope choice outside Admin and
no secret to show.

Banned in primary copy (UX contract §8 plus this plan): sandbox, container,
node, provisioning, tenant, SSRF, HMAC, JWT, JWKS, token, pseudonym, loopback,
RFC 1918, compose. "MCP" appears only on its own row (the page, its tab and its
cards). Values the administrator copies (`SYNAPLAN_JWKS_URL=…`) are values, not
copy.

---

## 5. Empty, error and flag-off states

| State | What the person sees |
| ----- | -------------------- |
| `MCP.INSTANCE_SERVERS_ENABLED` off | No "For everyone" section, no MCP servers tab in Admin → Plugins, no "Turn off for me"; `/api/v1/admin/mcp-servers` and `…/for-me` answer 404 (U11) |
| No shared server — a person | The section is absent; the personal empty state is unchanged |
| No shared server — an administrator | `mcpServers.everyone.emptyAdmin` + **Add server** (U5) |
| No plugin — an administrator | `plugins.admin.empty` + **How to add a plugin** (the docs page) (U5) |
| A person types an organisation-network address | "This address is on your organisation's network. Only an administrator can add it, for everyone." Nothing is saved. |
| Kill switch set | "This installation only accepts public addresses for MCP servers (MCP_PUBLIC_SERVERS_ONLY is set). Nothing was saved." |
| Loopback, metadata or reserved address | "This address points at Synaplan itself or at a reserved network and was refused. Nothing was saved." |
| **Test**: not reachable | "{name} did not answer at {host}. Check that the service is running." |
| **Test**: refused (401 / 403) | "{name} refused the request. Check that it reads Synaplan's keys from {keysUrl} and expects {audience}." |
| **Test**: not an MCP server | "{host} answered, but not as an MCP server. Check that the address ends in /mcp." |
| **Test**: only the newer MCP version (before OP15 ships) | "{name} only speaks a newer MCP version than this Synaplan. Update Synaplan, or let the server accept the 2025 version too." |
| Chat: the server is down | Unchanged: the tool error is explained in the answer (#2377); the walk checks that the server's name appears; after three failures `mcpServers.notAnswering` in the run detail |
| A tool asks for input | "This tool asks for input Synaplan cannot give yet." |
| Tool changes pending — administrator | `mcpServers.tools.pending` on the row, the count on the tab (J-OP-8) |
| Plugin service down — settings **Test** | "{plugin} did not answer. Nothing was changed." |
| Plugin service down — slash command | "{plugin} did not answer. Try again in a moment." |
| Plugin env URL unset | No row for people; `app:plugin:list` and Admin say "Not configured: WORD_TOOLS_URL is not set"; status page "Word tools · Not configured" |
| Plugin refused (invalid field, version, code in the self-hosted plugins folder) | No row for people; Admin "Not loaded: {reason}" naming the field and the fix |
| Plugin compose file refused (command line) | "plugins/word_tools/compose.yaml: {what} — {fix}. Nothing was started." |
| `PLUGINS.SERVICES_ENABLED` off | Plugin services, settings pages from a schema and `/svc/` routes are absent (404); PHP plugins unchanged |
