> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (1/11)**  
> **← Previous:** [3.6 Secure Compute (File Work)](COMPUTE.md) · **Next:** [4.2 Saved Tasks & n8n Webhooks →](N8N.md)

# External Connections

> **In this guide:** How to connect external cloud storage, calendars, mailboxes, and issue trackers so Synaplan's multi-task planner and Saved Tasks can read live data (`email_search`, `mcp_fetch`) and deliver generated files, emails, and calendar events (`nextcloud`, `folder`, `calendar`, `dropbox`, `outlook`, `mcp_action`).

Connections let Synaplan reach systems you own — to read from them (Microsoft 365
mail, IMAP mailboxes, MCP servers) or to deliver results into them (a cloud
folder, a calendar). You set a connection up once under **Manage → Connections**;
chat plans, saved tasks, and file delivery then use it.

Credentials are stored in Synaplan's encrypted credential vault (`BCREDENTIALS`) and are never
returned by the API. Deleting a connection also deletes its credential.

| Connection | Auth Mechanism | Channel Word / Tool | Direction |
| ---------- | -------------- | ------------------- | --------- |
| **[Nextcloud / ownCloud / WebDAV](#nextcloud--owncloud--generic-webdav-folder-delivery)** | App password | `nextcloud` / `folder` | Write (deliver generated files) |
| **[CalDAV Calendar](#caldav-calendar-event-delivery)** | App password | `calendar` | Write (idempotent `.ics` event delivery) |
| **[Dropbox](#dropbox-folder-delivery)** | OAuth 2.0 (offline refresh) | `dropbox` | Write (deliver generated files) |
| **[Microsoft 365](#microsoft-365)** | OAuth 2.0 (Microsoft Graph) | `email_search`, `outlook`, `Mail.Send` | Read mail + Write calendar events + Send mail |
| **[Jira & Confluence](#jira--confluence-via-mcp)** | Atlassian OAuth (hosted MCP) | `mcp_fetch`, `mcp_action` | Read pages/tickets + Opt-in write |

---

## Nextcloud / ownCloud / generic WebDAV (folder delivery)

Delivers files into a folder on your own cloud. One adapter covers Nextcloud,
ownCloud and any WebDAV server — Nextcloud is a preset, not a separate
integration.

**Setup (Nextcloud preset):**

1. In Nextcloud, create an **app password**: *Settings → Security → Devices &
   sessions → Create new app password*. Your account password is never stored.
2. In Synaplan, open *Settings → Connections → Nextcloud folder & calendar* and
   enter the server address (`https://cloud.example.com`), your username, and
   the app password. The DAV path
   (`/remote.php/dav/files/<username>`) is derived automatically; for other
   WebDAV servers, paste the full DAV collection URL instead.
3. The connection is tested immediately (a `PROPFIND` on the collection).

**Channel name (what the planner uses):** every connection gets a short,
prompt-safe name stored as `config.channel`. Nextcloud folders are `nextcloud`,
generic WebDAV folders are `folder`, CalDAV is `calendar`. That name is the
only identifier the planner may put in `params.channel` — never a numeric id
and never the display label (`nextcloud-Ordner (admin)`). In chat, say
*nextcloud*; the Connections page shows the name as a pill so you can see
exactly what to ask for.

**In chat:** after the connection tests successfully, you can ask Synaplan to
generate something and file it there — for example *“create a picture of a cat
and put it in nextcloud”*. The image still appears in chat; a copy is
uploaded to the connected folder.

**Behaviour:**

- Files land in the configured folder (default `Synaplan`); missing folders are
  created.
- Name conflicts are resolved by renaming (`report (2).docx`) by default;
  `overwrite` can be set in the connection config.
- HTTPS is required, redirects are not followed, and private/reserved network
  addresses are blocked (SSRF guard).
- **Local development only:** a Nextcloud on the Synaplan Docker network
  (`synaplan-nextcloud/docker-compose.dev.yml`, [http://localhost:8081](http://localhost:8081))
  is reached from the backend as `http://nextcloud`. Set
  `DAV_ALLOW_INSECURE_LOCAL=1` (already on in the local compose) while
  `APP_ENV=dev`. Production ignores that flag. Server address in the form:
  `http://nextcloud` — not `http://localhost:8081` (that is the host browser
  URL; inside the backend container `localhost` is the backend itself).
- Failure modes map to the shared vocabulary: a revoked app password reports
  *unauthorized* (and sets the connection to needs-reattention), a full server
  *quota exceeded*, a deleted folder *not found*.

## CalDAV calendar (event delivery)

Puts the events of a generated `.ics` file into a calendar on your own cloud.
Nextcloud/ownCloud serve CalDAV under the same `remote.php/dav` endpoint and
accept the **same app password** as WebDAV — the Nextcloud preset can create
both connections in one step.

**Idempotency (why re-runs never duplicate events):** every event is written
with a deterministic UID derived from the delivering file and source message.
Before writing, the calendar is queried for that UID (`REPORT`
calendar-query), and the write itself is create-only (`If-None-Match: *`) — an
event that already exists is counted as delivered, never created twice.

The `.ics` download always remains available as the no-connection fallback.

### Calendar entries from a file or an earlier step

A calendar request does not have to name the time itself. *"Put the three
flights of this booking into my calendar"* with a picture of the booking, or
*"add the meeting from Tom's mail to my calendar"*, works in one turn:

- The plan reads first (`file_analysis` for a picture or a document,
  `email_search`, `url_fetch`), and one `calendar_event` step takes every
  entry from that text. Without a reading step it uses the text of the files
  attached to the message, else of the files attached earlier in the chat, so
  a follow-up such as *"it is all in the picture"* works too.
- All entries land in one `.ics` and, with a calendar connected, in that
  calendar. The reply lists each entry with its date and times.
- No hour is invented. An entry is written only when the text that was read
  names its start time. A start the planner wrote is used only when the person
  named it: any clock time in the latest message, or that very hour and minute
  earlier in the chat.
- When neither the message nor anything that was read holds a time, the reply
  asks for it. When no calendar is connected, the reply says so once and the
  `.ics` is attached in the same turn.
- Times are the wall-clock times on the page, placed in the person's time
  zone. A flight that lands in another time zone keeps the arrival time as
  printed.

The reading is one call on the Text Analytics model (the Chat model when none
is set). Its instructions are the internal prompt `tools:calendar_entries`.

## Dropbox (folder delivery)

OAuth2 consent flow, the same framework as Microsoft 365 (no password stored;
Dropbox issues a revocable token). Files delivered through this connection are
stored on a US-hosted cloud — the connection UI labels it accordingly.

**Operator setup (once per installation):** create a Dropbox app (scoped
access, permissions `account_info.read` + `files.content.write`), register the
redirect URI, and enter the app key/secret under *Admin → Configuration →
Channels → Dropbox*. The in-app setup guide shows the exact redirect URI and
permission list as copyable text.

**User setup:** *Settings → Connections → Connect Dropbox* → sign in and
consent. The connection is named after the Dropbox account and can be
disconnected at any time (Dropbox side: dropbox.com → Settings → Connected
apps).

**Channel name:** `dropbox`. In chat: *“create a marketing plan document and
put it into my Dropbox”* — the file still appears in chat; a copy is uploaded
to the connected Dropbox.

**Behaviour:**

- Files land in the `Synaplan` folder (created implicitly by the upload); a
  different `folder` can be set in the connection config.
- Name conflicts are resolved by Dropbox's native autorename
  (`report (2).docx`) by default; `overwrite` can be set in the connection
  config.
- Access tokens are short-lived and refreshed unattended (the consent
  requests `token_access_type=offline`), so scheduled tasks keep working. A
  revoked consent flips the connection to `reauth_required` and pauses
  dependent tasks until the user reconnects.
- Failure modes map to the shared vocabulary: a full Dropbox reports *quota
  exceeded*, a revoked grant *unauthorized*, throttling *rate limited* (with
  bounded, `Retry-After`-honoring retries).

## Microsoft 365

OAuth2 consent flow (no password stored; Microsoft issues a revocable token).
See the in-app setup guide under *Admin → Configuration → Channels → Microsoft
365*. Content read through this connection comes from a US-hosted cloud —
the connection UI labels it accordingly.

**What a connected account unlocks in chat:**

- **Mail search** (`email_search`): "What is the latest mail of X regarding
  Y, summarize that for me" searches the connected mailbox live via Microsoft
  Graph (delegated `Mail.Read`, read-only). Search results carry short
  previews; only the newest matching mail's full body is fetched so a
  summarize step has real content. Nothing is stored on the server — mail
  content exists only inside that one answer. IMAP accounts (Channels →
  Email Automation) are searched the same way; a user can have both, and the
  results are merged newest-first.
- **Outlook calendar** (channel `outlook`): "Create a meeting reminder for
  tomorrow at 10am and put it into my Outlook" creates the event directly in
  the connected calendar via Microsoft Graph (delegated
  `Calendars.ReadWrite`). Every event is created with a deterministic Graph
  `transactionId` derived from the delivering file and source message, so a
  re-run never duplicates an event (Graph answers 409 for a repeated
  transaction, which counts as already delivered). The `.ics` download stays
  attached to the reply as the no-connection fallback. The times can also
  come from an attached file or an earlier step, and several entries are
  created in one turn: see
  [Calendar entries from a file or an earlier step](#calendar-entries-from-a-file-or-an-earlier-step).
- **Mail from your own mailbox** (`Mail.Send`): "mail it to me" results are
  sent from the connected account itself (they appear in the Outlook Sent
  items) instead of the platform's system mailbox. If the Graph send fails or
  an attachment exceeds Graph's ~3 MB simple-attachment limit, delivery falls
  back to the system mailbox — the mail always arrives.

**Reconnect after a scope change:** accounts connected before the calendar and
send scopes were added carry only the old grant. They keep working for mail
search; the calendar channel is simply not offered and sends fall back to the
system mailbox. Reconnect the account once (*Settings → Connections →
Reconnect*) to grant the new permissions.

## Jira & Confluence (via MCP)

Jira and Confluence connect through **Manage → Connections → MCP Servers**.
Choose the **Jira & Confluence** card, save the
connection, then sign in with your Atlassian account. Synaplan uses Atlassian's
hosted server (`https://mcp.atlassian.com/v2/mcp`). No API token is needed —
Atlassian refuses API tokens unless an organization admin allows them. You can
revoke access in your Atlassian account at any time.

A Confluence page pasted into chat is read through that signed-in connection
when the task has **MCP data sources** turned on. For normal chat questions
that is General Chat. A task that turns it off, or that limits which
connections it may use, does not call Atlassian.

- **Read** (`mcp_fetch`): "search Confluence for the onboarding page and
  summarize it", "list my open Jira tickets" — read-only tools feed the
  answer.
- **Write** (`mcp_action`): "create a Confluence page about X", "open a Jira
  ticket for this bug" — governed by the tool permissions of the connection
  (below).

## Tool permissions (every MCP connection)

Open a connection's **Permissions** (Notion: **Apps → Notion → Permissions**;
other connections: **Manage → Connections → MCP Servers →** the row's
**Permissions** button) to see every tool the server offers, with its
description. Each tool has one choice:

| Choice | What happens |
| ------ | ------------ |
| **Auto** | The AI uses the tool without asking |
| **Approve** | The chat shows an approval card first. Nothing runs until you approve. Decline and nothing runs |
| **Block** | The tool is never offered to the AI and is refused on the server |

Tools are grouped as **read**, **write** and **destructive**; a category's
choice sets all its tools at once. Defaults: read and write **Auto**,
destructive **Approve**, nothing **Block**. A change shows a toast with
**Undo**, and a tool set away from its default has **Reset to default**. Auto on
a destructive tool asks for a confirmation first.

The category comes from what the server declares about each tool. A tool is
**read** only when the server marks it read-only, and **write** only when the
server marks it as not destructive. Everything else is **destructive**,
including every tool of a server that declares nothing: it asks first until
you set it to Auto. Connections that existed before this rule keep the choices
you made; tools you had left untouched on such a server now ask.

- **Always allow** on an approval card sets that tool to Auto on your
  connection. It is offered for write tools only, never for destructive ones,
  and never lifts a Block.
- **Saved tasks and unattended runs** keep asking before they write, even for
  an Auto tool, unless the step explicitly allows it. Auto set in chat does
  not unlock them.
- With tool approvals switched off instance-wide (`TOOLS.APPROVALS_ENABLED`),
  **Block** still holds, **Approve** tools are refused with a message, and
  **Auto** runs.
- Arguments that delete page content escalate to **Approve** for that call,
  whatever the tool is set to.
- A **Save to Notion** step follows the permission of Notion's `create-pages`
  tool: Block refuses it before any file is uploaded, Approve makes a saved
  task wait for approval (even when the step allows unattended runs) and
  refuses the step in a chat reply, which cannot ask yet.
- A person using a connection shared by an organisation can read its tools but
  cannot change them.
- Connections that existed before this feature let the AI write without
  asking (the old "allow write" switch is gone). A one-time notice on the
  connection says so; destructive tools still ask.

**Connection choice vs. group policy.** The choice on the connection replaces
the instance default for its tools. A group policy and an agent policy can
only tighten it: a tool a group blocks shows as locked in the list and cannot
be raised there.

## Status of a connection

| Status | Meaning |
| ------ | ------- |
| `connected` | The last test reached the system with the stored credential |
| `reauth_required` | The credential was rejected (revoked app password / expired consent) — reconnect; dependent scheduled tasks pause |
| `error` | The system answered but something is wrong (e.g. the folder or calendar no longer exists) |
| `never_tested` | Created but not yet verified |

Use the **Test** button on any connection to re-verify it; the result and
timestamp are stored.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (1/11)**  
> **← Previous:** [3.6 Secure Compute (File Work)](COMPUTE.md) · **Next:** [4.2 Saved Tasks & n8n Webhooks →](N8N.md)
