# Synaplan on umbrelOS

`synaplan/` is the complete Umbrel App Store package. Its contents are copied
unchanged into a `synaplan/` directory of a
[getumbrel/umbrel-apps](https://github.com/getumbrel/umbrel-apps) fork; nothing
in it is generated or rewritten on the way.

Like [`../elestio/`](../elestio/), this is an adapter: the deployment contract —
roles, persistence, health, secrets — is the one described in
[`../README.md`](../README.md), and this directory only records where umbrelOS
needs something different.

## Before submitting to the App Store

**The pinned version must contain the auth-cookie fix.** umbrelOS serves apps
over plain `http://<device>.local:<port>`, and up to and including 4.0.13
Synaplan marks its auth cookies `Secure` whenever `APP_ENV=prod`. A browser
never sends those back over HTTP, so the user logs in successfully and is
anonymous again on the next request. `AuthCookieFactory` derives the flag from
the `APP_URL` scheme instead; **4.0.14 is the first release that can be offered
here**, and the package is pinned to it.

### How the pin is raised

Publishing a release tag opens one pull request
(`automation/default-release-version`) that bumps every catalog pin together:

- `elestio.yml` and `deploy/selfhost.env.example`
- this package: `umbrel-app.yml` (`version`), `docker-compose.yml`
  (`APP_VERSION` and the `tag@sha256:…` image pin)

`scripts/set-release-version.mjs` owns those lines. The digest comes from the
multi-arch manifest the release workflow just published, so the PR never invents
one. Do not edit them by hand.

Nothing merges that PR on the spot, because merging restarts every Elestio
instance tracking the default branch. `.github/workflows/release-rollout.yml`
does it in a nightly maintenance window once its guards agree.

### How the package reaches the store

Umbrel cannot pull from this repository, so the package has to be carried into
[getumbrel/umbrel-apps](https://github.com/getumbrel/umbrel-apps) — and that is
`.github/workflows/umbrel-store-sync.yml`'s job, triggered by the raised pins
landing on the default branch. It rebuilds
[`metadist/umbrel-apps`](https://github.com/metadist/umbrel-apps) from upstream
`master`, mirrors `synaplan/` from this directory, runs Umbrel's own
`lint:apps --check-images`, and pushes.

What happens next depends on whether the store already carries the app, because
Umbrel's linter applies different rules to each case:

| | Not in the store yet (today) | Already in the store |
| --- | --- | --- |
| Branch | `synaplan`, the one the open submission is built from | `synaplan-<version>` |
| Pull request | the existing submission is updated | a new one is opened |
| `releaseNotes` | **must stay empty** — an error otherwise | filled from the GitHub release |
| `gallery`, `icon` | **must stay empty** — Umbrel produces the final assets | unchanged |

That is why `releaseNotes: ""` and `gallery: []` in `umbrel-app.yml` are not
placeholders waiting to be filled: for a new submission they are the required
values, and the sync fills `releaseNotes` in the fork only once the app is
released. `submission` points at the pull request the submission lives in.

The merge itself stays Umbrel's: they review and merge store pull requests, and
no automation here can shorten that.

The sync owns `synaplan/` on that branch and force-pushes it. It refuses to run
if the branch has grown changes outside `synaplan/`, so a maintainer's work
elsewhere in the fork can never be discarded silently.

Re-run the login check from *Testing* below against every raised pin: cookies
must be issued without `Secure` over plain HTTP.

## What umbrelOS changes

**One shared Docker network.** Every app on a device runs on
`umbrel_main_network`, and Compose registers both the service name and the
container name as DNS aliases. A second installed app with a service called
`redis` or `centrifugo` therefore claims the same short alias, and Docker
answers with whichever it likes. Every internal address in this package uses the
unique injected name (`synaplan_db_1`, `synaplan_redis_1`, …). Two of those
addresses are not plain application config and need the environment variables
added for this purpose:

- `REALTIME_UPSTREAM_ADDR` — the upstream Caddy proxies `/connection/*` to.
- `SYNAPLAN_WEB_HEALTH_URL` — the endpoint the worker and scheduler block on
  until the web role is serving. Its default addresses the web role as
  `backend`, which is the service name of the reference deployment, not of this
  package. Without this the worker never becomes healthy.

**No `.env`, no Compose profiles.** The package carries literal values, and
secrets come from Umbrel's `derive_entropy` (see below). Because profiles are
unavailable, the optional local-AI services are not merely disabled but absent —
see *Deliberate limits*.

**Umbrel owns the proxy.** `app_proxy` publishes the app on port 8300 and
forwards to `synaplan_web_1:80`. It runs with `PROXY_AUTH_ADD: "false"`, so
Umbrel does not put its own login in front of Synaplan. That is deliberate:
Synaplan has its own multi-user accounts, and several of its endpoints cannot
carry an Umbrel session cookie at all — the chat widget embedded on third-party
websites, the MCP endpoint with its OAuth discovery documents, and mobile
clients authenticating with a bearer token. Roughly a quarter of the apps in the
store do the same.

**Secrets survive the device, not the seed.** `exports.sh` derives six secrets
from the device seed and then keeps them in `data/secrets.env` (mode `0600`),
which wins on every later start. The reason is a specific failure mode: an
umbrelOS backup contains `home` and `app-data` only. The seed lives in
`db/umbrel-seed` and is regenerated at random when missing, so restoring this
app onto a different device would derive a new database password while the
restored data directory still expects the old one — a permanent lockout with no
user-visible cause. This matches the secrets contract in
[`../README.md`](../README.md#generated-secrets-deploydatasecretsenv), and
umbrelOS names app-owned secret storage as its own intended direction (see the
comment on the seed in `modules/apps/apps.ts`).

`BOOTSTRAP_ADMIN_PASSWORD` is excluded from that file on purpose: it uses
Umbrel's `APP_PASSWORD` so `deterministicPassword: true` shows the user the very
password the account was created with. It is read only while no administrator
exists, so a changed value cannot lock anyone out.

## Deliberate limits

- **Cloud AI only.** Ollama and Whisper are absent. Local inference needs far
  more memory than the typical Umbrel device has, and umbrelOS offers Ollama as
  its own app. Users add a provider key under Admin > AI Providers after the
  first login; the app ships without one, so this step is required before the
  first answer.
- **No consistent backup hook.** Umbrel backs up the app data directory as a
  filesystem snapshot of the running stack. The quiesced dumps from
  `../scripts/pre-backup.sh` cannot be wired in because umbrelOS has no
  pre-backup hook.
- **Nothing that needs a public URL works.** WhatsApp media delivery,
  URL-fetching image-to-video providers, and Google/GitHub/Apple sign-in all
  require the provider to reach the instance, or reject an HTTP redirect URI.
- **Widget embedding is LAN-bound.** `REALTIME_ALLOWED_ORIGINS` points at the
  Umbrel origin, so a widget on a public website cannot open its realtime
  connection.
- **Realtime needs the `.local` origin.** `REALTIME_ALLOWED_ORIGINS` is derived
  from `DEVICE_DOMAIN_NAME`, so reaching the app by IP address or through a
  Tailscale hostname instead makes Centrifugo refuse the WebSocket upgrade.
  Everything served over plain HTTP keeps working — CORS is open and the session
  cookie is host-scoped — only the realtime channel stays closed. Widening the
  allowed origins would trade a real CSWSH guard for that convenience, so the
  package keeps the single canonical origin.
- **A cross-device restore shows the wrong password.** Umbrel derives the
  password it displays from the new device's seed, while the account keeps the
  one it was created with. The data is intact; the displayed credential is not.
- **`SYNAPLAN_PLATFORM: umbrel`** is unknown to `UpdatePlatformGuide` and falls
  back to the self-hosted update guide. Updates actually arrive through the App
  Store, so a dedicated branch would be an improvement, not a fix.

## Testing

Linting is necessary but proves nothing about runtime. The store sync runs the
linter on every release, so this is for trying a change before it is committed.
From a fork of `umbrel-apps` with `deploy/umbrel/synaplan/` copied in as
`synaplan/`:

```bash
npm run lint:apps -- synaplan --check-images
git diff --check
```

Add `--changed upstream/master...HEAD` instead of naming the app to get the rules
that depend on whether this is a new submission or an update — without it the
linter cannot tell, and silently skips those checks. That is the form the sync
uses.

Then install it on a real umbrelOS. A containerised instance is enough and is
what the current package was verified on:

```bash
docker run -d --name umbrelos-test --privileged --cgroupns=host \
  -p 8081:80 -p 8300:8300 --volume umbrelos-test:/data \
  ghcr.io/getumbrel/umbrelos:1.7.4 /sbin/init
```

The documented invocation uses `--network host`. On Docker Desktop for macOS
that combination wedges the daemon; bridge networking with published ports works
and keeps `localhost:8300` usable from inside the container.

Copy the package into the store checkout, make it `umbrel:umbrel`-owned (a
macOS `tar` transfers uid 501, and Redis then cannot write its append-only file),
and install:

```bash
umbreld client user.register.mutate --name Test --password test-umbrel-1234
umbreld client apps.install.mutate --appId synaplan
```

What to check, beyond "the containers are up":

1. All nine containers healthy, `apps.state.query` reports `ready`.
2. The app answers through `app_proxy` on its port, and `deterministicPassword`
   surfaces `admin@umbrel.local` plus the derived password.
3. Login over plain HTTP, and — the point of the whole exercise — the session
   survives the next request. Check that `Set-Cookie` carries no `secure`.
4. `PROXY_AUTH_ADD: "false"` boundary: an admin endpoint must answer `401`
   without a session and `200` with one, while `/widget.js` stays public with
   `Access-Control-Allow-Origin: *`.
5. `/connection/websocket` upgrades with `101 Switching Protocols` — this covers
   both the Caddy upstream override and `app_proxy` passing WebSockets through.
6. Upload a document: text is extracted, and the file is visible from the worker
   container as well as on the host bind mount.
7. Restart through Umbrel, then confirm the data is still there.
8. Replace `db/umbrel-seed/seed` with a random value and restart. The values in
   `data/secrets.env` must not change and the app must still open its database.
