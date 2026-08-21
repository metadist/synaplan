# Connections

Connections let Synaplan reach systems you own — to read from them (Microsoft 365
mail, IMAP mailboxes, MCP servers) or to deliver results into them (a cloud
folder, a calendar). You set a connection up once under **Settings →
Connections**; saved tasks and file delivery then use it.

Credentials are stored in Synaplan's encrypted credential vault and are never
returned by the API. Deleting a connection also deletes its credential.

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
  attached to the reply as the no-connection fallback.
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

Jira and Confluence connect through the **MCP servers** page (*Channels → MCP
servers*), not through this registry: add an Atlassian-capable MCP server
(for example a self-hosted Atlassian MCP service) with its endpoint URL and
access token — the Jira/Confluence quick-start presets prefill the form.

- **Read** (`mcp_fetch`): "search Confluence for the onboarding page and
  summarize it", "list my open Jira tickets" — read-only tools feed the
  answer.
- **Write** (`mcp_action`): "create a Confluence page about X", "open a Jira
  ticket for this bug" — only after the server's owner enables **allow write
  actions** on that connection. Tools that declare themselves destructive
  (deletes) are never allowed.

## Status of a connection

| Status | Meaning |
| ------ | ------- |
| `connected` | The last test reached the system with the stored credential |
| `reauth_required` | The credential was rejected (revoked app password / expired consent) — reconnect; dependent scheduled tasks pause |
| `error` | The system answered but something is wrong (e.g. the folder or calendar no longer exists) |
| `never_tested` | Created but not yet verified |

Use the **Test** button on any connection to re-verify it; the result and
timestamp are stored.
