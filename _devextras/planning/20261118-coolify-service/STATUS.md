# Coolify service — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| CF0 | decided 2026-11-19 | — | §0 #5 setup wizard, #6 `coolify` platform, #9 publish a TTS tag; #7, #8 open |
| CF1 | done 2026-11-19 | — | Coolify development stack, `next` @ `ed1e184` (reports 4.7.5), on the owner's M4 (Colima, arm64), ports moved off the Synaplan dev stack. `traefik/whoami:v1.11.0` from the API's Docker Compose path answered 200 through `coolify-proxy` (`traefik:v3.7`) |
| CF2 | in progress | — | `deploy/coolify/synaplan.yaml` deployed, all nine containers healthy, setup wizard walked, live chat connected, redeploy survived. Chat, upload and a spoken answer not walked yet (see below) |
| CF3 | done 2026-11-19 | — | tag `v2.1.1` on `metadist/synaplan-tts` `c208a5f42`; its `ci.yml` published `ghcr.io/metadist/synaplan-tts:2.1.1` for `amd64` and `arm64` (`docker manifest inspect`). The file pins `2.1.1` |
| CF4 | in progress | — | file, guide, test, release pins on branch `feat/coolify-service` |
| CF5 | not started | — | |
| CF6 | not started | — | `SYNAPLAN_PLATFORM: coolify` already set in the file; unknown platforms fall back to `selfhost` until CF6 |
| CF7 | not started | — | opened by a person (§0 #7) |
| CF8 | blocked | — | Coolify docs still show the pause on 2026-11-19 |
| CF9 | blocked | — | after CF8 is merged upstream |

## CF2 walk, 2026-11-19

Instance: CF1. The generated `sslip.io` address of the M4 LAN IP does not
resolve on the test Mac (router DNS rebind protection), so the browser
reached Traefik through `ssh -L 8880:localhost:80` with the domain
`http://synaplan.localhost`. Because the browser origin then carries `:8880`,
the deployed copy replaced `${SERVICE_URL_BACKEND}` with
`http://synaplan.localhost:8880` in the four URL variables. Everything else
was the file as committed.

| Check | Result |
| ----- | ------ |
| Parse | Coolify kept the `x-` anchors and the `<<:` merges; every `SERVICE_PASSWORD_*` was generated (32 or 64 chars), every operator variable listed as editable |
| Start | db, redis, centrifugo, tika, qdrant, tts healthy, then backend, then worker and scheduler; `ghcr.io/metadist/synaplan:5.8.8` |
| Address | `SERVICE_URL_BACKEND=http://synaplan.localhost` in the container after setting the domain via the API |
| Editing `APP_URL` in Coolify | overwritten from the domain on the next start; the guide says to change the domain instead |
| Setup wizard | administrator created, provider step skipped, sign-up step finished, signed in |
| Synaplan Demo | `POST https://web.synaplan.com/v1/free/keys` answered 403 from this install; not a Coolify issue, not investigated further |
| Live chat | WebSocket to `/connection/websocket` opened; Centrifugo `api/info` reported `num_clients: 1` |
| Redeploy | compose updated to `synaplan-tts:2.1.1` through the API, service restarted: all nine containers healthy again within a minute, the four volumes kept their creation time, `BUSER` still 1 row, the administrator signed in (`POST /api/v1/auth/login` 200, level `ADMIN`) |
| Not walked | chat stream, document upload and question, spoken answer (need a provider key typed by the owner) |

## Not verified (plan §5)

| # | Answer | Date | How it was measured |
| - | ------ | ---- | ------------------- |
| U1 | yes: `mariadb:13.0.2@sha256:…` kept in the stored compose and deployed. The file pins tags only anyway, like all 385 upstream templates | 2026-11-19 | stored `docker_compose` of the service via `artisan tinker` |
| U2 | yes | 2026-11-19 | browser WebSocket `open`, Centrifugo `num_clients: 1` |
| U3 | the web role's peer is Traefik on a private Docker network; Traefik replaces a visitor's `X-Forwarded-For` (`X-Forwarded-For: 1.2.3.4` sent, `172.19.0.1` received). Default `SYMFONY_TRUSTED_PROXIES=PRIVATE_SUBNETS` | 2026-11-19 | `traefik/whoami` behind `coolify-proxy` |
| U4 | yes, on Colima on the M4 with ports moved; the browser needs a tunnel or a resolvable domain | 2026-11-19 | CF1 |
| U5 | open: no certificate on the local instance. `SERVICE_URL_*` takes its scheme from the domain, so an `https://` domain gives `https://` | | |
| U6 | open. Traefik v3 default `readTimeout` is 60 s for the whole request, and Coolify's proxy command sets none; a slow large upload may be cut | 2026-11-19 | `bootstrap/helpers/proxy.php`, Traefik docs |
| U7 | open | | |
| U8 | yes, all five third-party images publish `arm64`; the stack ran on arm64 | 2026-11-19 | Docker Hub tags API, CF1 host |
| U9 | open | | |
| U10 | open: pause still shown 2026-11-19 | 2026-11-19 | coolify.io/docs contribute page |
| U11 | open | | |
| U12 | open | | |
