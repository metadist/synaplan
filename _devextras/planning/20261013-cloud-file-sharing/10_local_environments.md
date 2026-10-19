# Local environments — requirements, inventory, cleanup, update plan

Every walk in [`09_functional_tests.md`](./09_functional_tests.md) and every
brief in [`08_agent_briefs.md`](./08_agent_briefs.md) assumes the stacks of
this file. A stack is **ready** when `env-check.sh` (§5) prints `PASS` for
every check of its profile. Versions are pinned here; a bump is a dated entry
in [`STATUS.md`](./STATUS.md).

The inventory in §1 was taken on the maintainer workstation on 2026-10-13.
Other machines start at §3 and skip §2.

---

## 0. The target in one table

| Component | Why this plan needs it | How it runs | Reach it at | Phases |
| --------- | ---------------------- | ----------- | ----------- | ------ |
| **Synaplan core** (backend, frontend, worker, scheduler, db, redis, centrifugo, qdrant, tika, mailhog, compute sidecar) | The API every app calls; Library, Tasks, Apps; mail for "Summarize and mail me"; file work | `docker compose up -d` in `synaplan/` | API `http://localhost:8000`, UI `http://localhost:5173`, MailHog `http://localhost:8025` | all |
| **Keycloak** (`--profile oidc`) | Organisation login: the reference client's `sso` profile and Nextcloud CF2.6 in P1, OpenCloud in P2, the `sso` regression in P3 | `docker compose --profile oidc up -d` in `synaplan/` | `https://host.docker.internal:8443` (issuer), `http://localhost:8080` (admin) | all |
| **Reference client** (E-9) | Plays the app side of every protocol in two profiles before and beside the real apps (`00` row 45) | `docker compose -f _devextras/testing/cloud-apps/fixture-app/compose.yml up -d` in `synaplan/` | `http://cloud-fixture:8099` on the Synaplan network (published on `localhost:8099` for debugging) | all |
| **Nextcloud dev** | The P1 app; every journey is walked here first | `make dev-up` in `synaplan-nextcloud/` | `http://localhost:8081` | all (regression in P2, P3) |
| **OpenCloud dev** | The P2 app; in P1 for spikes and the compatibility check | `make docker-up` in `synaplan-opencloud/` (+ `make frontend-serve` for extension work) | `https://host.docker.internal:9200` (bundled), `:9201` (web dev server), Go service `:9106` | P1 (check), P2, P3 (regression) |
| **ownCloud.online dev** | The P3 app | `make dev-up` in `synaplan-owncloud-online/` (new, E-5); until then the host install on `:8088` for CF-S4 and the compatibility check | `http://localhost:8083` | P3 |
| Collabora (`--profile office`) | Not required by this track; keep off unless the Collabora plan needs it | `docker compose --profile office up -d` | internal only | — |

Two rules that hold for every stack:

1. **Two directions, two kinds of address** (`03` §2).
   - **App → Synaplan:** the cloud stacks join `synaplan_synaplan-network`
     and call the API as `http://backend` (the app's `synaplan_url`); links
     for the browser use `http://localhost:5173` (Nextcloud's public URL
     setting).
   - **Synaplan → app:** Synaplan calls each app's **delivery URL**, which
     may differ from the address people's browsers use (the registered host).
     In development the delivery-origin rule lets it use plain http to the
     shared dev host list while `APP_ENV` is `dev` or `test`:
     `http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound`,
     `http://owncloud/index.php/apps/oco_synaplan/api/v1/inbound`,
     `http://cloud-fixture:8099/inbound`, and for OpenCloud the Go service
     directly, `http://host.docker.internal:9106/api/synaplan/inbound` (the
     backend reaches the host through `extra_hosts: host-gateway`, which the
     core compose already sets). Self-signed TLS is never used for these
     calls.
   - Every location Synaplan hands an app (download, upload target) is a path
     the app joins to its `synaplan_url` — never `APP_URL`, which a container
     cannot reach.
2. **`host.docker.internal` resolves on the host.** Keycloak's issuer and
   OpenCloud's UI use it. On WSL2 / Linux add `127.0.0.1 host.docker.internal`
   to `/etc/hosts` once; Docker Desktop has it already. Accept the self-signed
   Keycloak certificate once in the browser at
   `https://host.docker.internal:8443`.

---

## 1. Found on the maintainer workstation (2026-10-13)

Verdicts: **keep**, **update** (an E-brief in §4), **removed** (done in §2.1),
**confirm** (§2.2 — the owner decides).

### 1.1 Containers

| Container | Image / state | Verdict |
| --------- | ------------- | ------- |
| Synaplan core: `synaplan-backend`, `-frontend`, `-worker`, `-db` (MariaDB 13), `-redis`, `-centrifugo`, `-qdrant`, `-tika`, `-mailhog`, `-compute` | running | keep |
| `synaplan-scheduler` | **exited (127) for 10 days** | **update** — the scheduler runs the sweeps CF3 and CF5 depend on (unconfirmed copies, stuck items, windows); `docker compose up -d scheduler` must stay up before any CF3+ test (E-1 check) |
| `synaplan-compute-python`, `synaplan-compute-node` | exited (0) — image builders, expected | keep |
| `synaplan-nextcloud-dev` | `nextcloud:31-apache`, Nextcloud 31.0.14, PHP 8.3, **SQLite**, background jobs in the default `ajax` mode, no `user_oidc`, `notifications` on, app linked through the `before-starting` hook; up 10 days | **update** (E-2): MariaDB, cron container, `user_oidc`, test users |
| `nextcloud-collabora` | `collabora/code:26.04.3.2.1`, started from `/wwwroot/nextcloud/docker-compose.collabora.yml` (project `nextcloud`, network `nextcloud_default`) | keep — belongs to the Collabora plan, not to this track |
| `synaplan-opencloud-opencloud-1`, `-traefik-1`, `-synaplan-backend-1` | exited 5 weeks / 12 days; **OpenCloud 6.0.0** while the compose file pins **8.1.0** | **removed** (§2.1); refreshed by E-4 |
| `synaplan-piper-tts` | exited, other repo | keep |

### 1.2 Volumes, images, networks

| Item | State | Verdict |
| ---- | ----- | ------- |
| volume `synaplan-nextcloud_nextcloud_data` | the dev Nextcloud (SQLite + files) | **removed** 2026-10-14. The container is down until E-2's `make dev-up` installs MariaDB |
| volume `synaplan-opencloud_opencloud-config` | generated by `opencloud init` on 6.0.0 | **removed** 2026-10-14. E-4 runs a fresh `init` on 8.1.0 |
| volume `synaplan-opencloud_go-cache` | Go build cache | keep |
| volume `synaplan_keycloak_certs` | self-signed Keycloak cert | keep |
| image `mcr.microsoft.com/playwright:v1.62.1-noble` (3.5 GB) | superseded by `v1.63.0-noble`, the version `frontend/tests/e2e/playwright.config.ts` expects | **removed** |
| images tagged `<none>` for `keycloak`, `opencloud-rolling`, `collabora/code` | digest-pinned pulls of the compose files, not dangling | keep |
| image `synaplan-opencloud-synaplan-backend:latest` (2.6 GB, 5 weeks) | Go service build | **update** — rebuilt by E-4 (`docker compose build`) |
| network `nextcloud-aio` | orphan, 0 containers | **removed** |
| network `nextcloud_default` | used by `nextcloud-collabora` | keep |
| network `synaplan_synaplan-network` | the shared network | keep |

### 1.3 Repositories and host

| Item | State | Verdict |
| ---- | ----- | ------- |
| `synaplan-nextcloud/js_root_stale/` | root-owned build output from June, untracked | **removed** |
| `synaplan-nextcloud/synaplan_integration.tar.gz` + `.sig` | release artefacts (gitignored) | keep; `make package` regenerates |
| `synaplan-nextcloud/.local/` | private notes (gitignored) | keep |
| `synaplan-nextcloud/docker-compose.dev.yml` | identical to `.example` (gitignored copy) | **update** (E-2) |
| `synaplan-opencloud`: `backend/bin/synaplan`, `backend/bin/build-errors.log`, `backend/internal/synaplanapi/client.gen.go` + `.cache/`, `frontend/dist/`, `frontend/.__mf__temp/`, `dev/docker/traefik/certificates/server.*` | gitignored build outputs and dev certs | keep; regenerated by the Makefile |
| `synaplan-owncloud-online`: `vendor/`, `.php-cs-fixer.cache` | gitignored | keep |
| `/wwwroot/owncloud.online` (596 MB) | BW-Tech ownCloud.online checkout (HEAD 2026-08-05), **installed**: host Apache on `:8088`, host MariaDB (`owncloud_online`), host PHP 8.4.17, `apps/oco_synaplan` symlinked to the plugin repo | keep as the fallback until E-5 is green, then stop using it for walks (not reproducible, not usable in CI) |
| `/wwwroot/nextcloud` (7.2 GB) | bare **Nextcloud 34 dev** server checkout (Feb 2026), `db.sql`, `assistant-worker.sh` (Sep 2026, Assistant / TaskProcessing worker), `docker-compose.collabora.yml` | **confirm** — not this track's stack; the Collabora / Assistant work uses it. Leave unless its owner archives it |
| `synaplan-nextcloud/.local/nextcloud-certs.tgz`, `.local/nextcloud-keys-inine.tgz` | the Nextcloud App Store signing private key and its CSR, with `.local/README.md` | **moved** 2026-10-14 out of `/wwwroot`. Gitignored. Never into a repository |
| `/wwwroot/keycloak-nsc-config` | Helm config of another lab, private remote | out of scope |
| Host Apache serves `/wwwroot` on `:80`, `:8082`, `:8088` | — | keep; it is why `:8082` is taken and ownCloud.online Docker gets `:8083` |

### 1.4 Re-checked on 2026-10-19

| Item | State | Consequence |
| ---- | ----- | ----------- |
| Synaplan core incl. `synaplan-scheduler` | all running, scheduler healthy since 2026-10-17 | E-1 done; the cause of the earlier exit 127 was not recorded, `env-check.sh` watches the scheduler |
| Keycloak (`--profile oidc`) | no container in the morning; running since E-3 the same day | Synaplan's `backend/.env` got the OIDC values of §3.1 |
| Nextcloud dev | no container in the morning (volume removed 2026-10-14); E-2 stack running the same day on 35.0.2, also checked on 33.0.10 | — |
| OpenCloud dev | no container in the morning (config volume removed 2026-10-14); E-4 stack running the same day on 8.1.0 | the E2E suite runs with one worker (§6) |
| Nextcloud upstream | 35.0.2, 34.0.5, 33.0.10 released 2026-10-15; `nextcloud:latest` = 35, `stable` = 34; 32's last release 32.0.15 on 2026-09-10 | dev default 35, matrix 33 and 35 (row 47, D21) |
| OpenCloud upstream | rolling `8.1.0` (2026-10-05) is current; the stable channel is `7.2.4` | the 8.1.0 pin stands; the "P2 go" re-pins to what partner installations run (E-8) |
| `user_oidc` | `v8.11.0` (2026-08-24) | CF-S2 tests this version or newer |

---

## 2. Cleanup

### 2.1 Done on 2026-10-13

```bash
cd /wwwroot/synaplan-opencloud && docker compose down        # 3 exited 6.0.0 containers + project network; volumes kept
docker rmi mcr.microsoft.com/playwright:v1.62.1-noble         # 3.5 GB; v1.63.0 stays
docker network rm nextcloud-aio                               # orphan network
sudo rm -rf /wwwroot/synaplan-nextcloud/js_root_stale         # untracked root-owned build output
```

### 2.2 Confirmed and done on 2026-10-14

| Item | Result |
| ---- | ------ |
| Nextcloud dev data volume | Removed after `docker compose -f docker-compose.dev.yml down`. `synaplan-nextcloud-dev` stays down until E-2 (`make dev-up` installs MariaDB, admin, `ada`, `bob`). Hand-uploaded files in that volume are gone |
| OpenCloud config volume | Removed. E-4 runs `opencloud init` on 8.1.0 |
| Signing-key tarballs | Moved to `synaplan-nextcloud/.local/` (`nextcloud-certs.tgz`, `nextcloud-keys-inine.tgz`) with `.local/README.md`. Gitignored, outside the web root |

Still open, not part of this confirmation:

| Item | Why it waits |
| ---- | ------------ |
| `/wwwroot/nextcloud` (7.2 GB) | Bare Nextcloud 34 dev checkout used by the Collabora / Assistant work. Leave it unless that owner archives it |
| Host ownCloud.online on `:8088` | Fallback until E-5 is green |

---

## 3. Requirements per stack

### 3.1 Synaplan core

| Requirement | Value |
| ----------- | ----- |
| Start | `cd synaplan && docker compose --profile oidc up -d` in every phase (the reference client's `sso` profile runs in the regression set of every phase; Nextcloud CF2.6; OpenCloud) |
| Test values | For CF3+ scripts: `CLOUD_DELIVERY_TTL_SEC` and `CLOUD_DELIVERY_UNCONFIRMED_SEC` (`08` §0.8) on `backend`, `worker` and `scheduler` — the sweeps run in the scheduler |
| AI in L2 scripts | The scripts switch the default models to `TestProvider` like `frontend/tests/e2e/global-setup.ts` and restore them (`09` §0.4); walks use a real model |
| Must be running | `backend`, `worker`, **`scheduler`**, `db`, `redis`, `centrifugo`, `qdrant`, `tika`, `mailhog`, `compute`; `frontend` on `:5173` |
| OIDC | In `backend/.env`: `OIDC_CLIENT_ID=synaplan-app`, `OIDC_CLIENT_SECRET=test-oidc-secret`, `OIDC_DISCOVERY_URL=http://host.docker.internal:8080/realms/synaplan` — the values of `docker-compose.test.yml`; then `docker compose up -d backend worker scheduler`. Without them OpenCloud's organisation login and every bearer path of the `sso` profile answer 401 |
| Flags | `PLATFORM_LINKS.ENABLED = 1` in `BCONFIG` (toggle: `enable_flag` / `disable_flag` in `_devextras/testing/platform-links/lib.sh`). From CF4.1: the `cloud_files` FeatureModule, decisive env `CLOUD_FILES_ENABLED` (name fixed by brief CF4.1, listed in `backend/.env.minimal` and `docker-compose.minimal.yml`) |
| File work (J-CF-13, CF5.8) | `COMPUTE_URL=http://compute:8080` and `COMPUTE_TOKEN` matching `COMPUTE_AUTH_TOKEN` of the `compute` service in `backend/.env`; egress stays off |
| Mail | MailHog receives everything; "Summarize and mail me" is checked at `http://localhost:8025` |
| Accounts | The personas of `09` §0.2: Ada (linked) = `demo@synaplan.com` / `demo123` (seeded); Ada (organisation) = `ada@example.org`, created by her first organisation login; Carol (provisioned) = `carol@example.org`, created by a cloud's provisioning and never used with Keycloak; the seeded admin account (defaults in `_devextras/testing/platform-links/lib.sh`) |
| Keycloak realm `synaplan` | Clients `synaplan-app` (confidential), `opencloud` (public, redirects `https://host.docker.internal:9200/*`, `:9201/*`), `synaplan-opencloud` (confidential, token exchange). Users `testuser` / `testpass123` (role `administrator`, `testuser@synaplan.com`), `admin` / `admin` (`oidc-admin@synaplan.com`), `demo` / `demo` (`oidc-demo@synaplan.com`) — their emails differ from local accounts on purpose, because an organisation login is refused for an email another account already has. All created by `_docker/keycloak/setup.sh` (`keycloak_setup` service). E-3 adds `ada`, `bob` and the `nextcloud` client |
| Host | `host.docker.internal → 127.0.0.1` in `/etc/hosts`; Keycloak certificate accepted once |
| Playwright | Host install: `make -C frontend deps-host` then `npx playwright install --with-deps` (image `v1.63.0-noble` if run in Docker) |

### 3.2 Nextcloud — target `docker-compose.dev.yml` (E-2)

Today: one container, SQLite, no cron, no `user_oidc`. Target:

| Service | Image | Notes |
| ------- | ----- | ----- |
| `nextcloud` | `${NC_IMAGE:-nextcloud:35-apache}` | 35 is `nextcloud:latest` and the top of the range CF1.3a sets (`min-version="33"`, `max-version="35"`, row 47). The test matrix is `nextcloud:33-apache` (floor) and `nextcloud:35-apache` (top); pin both by digest in E-2, Renovate keeps them current |
| `nextcloud-db` | `mariadb:11.4` by digest | Database `nextcloud`, user `nextcloud`; on the stack's private network only — the Synaplan network already has a service called `db` |
| `nextcloud-cron` | same image as `nextcloud`, `entrypoint: /cron.sh`, same volumes | Makes "system cron" true for the dev stack — watches (CF5) and the admin warning depend on the mode |
| (no `redis`) | — | Not needed for a one-person dev stack |

| Setting | Value |
| ------- | ----- |
| Port | `8081:80` (unchanged) |
| Network | `synaplan_synaplan-network` (external, unchanged) |
| Env | `NEXTCLOUD_TRUSTED_DOMAINS="localhost localhost:8081 nextcloud 127.0.0.1 host.docker.internal"` (Nextcloud 33+ refuses a password login whose `Origin` host is not trusted, so `localhost:8081` is listed), `OVERWRITEHOST=localhost:8081`, `OVERWRITEPROTOCOL=http`, `OVERWRITECLIURL=http://localhost:8081`, `NEXTCLOUD_ADMIN_USER=admin`, `NEXTCLOUD_ADMIN_PASSWORD=admin`, `PHP_MEMORY_LIMIT=1G` |
| Hooks | `docker/dev-hooks/before-starting/10-link-synaplan-app.sh` links the app on every start; `docker/dev-hooks/post-installation/20-dev-setup.sh` runs once after the official image installed Nextcloud, before Apache starts; `docker/dev-seed.sh` runs on every `make dev-up` for what needs HTTP |
| `20-dev-setup.sh` does | `occ background:cron`; `allow_local_remote_servers` true (the app calls `http://backend`); no skeleton files; default language `en`; `firstrunwizard` disabled (its dialog covers the page for every new user); mail to MailHog (`mailhog:1025`); the app set to the local stack (`active_env=local`, `synaplan_url_local=http://backend`, `synaplan_public_url_local=http://localhost:5173`); users `ada`, `bob`, `carol` (password `<name>-dev-pass`; emails `ada@example.org`, `bob@example.org`, `carol@example.org`); organisation login (next row) |
| `dev-seed.sh` does | enables the app (with `--force` while `info.xml` stops below the server version); Ada's eight folders `Specs`, `Incoming-DE`, `Incoming-EN`, `Reports`, `Invoices`, `Exports`, `Projects/Q3`, `HR/Contracts` over WebDAV; `Incoming-DE` (edit) and `Specs` (read) shared with Bob over the OCS API. Later, E-6's helper creates and deletes the throwaway user `nc-temp` for J-NC-2, and from CF3.3 on the stack sets `delivery_url_override` to `http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound` |
| Organisation login (default; `NC_OIDC=0` skips it) | `user_oidc` from the App Store; `allow_multiple_user_backends=1` (the password login stays); `allow_insecure_http=1` (`user_oidc` refuses plain `http://localhost:8081` otherwise); `soft_auto_provision` (Keycloak's `ada` signs in as the local `ada`); `occ user_oidc:provider keycloak --clientid=nextcloud --clientsecret=nextcloud-dev-secret --discoveryuri=http://keycloak:8080/realms/synaplan/.well-known/openid-configuration --scope="openid email profile" --unique-uid=0 --mapping-uid=preferred_username`. The discovery document names the issuer `https://host.docker.internal:8443/realms/synaplan`; `KC_HOSTNAME_BACKCHANNEL_DYNAMIC` keeps server calls on `http://keycloak:8080`. Keycloak accepts both redirect URIs, `/apps/user_oidc/code` and `/index.php/apps/user_oidc/code` (E-3) |
| Make targets | `dev-up`, `dev-down`, `dev-reset` (`down -v`), `dev-occ CMD="…"`, `dev-logs`, `dev-shell`; `NC_IMAGE=nextcloud:33-apache make dev-reset dev-up` for the floor (a different major version needs fresh volumes) |
| Ready when | `env-check.sh --cloud nextcloud --oidc` (§5) has no `FAIL` |

### 3.3 OpenCloud — existing `docker-compose.yml` (E-4)

| Requirement | Value |
| ----------- | ----- |
| Prerequisite | Synaplan `--profile oidc` up and healthy (`keycloak_setup` finished) |
| Version | `opencloudeu/opencloud-rolling:8.1.0` by digest (as pinned). Rolling releases move fast: a bump is an E-8 entry, never a silent change |
| Start | `make frontend-install && make frontend-build && make docker-up`; the Go service image is rebuilt with `docker compose build synaplan-backend` after Go changes |
| Config volume | fresh (§2.2) |
| Dev web server | `make frontend-serve` on `:9201` for extension work (`AGENTS.md`: never `pnpm build` during development); Playwright `make frontend-test-e2e-dev` against `:9201` |
| Users | Keycloak realm users: `testuser`, `admin`, `demo`; E-3 adds `ada` and `bob` so J-CF-1 and J-CF-10 read the same on all three clouds |
| Spike CF-S3 adds | event-bus exposure (NATS) and a service account for impersonation under `dev/docker/` with env overrides; both documented in `README.md` once the spike says yes |
| Delivery URL (from CF3.5, P2) | `SYNAPLAN_DELIVERY_URL=http://host.docker.internal:9106/api/synaplan/inbound` on the Go service |
| Ready when | `https://host.docker.internal:9200` logs `testuser` in through Keycloak · the Synaplan menu appears on a file · `curl -k https://host.docker.internal:9200/.well-known/openid-configuration` returns the realm issuer · `curl -s -o /dev/null -w '%{http_code}' http://localhost:9106/api/synaplan/me` answers `401` (the Go service is up and asks for a login; its routes are in `backend/pkg/command/server.go`) |

### 3.4 ownCloud.online — new `docker-compose.dev.yml` (E-5)

There is no official image for BW-Tech's ownCloud.online. The dev stack runs
the BW-Tech checkout inside a PHP-Apache container, the way the host install
does today, but reproducibly.

| Service | Image | Notes |
| ------- | ----- | ----- |
| `owncloud` | built from `docker/Dockerfile.dev`: `php:8.4-apache` + the extensions BW-Tech's release workflow installs (`apcu ctype curl dom exif fileinfo gd iconv imagick intl json libxml mbstring memcached openssl pdo pdo_mysql pdo_sqlite posix simplexml xml zip`) + `a2enmod rewrite headers env dir mime setenvif` | PHP 8.4 is the app's `min-version` |
| `db` | `mariadb:10.11` | the newest MariaDB in BW-Tech's own CI matrix |
| `cron` | same image, `sh -c 'while true; do php /var/www/html/occ system:cron; sleep 60; done'` as `www-data` | background jobs for CF5 |

| Setting | Value |
| ------- | ----- |
| Server checkout | `${OCO_SERVER_PATH:-../owncloud.online}` mounted at `/var/www/html`, checked out at tag `v11.0.10` (`OCO_TAG`, the version the app README names; the repository has tags `v11.0.10` … `v11.0.13`, the local checkout is at 11.0.11 + 39 commits). CF-S4 asks the hoster which version it runs; a different answer changes `OCO_TAG` in a dated STATUS entry |
| App | the plugin repo mounted at `/var/www/html/apps/oco_synaplan` (a nested bind mount, shadows the host symlink) |
| Port | `8083:80` (`:8082` and `:8088` are taken by the host Apache) |
| Network | `synaplan_synaplan-network` |
| First start (`docker/dev-entrypoint.sh`) | wait for `db`; if `config/config.php` has no `installed`: `occ maintenance:install --database mysql --database-host db --database-name owncloud --database-user owncloud --database-pass "$DB_PASS" --admin-user admin --admin-pass admin --data-dir /var/www/html/data`; `occ config:system:set trusted_domains 1 --value owncloud`, `… 2 --value host.docker.internal`; `occ config:system:set overwrite.cli.url --value http://localhost:8083`; `occ background:cron`; `occ app:enable oco_synaplan`; users `ada`, `bob` and `carol` — ownCloud.online's Carol has her own email `carol.oco@example.org`, because Synaplan refuses to provision a second account with an email that already exists — and the same folders and shares as 3.2; from CF3.4 on the delivery URL override `http://owncloud/index.php/apps/oco_synaplan/api/v1/inbound` |
| Writable paths | `data/`, `config/`, `apps-external/` (if the checkout uses it) owned by `www-data`; the container runs Apache as `www-data` with the host UID mapped (`APACHE_RUN_USER`) so the checkout stays editable on the host |
| Make targets | `dev-up`, `dev-down`, `dev-reset`, `dev-occ CMD="…"`, `dev-logs`, `dev-shell` (same names as Nextcloud, so briefs and scripts read alike) |
| Ready when | `curl -s http://localhost:8083/status.php` has `"installed":true` and the expected `versionstring` · `occ app:list` has `oco_synaplan` enabled · `docker exec … curl -s http://backend/api/v1/config/runtime` answers |

Until E-5 is green, walks may use the host install on `:8088`; the STATUS entry
then says so, because it is not the stack the briefs name.

### 3.5 Ports

| Port | Owner |
| ---- | ----- |
| 8000 / 5173 | Synaplan API / UI |
| 8025 | MailHog |
| 8080 / 8443 | Keycloak admin (http) / issuer (https) |
| 8081 | Nextcloud dev |
| 8083 | ownCloud.online dev (new, P3) |
| 8099 | reference client `cloud-fixture` (E-9; Synaplan calls it on the Docker network, the host port is for debugging) |
| 9200 / 9201 / 9106 | OpenCloud bundled / web dev server / Go service |
| 80, 8082, 8088 | host Apache (not ours; `:8088` is the host ownCloud.online install) |
| 9980 | `nextcloud-collabora` (Collabora plan) |

### 3.6 Memory and what to run together

Approximate resident memory: Synaplan core 4–5 GB, Keycloak 0.7 GB, Nextcloud
stack 0.8 GB, OpenCloud stack 1 GB, ownCloud.online stack 0.5 GB, Playwright
browsers on the host 1 GB, Collabora 2 GB. All three clouds plus Keycloak
beside the core need **16 GB** for WSL2 / Docker Desktop (`.wslconfig`
`memory=16GB`). With less, run one cloud at a time; the briefs never need two
clouds in the same test except the cross-cloud copy check in `09` §4, which
runs Nextcloud and OpenCloud only.

---

## 4. Update plan — environment briefs E-1 … E-9

Each E-brief is a PR (or, for E-1, a confirmed local action) and follows the
brief rules of [`08`](./08_agent_briefs.md) §0. Order per phase (`00` row 42):

- **P0, before CF0.7:** E-1 → E-3 → E-2 → E-6; E-4 beside them (the
  OpenCloud stack is needed in P1 for CF-S1, CF-S3 and the compatibility
  check). The host install on `:8088` gets the user `carol`
  (`carol.oco@example.org`) for the compatibility check.
- **P1, before CF2.1:** E-9 (the reference client; needs `00` row 45).
- **P3:** E-5 (the ownCloud.online Docker stack) is the first brief of P3;
  until then CF-S4 and the compatibility check use the host install on
  `:8088`, and the STATUS entry says so.
- **Any time:** E-7 (docs) once the stack it documents is green; E-8 is
  recurring.

| Brief | Where | Steps | Acceptance |
| ----- | ----- | ----- | ---------- |
| **E-1 Cleanup and baseline** | workstation | §2.1 and §2.2 done. Bring `synaplan-scheduler` up and find why it exited with 127 (image rebuilt? entrypoint missing?) — fix in `synaplan` if it is a repo issue, record it. | `docker compose ps` shows every §3.1 service up; the §2.2 table has a dated answer per row in STATUS |
| **E-2 Nextcloud stack v2** | `synaplan-nextcloud`: `docker-compose.dev.yml.example`, `docker/dev-hooks/post-installation/20-dev-setup.sh`, `Makefile`, `README.md` (dev section), `docs/HOSTING_GUIDE.md` (only the cron sentence if it changes) | Compose per §3.2 (`nextcloud-db`, `nextcloud-cron`, env, hooks, seed); new Make targets; `NC_IMAGE` and `NC_OIDC` variables; `make dev-reset` documented as destructive. Keep the hook that links the app. | Fresh `make dev-reset && make dev-up` reaches "Ready when" of §3.2 on `nextcloud:35-apache` and `nextcloud:33-apache` (on 35 one `WARN` until CF1.3a raises `max-version`); `ada` logs in, sees the eight folders and the share with Bob; `carol` exists; Keycloak's `ada` signs in as the local `ada` |
| **E-3 Keycloak clients and users for the clouds** | `synaplan`: `_docker/keycloak/setup.sh` (additive block "Cloud apps", defaults inside the script like the OpenCloud block), `docs/DEVELOPMENT.md` | Client `nextcloud` (confidential, `KC_NC_CLIENT_SECRET` default `nextcloud-dev-secret`, redirects `http://localhost:8081/apps/user_oidc/code` and `…/index.php/apps/user_oidc/code`, audience mapper like `synaplan-app`); users `ada` / `bob` (`ada-dev-pass`, `bob-dev-pass`, emails `ada@example.org`, `bob@example.org`, given/family names). The new block checks before it creates; the older blocks run once per Keycloak container. Synaplan accepts the realm's tokens once `backend/.env` carries the OIDC values of §3.1. | `docker compose --profile oidc up -d` from scratch creates them; `kcadm get users -r synaplan` lists five users; the Synaplan login page still logs `testuser` in (no regression on the `oidc` E2E jobs) |
| **E-4 OpenCloud refresh** | `synaplan-opencloud` (compose unchanged unless CF-S3 adds services), `README.md` | Config volume reset (§2.2); `make docker-up`; Go image rebuilt; walk the login; record the running OpenCloud version from the UI's about dialog in STATUS | "Ready when" of §3.3; `make frontend-test-e2e-dev` green on the refreshed stack |
| **E-5 ownCloud.online Docker stack** | `synaplan-owncloud-online`: `docker-compose.dev.yml.example`, `docker/Dockerfile.dev`, `docker/dev-entrypoint.sh`, `Makefile`, `README.md`, `.gitignore` (`docker-compose.dev.yml`) | Per §3.4. The server checkout path (`OCO_SERVER_PATH`) and tag (`OCO_TAG`, default `v11.0.10`) are variables; the compose never assumes `/wwwroot`. Document how to fetch the BW-Tech repository and check out the tag. First brief of P3. | "Ready when" of §3.4; the four file actions work against `http://backend` in shared mode; `make dev-reset && make dev-up` is repeatable in under 10 minutes |
| **E-6 `env-check.sh`, fixtures, cloud helpers** | `synaplan`: `_devextras/testing/cloud-apps/` (`env-check.sh`, `lib.sh`, `fixtures/`, `make-fixtures.sh`, `nextcloud.sh`, `opencloud.sh`, `owncloud.sh`, `use-test-provider.sh`) | §5 and `09` §0.3–§0.4. Reuse `_devextras/testing/platform-links/lib.sh` helpers (`login`, `http_json`, `assert_eq`, `summary`). `use-test-provider.sh on|off` switches the default models to `TestProvider` and back, with the same admin call as `frontend/tests/e2e/global-setup.ts`. Fixtures under 200 KB total committed; large files generated. | `env-check.sh --cloud nextcloud --oidc` prints one line per check and exits 0 on the maintainer workstation (P1); `make-fixtures.sh` is idempotent; the Nextcloud helper can upload a fixture into Ada's folder and wait for it to be visible; the OpenCloud and ownCloud helpers follow with their phases |
| **E-7 Developer docs** | each repo's `README.md` / `docs/DEVELOPMENT.md` | One "Local stack" section per repo that points here for versions and lists the Make targets; `synaplan/docs/DEVELOPMENT.md` gains the `--profile oidc` + clouds paragraph | A new developer reaches "Ready when" for one cloud from the docs alone (tested by someone who did not write them; dated STATUS entry) |
| **E-9 Reference client** (P1, before CF2.1; needs row 45) | `synaplan`: `_devextras/testing/cloud-apps/fixture-app/` (`index.php` for PHP's built-in server — the pattern of `_devextras/testing/messages-gateway/fixture-upstream.php`; `compose.yml` with the service `cloud-fixture` on `synaplan_synaplan-network`, port `8099`; `README.md`) | First version — the server skeleton: answers `POST /inbound` (unsigned → `401`), keeps "files" in a local folder per platform user, stores an instance credential once one is set, exposes `/control` endpoints the L2 scripts use (put a file, list what Synaplan wrote, switch profile, and the three test switches of `08` §0.8: hold inbound, mute events, allow self-write). Registration needs `cloud-fixture` in the dev host list, which CF2.1 adds — so registering and the probe are part of CF2.1's acceptance. Profiles per `00` row 45. Later core briefs add their protocol (`08` §0.8). | `env-check.sh` fixture group green: `cloud-fixture` running, reachable from `backend`, `401` to an unsigned body |
| **E-8 Refresh policy** (recurring) | STATUS | Monthly, or when an app's `max-version` moves: bump the digest-pinned images here (§3), re-run `env-check.sh --cloud all`, re-run the CF0 script set (`09` §1), one dated entry. OpenCloud rolling: pin to the version the partner installation runs. | The §0 table and this section carry the current pins; no stack is more than one major version behind what the apps declare |

---

## 5. `env-check.sh` — what "ready" means, mechanically

Location: `synaplan/_devextras/testing/cloud-apps/env-check.sh` (E-6).
Usage: `./env-check.sh [--cloud nextcloud|opencloud|owncloud|all] [--oidc]
[--compute]`. The fixture group always runs. Per phase: P1 `--cloud nextcloud
--oidc` (and `--cloud opencloud` before the compatibility check), P2 `--cloud
opencloud --oidc`, P3 `--cloud owncloud`. Output: one line per check — `PASS`, `FAIL` or `SKIP` with a
reason — then a summary; exit code 1 if any `FAIL`. Checks never change state.

| Group | Check |
| ----- | ----- |
| core | `backend`, `worker`, `scheduler`, `db`, `redis`, `centrifugo`, `qdrant`, `tika`, `mailhog`, `compute` containers running; `GET /api/v1/config/runtime` 200; `PLATFORM_LINKS.ENABLED` = 1 in `BCONFIG`; MailHog API `GET :8025/api/v2/messages` 200; login as Ada works |
| core `--compute` | `COMPUTE_URL` set in `backend/.env`; `compute` health route 200 |
| oidc `--oidc` | `keycloak` healthy; realm `synaplan` discovery document reachable at `http://localhost:8080/realms/synaplan/.well-known/openid-configuration`; clients `synaplan-app`, `opencloud`, `synaplan-opencloud`, `nextcloud` exist; users `testuser`, `ada`, `bob` exist (admin CLI inside the container); `host.docker.internal` resolves on the host |
| nextcloud | container healthy; `status.php` installed; version within the app's `min`/`max`; `synaplan_integration` enabled; `backgroundjobs_mode` = `cron`; `cron` container running; `allow_local_remote_servers` true; users `ada`, `bob`, `carol` exist; `ada` has the eight folders; the container reaches `http://backend`; the `backend` container reaches `http://nextcloud/status.php` |
| fixture | `SKIP` until E-9 exists; then `cloud-fixture` running and `docker exec synaplan-backend curl -s -o /dev/null -w '%{http_code}' -X POST http://cloud-fixture:8099/inbound` answers `401` (up, refuses unsigned) |
| opencloud | `:9200` answers; discovery document names the realm issuer; `http://localhost:9106/api/synaplan/me` answers `401`; the `backend` container reaches `http://host.docker.internal:9106`; `make frontend-test-e2e-dev` prerequisites present (`frontend/node_modules`) |
| owncloud | `status.php` installed with the expected version (`OCO_TAG`); `oco_synaplan` enabled; `cron` container running; users and folders as for Nextcloud; reaches `http://backend`; the `backend` container reaches `http://owncloud/status.php` |
| versions | prints the version of every component for the STATUS entry (Synaplan release, Nextcloud, OpenCloud, ownCloud.online, Keycloak, app versions from `appinfo/info.xml` / `manifest.json`) |

---

## 6. Known constraints

- **Cursor cloud sandbox** (`AGENTS.md`): `frontend-widgets` cannot start
  (cgroup), local Ollama chat segfaults — use a cloud provider key for chat
  steps; embeddings work, so Make-searchable and the bird can be tested there.
- **WSL2 clock drift** after sleep shifts timers; the quiet period (60 s) and
  window (10 min) tests in `09` §6 read the server clock (`date` inside the
  `backend` container), never the host clock.
- **Self-signed Keycloak** — browsers must accept the certificate once;
  Playwright runs with `ignoreHTTPSErrors` for the OpenCloud and OIDC specs
  (already the case in the existing configs).
- **Nextcloud `ajax` cron mode** silently breaks watches; E-2 makes `cron` the
  dev default and CF5.4 shows the admin warning for installations that stay on
  `ajax`.
- **Host Apache** owns `:80`, `:8082`, `:8088`; nothing in the plan may bind
  them.
- **Docker Desktop's Windows CLI on the maintainer workstation.** `docker` is a
  wrapper around `docker.exe`. Compose translates bind-mount paths, a bare
  `docker run -v /wwwroot/…` mounts an empty directory, and environment
  variables reach Compose only when `WSLENV` names them — the Nextcloud
  Makefile exports `NC_IMAGE` and `NC_OIDC` that way. Scripts use
  `docker compose` or `docker exec`, never `docker run -v`.
- **After a WSL restart, Docker Desktop starts containers before the WSL
  paths are shared.** Bind mounts come up empty — Nextcloud then serves
  without the app — and `/tmp` is gone. Restart the affected containers
  (`docker compose restart nextcloud nextcloud-cron`); keep nothing needed
  later in `/tmp`.
- **OpenCloud 8.1.0 crashes under parallel load.** Several Playwright workers
  at once end the `opencloud` container with `fatal error: concurrent map read
  and map write` (web service, config merge), and the service has no restart
  policy. Run the suite as CI does — `CI=1 pnpm test:e2e` (one worker) — and
  bring a crashed stack back with `docker compose up -d`; Graph answers about
  two minutes later. On a fresh install, the first run can fail once while the
  test user's personal space is still being created.
- **Logging out of Synaplan through Keycloak fails in the dev stack** with
  "Invalid redirect uri": `synaplan-app` allows post-logout redirects to
  `:8000`, the dev UI runs on `:5173`. The test stack serves both on `:8001`,
  so `oidc-auth.spec.ts` › "should logout" passes there and fails against the
  dev stack (the other OIDC specs pass on both, checked 2026-10-19).
