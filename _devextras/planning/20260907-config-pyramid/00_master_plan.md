# Configuration pyramid — one home per setting, one contract per deployment — master plan

**Status:** Proposal, 2026-09-07. Decision checklist in §0 is **unticked**.
Cross-cutting hygiene track; it does not appear in the six product tracks of
[`../20260903_roadmap.md`](../20260903_roadmap.md) but every one of them adds
`BCONFIG` groups and compose profiles, so it should be decided before W3.
**Owner surface:** Operate → System configuration (existing), plus one CLI
(`app:config:*`) and one file format (the instance profile).
**Release class:** backend-only + docs; chart and platform changes live in
their own repos. Nothing here is `store-required`.
**Related:**

- [`../../../docs/CONFIGURATION.md`](../../../docs/CONFIGURATION.md) — the
  three-places rule this plan formalizes and shrinks
- [`../../../deploy/README.md`](../../../deploy/README.md) — the single-node
  production contract and the "adapters call the scripts" rule
- [`../20260822-open-plugin-platform/README.md`](../20260822-open-plugin-platform/README.md)
  — manifest v2; plugin activation moves into the pyramid (§4.5)
- [`../202609_ai_plugs/00_master_plan.md`](../202609_ai_plugs/00_master_plan.md)
  — `PLUGS.*` and `plug_keys`; the first track that must use the registry
- [`../20260903_roadmap.md`](../20260903_roadmap.md) §8.1 — `synaplan-bundle.v1`;
  the instance profile is its admin-level section (§4.3)
- [`../20260902-platform-self-awareness/00_master_plan.md`](../20260902-platform-self-awareness/00_master_plan.md)
  — `app:selfaware:inventory`, which `app:config:doctor` extends
- `synaplan-charts/charts/synaplan/`, `synaplan-platform/docker-compose.yml`
  — the two enterprise consumers of the contract

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **Every setting has exactly one home layer** (L0 boot, L1 topology, L2 instance policy, L3 group, L4 user — §4.1). A setting that today lives in two layers keeps one and gets a deprecated alias in the other. | Locked | ☐ |
| 2 | **One precedence rule** replaces today's three (`provider_keys`: DB wins; `ACCESS.*`: env pin wins; `MESSAGES_GATEWAY.UPSTREAM_URL`: DB wins in prod). Rule: *env answers "how do I boot and who are my neighbours"; the database answers everything else; automation writes the database through the instance profile, and a profile row may be `locked`.* `BCONFIG.BLOCKED` is the lock; no new pin mechanism. | Locked | ☐ |
| 3 | **A settings registry is the single source of truth**: one declarative list (key, layer, type, default, secret, restart-required, since, deprecated aliases, doc) from which `.env.example`, `deploy/selfhost.env.example`, the admin schema, the Helm values schema section and the docs reference table are generated. Today's `SystemConfigService` schema (140 fields, `source=env|database`) is the seed. | Registry in `backend/config/settings/*.yaml` | ☐ |
| 4 | **CI parity gate**: every `%env(X)%` / `getenv` read in `backend/` must be in the registry; every registry entry must appear in the generated docs; every registry key of layer L2 must have a seeder default. Same discipline as the i18n locale-parity test. | Locked | ☐ |
| 5 | **Complexity budget**: L0 ≤ 20 variables, L1 ≤ 25 variables. The base `.env.example` shrinks from 134 to ≤ 45 assigned variables; everything else moves to L2 (encrypted where secret) or is deleted. A PR that raises the count must say so in its description. | Budget | ☐ |
| 6 | **Application credentials are not boot secrets.** Stripe/IAP, WhatsApp, Google/GitHub/Apple OAuth apps, reCAPTCHA, Brave, ElevenLabs, Cloudflare, Higgsfield follow the `provider_keys` model: encrypted `BCONFIG` group, admin UI, env value imported on first load, UI wins. Only `APP_SECRET`, `TOKEN_SECRET`, DB, Redis, `REALTIME_*`, `MAILER_DSN` stay env-only (they are needed before the database can be read). | Locked | ☐ |
| 7 | **Instance profile file** (`synaplan-profile.yaml`) = the admin-level sections of `synaplan-bundle.v1`, applied idempotently by `app:config:apply` (insert-or-update, honouring `locked`). Secrets appear as `${env:NAME}` references, never as values. It replaces the Helm chart's bespoke `models.*` → CLI init and `synaplan-platform`'s ad-hoc SQL/scripts. | One format, shared with export/import | ☐ |
| 8 | **Deployment targets express only L0 + L1 (env) and optionally one profile file.** Dev compose, `deploy/compose.yaml` (+ Elestio, AWS, Umbrel), `synaplan-platform`, `synaplan-charts`, and a future Ansible role are all *adapters* of that one contract. No adapter invents a setting name. | Locked | ☐ |
| 9 | **Ansible is an adapter, not a dialect.** A role under `deploy/ansible/` templates `selfhost.env` + `synaplan-profile.yaml` and calls `deploy/scripts/*.sh`, exactly like `deploy/elestio/` and `deploy/aws/`. It is the VM path for enterprises; `synaplan-charts` stays the Kubernetes/openDesk path. | `deploy/ansible/` | ☐ |
| 10 | **Helm chart alignment**: the init script stops running `doctrine:schema:update --force` (forbidden by house rules) and relies on the image entrypoint's migrate + `app:seed`; `models.*` becomes `profile:` (rendered to a ConfigMap, applied by `app:config:apply`); chart value names map to registry names 1:1 (`DB_*` → `DATABASE_*_URL` stays a chart convenience, `TIKA_URL` alias retired). | Chart 0.5.0 | ☐ |
| 11 | **Sidecar = one L1 URL, no extra flag.** Empty URL means the capability is absent (the `OFFICE_CONVERT_URL` model). Compose profiles (`office`, `tts`, `oidc`, `local-ai`, planned `docling`, `searxng`) set exactly that URL and nothing else. | Locked | ☐ |
| 12 | **Partner apps get one L2 flag each** in a `LINKS` group (Outlook add-in, Desktop, Nextcloud/ownCloud per-user provisioning, OpenCloud token exchange), replacing today's mix (route always on / `DESKTOP_AGENT.ENABLED` / per-user CLI / out-of-band Keycloak client). Plugin activation becomes an L2 list (`PLUGINS.ENABLED`) in coordination with the open-plugin-platform plan; `DEFAULT_USER_PLUGINS` env is retired. | `LINKS.*`, `PLUGINS.ENABLED` | ☐ |
| 13 | **`app:config:doctor`** prints the effective value and source of every setting per layer, unknown env variables (typos), deprecated aliases in use, placeholder secrets, unreachable L1 neighbours. It runs in the entrypoint (warn-only) and in `deploy/scripts/smoke-test.sh`. | Locked | ☐ |
| 14 | **Deprecations live one minor release** as aliases with a boot warning, then a migration removes the row/variable. Nothing is removed and renamed in the same release. | Locked | ☐ |
| 15 | **Mobile:** all `backend-only`; admin UI changes `ota-candidate`. Runtime config keys are additive. | Locked | ☐ |
| 16 | **Who owns L2 is one switch, not a second architecture.** `CONFIG.MANAGED_MODE`: `ui` (default — admin edits, profile seeds) or `file` (profile is authoritative: System configuration renders read-only with "managed by your operator", the setup wizard is off, `app:config:apply --prune` reconciles drift). Target 2 sets `file`; targets 1 and 3 stay `ui`. | `ui` default | ☐ |
| 17 | **One `OFFLINE=true` preset instead of hunting outbound flags.** It sets `UPDATES.CHECK_ENABLED=0`, `MARKETING_NEWS.ENABLED=0`, `SELF_AWARE.DOCS_RAG_ENABLED=0`, `WEB_SPEECH_ENABLED=false`, `AUTO_DOWNLOAD_MODELS=false` and makes `doctor` fail on any L1 URL outside the cluster. | Preset | ☐ |
| 18 | **No artefact downloads at runtime in the chart.** Model and voice weights come from a registry, PVC or pre-baked image the customer controls; `triton.models[].download` and the TTS `voice-downloader` init container become opt-in and default off. Today both pull from HuggingFace on start, which contradicts what the two Kubernetes customers were promised. | Default off | ☐ |
| 19 | **The dev quick win is a separate, immediate PR, not this plan.** Default `docker compose up` becomes cloud-first (local AI behind `COMPOSE_PROFILES=local-ai`, as `deploy/compose.yaml` already does) and the boot page becomes its own container so it answers before the backend image is built. This plan only guarantees it stays a *small* L0. | Split out | ☐ |

---

## 1. The concept in three sentences

> Synaplan settings form a pyramid: a small base the container needs to
> start, a thin layer saying where its neighbour services are, and a large
> top the administrator changes at runtime without a restart. Every setting
> has exactly one home in that pyramid, and every way of deploying Synaplan —
> a single Docker image, a three-node cluster, a Helm chart, an Ansible role —
> provides only the two bottom layers plus, optionally, one file that fills
> the top. Fewer places, one rule, one file format: less to learn for the
> single-server admin, and GitOps-able for the enterprise.

---

## 2. Why this exists (verified 2026-09-07)

Numbers from a survey of `synaplan/`, `synaplan-charts/`, `synaplan-platform/`
and the eight connector repositories:

- **`backend/.env.example` has 134 assigned variables in 612 lines**, plus five
  documented only as comments, plus variables set only by compose
  (`APP_ENV`, `PLUGINS_DIR`, `SEED_DEMO_DATA`, `AI_DEFAULT_PROVIDER`, …) and
  two read by code that appear nowhere (`FEATURE_HELP`, `APP_VERSION`).
  22 of the 134 are Stripe/IAP, 15 are OAuth/reCAPTCHA apps, 15 are Tika /
  rasterizer / office tuning, 6 are Brave — none of which the container needs
  to boot.
- **`BCONFIG` holds ~149 seeded keys in 26 groups**, plus groups written only
  at runtime (`ACCESS`, `provider_keys`, `M365`, `DROPBOX`, `GOOGLE_TAG`,
  `CLASSIFIER`, …). The admin schema in `SystemConfigService` exposes 140
  fields, ~91 database-backed and ~49 env-backed (shown read-only).
- **Three precedence rules for the same pattern.** Provider keys: env is a
  bootstrap, DB wins. Registration/guest chat: env is a pin, env wins.
  Messages-gateway upstream: DB wins in prod, env in dev. A new contributor
  cannot guess which one a new flag should follow.
- **Seven deployment targets say the same thing differently.** The Helm chart
  maps ~30 env names from 142 value paths and calls the database `DB_*` and
  Tika `TIKA_BASE_URL`; `synaplan-platform` uses a flat `.env` of 86 keys plus
  51 compose `environment:` entries (14 hard-coded) and calls Tika `TIKA_URL`;
  `deploy/selfhost.env.example` exposes 44 (12 of them deployment-only);
  dev compose injects 193 keys across services. The chart init script still
  runs `doctrine:schema:update --force`, which the house rules forbid.
- **Runtime enablement is scattered.** Model catalog: DB flags + Helm
  `models.*` → CLI at init; platform: SQL scripts and crons. Plugins: a
  per-user filesystem symlink created by `app:plugin:install`, plus a
  `DEFAULT_USER_PLUGINS` env in the platform repo. Partner apps: the Outlook
  bridge route is always on, Desktop has `DESKTOP_AGENT.ENABLED`, OpenCloud
  needs two Keycloak clients created by `_docker/keycloak/setup.sh`.
- **No Ansible exists anywhere**, and enterprise VM customers ask for it. If
  it is written against today's surface it becomes an eighth dialect.
- **Growth is guaranteed.** The six roadmap tracks add `PLUGS.*`, `LINKS`/
  `PLATFORM_LINKS`, approval policies, compute quotas, two compose profiles.
  Without a registry and a gate, the 134 becomes 180.

What is *not* the problem: the three-places rule in `docs/CONFIGURATION.md`
is right, `BCONFIG` per-user/global resolution with `BLOCKED` is right, the
`deploy/` contract ("adapters call the scripts") is right, `provider_keys`
env-bootstrap-then-DB is right. The plan generalizes those instead of
replacing them.

---

## 3. What already exists (do not rebuild)

| Piece | State | Role here |
| ----- | ----- | --------- |
| `SystemConfigService` schema (140 fields, `source`, tabs, types, `ProviderKeyStore` routing) | Shipped | Becomes the **registry**; the PHP array moves to declarative YAML and gains `layer`, `secret`, `restart`, `since`, `aliases` |
| `ConfigRepository` (user → owner 0), `BCONFIG.BLOCKED`, `AdminConfigLockController` | Shipped | The lock **is** the pin; `app:config:apply --locked` sets it |
| `BConfigSeeder::insertIfMissing`, `app:seed`, `app:config:seed-defaults` | Shipped | `app:config:apply` is the insert-or-update sibling for profiles |
| `ProviderKeyStore` (`provider_keys`, AES via `APP_SECRET`, env import on first load) | Shipped | Pattern for `billing_keys`, `channel_keys`, `auth_provider_keys`, `plug_keys` |
| `RegistrationConfig` / `GuestChatConfig` env pins | Shipped | Become deprecated aliases → profile `locked` rows |
| `BGROUPCONFIG` + `PolicyAllowList` | Shipped | Layer L3, unchanged |
| `GET /api/v1/config/runtime` | Shipped | Unchanged contract; additive `config.sources` for admins only |
| `deploy/compose.yaml`, `selfhost.env.example`, `scripts/lib.sh` (`ensure_deployment_secrets`, `validate_release_pin`), lifecycle hooks | Shipped | The single-node contract every adapter — including Ansible — calls |
| `SYNAPLAN_ROLE=web|worker|scheduler`, `/usr/local/bin/container-healthcheck` | Shipped | Unchanged |
| Compose profiles `office`, `tts`, `oidc`, `local-ai` | Shipped | L1 model: one URL per profile |
| `app:selfaware:inventory`, `PlatformCapabilityInventory` | Shipped | `app:config:doctor` reuses the capability probe for L1 reachability |
| `synaplan-bundle.v1` (roadmap §8.1, Agent Builder S6) | Planned | The instance profile is its admin-level section; one section registry |
| `synaplan-charts` `models.enabled/defaults`, `51-init-models.sh` | Shipped | Replaced by `profile:` → ConfigMap → `app:config:apply` |
| `synaplan-platform/scripts/activate-plugins.sh`, `enable-multitask-routing-all.sh` | Shipped | Replaced by a profile file on the NFS share |

---

## 4. Target architecture

### 4.1 The five layers

```text
            ┌─────────────────────────────────────────────┐
  L4 user   │ BCONFIG(owner=user), BCONNECTIONS, BMCPSERVERS│  never in a deployment
            │ BCREDENTIALS, plugin_data, provider_keys_user│
            ├─────────────────────────────────────────────┤
  L3 group  │ BGROUPCONFIG (PolicyAllowList)               │  IAM track, unchanged
            ├─────────────────────────────────────────────┤
  L2 policy │ BCONFIG(owner=0): flags, access, branding,   │  admin UI, no restart
            │ rate limits, DEFAULTMODEL, LINKS, PLUGINS,   │  ← synaplan-profile.yaml
            │ encrypted credential groups (*_keys)         │    (apply, optional lock)
            ├─────────────────────────────────────────────┤
  L1 topo   │ env: OLLAMA_BASE_URL, TIKA_BASE_URL,         │  "who are my neighbours"
            │ QDRANT_URL, OFFICE_CONVERT_URL, TTS, TRITON, │  empty = absent
            │ DOCLING, SEARXNG, MAILER_DSN, REALTIME_API_URL│ one per compose profile
            ├─────────────────────────────────────────────┤
  L0 boot   │ env: APP_ENV, APP_SECRET, TOKEN_SECRET,      │  needed before the DB
            │ DATABASE_*_URL, REDIS_DSN, LOCK_DSN, APP_URL,│  can be read; secrets
            │ FRONTEND_URL, REALTIME_* secrets, LOG_FORMAT,│  → K8s Secret /
            │ SYNAPLAN_ROLE, SETUP_WIZARD_ENABLED,         │    deploy/data/secrets.env
            │ BOOTSTRAP_ADMIN_*                            │
            └─────────────────────────────────────────────┘
```

Placement test for any setting, in order:

1. Is it needed before the database can be read, or is it a secret that
   encrypts other secrets? → **L0**.
2. Is it the address of another process? → **L1**. It has no companion
   `*_ENABLED`; empty means absent.
3. Is it something an administrator may change on a running instance? →
   **L2**. If it is a credential, it lives in an encrypted group and may be
   bootstrapped from env or from `${env:…}` in the profile.
4. Does it vary per team? → **L3**, only through `PolicyAllowList`.
5. Does it belong to one person? → **L4**.

Anything that fails all five is not a setting; it is a constant in the image.

### 4.2 One precedence rule

```text
effective(key) =
    L4 user row                       if the key is user-scoped and a row exists
  ▸ L3 group merge                    if key ∈ PolicyAllowList and groups on
  ▸ L2 owner-0 row                    (a BLOCKED row wins alone, as today)
  ▸ L2 seeder / registry default
```

Env never sits in that chain for L2 keys. What env *can* do for an L2
credential is **bootstrap**: on first resolution an env value is imported
into the encrypted group (the `provider_keys` behaviour, kept). What
automation does for an L2 policy is **apply a profile**, optionally with
`locked: true`, which sets `BLOCKED` — the same lock an admin can set in the
UI today. `REGISTRATION_ENABLED` / `GUEST_CHAT_ENABLED` / `MESSAGES_GATEWAY_
UPSTREAM_URL` stay working for one minor release as aliases that the
entrypoint translates into a locked profile row and logs a deprecation.

### 4.3 The instance profile

```yaml
# synaplan-profile.yaml — admin-level sections of synaplan-bundle.v1
version: 1
settings:                       # L2 BCONFIG owner 0
  ACCESS.REGISTRATION_ENABLED: { value: false, locked: true }
  ACCESS.GUEST_CHAT_ENABLED:   { value: false, locked: true }
  IAM.GROUPS_ENABLED:          true
  MULTITASK.ROUTING_ENABLED:   true
  BRANDING.BRAND_NAME:         "Stadt Musterhausen KI"
  DEFAULTMODEL.CHAT:           "openai_compatible:sovereign-gpu:chat"   # catalog key, never a BID
  LINKS.OUTLOOK_ADDIN:         false
  PLUGINS.ENABLED:             [synaform]
credentials:                    # encrypted groups; values are env references only
  provider_keys.openai:        ${env:OPENAI_API_KEY}
  auth_provider_keys.oidc_client_secret: ${env:OIDC_CLIENT_SECRET}
models:                         # BMODELS operator toggles (BACTIVE / BSELECTABLE)
  enable:  [ollama:bge-m3, openai_compatible:sovereign-gpu:*]
  disable: [anthropic:*]
```

Rules: keys are validated against the registry (`deny_unknown_fields`);
references are stable keys (`service:providerId:tag`, plugin name), never
BIDs; secrets are `${env:…}` or absent; `app:config:apply` is idempotent
(insert-or-update, deletes nothing, never touches a row it did not write
unless `--prune` is given). `app:config:export` produces the same file from a
running instance, which is how "copy this install's setup to staging" works
and why the format is shared with the bundle.

### 4.4 One contract, many adapters

```text
             registry (backend/config/settings/*.yaml)
                 │ generates
   ┌─────────────┼──────────────┬───────────────────┬─────────────────┐
   ▼             ▼              ▼                   ▼                 ▼
 .env.example  selfhost.env   docs/CONFIGURATION  admin schema     Helm values.schema
 (L0+L1 only)  .example       reference table     (SystemConfig)   section (L0+L1)
                 │
                 ▼  consumed by adapters, each providing L0+L1 env + optional profile
   docker-compose.yml (dev)   deploy/compose.yaml   deploy/elestio   deploy/aws
   deploy/umbrel              deploy/ansible (new)   synaplan-platform   synaplan-charts
```

| Adapter | Provides L0+L1 as | Provides the profile as |
| ------- | ----------------- | ----------------------- |
| dev compose | `docker-compose.yml` defaults + `backend/.env` | seeders + demo fixtures (no profile) |
| `deploy/compose.yaml` | `deploy/.env` (generated secrets in `data/secrets.env`) | `deploy/profile.yaml`, applied by the web role on start |
| Elestio / AWS / Umbrel | as today, calling `deploy/scripts` | same file, mounted |
| `deploy/ansible/` (new) | Jinja template of `selfhost.env` | Jinja template of the profile; runs `prepare.sh`, `up`, `smoke-test.sh` |
| `synaplan-platform` | one `.env` on NFS, compose reduced to `env_file` + role | `profile.yaml` on NFS; web1 applies (lock prevents web2/3 racing) |
| `synaplan-charts` | Secret refs + `env` from structured values (unchanged shape) | `profile:` value → ConfigMap → `app:config:apply` in the init container |

The single-image sysadmin sees: ~20 required lines, ~10 optional URLs, one
optional YAML. The enterprise sees: the same three things, rendered by their
tool of choice, plus a lock flag.

### 4.5 Sidecars, AI servers, MCP, plugins, partner apps in the pyramid

| Concern | L0 | L1 | L2 | L4 |
| ------- | -- | -- | -- | -- |
| AI inference servers (Ollama, Triton, OpenAI-compatible endpoints) | — | `OLLAMA_BASE_URL`, `TRITON_SERVER_URL` | catalog toggles + `DEFAULTMODEL.*` + endpoint registry rows + `provider_keys` | `provider_keys_user` |
| Sidecars (Tika, Collabora, TTS, Docling, SearXNG, Qdrant) | — | one `*_URL` each | tuning (`PLUGS.EXTRACTION.*`, thresholds — today's 7 `TIKA_*`) | — |
| MCP inbound (`/mcp`) | `MCP_ALLOWED_HOSTS` | — | `MESSAGES_GATEWAY.MCP_*` | API-key scopes |
| MCP outbound | — | — | `MCP.CLIENT_ENABLED`, `MCP.OAUTH_CONNECTORS_ENABLED`, `MULTITASK.MCP_*` | `BMCPSERVERS` |
| Plugins | `PLUGINS_DIR` | — | `PLUGINS.ENABLED` (list; replaces per-user CLI install + `DEFAULT_USER_PLUGINS`), `P_<name>.*` | `plugin_data` |
| Partner apps | — | — | `LINKS.OUTLOOK_ADDIN`, `LINKS.DESKTOP` (= today's `DESKTOP_AGENT.ENABLED`), `LINKS.NEXTCLOUD_PROVISIONING`, `LINKS.OPENCLOUD_EXCHANGE` | API keys, pairings, external ids |
| Channels (WhatsApp, M365, Dropbox, inbound mail) | — | — | `channel_keys` (encrypted; WhatsApp joins M365/Dropbox) | `BCONNECTIONS` |
| Identity (OIDC, Google/GitHub/Apple, reCAPTCHA) | `OIDC_DISCOVERY_URL`, `OIDC_CLIENT_ID`¹ | — | `auth_provider_keys` (secrets), `OIDC_ADMIN_ROLES`, `OIDC_ROLE_CLAIMS`, `IAM.*` | — |
| Billing (Stripe, IAP) | — | — | `billing_keys` (encrypted) + `BILLING.*` prices/products | — |

¹ OIDC discovery and client id stay L0 because an SSO-only instance has no
local administrator to enter them; the client secret is bootstrapped from env
into `auth_provider_keys` like every other credential.

The Keycloak clients that OpenCloud token exchange needs are an **external
L1 dependency**, not a Synaplan setting: `app:config:doctor` checks that the
exchange client exists and reports it; provisioning stays in the IdP (Nubus/
Keycloak realm export in the openDesk case), documented in one place.

---

## 4.6 The three primary targets

The same pyramid, three lid settings. No target gets its own mechanism.

| | **1 Developer clone** | **2 Kubernetes customers** | **3 Our platform** |
| - | --- | --- | --- |
| Goal | See a working app fast, feel the power | Fully automated rollout, no web administration, no downloads | Constant fresh deploys of a showcase |
| L0 | compose defaults; nothing to fill in | Secret refs from their GitOps tool | `.env` on NFS, per-node `SYNDBHOST` |
| L1 | whatever the profile started; empty = hidden | their Ollama / OpenAI-compatible / Qdrant / Tika | external Galera, external Redis, per-node Collabora |
| L2 owner (row 16) | **`ui`** — the wizard and the admin pages *are* the product tour | **`file`** — profile authoritative, UI read-only, wizard off | **`ui`** — they demo the admin UI to customers |
| Profile file | none | the whole point; `--prune` reconciles | shared on NFS; every node applies under the lock |
| Egress | free | `OFFLINE=true` (row 17), no artefact pulls (row 18) | free |
| What this plan must not do | add a required variable | require a browser step for anything | require an operator action per node |

### 4.6.1 `MANAGED_MODE=file` — the conditions that keep it safe

Hiding the administration surface is additive only if all five hold:

1. **Default is `ui`** — byte-identical behaviour for every existing install;
   `file` is a deliberate operator choice.
2. **Enforced server-side, not hidden in Vue.** `AdminSystemConfigController`
   rejects a write to a managed key with a specific error; the UI renders
   read-only because `GET /api/v1/config/runtime` tells it the mode. A frontend
   that only hides the field is a fake lock.
3. **Only keys the profile actually contains become read-only.** Everything
   else stays editable, so flipping the switch cannot freeze the whole
   instance.
4. **L3 and L4 stay editable.** Managed mode governs instance policy (L2)
   only — a user keeps their own model preference, connections and MCP
   servers, a group keeps its policies.
5. **The CLI escape hatch survives.** `app:admin:reset-password --promote`,
   `app:config:doctor` and `app:config:apply` keep working, and a profile that
   references a missing `${env:…}` fails loudly at boot instead of leaving an
   instance with no working AI and no way to fix it in the browser.

The mechanism is mostly there already (`BCONFIG.BLOCKED`,
`AdminConfigLockController`, `SETUP_WIZARD_ENABLED=false`, the SSO-only path).
The real work is the 140 admin fields rendering a managed state — one
`ota-candidate` PR, not a new subsystem.

Three consequences that today's code does not deliver:

- **Target 1 is not blocked by config but by 9 GB.** The dev compose header
  advertises `gpt-oss:20b` + `bge-m3` + Whisper and "5–15 minutes", while
  `deploy/compose.yaml` already defaults to cloud AI with `local-ai` as an
  opt-in profile. The two should agree, and the one that greets a first-time
  cloner should be the fast one (row 19). The boot page
  (`_docker/frontend/boot-status/`) is the right idea and is already started
  before `npm ci`; the remaining delay is that it lives inside the frontend
  container, so it cannot answer until that image is pulled.
- **Target 2 needs a lid, not more flags.** `BCONFIG.BLOCKED` locks single
  rows; there is no instance-level "this install is managed from a file".
  Without row 16 a fully automated customer still has a first-run wizard and
  an editable admin UI that silently diverges from their Git repository.
  And "no downloads" is currently untrue one layer down: the Triton chart
  pulls `gpt-oss-20b` and `bge-m3` from HuggingFace and the TTS chart runs a
  `voice-downloader` init container (row 18).
- **Target 3 is the strongest argument for the profile.** `BCONFIG` defaults
  are bootstrap-only: changing a seeder value never reaches an existing
  install, so every changed default needs a hand-written migration that
  `UPDATE`s rows — on a platform that redeploys constantly, that is the
  recurring cost. `app:config:apply` (insert-**or-update**, idempotent,
  lock-aware) is exactly that mechanism, generalized.

**Everyone else stays compatible** because L2 defaults keep today's effective
values, env variables live on one minor release as aliases, and
`GET /api/v1/config/runtime` only grows. A partner integration
(Outlook, Nextcloud, OpenCloud, Desktop, mobile) sees no change unless an
operator flips its `LINKS.*` flag.

---

## 5. Cut line and growth

**v1 (this plan):** registry + gate, one precedence rule, `app:config:apply`
/ `export` / `doctor`, env diet to ≤ 45, chart and platform aligned, Ansible
adapter skeleton, docs regenerated. `LINKS` and `PLUGINS.ENABLED` ship only
if the open-plugin-platform plan has not already landed an equivalent; if it
has, this plan adopts its names.

**v2 and later (not in scope):** a web UI that renders the profile diff
before applying ("what would change"), profile signing for marketplace
images, per-tenant profiles on shared clusters, Terraform module wrapping
the Ansible role, Kustomize overlays generated from the registry.

---

## 6. Sprints (PR-sized; refactor first; default-off)

| Sprint | Deliverable | Behaviour change | Repos |
| ------ | ----------- | ---------------- | ----- |
| **S0 Registry & gate** | `SystemConfigService` schema extracted to `backend/config/settings/*.yaml` with `layer`/`secret`/`restart`/`aliases`; loader; `tests/Config/SettingsRegistryParityTest` (every `%env(` in `backend/` is registered, every registered key is documented, every L2 key has a seeder default); generator for `.env.example` and the docs table; `app:config:doctor` read-only | None. Generated files are byte-identical to today's hand-written ones on the first run (that is the test) | `synaplan` |
| **S1 One rule** | `app:config:apply` / `app:config:export` / `doctor --diff` with `locked` → `BLOCKED` and `CONFIG.MANAGED_MODE` (`ui` default, `file` = read-only UI + wizard off + `--prune`); `REGISTRATION_ENABLED`, `GUEST_CHAT_ENABLED`, `MESSAGES_GATEWAY_UPSTREAM_URL` become aliases translated to locked rows at boot with a deprecation warning; admin UI shows "locked by profile" where it shows "pinned by env" today | Admin UI wording; env pins keep working; `ui` mode is today's behaviour | `synaplan` |
| **S2 Env diet** | `billing_keys`, `channel_keys`, `auth_provider_keys` encrypted groups on the `ProviderKeyStore` pattern; env import on first load; admin fields move from `source=env` to `source=database`; Tika/rasterizer/office thresholds to `PLUGS.EXTRACTION.*` (coordinated with AI Plugs S1); `GMAIL_*` retired in favour of the per-user mailbox connection; `.env.example` regenerated (≤ 45) | Env values still honoured (bootstrap); UI now editable | `synaplan` |
| **S3 Adapters** | `deploy/selfhost.env.example` regenerated; `deploy/profile.example.yaml`; web role applies the profile on start; `smoke-test.sh` runs `doctor`; **charts 0.5.0**: drop `schema:update --force`, add `profile:` → ConfigMap → init `apply`, retire `TIKA_URL`, default the Triton/TTS artefact downloads off (row 18), README regenerated; **platform**: deliberately NOT in this sprint — `synaplan-platform` works today and its scripts are the workaround the profile removes, so it adopts the profile only once S1–S3 are proven elsewhere, and only to delete `activate-plugins.sh` / `enable-multitask-routing-all.sh` | None for existing installs (profile is opt-in) | `synaplan`, `synaplan-charts` |
| **S4 Ansible adapter** | `deploy/ansible/` role: `synaplan_env` dict → `selfhost.env`, `synaplan_profile` dict → profile, tasks call `prepare.sh` / `up` / `smoke-test.sh`; Molecule test on a Docker-in-Docker target reusing `deploy/scripts/tests/test-lifecycle.sh`; README | New adapter only | `synaplan` |
| **S5 Links & plugins** | `LINKS.*` group with one flag per partner app (`DESKTOP_AGENT.ENABLED` aliased to `LINKS.DESKTOP`); `PLUGINS.ENABLED` list applied by `PluginManager` at boot (per-user symlinks remain the runtime mechanism, created from the list); `DEFAULT_USER_PLUGINS` retired; doctor checks the OpenCloud exchange client | Default: all links stay at today's effective state | `synaplan` (+ note in partner repos) |
| **S6 Docs & removal** | `docs/CONFIGURATION.md` restructured by layer with the generated reference; `_devextras/SYSADMIN-help.md` and `synaplan-platform/CLUSTER-DOC.md` point at the profile; one minor release after S1/S2: migration removes aliased env reads | Aliases removed | all |

Each sprint ends with the full gate (`make lint && make -C backend phpstan &&
make test && vue-tsc`), the mobile-impact classification, and for S3
`make all` in `synaplan-charts`.

---

## 7. Invariants (must not break)

- **INV-1 Existing installs boot unchanged.** Every current env variable
  keeps working through v1; removal happens only in S6 after one minor
  release with a logged warning.
- **INV-2 Galera-safe.** Migrations use `addSql` + `IF NOT EXISTS`; profile
  apply on a cluster is serialized through the existing Redis lock.
- **INV-3 Secrets never in files that leave the host.** The profile carries
  `${env:…}` references only; `app:config:export` refuses to emit a secret
  value; `doctor` masks.
- **INV-4 `APP_SECRET` rotation semantics unchanged.** New encrypted groups
  share `ProviderKeyStore`'s derivation and its documented caveat.
- **INV-5 Runtime config contract is additive.** `GET /api/v1/config/runtime`
  keeps every key; mobile and desktop clients are unaffected.
- **INV-6 Routing snapshots untouched.** Moving `CLASSIFIER.FAST_PATH_ENABLED`
  into the registry does not change its default.
- **INV-7 The `deploy/` contract stays the only production contract.** Ansible,
  Elestio, AWS, Umbrel and the platform call it; none reimplements it.
- **INV-8 Registry growth is visible.** The parity test prints the L0/L1
  count; exceeding the budget fails CI unless the PR raises the budget
  explicitly in the registry file.

---

## 8. Open questions for the review

1. Registry format: YAML files under `backend/config/settings/` (readable by
   the chart generator without PHP) or PHP attributes on `*Config` classes
   (type-safe, but the chart repo cannot read them)? Proposal: YAML, with a
   PHPStan-checked loader.
2. Should `OIDC_CLIENT_ID` / `OIDC_DISCOVERY_URL` really stay L0, or should an
   SSO-only install be bootstrapped entirely from the profile? Proposal: L0,
   because the profile needs a database and the wizard is off.
3. Does `synaplan-platform` want the profile applied by web1 only (host
   cron / start script) or by every node under the lock? Proposal: every
   node under the lock, so a rebuilt node converges without operator action.
4. Where does the Ansible role live long-term: `synaplan/deploy/ansible/`
   (one contract, one repo, public) or a `synaplan-ansible` repo (Galaxy
   publishing)? Proposal: start in `deploy/ansible/`, split when Galaxy
   publishing is requested.
5. The openDesk integration: is the Keycloak realm/client provisioning to be
   delivered as a Nubus extension, or documented for the operator? Out of
   scope here, but `doctor` will name the missing client either way.
