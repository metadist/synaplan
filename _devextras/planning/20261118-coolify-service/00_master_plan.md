# Coolify: Synaplan as a one-click service

**Status:** 2026-11-18. Plan only. §0 #1–#4 are decided by the owner,
#5–#9 are open. See [`STATUS.md`](./STATUS.md).
**Owner:** product owner.
**Roadmap:** row 48. Steps `CF0`–`CF9`.
**Binding:** root [`AGENTS.md`](../../../AGENTS.md).
**Facts taken on:** 2026-11-18, Synaplan `main` @ `595440191`,
coollabsio/coolify `next` @ `cf7eff55` (2026-11-15). Every statement about
Coolify in this file names its source in §7. What was not verified is
listed in §5 and is not stated as fact anywhere else.

## Goal

A person who runs Coolify installs Synaplan from Coolify's service catalog:
pick "Synaplan", deploy, open the generated HTTPS address, create the first
administrator. Until Coolify accepts the template, the same person pastes
one file from this repository into Coolify's **Docker Compose Empty** and
gets the same result.

## Blocker outside our control

Coolify's contribution page opens with this notice (source S1):

> External contributions are temporarily paused due to a high volume of
> low-quality pull request spam. There is **no ETA** for resuming external
> contributions.

The notice was added to the docs repository on 2026-10-18 (S8). A search
on 2026-11-18 for pull requests titled `feat(service): add …` created after
2026-10-01 returned seven, all opened by repository members (S9). The
upstream pull request (CF8) therefore waits. Everything before it does not.

## §0 Decisions

| # | Decision | State |
| - | -------- | ----- |
| 1 | **Secrets come from Coolify.** `SERVICE_PASSWORD_*` values go to the containers as plain environment variables, the way the Umbrel package passes its secrets (`deploy/umbrel/synaplan/docker-compose.yml`). No `secrets-init` container in the Coolify file. Accepted risk: a password edited later in Coolify's Environment Variables locks the app out of its database; the install guide says so. | ✅ owner 2026-11-18 |
| 2 | **Two phases.** Phase 1 now: a Coolify-ready compose file and guide in this repository, installable through Docker Compose Empty, plus a request in Coolify's discussions. Phase 2: template and docs pull requests once Coolify reopens. | ✅ owner 2026-11-18 |
| 3 | **Full quickstart stack**: backend, worker, scheduler, db, redis, centrifugo, tika, qdrant, tts. One template, no lite variant. | ✅ owner 2026-11-18 |
| 4 | **Test on a local Coolify first**, on the owner's machine. If a local instance cannot deploy and route the stack (§5 U4), test on the owner's private Coolify server instead. | ✅ owner 2026-11-18 |
| 5 | **First administrator.** Owner chose the setup wizard (2026-11-19): both variables empty by default, `deploy/coolify/README.md` tells the operator to finish `/setup` at once or set both variables before the first deployment. Was recommended: `BOOTSTRAP_ADMIN_EMAIL` required (`${BOOTSTRAP_ADMIN_EMAIL:?}`) and `BOOTSTRAP_ADMIN_PASSWORD` from `SERVICE_PASSWORD_ADMIN`, so a public address never serves an unclaimed `/setup`. Alternative: both empty, first visitor claims the instance (what the quickstart does). `deploy/README.md` names the claim window as the risk on a public host. | ✅ owner 2026-11-19 |
| 6 | **Update guide.** Recommended: add `coolify` to `UpdatePlatformGuide` with `docs/UPDATE_COOLIFY.md`, and set `SYNAPLAN_PLATFORM: coolify`. Alternative: `selfhost`, whose guide describes `docker compose` commands a Coolify operator does not run. This is backend code (CF6). | ✅ owner 2026-11-19: `coolify` (CF6) |
| 7 | **Who files upstream.** The discussion and both pull requests are opened by a person from their own GitHub account (§3.3 lists what the account must satisfy). The "Changes" and "Why" sections are written by that person. Who? | open |
| 8 | **Metadata.** Recommended: `category: ai`, `documentation: https://docs.synaplan.com/`, logo from `frontend/public/synaplan-light.svg` or `-dark.svg` (which one reads on Coolify's dark and light cards is checked in CF4). Slogan text is the owner's. | open |
| 9 | **TTS image tag.** The quickstart pins `ghcr.io/metadist/synaplan-tts:latest@sha256:4efc…`. Coolify asks for a fixed version tag. Tags `2.1` and `2.1.0` exist but were last updated on 2026-09-21; `latest` on 2026-10-28 (GHCR package versions). Recommended: publish a version tag for the current TTS image and pin that. | ✅ owner 2026-11-19: publish a tag (CF3) |

## 1. What Coolify requires

All from S1–S6. Two sources contradict each other in one place; that is
called out.

### 1.1 Eligibility and process

| Requirement | Source | Synaplan today |
| ----------- | ------ | -------------- |
| Upstream repository has at least 1,000 GitHub stars | S1 | 1,025 on 2026-11-18 (`gh api repos/metadist/synaplan`) |
| Every pull request references an issue or discussion; without one it "may not be reviewed and can be closed at any time" | S3 | none exists; no issue, pull request or discussion mentions Synaplan (S9) |
| New services are requested in the discussion category "Service Template Requests" | S1 | CF7 |
| Template pull request plus a matching docs pull request in coollabsio/coolify-docs; the docs one "is required before the template PR can be merged" | S1 | CF8 |
| Tested "through the Docker Compose Empty deployment flow", "from a fresh Docker Compose Empty resource" | S1 | CF2 |
| Contributor agreement: tested "thoroughly with a local development instance of Coolify" | S4 | CF1, CF2 |
| AI use is disclosed in the pull request; the author understands every change | S3, S4 | §3.3 |
| Commit and pull request title: `feat(service): add synaplan` | S3 | — |

**Target branch is unclear.** S1 says "the current `release/v4.x` branch
(for example, `release/v4.5` while production is `4.4.x`)". S3 says new
features target `next`. On 2026-11-18 the repository has `main`, `next`
and `v4.0.x`–`v4.3.x`, no branch named `release/…`, the latest release is
v4.7.5, and three open member pull requests that add a service target
`main` (S9). CF7 asks the maintainers; CF8 does not guess.

### 1.2 Files

| File | Where | Source |
| ---- | ----- | ------ |
| `templates/compose/synaplan.yaml` | coollabsio/coolify | S1 |
| Logo, referenced as `svgs/synaplan.svg`. **Which directory is unclear:** S1 says `svgs/<service>.svg`; the repository has `public/svgs/` (371 files) and a top-level `svgs/` (11 files), and two open member pull requests use one each (12298 `svgs/superplane.svg`, 12581 `public/svgs/opencode.svg`). §5 U11 | coollabsio/coolify | S1, S6, S9 |
| `content/docs/services/synaplan.mdx` and a logo under `public/images/services/` | coollabsio/coolify-docs | S1, S8 |
| `templates/service-templates.json`, `service-templates-latest.json` | generated. Must **not** be in the pull request: both are blocked paths in the quality check | S1, S5 |

### 1.3 Template header

Comment lines at the top of the compose file (S1):

```yaml
# documentation: https://docs.synaplan.com/
# slogan: <owner's text>
# category: ai
# tags: ai,chat,llm,rag,agents,mcp
# logo: svgs/synaplan.svg
# port: 80
```

`port` is always set (S1). The generator reads every line that matches
`^#key: value` as metadata (S6, `app/Console/Commands/Generate/Services.php`),
so no other comment in the file may have that shape. It also understands
`ignore`, `minversion`, `env_file`, `amd_only` and `arm_only`; none is
needed. `category` is free text; `ai` is used by 25 of 388 templates (S6).

### 1.4 Compose rules

| Rule | Source |
| ---- | ------ |
| "Do not use `latest` or other floating tags. Pin the image to a specific version." | S1 |
| Omit `container_name`. | S1 |
| Omit `restart`; Coolify sets `unless-stopped`. `restart: "no"` only for a one-time container. | S1, S2 |
| Declare every persistent path as a volume on the service. Empty top-level `volumes:` declarations may be omitted. | S1 |
| No `ports:` unless the service must be reachable on a server interface; a published port bypasses the proxy. | S2 |
| Health checks come from the compose file or the image. | S2 |
| The public address comes from `SERVICE_URL_<SERVICE>_<PORT>` on the service that receives traffic; `SERVICE_FQDN_…` is the same without scheme. The service name in the variable is the compose service name, hyphens and dots as underscores. | S2 |
| Generated credentials: `SERVICE_PASSWORD_<ID>` (32 chars, no symbols), `SERVICE_PASSWORD_64_<ID>`, `SERVICE_HEX_32/64/128_<ID>`, `SERVICE_REALBASE64_…`, `SERVICE_USER_<ID>`. `<ID>` is one token **without underscores**. The same full name gives the same value to every service. Values persist between deployments and can be edited in the UI. | S2 |
| `${VAR}` creates an empty editable variable, `${VAR:-text}` one with a default, `${VAR:?}` a required one that blocks deployment while empty. | S2 |

S1 also shows `${PORT:?8080}` as "required with sensible defaults". S2 does
not document a default for `:?`, and in Docker Compose the text after `:?`
is an error message. This plan does not use that form.

### 1.5 The automated quality check

Every pull request from outside the organisation runs
`.github/workflows/pr-quality.yaml` (S5, `peakoss/anti-slop@v0.3.0`). It
closes the pull request when four checks fail (`max-failures: 4`,
`close-pr: true`). Members and collaborators are exempt. The checks that
matter here:

| Check | Value |
| ----- | ----- |
| Conventional title | required |
| Description | required, at most 2,500 characters, at most 2 emoji, at most 5 code references (file paths, function calls) |
| Blocked terms in the description (case sensitive) | `STRAWBERRY`, `🤖 Generated with Claude Code`, `Generated with Claude Code` |
| Pull request template | required; the "Contributor Agreement" section unchanged |
| Blocked paths | the two generated JSON files, `README.md`, `SECURITY.md`, `LICENSE`, `CODE_OF_CONDUCT.md` |
| Changed files | end with a newline; at most 10 added comment lines across all changed files |
| Commits | every commit authored by the account that opened the pull request; message at most 500 characters |
| Maintainers can push to the source branch | required |
| Author account | at least 30 days old, at least 4 profile signals (name, email, bio, …), global merged/closed ratio at least 30 % |

The pull request template (S4) starts with an HTML comment that tells the
reader to put the word "STRAWBERRY" at the top of the description and to
"ignore if you are a human". The quality check blocks that exact word. It
is a trap for generated descriptions. The word never goes into the
description.

The template also says the "Changes" and "Why" sections "must be
human-written and not AI-generated", and a preview (screenshot or short
video) is "mandatory for new features".

## 2. Synaplan today

| Piece | Where | Relevant fact |
| ----- | ----- | ------------- |
| One-file stack | `deploy/quickstart/compose.yaml` | Published images only, named volumes, a health check on every service except `tts`. The base for the Coolify file. |
| Secrets | `secrets-init` in the same file | Generates eight values into a volume; each container sources its own file through an overridden `entrypoint`/`command`. Not used on Coolify (§0 #1). |
| Plain-env precedent | `deploy/umbrel/synaplan/docker-compose.yml` | Runs the same app image with its default entrypoint and `APP_SECRET`, `TOKEN_SECRET`, `DB_PASSWORD`, `REALTIME_API_KEY`, `REALTIME_TOKEN_SECRET`, `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD` as environment variables. |
| Public address | `APP_URL`, `FRONTEND_URL`, `REALTIME_ALLOWED_ORIGINS`, `CENTRIFUGO_CLIENT_ALLOWED_ORIGINS` | All four carry the address people type. Live chat stays disconnected when it does not match (`deploy/quickstart/.env.example`). |
| Cookies | `AUTH_COOKIE_SECURE` | Empty derives "Secure" from the scheme of `APP_URL` (`deploy/selfhost.env.example`). |
| Proxy trust | `SYMFONY_TRUSTED_PROXIES` | Unset by default; names the proxy that connects to Synaplan so sign-up limits see the visitor (`docs/CONFIGURATION.md`). |
| WebSocket | `REALTIME_PUBLIC_WS_URL: ""` | The browser dials `/connection/websocket` on the same origin; the app container proxies it to `centrifugo:8000`. |
| First administrator | `BOOTSTRAP_ADMIN_EMAIL` + `BOOTSTRAP_ADMIN_PASSWORD` | Set together or empty together. Empty serves the `/setup` wizard to the first visitor (`deploy/README.md`). |
| Images | `ghcr.io/metadist/synaplan:5.8.8`, `ghcr.io/metadist/synaplan-tts:latest` | Both public, both `linux/amd64` and `linux/arm64` (`docker manifest inspect`, 2026-11-18). |
| Image pins | every image is `tag@sha256:…` | No Coolify template pins a digest (0 of 388, S6). §5 U1. |
| Version pins per release | `scripts/set-release-version.mjs`, `scripts/read-release-version.mjs` (`CATALOG_PATHS`) | Write and read `SYNAPLAN_VERSION` in every packaged file. A new packaged file is registered in both. |
| Store syncs | `.github/workflows/umbrel-store-sync.yml`, `casaos-store-sync.yml` | Carry a package into a fork and open or update the upstream pull request after a release. The model for CF9. |
| Platform hint | `backend/src/Service/Update/UpdatePlatformGuide.php` | Knows `selfhost`, `elestio`, `aws`; anything else falls back to `selfhost`. |
| Requirements | `deploy/README.md` | Cloud-AI profile: at least 4 vCPU, 8 GB RAM, 30 GB free disk. |
| Path policy | `.github/mobile-impact-policy.json` | `deploy/**` and `docs/**` are already classified; `deploy/coolify/` needs no new entry. |

## 3. Target

### 3.1 The file

`deploy/coolify/synaplan.yaml` in this repository is the single source. In
phase 1 a person pastes it into Docker Compose Empty. In phase 2 the same
file is `templates/compose/synaplan.yaml` upstream. It is derived from the
quickstart with these changes:

| Quickstart | Coolify file | Why |
| ---------- | ------------ | --- |
| `ports: 127.0.0.1:8000:80` on `backend` | removed; `SERVICE_URL_BACKEND_80` on `backend` | §1.4 |
| `APP_URL`, `FRONTEND_URL`, `REALTIME_ALLOWED_ORIGINS`, `CENTRIFUGO_CLIENT_ALLOWED_ORIGINS` from `SYNAPLAN_URL` | all four `${SERVICE_URL_BACKEND}` | one address, generated |
| `secrets-init` and the `secrets*` volumes | removed | §0 #1 |
| `entrypoint`/`command` that source `secrets.env` (app, db, centrifugo) | removed; image defaults. `db` keeps `--max-allowed-packet=256M --innodb-buffer-pool-size=512M` as `command` | §0 #1 |
| secrets from the file | see §3.2 | §0 #1 |
| `restart: unless-stopped` everywhere | removed | §1.4 |
| `image: …:${SYNAPLAN_VERSION:-5.8.7}` | fixed tag, raised per release | §1.4 |
| `tag@sha256:…` | decided by CF2 (§5 U1) | — |
| `synaplan-tts:latest@sha256:…` | version tag (§0 #9) | §1.4 |
| `SYNAPLAN_PLATFORM: selfhost` | §0 #6 | — |
| comments | header plus at most four more lines | §1.5 |
| top-level `volumes:` | kept or dropped per CF2; both are allowed | §1.4 |

Unchanged: service names (`backend`, `worker`, `scheduler`, `db`, `redis`,
`centrifugo`, `tika`, `qdrant`, `tts`), `depends_on` conditions, health
checks, the `db`, `redis`, `qdrant` and `uploads` volumes, and the provider
keys as optional editable variables (`${GROQ_API_KEY:-}` and the others).
The service is named `backend` because the worker and scheduler wait on
`http://backend/api/health` by default and the app proxies the WebSocket to
`centrifugo:8000` by default (both stated in the Umbrel compose, which has
to override them because its names differ).

### 3.2 Secrets

One Coolify identifier per secret, no underscore in the identifier (§1.4):

| Synaplan variable | Container | Coolify variable |
| ----------------- | --------- | ---------------- |
| `APP_SECRET` | app roles | `SERVICE_PASSWORD_64_APPSECRET` |
| `TOKEN_SECRET` | — | not set: no longer read, and a new install has no older file to load |
| `DB_PASSWORD`, `MARIADB_PASSWORD` | app roles, db | `SERVICE_PASSWORD_MARIADB` |
| `MARIADB_ROOT_PASSWORD` | db | `SERVICE_PASSWORD_MARIADBROOT` |
| `REALTIME_API_KEY`, `CENTRIFUGO_HTTP_API_KEY` | app roles, centrifugo | `SERVICE_PASSWORD_64_REALTIMEAPI` |
| `REALTIME_TOKEN_SECRET`, `CENTRIFUGO_CLIENT_TOKEN_HMAC_SECRET_KEY` | app roles, centrifugo | `SERVICE_PASSWORD_64_REALTIMETOKEN` |
| `CENTRIFUGO_ADMIN_PASSWORD` | centrifugo | `SERVICE_PASSWORD_CENTRIFUGOADMIN` |
| `CENTRIFUGO_ADMIN_SECRET` | centrifugo | `SERVICE_PASSWORD_64_CENTRIFUGOSECRET` |
| `BOOTSTRAP_ADMIN_PASSWORD` (if §0 #5 is the recommended option) | app roles | `SERVICE_PASSWORD_ADMIN` |

`SERVICE_PASSWORD_*` values have no symbols (S2), so they need no quoting.
The guide states: these values are created once, they live in Coolify's
Environment Variables, and `SERVICE_PASSWORD_MARIADB`,
`SERVICE_PASSWORD_MARIADBROOT` and `SERVICE_PASSWORD_64_APPSECRET` must not
be changed after the first deployment. A new database password does not
open the existing database, and a new `APP_SECRET` makes the provider keys
stored in the database undecryptable (`deploy/README.md`).

### 3.3 Upstream submission

- A person opens the discussion and both pull requests from their own
  account. The account is at least 30 days old, has at least four profile
  fields filled, and "Allow edits by maintainers" stays ticked (§1.5).
- Every commit in the pull request is authored by that account, one commit,
  message `feat(service): add synaplan`.
- Coolify's pull request template is kept whole. "Changes" and "Why" are
  written by that person. "AI Assistance" is ticked "AI was used" with the
  tool and extent named. No attribution line, no generated-with footer,
  never the word from the trap in §1.5.
- The description stays under 2,500 characters with at most five file paths
  or function names. Testing steps and a screenshot of the running service
  go into "Testing" and "Preview".
- The pull request holds the template and the logo, and nothing else
  unless CF7 answers U12 differently.

This repository's rule "No AI attribution" is about this repository. It is
not broken by answering Coolify's question truthfully in Coolify's
repository.

## 4. Steps

Release class is `no-app-impact` for every step except CF6
(`backend-only`). One branch and one pull request per step that changes
this repository, per root `AGENTS.md`.

| Step | What | Done when |
| ---- | ---- | --------- |
| **CF0** | This plan. Owner decides §0 #5–#9. | §0 has no open row that blocks CF2 (#5, #9). |
| **CF1** | A Coolify to test on. Local first: Coolify's development stack (`docker compose -f docker-compose.yml -f docker-compose.dev.yml up`, sign in at `http://localhost:8000` with the seeded account from its `DEVELOPMENT.md`). It publishes host ports by default, among them 8000, 6001, 6002, 5432, 6379, 1025, 8025, 9000 and 9001, so the Synaplan development stack is stopped or Coolify's ports are moved through its `.env` first. Answer §5 U4. If the answer is no, use the owner's private Coolify server, in its own project, away from the hosted sites. | A Docker Compose Empty resource with a one-container test image deploys and its generated address opens in a browser. STATUS records which instance and its Coolify version. |
| **CF2** | Write `deploy/coolify/synaplan.yaml` (§3.1, §3.2) and deploy it from a fresh Docker Compose Empty resource. Answer §5 U1, U2, U3, U5, U6. Walk: open the generated address, create or sign in as the administrator, add a provider key, send a chat message and see it stream, upload a document and ask about it, play a spoken answer, redeploy and confirm chats, files and login survive. | The walk passes on a fresh resource twice in a row. STATUS holds the result of every §5 item it answers. |
| **CF3** | Publish a version tag for the TTS image if §0 #9 says so, and pin it in the Coolify file. | The file has no `latest`. |
| **CF4** | Phase 1 release in this repository: `deploy/coolify/synaplan.yaml`, `deploy/coolify/README.md` (install through Docker Compose Empty, requirements, the secrets warning from §3.2, update, backup), a row in `deploy/README.md` and `docs/INSTALLATION.md`. Register the file in `scripts/set-release-version.mjs` and `CATALOG_PATHS` so a release raises its version. A test fails when the file contains `container_name`, `restart: unless-stopped`, `ports:`, `:latest`, or more than ten comment lines. Pick the logo variant. | `make ci-local` green, pull request merged, a release raises the version in the file without a manual edit. |
| **CF5** | Update and backup on Coolify, measured on the CF1 instance: change the image tag, redeploy, confirm migrations ran and data survived; restore the volumes from a backup. Write the result into `deploy/coolify/README.md`. | The guide's update and restore sections describe what was done, not what is expected. |
| **CF6** | Only if §0 #6 says so: `coolify` in `UpdatePlatformGuide`, `docs/UPDATE_COOLIFY.md`, `SYNAPLAN_PLATFORM: coolify` in the file, unit test. | Admin update notice links to the Coolify guide on a Coolify install. |
| **CF7** | The owner opens a discussion in "Service Template Requests" (S1): what Synaplan is, the link to `deploy/coolify/`, that the template is tested on Coolify, and the question which branch a service pull request targets. | Discussion exists; its URL is in STATUS. |
| **CF8** | When S1 no longer shows the pause: template pull request (template and logo) and docs pull request (`content/docs/services/synaplan.mdx`, logo under `public/images/services/`, frontmatter `title`, `description`, `category`; `bun run generate:services` to check the card), each linking the other and the discussion. Per §3.3. | Both pull requests open and not closed by the quality check. Merging is Coolify's decision. |
| **CF9** | After the template is merged upstream: a release step that raises the version in Coolify's copy, modelled on `umbrel-store-sync.yml` (fork, branch, pull request). Not before; an update pull request is an external contribution too. | A Synaplan release opens the update pull request upstream. |

Order: CF0 → CF1 → CF2 → CF3 → CF4 → CF5. CF6 and CF7 can follow CF4 in
any order. CF8 waits for Coolify. CF9 waits for CF8.

## 5. Not verified

Each item is answered by a step and recorded in STATUS. Until then it is
unknown, and the plan does not depend on a particular answer.

| # | Question | Answered in | If the answer is no |
| - | -------- | ----------- | ------------------- |
| U1 | Does Coolify's parser accept `image: name:tag@sha256:…`? No existing template uses it. | CF2 | Pin tags only in the Coolify file. |
| U2 | Does the WebSocket at `/connection/websocket` connect through Coolify's proxy on the generated address? | CF2 | Find the cause before CF4; live chat is part of the walk. |
| U3 | Which address does Synaplan see as the client behind Coolify's proxy, and what must `SYMFONY_TRUSTED_PROXIES` be so sign-up limits see the visitor? | CF2 | Document the value; do not ship a wide trust range by default. |
| U4 | Can Coolify's development stack on macOS deploy a nine-container service to its `testing-host` and serve a generated address a browser on the same machine can open? | CF1 | Use the private server (§0 #4). |
| U5 | Does `SERVICE_URL_BACKEND` carry `https://` on the test instance, so cookies are marked Secure without `AUTH_COOKIE_SECURE`? | CF2 | Set `AUTH_COOKIE_SECURE` as documented. |
| U6 | Do uploads up to Synaplan's limit and long streamed answers pass Coolify's proxy without a size or time cut-off? | CF2 | Document the proxy setting. |
| U7 | How does the quality check count "added comment lines" in a YAML file? | not answerable before CF8 | The file stays at ten comment lines or fewer either way. |
| U8 | Are the third-party images (`mariadb`, `redis`, `centrifugo`, `tika`, `qdrant`) published for `arm64`? Synaplan's two are. | CF2 | Add `# amd_only: true` (S6). |
| U9 | How and how often do Coolify's maintainers run `services:update-versions`, the command that raises image versions in templates from registries (S6)? | CF7 (ask) | CF9 as planned. |
| U10 | When does Coolify reopen external contributions? | — | Phase 1 is the offer until then. |
| U11 | Does the logo go to `svgs/` or `public/svgs/` (§1.2)? | CF7 (ask) | — |
| U12 | Member pull request 12581 also adds a `.changes/<name>.md` file. Is that expected from an external service pull request? S1 and S3 do not mention it. | CF7 (ask) | — |

## 6. Out of scope

- A lite template, local AI (Ollama), the office (Collabora) and file-work
  profiles. They stay in `deploy/compose.yaml`.
- A Coolify-specific image or entrypoint. The published image is used as is.
- Changes to the quickstart, Umbrel, CasaOS, Elestio or AWS packages.

## 7. Sources

Read on 2026-11-18.

| # | Source |
| - | ------ |
| S1 | Coolify docs, "Add a new service template to Coolify": <https://coolify.io/docs/contribute/service> (also served at `/docs/get-started/contribute/service`) |
| S2 | Coolify docs, "Docker Compose": <https://coolify.io/docs/applications/builds/docker-compose> |
| S3 | `CONTRIBUTING.md`, coollabsio/coolify, default branch `main` |
| S4 | `.github/pull_request_template.md`, coollabsio/coolify, default branch `main` |
| S5 | `.github/workflows/pr-quality.yaml`, coollabsio/coolify `next` @ `cf7eff55`; option meanings from the README of peakoss/anti-slop v0.3.0 |
| S6 | coollabsio/coolify `next` @ `cf7eff55`: `templates/compose/` (388 files), `public/svgs/`, `app/Console/Commands/Generate/Services.php`, `app/Console/Commands/UpdateServiceVersions.php`, `DEVELOPMENT.md`, `docker-compose.dev.yml` |
| S7 | `templates/compose/forgejo-with-mariadb.yaml` in S6: `SERVICE_URL_FORGEJO_3000` declared on the service, `${SERVICE_URL_FORGEJO}` used for the app's own address, `SERVICE_PASSWORD_MYSQL` and `SERVICE_PASSWORD_MYSQLROOT` shared between app and MariaDB |
| S8 | coollabsio/coolify-docs: commit `3b783c17` (2026-10-18, "announce temporary external contribution pause"), `content/docs/services/dify.mdx`, `content/docs/contribute/service.mdx` |
| S9 | GitHub API, coollabsio/coolify: branch list, releases, search for "synaplan" in code, issues, pull requests and discussions (0 results each), pull requests 12577, 12581 and 12298 (base `main`, opened by members) and their file lists |
