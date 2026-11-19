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
5. Open the address of `backend` right away and finish the setup:
   create the administrator, connect an AI provider, and decide whether
   visitors may sign up.

**Finish step 5 immediately.** Until an administrator exists, the first visitor
of the address can claim the server through `/setup`. If the address is public
and you cannot finish setup at once, set `BOOTSTRAP_ADMIN_EMAIL` and
`BOOTSTRAP_ADMIN_PASSWORD` in **Environment Variables** before the first
deployment; the administrator is then created on the first start and `/setup`
is closed.

## Secrets

Coolify generates every secret on the first deployment and keeps it under
**Environment Variables** of this resource:

| Coolify variable | Used as |
| ---------------- | ------- |
| `SERVICE_PASSWORD_64_APPSECRET` | `APP_SECRET` |
| `SERVICE_PASSWORD_64_TOKENSECRET` | `TOKEN_SECRET` (accepted, not read) |
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

## Update

The three app roles run `ghcr.io/metadist/synaplan:${SYNAPLAN_VERSION}`.
Redeploying never changes the version on its own.

1. Read the release notes of the target version.
2. Back up (see below).
3. Set `SYNAPLAN_VERSION` under **Environment Variables** to the release, for
   example `5.9.0`, and click **Redeploy**.

The web role applies database migrations on start. To go back, restore the
backup taken in step 2 and set the old version again; migrations are not
reversed.

## Backup

Synaplan's data lives in four volumes of this resource: `synaplan-mariadb`,
`synaplan-qdrant`, `synaplan-uploads` and `synaplan-redis`. Back up all four
together, together with the secrets above. A database backup without
`SERVICE_PASSWORD_MARIADB` and `SERVICE_PASSWORD_64_APPSECRET` cannot be used.
Stop the resource before copying the volumes, so the database and the vector
store are copied at the same point in time.

## Not included

Local AI (Ollama), the office profile (Collabora) and file work (secure
compute) are not in this file. Use [`../compose.yaml`](../compose.yaml) for
those.
