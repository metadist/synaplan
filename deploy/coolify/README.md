# Synaplan on Coolify

[`synaplan.yaml`](synaplan.yaml) runs the full Synaplan stack on
[Coolify](https://coolify.io): the web role, worker, scheduler, MariaDB,
Redis, Centrifugo, Apache Tika, Qdrant, and the TTS sidecar. It uses the
published images only and needs no git checkout.

Synaplan is not in Coolify's service catalog yet. Until it is, install it as
a **Docker Compose Empty** resource. The file is written to Coolify's template
rules, so the catalog entry will be the same file.

Like [`../elestio/`](../elestio/) and [`../umbrel/`](../umbrel/), this is an
adapter. The deployment contract (roles, persistence, health, secrets) is the
one in [`../README.md`](../README.md); this page lists only what Coolify does
differently.

## Requirements

- Coolify v4 with its default Traefik proxy, on a server with at least 4 vCPU,
  8 GB RAM and 30 GB free disk (the Cloud-AI profile in
  [`../README.md`](../README.md#requirements)).
- A domain or Coolify's wildcard domain for the server. Use HTTPS for anyone
  who is not on that server: sign-in cookies are marked `Secure` from the
  scheme of that address.
- `linux/amd64` or `linux/arm64`. Every image in the file is published for
  both.

## Install

1. In Coolify, open a project and choose **+ New → Docker Compose Empty**.
2. Paste the whole content of [`synaplan.yaml`](synaplan.yaml) and save.
3. Optional: under **Domains**, give the `backend` service your own domain,
   for example `https://ai.example.com`. Without one, Coolify generates an
   address from the server's wildcard domain.
4. Click **Deploy**. The first start applies the database migrations and seeds
   the model catalog; the web role reports healthy after a few minutes.
5. Open the address of `backend` and finish the setup: enter the setup
   token, create the administrator, connect an AI provider, and decide
   whether visitors may sign up. The token is in the **Logs** of the `backend`
   service, in the line that starts with `Setup token:`; every restart prints
   a new one.

Without the token nobody can claim the server through `/setup`. To skip the
browser step, set `BOOTSTRAP_ADMIN_EMAIL` and `BOOTSTRAP_ADMIN_PASSWORD` in
**Environment Variables** before the first deployment; the administrator is
then created on the first start and `/setup` is closed.

Set both or neither: one without the other stops every app container at
start. The password needs 8 to 64 characters; below 16 it must mix lower
case, upper case and a digit. Once you have signed in, delete **both**
variables and redeploy. The account keeps its password, and the password no
longer sits in the container environment.

Without the setup wizard nobody decides on sign-up and guest chat. Since
5.8.9 the first start stores closed defaults: sign-up is **off**, and the Free
plan allows nothing, so a new account cannot use your provider keys until you
raise its level under **Admin → Users**. Guest chat stays **on**: anyone who
reaches the address can chat as a guest. For a server only your team uses,
switch it off right after the first sign-in under **Admin → Configuration**,
or set `GUEST_CHAT_ENABLED=false` before the first deployment.
`REGISTRATION_ENABLED=true` opens sign-up from the start.

## Secrets

Coolify generates every secret on the first deployment and keeps it under
**Environment Variables** of this resource:

| Coolify variable | Used as |
| ---------------- | ------- |
| `SERVICE_PASSWORD_64_APPSECRET` | `APP_SECRET` |
| `SERVICE_PASSWORD_MARIADB` | `DB_PASSWORD`, `MARIADB_PASSWORD` |
| `SERVICE_PASSWORD_MARIADBROOT` | `MARIADB_ROOT_PASSWORD` |
| `SERVICE_PASSWORD_64_REALTIMEAPI` | `REALTIME_API_KEY`, Centrifugo API key |
| `SERVICE_PASSWORD_64_REALTIMETOKEN` | `REALTIME_TOKEN_SECRET`, Centrifugo token key |
| `SERVICE_PASSWORD_CENTRIFUGOADMIN` | Centrifugo admin password |
| `SERVICE_PASSWORD_64_CENTRIFUGOSECRET` | Centrifugo admin secret |

**Never change `SERVICE_PASSWORD_MARIADB`, `SERVICE_PASSWORD_MARIADBROOT` or
`SERVICE_PASSWORD_64_APPSECRET` after the first deployment.** MariaDB keeps
the password it was created with, so a new value locks Synaplan out of its own
database. A new `APP_SECRET` makes the provider keys stored in the database
unreadable and signs everyone out. Keep a copy of these values with your
backups. The two realtime values may change; both sides read the same
variable.

Unlike the quickstart, these secrets are plain environment variables of the
containers, not a file in a volume. Anyone who can open this resource in
Coolify, or run `docker inspect` on the server, can read them.

## Settings

Every other variable in **Environment Variables** has a working default:

| Variable | Default | Meaning |
| -------- | ------- | ------- |
| `SYNAPLAN_VERSION` | the release this file was published with | Image tag of the three app roles. See [Update](#update). |
| `GROQ_API_KEY`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GOOGLE_GEMINI_API_KEY` | empty | Provider keys. Keys added under Admin → AI Provider Setup are stored encrypted in the database instead. |
| `AI_DEFAULT_PROVIDER` | `groq` | Provider for new chats. |
| `BOOTSTRAP_ADMIN_EMAIL`, `BOOTSTRAP_ADMIN_PASSWORD` | empty | Set both to create the administrator on the first start instead of `/setup`. |
| `REGISTRATION_ENABLED`, `GUEST_CHAT_ENABLED` | empty | Pin the sign-up and guest-chat switches of the setup wizard. |
| `MAILER_DSN`, `APP_SENDER_EMAIL` | `null://null`, empty | Outgoing mail. Without a mailer, new accounts cannot confirm their address. |
| `SYMFONY_TRUSTED_PROXIES` | `PRIVATE_SUBNETS` | See below. |

`APP_URL`, `FRONTEND_URL` and both realtime origin lists follow the domain of
the `backend` service. Change the domain under **Domains**, not by editing
those variables: Coolify rewrites them on the next deployment.

### Client addresses

No container publishes a port, so the only peers that reach the web role are
Coolify's Traefik and the stack's own containers, all on private Docker
networks. Traefik replaces any `X-Forwarded-For` a visitor sends with the
address it saw. `SYMFONY_TRUSTED_PROXIES=PRIVATE_SUBNETS` therefore lets
sign-up limits see the visitor instead of the proxy. Behind Cloudflare, add
Cloudflare's published ranges to the value; behind any other proxy in front
of Coolify, add that proxy's address.

## Live chat

The browser opens the WebSocket on the same address
(`/connection/websocket`); the web role passes it to Centrifugo. Coolify's
Traefik forwards WebSocket upgrades without extra labels.

## Large uploads

Traefik v3 ends a request whose body has not arrived within 60 seconds
(`respondingTimeouts.readTimeout`). A large document over a slow line can
therefore fail even though Synaplan would accept it. To allow longer uploads,
open **Servers → your server → Proxy**, add
`--entrypoints.https.transport.respondingTimeouts.readTimeout=600s` (and the
same for `http` if you serve plain HTTP) to the Traefik command, and restart
the proxy. This applies to every resource on that server.

## Update

The three app roles run `ghcr.io/metadist/synaplan:${SYNAPLAN_VERSION}`.
Redeploying never changes the version on its own.

1. Read the release notes of the target version.
2. Back up (see below) and wait until all three backups show **Success**.
3. Set `SYNAPLAN_VERSION` under **Environment Variables** to the release, for
   example `5.9.0`, and click **Restart current version**.

Coolify pulls the new image and recreates the containers; the web role applies
the database migrations on start. Expect about half a minute in which the
address does not answer. Accounts, files and settings stay as they were.

Migrations are not reversed. To go back, restore the backup from step 2 and
set the old version again.

The update notice in the admin area links to
[Update on Coolify](../../docs/UPDATE_COOLIFY.md), which has the same steps.

## Backup

Synaplan keeps its data in the database and in three volumes. Coolify backs
up all of it from the resource's **Backups** page:

| Add Backup → | Target | Archive behavior | Contains |
| ------------ | ------ | ---------------- | -------- |
| Database backup | `db` | — | accounts, chats, settings, encrypted provider keys |
| Storage backup | `Backend: synaplan-uploads` | Keep containers running | uploaded files |
| Storage backup | `Qdrant: synaplan-qdrant` | Stop containers during archive | the document index |

The database backup is a `mariadb-dump` taken while Synaplan runs. Stopping
Qdrant for its archive takes a few seconds; chat keeps working, document
search waits. `synaplan-redis` holds only queues and caches and needs no
backup. Under each schedule, **Enable S3** copies the archives off the
server; without it they stay in `/data/coolify/backups` on the same disk.

Keep the secrets from [Secrets](#secrets) with the backups. A database backup
without `SERVICE_PASSWORD_64_APPSECRET` cannot decrypt the stored provider
keys.

### Restore

Restore all three from the same point in time.

1. **Backups → Import Backup → File**, enter the server path of the database
   backup, **Check File**, then **Restore From File**. This replaces the
   tables. The dumps are in
   `/data/coolify/backups/databases/<team>/<resource>-db-<UUID>/`;
   **Download backup** under **Executions** gives a copy.
2. Click **Stop** on the resource.
3. Coolify does not restore volumes itself. On the server, as root, unpack
   each archive into its volume. The volume names start with the resource's
   UUID, shown in the URL of the resource:

   ```bash
   UUID=your-resource-uuid
   for v in uploads qdrant; do
     f=$(ls -t /data/coolify/backups/volumes/*/*/volume-$UUID-synaplan-$v-*.tar.gz | head -1)
     docker run -i --rm -v "${UUID}_synaplan-$v:/restore" alpine:3.22 \
       sh -c 'find /restore -mindepth 1 -delete && tar -xzf - -C /restore' < "$f"
   done
   ```

4. Click **Start**.

## Not included

Local AI (Ollama), the office profile (Collabora) and file work (secure
compute) are not in this file. Use [`../compose.yaml`](../compose.yaml) for
those.
