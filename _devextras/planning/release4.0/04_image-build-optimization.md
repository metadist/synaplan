# Feature 3 — Image & first-boot optimization ("fast first boot")

**Release:** 4.0 · **Priority:** P1 (developer-experience / OSS adoption) · **Status:** Planned (2026-06-23)
**Type:** Infra / DevEx (not user-facing) — spans `synaplan`, `synaplan-base-php`, and a new `synaplan-ollama` repo.

> Goal: a first-time user who clones `synaplan` and runs `docker compose up`
> reaches a usable UI in **a few minutes**, not 30+ — on Apple Silicon as well
> as x86. No multi-minute `composer install` / `npm ci` on first boot, no
> waiting on a `bge-m3` model download, and no QEMU-emulated backend on Macs.

---

## 1. Problem statement (observed)

A fresh install on an Apple Silicon Mac took **> 30 minutes** before the app was
usable. Tracing a clean `docker compose up` against the current config, the time
is spent in four independent places — and the largest is **not** an install
command at all:

| # | Time sink | Why it's slow | Where |
|---|---|---|---|
| 1 | **Backend runs under QEMU emulation on Apple Silicon** | The base image is **amd64-only**, so the whole PHP/FrankenPHP backend (and every `composer`/`bin/console` call) is emulated via Rosetta/QEMU — typically **3–6× slower** for everything below. This is why "the backend was slow *despite* a base image." | `synaplan-base-php` build is single-arch |
| 2 | **`composer install` at container startup** | The dev image deliberately does **not** bake `vendor/` (the `./backend` bind-mount would shadow it), so it installs at runtime — and under QEMU this is the single slowest step (its 300s timeout is disabled precisely because it used to exceed it). | `_devextras/backend/docker-entrypoint.d/10-composer-install.sh` |
| 3 | **`npm ci` at startup — twice — + widget build + schema gen** | `frontend` runs `npm ci` on boot; `frontend-widgets` runs a **second** `npm ci` plus a widget build; then Zod schemas are generated against the backend. | `_devextras/frontend/docker-entrypoint.d/10-npm-install.sh`, `docker-compose.yml` (`frontend-widgets` command) |
| 4 | **Ollama model downloads at runtime** | `bge-m3` (~1.5 GB, always needed for embeddings) and optionally `gpt-oss:20b` (~12 GB) are pulled by the backend entrypoint after boot. | `_docker/backend/docker-entrypoint.sh` (model-download block) |

Net effect on a Mac: a large emulation tax multiplied across two runtime
dependency installs, plus gigabytes of model download, serialized into the
"first boot" experience.

---

## 2. Current architecture (verified)

### Base image — single-arch, x86-hardcoded
- `synaplan-base-php/.github/workflows/build.yml` builds with **no `platforms:`**
  → single platform (the amd64 runner), and exports one image tar
  (`outputs: type=docker,dest=…`) shared between the build and push jobs. A
  `type=docker` tar **cannot** hold a multi-arch manifest list.
- `synaplan-base-php/Dockerfile` hardcodes an x86_64 asset:
  `protoc-${PROTOC_VERSION}-linux-x86_64.zip`. (whisper.cpp already builds with
  `GGML_NATIVE=OFF`, so it is arm64-safe; `dunglas/frankenphp:php8.5-bookworm`,
  `install-php-extensions`, and `composer` are all multi-arch upstream.)
- `synaplan/_docker/backend/Dockerfile` pins the base by **immutable single-arch
  digest** (`FROM ghcr.io/metadist/synaplan-base-php:1.1.0@sha256:…`). Even once
  the base is published multi-arch, this *old* digest stays amd64 — the pin
  **must** be updated to the new multi-arch **index** digest or Macs keep pulling
  amd64.

### Backend dev image — deps installed at runtime
- `docker-compose.yml` `backend` (and `worker`) build `target: dev` with
  `pull_policy: build`; the `dev` stage is intentionally source-independent
  (no app `COPY`), so it carries no `vendor/`.
- `./backend:/var/www/backend` bind-mount shadows the image FS; a
  `- /var/www/backend/var/cache` anonymous volume already demonstrates the
  "surface a path past the bind-mount" pattern.
- Runtime install guard only checks presence:
  `if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]`.

### Frontend — raw `node:22`, installs at runtime
- `frontend` and `frontend-widgets` services use the stock `node:22` image with a
  `- /app/node_modules` **anonymous volume** (so host `node_modules` doesn't leak
  in) — but they still `npm ci` into that volume on every fresh boot.
- `frontend-widgets` command: `sh -c "npm ci && npm run build:widget -- --watch"`
  (the second `npm ci`).
- There is **no** production/custom frontend image; the prod frontend is built in
  CI and `COPY`-ed into the backend `prod` stage.

### Ollama — runtime pull
- `docker-compose.yml` `ollama` service uses `ollama/ollama:latest` with a named
  volume `ollama_data:/root/.ollama`.
- The backend entrypoint pulls models in the background when
  `AUTO_DOWNLOAD_MODELS=true`: `bge-m3` always, `gpt-oss:20b` when
  `ENABLE_LOCAL_GPT_OSS=true` (already-present models are skipped via the
  `/api/tags` check).

### Production note (scoping)
- The published app image `ghcr.io/metadist/synaplan:latest` is built single-arch
  via a tar that CI hands off to the e2e job (`docker-build` → `docker load`).
  **Dev does not pull this image** — it builds the `dev` stage locally — so the
  *base* image's arch is what governs dev speed. Making the app image multi-arch
  is a separable, optional follow-up (it would complicate the e2e tar hand-off)
  and is **out of scope** for this feature's core win.

---

## 3. Target architecture

```
            ┌─────────────────────────── A: multi-arch base ───────────────────────────┐
            │ ghcr.io/metadist/synaplan-base-php  →  manifest list {amd64, arm64}        │
            │   Mac `docker compose up` builds the dev stage arm64-NATIVE (no QEMU)      │
            └───────────────────────────────────────────────────────────────────────────┘
                     │ FROM (multi-arch index digest)
                     ▼
   synaplan/_docker/backend/Dockerfile (dev stage)
     + COPY composer.{json,lock} + composer install  ── B ──▶ /var/www/backend/vendor (baked)
                     │                                            │ surfaced past bind-mount
                     │                                            ▼  via `- /var/www/backend/vendor` volume
   docker-compose.yml backend/worker ───────────────────────▶ first boot: NO composer install

   _docker/frontend/Dockerfile (NEW)
     FROM node:22 + COPY package*.json + npm ci ──── B ──────▶ /app/node_modules (baked)
                     │                                            │ seeds the existing
                     ▼                                            ▼ `- /app/node_modules` volume
   frontend + frontend-widgets ─────────────────────────────▶ first boot: NO npm ci (×2 → 0)

   ghcr.io/metadist/synaplan-ollama:latest (NEW repo) ── C ──▶ bge-m3 baked into /root/.ollama
                     │  weekly scheduled rebuild
                     ▼  seeds the empty `ollama_data` volume on a fresh install
   docker-compose.yml ollama ───────────────────────────────▶ first boot: bge-m3 already present
```

### Key decisions
1. **Fix the emulation tax first.** A multi-arch base image (A) is the highest-
   leverage change for Macs; everything else compounds on top of it.
2. **Bake deps, but keep a lockfile-hash self-heal.** Surface baked
   `vendor/` and `node_modules/` past the bind-mounts using the existing
   anonymous-volume seeding pattern, and downgrade the runtime install scripts to
   **hash-guarded** (`composer.lock` / `package-lock.json`) so changing a
   dependency still triggers exactly one reinstall — correctness preserved.
3. **Bake only `bge-m3` into the Ollama image** (locked decision). It is ~1.5 GB,
   always required for embeddings, and arch-independent. `gpt-oss:20b` (~12 GB)
   stays a runtime/opt-in pull — too large for a regularly-pushed image.
4. **New `synaplan-ollama` repo** (locked decision), mirroring `synaplan-base-php`
   build/CI conventions, with a **weekly** scheduled rebuild.
5. **Volume seeding, not entrypoint copying.** Named/anonymous volumes initialise
   from image content on **first** creation (empty volume only). Fresh installs
   (the target scenario) win automatically; pre-existing volumes are documented
   as "re-seed by removing the volume."
6. **Zero behaviour change for existing checkouts.** All guards fall back to the
   current "install if missing" behaviour, so a developer with a warm `vendor/`
   or an existing `ollama_data` volume sees no regression.

---

## 4. Workstreams

Each workstream is independently shippable and CI-green on its own. Recommended
order: **A → B → C → D** (A unblocks the biggest Mac win; B/C are parallelizable).

### A — Multi-arch base image (`linux/amd64` + `linux/arm64`)  · biggest Mac win

**Repo: `synaplan-base-php`**
- `Dockerfile`: make `protoc` arch-aware. Add `ARG TARGETARCH` and map to the
  protobuf asset name (`amd64 → x86_64`, `arm64 → aarch_64` — note protobuf's
  underscore spelling). Verify the whisper `COPY --from=whisper-builder` library
  paths are arch-neutral (they are: `build/.../libggml*.so`).
- `.github/workflows/build.yml`: rework for multi-arch.
  - Add `platforms: linux/amd64,linux/arm64` and QEMU setup
    (`docker/setup-qemu-action`).
  - A `type=docker` tar can't carry a manifest list, so **drop the tar
    build→artifact→load→push split** and build+push in one job with
    `push: ${{ github.event_name != 'pull_request' }}`. For PRs, keep
    `push: false` (build both arches to validate, output discarded) so PR CI
    still proves the arm64 build compiles.
  - Keep the existing `metadata-action` tag set and the `concurrency` group.
- Record the new **multi-arch index digest** from the push step.

**Repo: `synaplan`**
- `_docker/backend/Dockerfile`: bump the `FROM …@sha256:` pin to the **new
  multi-arch index digest** (this is mandatory — the old digest is amd64-only).
  Update the surrounding "to bump" comment.
- Validate: on a Mac, `docker compose build backend` resolves the arm64 base and
  `docker compose exec backend php -i | grep -i architecture` (or `uname -m`)
  reports `aarch64`; a smoke boot (migrations + `/api/health`) passes.

**Out of scope (note as optional follow-up):** making the published app image
`ghcr.io/metadist/synaplan:latest` multi-arch (coupled to the e2e tar hand-off in
`synaplan/.github/workflows/ci.yml`). Not needed for the dev win because dev
builds the `dev` stage locally.

**Risks:** arm64 build differences (protoc asset name, any transitive native
ext). Mitigation: PR builds both arches; smoke-boot the arm64 image before
bumping the pin.

### B — Bake `vendor/` and `node_modules/` into the dev images

**Backend (`synaplan`):**
- `_docker/backend/Dockerfile` `dev` stage: `COPY backend/composer.json
  backend/composer.lock ./` then
  `COMPOSER_PROCESS_TIMEOUT=0 composer install --no-interaction --prefer-dist
  --no-scripts` (dev deps **included** — dev/test need PHPUnit/fixtures). This
  makes the dev image rebuild only when `composer.lock` changes (still a cache
  hit for source-only edits). Keep `--no-scripts` (cache:clear needs runtime DB).
  Confirm `composer proto:generate` is still handled for dev (today only the
  `prod` stage runs it; dev relies on host-generated stubs) — either run it in
  the dev stage too or document the `make` step. **Open item — verify before
  shipping.**
- `docker-compose.yml`: add `- /var/www/backend/vendor` to **both** `backend` and
  `worker` `volumes:` (anonymous volume seeds from the image past the bind-mount,
  mirroring the existing `- /var/www/backend/var/cache`).
- `_devextras/backend/docker-entrypoint.d/10-composer-install.sh`: replace the
  "vendor missing?" check with a **lockfile hash guard** — store
  `sha256(composer.lock)` in `vendor/.composer.lock.sha`; reinstall only when the
  hash differs (or `vendor/autoload.php` is missing). Keeps changing deps correct
  while skipping the install on every normal boot.

**Frontend (`synaplan`):**
- New `_docker/frontend/Dockerfile`: `FROM node:22` (pinned digest, multi-arch
  upstream) · `WORKDIR /app` · `COPY frontend/package.json frontend/package-lock.json ./`
  · `RUN npm ci`. (Builds `node_modules` into the image, which seeds the existing
  `- /app/node_modules` anonymous volume.)
- `docker-compose.yml`: `frontend` and `frontend-widgets` switch from
  `image: node:22` to `build:` the new Dockerfile (or a shared `image:` tag built
  once). `frontend-widgets` command drops its `npm ci` → just
  `npm run build:widget -- --watch`.
- `_devextras/frontend/docker-entrypoint.d/10-npm-install.sh`: convert to the same
  `package-lock.json` hash guard (install only on change).
- Leave `20-generate-schemas.sh` as-is (it needs the live backend); optionally
  hash-guard it later.

**Net:** first boot runs **0** dependency installs unless a lockfile changed.

**Risks:** stale anonymous volume after a lockfile change on an *existing*
checkout → handled by the hash guard (it reinstalls into the volume). Document
`docker compose down -v` to force a clean re-seed.

### C — Custom Ollama image with `bge-m3` baked (new repo, weekly rebuild)

**New repo: `synaplan-ollama`** (mirror `synaplan-base-php` layout/CI):
- `Dockerfile`: `FROM ollama/ollama:<pinned>`; bake the model by starting the
  daemon at build time and pulling:
  ```dockerfile
  RUN ollama serve & pid=$!; \
      until ollama list >/dev/null 2>&1; do sleep 1; done; \
      ollama pull bge-m3; \
      kill "$pid"
  ```
  Models persist in `/root/.ollama/models` as an image layer.
- `.github/workflows/build.yml`: mirror the base-php workflow + add
  `schedule: cron` (weekly) and `workflow_dispatch`; push
  `ghcr.io/metadist/synaplan-ollama:latest` plus a dated tag. Build multi-arch
  (`linux/amd64,linux/arm64`) — the ollama binary differs per arch; the GGUF
  blob is identical, each platform re-pulls it at build.
- README/LICENSE/NOTICE mirroring base-php conventions.

**Repo: `synaplan`:**
- `docker-compose.yml` `ollama` service: `image: ghcr.io/metadist/synaplan-ollama:latest`
  (pin a digest). The existing `ollama_data:/root/.ollama` named volume seeds
  `bge-m3` from the image on a **fresh** install (empty volume only).
- Backend entrypoint needs **no change**: its `/api/tags` check already skips
  `bge-m3` when present; `gpt-oss:20b` still pulls at runtime when
  `ENABLE_LOCAL_GPT_OSS=true`. Optionally simplify the log copy.
- `docker-compose-minimal.yml`: same image swap (minimal only needs `bge-m3`, so
  it benefits most).

**Risks:** a pre-existing `ollama_data` volume won't re-seed (documented:
`docker volume rm synaplan_ollama_data`). Image size grows by ~1.5 GB — acceptable
and offset by removing the runtime download.

### D — Small wins (cheap, do alongside)

- **Default the quick path to Groq.** `_1st_install_linux.sh` already defaults to
  option 2 (Groq → only `bge-m3` needed, no 12 GB `gpt-oss:20b`). Make it the
  documented "fast first experience" path and confirm the script's macOS branch
  (`darwin` `sed`) is exercised; add a discoverable entry point for Mac users
  (cross-platform name or a short README one-liner).
- **Kill the duplicate `npm ci`** (covered by B: widgets reuse the seeded
  `node_modules`).
- **Expectation-setting docs.** Update the "~2 minutes" claims in `README.md` and
  `docs/INSTALLATION.md` to reflect the new fast path, and document
  `docker compose down -v` for a clean re-seed and the warm-vs-cold timings.
- **(Optional, note only)** publish the app image multi-arch so arm64 servers can
  `pull` instead of build — separate from the dev win; gated by the e2e tar
  coupling.

---

## 5. Repos & artifacts touched

| Repo | Change | Workstream |
|---|---|---|
| `synaplan-base-php` | `Dockerfile` arch-aware protoc; `build.yml` multi-arch build+push | A |
| `synaplan` | `_docker/backend/Dockerfile` bump base digest + bake vendor; `_docker/frontend/Dockerfile` (new) bake node_modules; `docker-compose.yml` + `docker-compose-minimal.yml` volumes/images; two dev `docker-entrypoint.d` hash guards; docs | A, B, C, D |
| `synaplan-ollama` (**new**) | `Dockerfile` (bge-m3 baked) + `build.yml` (weekly cron, multi-arch) + README/LICENSE/NOTICE | C |

All of the above are in the "Ask First" boundary (Docker/CI/build configs, new
repo) — this plan **is** that ask; implementation proceeds once approved.

---

## 6. Risks & mitigations (summary)

- **arm64 build regressions** → PR CI builds both arches; smoke-boot arm64 before
  bumping the base pin.
- **Stale baked deps after a lockfile change** → lockfile-hash guards reinstall
  exactly once; `down -v` documented for a clean re-seed.
- **Pre-existing named volumes don't re-seed** (Ollama, node_modules) → documented
  remove-volume steps; fresh installs (the target) are unaffected.
- **Image size growth** (baked vendor + node_modules + bge-m3) → acceptable
  trade for eliminating multi-minute first-boot work; base/app layers are cached.
- **CI push semantics change** (base-php loses the tar artifact split) → PRs still
  build (validate) both arches without pushing.

---

## 7. Definition of done

- On a clean Apple Silicon Mac, `git clone` → `docker compose up` reaches a usable
  UI **in a few minutes**, with the backend running **arm64-native** (no QEMU).
- First boot performs **no** `composer install` and **no** `npm ci` unless a
  lockfile changed (verified by logs: the hash guards report "already present").
- `bge-m3` is available **without** a runtime download on a fresh install
  (`ollama list` shows it immediately).
- `synaplan-base-php` publishes a multi-arch manifest list; `synaplan` pins its
  index digest. `synaplan-ollama` exists, builds weekly, and is referenced by both
  compose files.
- No regression for existing checkouts/warm volumes; standard gate stays green
  where code is touched
  (`make lint && make -C backend phpstan && make test && docker compose exec -T frontend npm run check:types`).

## 8. Decisions (resolved 2026-06-23)

1. ~~Bake `gpt-oss:20b` too?~~ → **No, `bge-m3` only** (always-needed, small,
   arch-independent; `gpt-oss:20b` stays runtime/opt-in).
2. ~~Where does the Ollama image live?~~ → **New `synaplan-ollama` repo**, mirroring
   `synaplan-base-php` build/CI, **weekly** scheduled rebuild.
3. ~~Scope of multi-arch?~~ → **Base image only** for the core win (dev builds the
   dev stage locally); app-image multi-arch is an optional follow-up.
4. Workstreams: **A + B + C + D all in scope** for 4.0.

## 9. Open items to verify during implementation

- Whether the `dev` stage must run `composer proto:generate` (gRPC stubs) once it
  bakes `vendor/`, or whether dev keeps relying on host-generated proto.
- Exact protobuf arm64 asset name for the pinned `PROTOC_VERSION` (`aarch_64`).
- Confirm `ollama/ollama` stores pulled models under `/root/.ollama` for the
  pinned tag (so the named-volume seeding path is correct).
