# First-Run Onboarding — Provider Wizard & DB-Backed API Keys

**Release:** 4.1 · **Priority:** P1 (adoption) · **Status:** Tier 1 + 2 shipped (PR #1392); Tiers 3–4 implemented on `feat/provider-key-wizard`; Tier 5 (arm64 base) started in `synaplan-base-php` `feat/multi-arch-base`; Tier 6 open
**Trigger:** "Is our setup easy enough for a large number of people? Groq keys and model downloads feel complicated."
**Scope of the audit:** README, `_1st_install_linux.sh`, both compose files, `docs/`, seeders + entrypoint, frontend first-run behaviour — benchmarked against Open WebUI, LibreChat and AnythingLLM (2026).

> **TL;DR** — The Docker plumbing is genuinely good; the **first five minutes inside
> the app** are where we lost people. A plain `docker compose up -d` dropped users
> into a chat whose default model (Anthropic) they had no key for, the failure was
> reported only to the browser console, and the fix meant editing `backend/.env`
> plus a container restart. PR #1392 fixes the two highest-leverage tiers: an
> in-app **provider setup wizard** (`/admin/setup`) and **DB-backed, encrypted,
> hot-reloadable provider keys** with a one-time env import. Keys never touch git.
> Tiers 3–6 (auto-resolved defaults, download progress, small-model tier + arm64,
> distribution channels) remain open.

---

## 1. Findings

### 1.1 What was already good

- One-command start, seeded admin/demo logins, minimal vs. standard compose split,
  an admin System Config UI, a Helm chart repo, and a hosted instance to try first
  — above average for a stack this size.
- The compose files show real battle-hardening (healthchecks, self-healing
  dependency ordering). **Reliability was never the problem.**

### 1.2 Where the friction actually was

| # | Friction | Impact |
|---|----------|--------|
| 1 | **Default-provider trap** — `DefaultModelConfigSeeder` seeded chat to Anthropic, so the README's own Quick Start produced a chat that errored on the first message. The Groq path only worked via the install script. | Fatal for a first impression |
| 2 | **The install script did the app's job, badly** — `_1st_install_linux.sh` fired raw SQL at the DB after boot (`UPDATE BCONFIG SET BVALUE='9' …`) behind a hand-rolled deadlock-retry loop, hardcoding a model **BID**. Our own seeder docs forbid raw BIDs (catalog keys exist for this); a catalog reorder would silently repoint new installs. Interactive + Linux/macOS-only, so unpipeable. | Latent correctness bug + no automation |
| 3 | **Keys required a restart and lived in a file** — System Config wrote `backend/.env` and returned `requiresRestart: true`. Open WebUI, LibreChat and AnythingLLM all store keys in the DB and apply them live; that is the 2026 baseline. | Below competitor baseline |
| 4 | **Failure was invisible** — the backend computed `unavailableProviders` but the frontend only `console.warn`ed it. The Feature Status page that would show provider health is dev-only (403 in prod). Ollama's multi-GB pull logged progress to container stdout only. | "Is it broken?" support load |
| 5 | **Hardware/platform barriers** — `linux/amd64` images only (every Apple Silicon dev runs under Rosetta); the local-AI path assumes `gpt-oss:20b` (~12 GB, ~24 GB GPU RAM) with no small-model tier; 12+ containers / 7 host ports vs. Open WebUI's single container. | Excludes the audience most likely to try us |
| 6 | **Hygiene** — `APP_SECRET`, `TOKEN_SECRET` and the Centrifugo secrets ship as `changeme_*` with nothing generating them; README says ~9 GB disk while `INSTALLATION.md` says 20 GB. | Trust + prod-safety |

---

## 2. Tiered improvement plan

| Tier | Work | Status |
|------|------|--------|
| **1** | **First-run provider wizard** — on first admin login, if no provider has a working key, show a guided screen: pick a provider (Groq recommended + deep link), paste key, live "Test connection", set sane defaults via **catalog keys**. Lets us delete the raw-SQL half of the install script. | ✅ **Shipped** (PR #1392) |
| **2** | **DB-backed, hot-reloadable keys** — store keys encrypted in `BCONFIG`; env stays available as a bootstrap/override for ops/K8s. The architectural enabler for Tier 1. | ✅ **Shipped** (PR #1392) |
| **3** | **Provider-aware defaults instead of hardcoded Anthropic** — resolve the default chat model to the first provider that actually has a key (Groq → OpenAI → … → Ollama-if-present); persistent "Connect an AI provider" banner replacing the console-only warning; open the Feature Status page to admins in prod. | ✅ **Done** on branch — runtime `autoApplyBestAvailable` on `/config/runtime`; Feature Status admin-only in prod (nav + route + API) |
| **4** | **Surface the model download** — the entrypoint already logs pull progress at 10% milestones; expose it via a status endpoint and show a progress card ("Local AI is downloading, 43% — cloud chat works now"). | ✅ **Done** on branch — `var/ollama-download.json` + `GET /api/v1/config/local-ai/status` + `LocalAiDownloadCard` |
| **5** | **Lower the hardware bar** — ship a small-model tier (`llama3.2:3b` / `qwen3:4b`, ~2–4 GB) as the local default so chat works on an 8–16 GB laptop with no GPU, `gpt-oss:20b` as opt-in "quality"; **publish multi-arch (arm64) images**. | 🟡 **Partial** — arm64 base image work started in `synaplan-base-php` (`feat/multi-arch-base`: arch-aware protoc + multi-arch CI). Still open: publish index digest, bump `_docker/backend/Dockerfile` pin, small-model tier (see §8.2) |
| **6** | **Distribution channels** — "the masses" rarely `git clone`. One-click templates for Coolify, CapRover, Elestio, PikaPods, Railway; app-store listings for Umbrel and CasaOS. Mostly a compose/template file + metadata per channel; an all-in-one evaluation image would make several listings trivial. | ⬜ Open |
| **H** | **Hygiene** — auto-generate `APP_SECRET`/`TOKEN_SECRET` on first boot when still placeholders; reconcile the disk-size numbers; ~~make the install script POSIX/Windows-friendly or retire it~~ **install script retired** to `_devextras/_1st_install_linux.sh` (docs now point at compose + Admin → AI Providers). Remaining: secret autogen + disk-size reconciliation. | 🟡 **Partial** |

---

## 3. What shipped in this branch

**Branch:** `feat/provider-key-wizard` → **PR [#1392](https://github.com/metadist/synaplan/pull/1392)** ·
41 files, +2976 / −224 · all CI checks green.

| Commit | Purpose |
|--------|---------|
| `64785ea` | Main implementation (store, catalog, validator, defaults service, admin API, wizard UI, docs, install script) |
| `d98eb65` | `BCONFIG.BVALUE` → **LONGTEXT** (CI `doctrine:schema:validate` fix) |
| `c9be11a` | Restore unlimited execution time after `HiggsfieldProviderTest` (suite time-bomb) |
| `dfd109e` | Merge `main` |

### 3.1 Backend — the key lifecycle

New namespace `App\AI\Credential`:

| Class | Role |
|-------|------|
| `ProviderKeyStore` | Install-wide store for the 8 keyed cloud providers (`anthropic`, `openai`, `groq`, `google`, `mistral`, `trustedtokens`, `huggingface`, `xai`). Resolution, encryption, env import, rotation, masked status. |
| `ProviderKeyCatalog` | Static per-provider metadata: display name, env var, console URL, `freeTier`, `recommended`, and the live-validation probe (method/URL/headers with a `{key}` placeholder). Deliberately has **no** entry for providers without a platform key (Ollama, Piper, OpenAI-compatible endpoints). |
| `ProviderKeyValidator` | One cheap authenticated request (list models / whoami) per provider. `429` counts as valid (the key authenticated far enough to be rate-limited). |
| `ProviderDefaultsService` | Recommended capability → model bindings per provider, resolved through `ModelCatalog::findBidByKey()`. Writes global defaults (ownerId 0) + `ai.default_chat_provider`, then clears the `model_config` cache. |

**Storage.** One `BCONFIG` row per provider: `ownerId = 0`, group `provider_keys`,
setting = provider name, value = AES-256-CBC ciphertext (via `EncryptionService`,
keyed off `APP_SECRET`) of `{"key": "…", "origin": "env"|"ui"}` — the same at-rest
pattern already used by `OpenAiCompatibleEndpointRegistry` and the Higgsfield
credentials.

**Resolution order** (`ProviderKeyStore::getKey()`):

1. **A stored DB row wins.** A row saved through the UI (`origin: ui`)
   *permanently* beats the environment, so operators can delete the key from
   `.env` after the transfer.
2. **Env bootstrap ("transfer on first load").** No DB row + env var set ⇒ the env
   key is imported (`origin: env`) and used. An existing `origin: env` row whose
   env var **changed** to a different non-empty value is refreshed — key rotation
   through `.env` / orchestrator secrets keeps working.
3. Neither configured ⇒ `null`; the provider reports itself unavailable.

The import checks **presence, not live validity** on purpose: a network blip at
boot must never drop a valid key. Live validation happens only in the admin
endpoints.

**Hot reload without a restart.** All 8 providers were refactored to resolve the
key *per call* and lazily (re)build their SDK client when the key changes
(`resolveApiKey()` + `client()`), with the constructor `$apiKey` kept as an
explicit override for tests/custom wiring. Reads are memoized ~15 s per process,
so per-request calls don't hammer `BCONFIG` while long-lived processes (FrankenPHP
worker mode, the messenger worker) still pick up UI changes quickly.

**Admin API** — 5 routes, admin-only, masked keys only:

```
GET    /api/v1/admin/provider-keys                        # statuses + defaultChatProvider
PUT    /api/v1/admin/provider-keys/{provider}             # save (optional validate + apply defaults)
DELETE /api/v1/admin/provider-keys/{provider}
POST   /api/v1/admin/provider-keys/{provider}/test
POST   /api/v1/admin/provider-keys/{provider}/apply-defaults
```

**Also backend:**

- `ApplyProviderDefaultsCommand` — `php bin/console app:provider:apply-defaults <provider>`,
  the CLI twin of the wizard's one-click action (catalog-key based, no raw BIDs).
- `ConfigController::getRuntimeConfig()` gained `setup.chatReady` — true only when
  the provider serving the **global default chat model** is actually available.
  (Checking "any provider reachable" would false-positive on a bare Ollama.)
- `SystemConfigService` now reads/writes cloud provider keys **through the store**
  instead of `.env`, so the existing admin config screen and the new wizard share
  one source of truth. `XAI_API_KEY` was added to its schema.
- Migration `Version20260729120000` widens `BCONFIG.BVALUE` to `LONGTEXT`
  (see §5.1). Galera-safe by design: it never reads the injected `Schema` (the DBAL
  comparator throws `TableDoesNotExist` on the prod cluster) and checks
  `information_schema` through `$this->connection`, so it is idempotent and
  re-runnable on any schema shape.

### 3.2 Frontend

| File | Role |
|------|------|
| `views/ProviderSetupView.vue` | The wizard at `/admin/setup` — readiness header, provider cards, local-AI (Ollama) section |
| `components/admin/ProviderKeyCard.vue` | Per-provider card: status, masked key, console link, key input, **Test & Save**, **Use as default**, **Remove** |
| `components/setup/ProviderSetupBanner.vue` | Dismissable banner in chat while `chatReady === false` — admins get the wizard CTA, regular users an info message |
| `services/api/providerKeysApi.ts` | Typed client over generated Zod schemas |
| `stores/config.ts`, `views/ChatView.vue`, `router/index.ts`, `composables/useNavItems.ts` | `setup.chatReady` getter, banner mount, route, **Admin → AI Providers** nav entry |
| `i18n/{en,de,es,tr}.json` | `adminSetup` + `setupBanner` keys in all four locales |

### 3.3 Install script & docs

- Install script: the raw-SQL block was replaced by
  `php bin/console app:provider:apply-defaults groq`, then the script was **moved
  out of the repo root** to `_devextras/_1st_install_linux.sh` (legacy/optional).
  Supported install path is `docker compose up -d` + **Admin → AI Providers**.
- **This repo:** `README.md` (Quick Start, Install Options, provider table),
  `docs/INSTALLATION.md`, `docs/CONFIGURATION.md`, `backend/.env.example`.
- **`synaplan-docs`:** `docs/quickstart.md` (TL;DR path no longer edits `.env`;
  no more "restart the backend"), `docs/faq.md` (new leading "easiest way to add a
  key" entry, all restart instructions removed, troubleshooting row updated).
  While in there: **xAI/Grok was missing from the FAQ entirely** — added to the
  provider table, the `.env` section list, and its own entry.

---

## 4. Open-source hygiene — how keys stay out of the public repo

The explicit constraint was "we must never publish migrations with keys". The
design satisfies it structurally, not by convention:

1. **No secret ever enters a tracked file.** The migration is **structure-only**
   (one `ALTER TABLE`); no seeder, fixture or migration reads or writes a key.
   Keys enter the database **exclusively at runtime** — admin UI or the one-time
   env import — inside the operator's own install.
2. **Encrypted at rest** with the operator's own `APP_SECRET`. A dump of `BCONFIG`
   from one install is useless in another. A decrypt failure (rotated
   `APP_SECRET`) is logged and treated as *not configured* so the env fallback
   still applies rather than breaking requests.
3. **Never echoed back.** Every API response and log line carries a masked key
   (`gsk_••••••••••••SPaz`) or nothing at all.
4. **`clone` + `compose up` is the supported path.** No key is required to boot;
   the app tells the admin what to do next (banner → wizard) instead of failing
   silently.
5. **Env vars remain first-class** for scripted/orchestrated deploys (K8s,
   `synaplan-platform`), with documented precedence: env bootstraps and can
   rotate an `origin: env` row, but a UI-saved key wins permanently.

---

## 5. Bugs found while building this

All pre-existing except 5.3; each is worth remembering.

### 5.1 `BCONFIG.BVALUE` was too narrow for its own existing payloads

`VARCHAR(250)` could not hold AES-256-CBC ciphertext: a modern OpenAI project key
(~160 chars) encrypts to ~300, and an encrypted OpenAI-compatible **endpoint** JSON
payload already exceeded 250 for any realistic `base_url` + key. Under MariaDB's
default `STRICT_TRANS_TABLES` those writes fail with *Data too long* — so this was
a **latent bug affecting the existing endpoint registry**, not just the new keys.
Confirmed live afterwards: the imported OpenAI row is 280 chars of ciphertext.

**Follow-up trap:** the first version widened it to `TEXT`, which passed the local
gate but failed CI's `doctrine:schema:validate` — Doctrine maps `Types::TEXT` to
**LONGTEXT** on MariaDB, which is also the house convention for every other text
column. Fixed in `d98eb65`, and the guard is now `!= longtext` (idempotent from any
starting shape) rather than `== varchar`.

> **Lesson for the local gate:** `make test` does not run `doctrine:schema:validate`.
> Any migration touching a column type should be checked with
> `php bin/console doctrine:schema:validate` before pushing.

### 5.2 A catalog key that silently resolved to nothing

`ProviderDefaultsService` mapped HuggingFace to
`huggingface:moonshotai/Kimi-K2.6:deepinfra:chat`, but `ModelCatalog::modelKey()`
normalises colons **inside** a `providerId` to dashes, so the key matched zero
entries and would have thrown at apply time in an operator's install. Caught
immediately by the new test that locks every mapping — exactly the catalog-drift
protection it exists for. Correct key: `…Kimi-K2.6-deepinfra:chat`.

### 5.3 Two PHPStan errors unmasked by typing the client

Replacing the untyped `private $client` with `?OpenAI\Client` revealed that
`GroqProvider` indexed a sealed SDK usage array shape (`prompt_tokens_details`) and
`OpenAIProvider` read a nonexistent `->capabilities` property. Both now read the raw
payload via `toArray()` with `is_array()` narrowing — fixed properly rather than
suppressed.

### 5.4 An OpenAPI enum that generated invalid Zod

`enum: ['env', 'ui', null]` on a nullable property generated
`z.enum(['env','ui',null])`, which `vue-tsc` rejects. Correct form is
`enum: ['env','ui']` + `nullable: true`.

### 5.5 A suite-wide time bomb in `HiggsfieldProviderTest`

The full backend suite began dying with *Maximum execution time of 45 seconds
exceeded* — in a **different random test each run**. Root cause: the Higgsfield
render-poll loop calls `set_time_limit(45)` per iteration (correct and necessary
under FrankenPHP, where the request budget is wall-clock). Exercised from the CLI
runner, that call converts the phpunit process from *unlimited* to a 45 s CPU
budget; once the remaining ~2400 tests cumulatively cross it, whichever test is
running dies. It only surfaced now because the machine got slow enough to tip over.
Fixed by restoring `set_time_limit(0)` in that class's `tearDown()` (`c9be11a`).

---

## 6. Tests & verification

**New:** `backend/tests/AI/Credential/` — 25 tests / 157 assertions.

| Suite | Covers |
|-------|--------|
| `ProviderKeyStoreTest` | env import, encryption at rest, UI-key precedence, env rotation, survival of env removal, delete → re-import, memoization + invalidation, corrupt-ciphertext fallback, masking, catalog completeness |
| `ProviderDefaultsServiceTest` | **every** catalog key in `PROVIDER_DEFAULTS` resolves (drift guard), unknown provider rejected, global write + provider flag + cache clear, unrelated capabilities untouched |
| `ProviderKeyValidatorTest` | 200 / 401 / 429 / transport failure, key goes into the auth header and **never** into the URL, invalid input fails fast without HTTP |

**Gate:** `make lint` ✓ · `make -C backend phpstan` ✓ (0 errors) · 2887 backend
tests ✓ · 975 frontend tests ✓ · `vue-tsc` ✓ · full PR CI green.

**Live smoke test** on the dev stack: all 7 keys present in `backend/.env` were
imported into `BCONFIG` as ciphertext with `origin: env`, returned masked, xAI
correctly reported unconfigured, and an authenticated runtime config returned
`setup: {chatReady: true}`.

---

## 7. Next steps

**Tier 3 is done:** the default chat model is resolved automatically to the first
provider with a working key (Groq → OpenAI → … → Ollama-if-model-present), but
from `app:provider:apply-defaults --auto` at container start rather than from a
request — see §9.2. The Feature Status page is open to admins in production.
Note the `BCONFIG` rule that shaped this: seeder defaults are bootstrap-only, so
rolling out a changed default for existing installs needs a migration that UPDATEs
the rows.

**Then, in adoption order:** Tier 4 (download progress endpoint + card) → Tier 5
(small-model default + arm64 images) → Tier 6 (Coolify/CapRover/Elestio/PikaPods/
Railway templates, Umbrel/CasaOS listings) → remaining hygiene (`APP_SECRET`
autogeneration, disk-size reconciliation).

---

## 8. Tier 3/4 review findings (2026-07-30)

Audit of the Tier 3 + 4 implementation against this plan. Two bugs were found and
fixed on the branch; one gap is deliberately deferred to Tier 5.

### 8.1 Fixed: auto-apply trusted a bare Ollama (would have re-created the
### default-provider trap)

`autoApplyBestAvailable()` was fed `$provider->isAvailable()`, and
`OllamaProvider::isAvailable()` only proves **the server answers** — it does not
check that any model is pulled. Verified on the dev stack: `/api/tags` holds
**`bge-m3:latest` only**, because `docker-compose.yml` defaults
`ENABLE_LOCAL_GPT_OSS=false`, so the stock install has *no local chat model at
all*. A keyless fresh install would therefore have:

1. auto-bound global `CHAT` to `ollama:gpt-oss-120b` (never downloaded),
2. reported `setup.chatReady = true`, **hiding the setup banner**, and
3. failed on the first message — exactly the friction §1.2 #1 set out to kill,
   in a new disguise.

Fixes: new `OllamaProvider::hasModel()` (tag-aware, handles the implicit
`:latest`); `ConfigController` corrects the Ollama entry in the availability map
with it before both the auto-apply and the `chatReady` evaluation; the
`isDefaultChatModelReady()` fallback now consults that corrected map instead of
`getAvailableProviders('chat')`, so a bare Ollama can no longer report an install
as chat-ready. The plan's own wording — "Ollama-**if-model-present**" and
"checking 'any provider reachable' would false-positive on a bare Ollama" —
was the specification; the first implementation violated it.

### 8.2 Was deferred, now fixed in §9.1: Ollama defaults pointed at a model we never pull

`PROVIDER_DEFAULTS['ollama']` bound `gpt-oss:120b`, but the entrypoint pulls
`gpt-oss:20b` (and only when `ENABLE_LOCAL_GPT_OSS=true`). There was no catalog
entry for a small local model, so **local-only chat was unreachable by design** —
the guard in §8.1 made that honest (banner stays up, user is guided to a free
cloud key) instead of silently broken. Resolved in §9.1.

### 8.3 Fixed: phantom "downloading forever" card

A container killed mid-pull, or a later boot with `AUTO_DOWNLOAD_MODELS=false`,
left `status: downloading` on disk and the card would poll it forever. The
entrypoint now writes `idle` when it skips downloads, and
`LocalAiDownloadStatusService` treats an in-progress status older than 30 min as
idle (finished states are kept regardless of age).

Also fixed while verifying: the "model already available" short-circuit compared
`"name":"bge-m3"` against Ollama's `"name":"bge-m3:latest"`, so it never matched
and **every backend restart re-issued a pull** (cheap because cached, but it
flashed a download card on each restart).

### 8.4 Verified live (not just unit-tested)

- Entrypoint → file → API: restarting `backend` produced valid JSON and the
  `ready` transition; `GET /api/v1/config/local-ai/status` returned it.
- Access control: `/config/features` → 200 admin / **403 non-admin**;
  `/config/local-ai/status` → 200 authenticated / **401 anonymous**.
- `ToolsDropdown` no longer strands every tool in its "checking" state for
  non-admins (the admin-only early return skipped the loading-flag reset).
- Frontend Zod schema for the new endpoint is **generated** from the OpenAPI
  annotations (`GetApiConfigLocalAiDownloadStatusResponseSchema`), per house rule
  — the first version was hand-written.

**New tests:** `OllamaProviderHasModelTest` (4), `LocalAiDownloadStatusServiceTest`
(5, incl. staleness), `ProviderDefaultsServiceTest` auto-apply cases (4, incl.
"no provider available ⇒ write nothing"), `localAiDownloadCard.spec.ts` (5,
asserting the real shipped `en.json` copy).

**Still thin:** no functional test boots `ConfigController::getRuntimeConfig()`
with a keyless DB (the Ollama guard is covered at unit level only), and the
entrypoint shell logic itself has no automated coverage.

---

## 9. PR #1392 external review — resolutions (2026-07-30)

An external review of the branch raised three blockers, five security findings and
a set of majors. What changed:

### 9.1 Local Ollama chat is now actually reachable (blocker 1, closes §8.2)

`ModelCatalog` gained Ollama `gpt-oss:20b` entries (chat + mem, priced 0 and
`showWhenFree`), `PROVIDER_DEFAULTS['ollama']` points at them, and the entrypoint
default for `ENABLE_LOCAL_GPT_OSS` is now `false` — matching `docker-compose.yml`,
so a bare `docker run` no longer triggers a ~14 GB pull. Docs were corrected: the
standard install pulls the **embedding** model only (~9 GB total); local chat is an
explicit opt-in.

### 9.2 `GET /config/runtime` no longer writes (blocker 2)

Provider probing moved into `ChatReadinessService`, which is read-only and caches
its snapshot for 30 s in the same pool a defaults change clears (also answers the
"probe every provider on every poll" performance finding). The write path lives in
`repairDefaultsIfBroken()`, reached only by
`app:provider:apply-defaults --auto` — run once at container start after seeding —
or by an admin saving a key. Saving/deleting a key and applying defaults invalidate
the snapshot, so the banner reacts on the next page load instead of up to 30 s
later.

### 9.3 Placeholder and masked secrets are rejected again (blocker 3, security 6)

New `SecretValueGuard` recognizes template text (`your-api-key-here`, `changeme`,
…) and the masked display value. `ProviderKeyStore` refuses both on save and never
imports a placeholder from the environment, so an untouched `.env.example` reports
its providers as unconfigured instead of persisting the placeholder encrypted.
`SystemConfigService::setValue()` rejects the mask server-side, so an API client
echoing back `••••••••` can no longer destroy a working key. Env aliases
(`GEMINI_API_KEY`, `GOOGLE_API_KEY`) and `any_of` requirements are honoured again.

### 9.4 Install script hardened (security 7)

Key entry uses `read -rs` (no scrollback), `.env` is written through a temp file
with `printf '%s=%s'` instead of a `sed` replacement (a `/`, `&` or `\` in a key
can no longer corrupt or inject settings), the file ends up `chmod 600`, and
`--yes` plus `AI_PROVIDER` / `GROQ_API_KEY` make it usable non-interactively.
`docker compose exec -T` and retry-the-real-command loops replace the TTY
dependency and the `sleep 2` polling.

### 9.5 Frontend and contract

Undefined `--border-subtle` / `--bg-muted` replaced with real tokens; every raw
Tailwind status color replaced with `--status-*`; download polling stops on
`error`, `ready`, `idle` and on dismiss; `providerKeysApi.ts` uses the generated
schemas, which required completing the OpenAPI annotations (`DELETE` body,
400/403/404 content). Deleting a key while its env var is still set now says so
instead of claiming the provider was removed.

### 9.6 New tests

`SecretValueGuardTest`, `ApplyProviderDefaultsCommandTest`,
`LocalAiDownloadStatusServiceTest` (10, incl. truncated JSON and staleness),
`ConfigControllerSetupEndpointsTest` (401/403 gates for `/config/features` and
`/config/local-ai/status`), `ProviderKeyStoreTest` cases for placeholders, masks
and env aliases, plus `providerKeyCard.spec.ts` and `providerSetupBanner.spec.ts`.

### 9.7 Knowingly not changed

- **AES-256-CBC without AEAD and no re-encrypt command** (security 4). Swapping
  the cipher is a data-migration for every stored secret and belongs in its own
  change. Mitigated for now by documenting that `APP_SECRET` must be backed up and
  that rotating it means re-entering every key.
- **`deleteKey()` does not disable a provider** (security 5). A tombstone row is a
  schema/semantics decision; the UI now states the env fallback instead of
  implying the provider is off.
- **Provider classes without `declare(strict_types=1)` / `final`** and the
  `set_time_limit(0)` teardown in `HiggsfieldProviderTest` — pre-existing, and
  touching strict types on nine provider classes is a separate, riskier change.
