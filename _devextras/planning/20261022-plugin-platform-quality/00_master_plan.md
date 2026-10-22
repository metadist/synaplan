# Plugin platform quality

Three findings from the review of the bundled synascriber plugin, turned
into one track with four pull requests. Each finding is a GitHub issue by
dschmidt; each pull request closes one of them.

| Issue | Finding | Step |
| ----- | ------- | ---- |
| [#2636](https://github.com/metadist/synaplan/issues/2636) | Code under `plugins/<id>/` runs in the Synaplan image and no static check in CI looks at it | PQ1 |
| [#2638](https://github.com/metadist/synaplan/issues/2638) | The transcriber image is published differently from the app image: rebuilt after the tests, QEMU for arm64, a permanent short-commit tag, no `<major>` alias, not part of the promote gate | PQ2 |
| [#2635](https://github.com/metadist/synaplan/issues/2635) | Plugins have no i18n hook; synascriber ships two hand-written copy tables | PQ3 |
| (owner, 2026-10-22) | A plugin that ships in the Synaplan release is tested like the release, and the docs say which CI runs prove it | PQ4 |

The fourth row is the owner's framing of the same review: a bundled plugin
is part of the product and is held to the product's gate. It closes no
issue of its own; it is the step that makes the first three visible to the
next contributor.

Context: [#2640](https://github.com/metadist/synaplan/issues/2640) (the
technical owner account) is handled in
[#2642](https://github.com/metadist/synaplan/pull/2642) and is not part of
this track. [synaplan-charts#88](https://github.com/metadist/synaplan-charts/pull/88)
is the deployment that consumes PQ2's image and PQ3's loader.

## What already exists

| Piece | Where | State today |
| ----- | ----- | ----------- |
| Plugin discovery | `backend/src/Kernel.php`, `backend/config/plugin-autoloader.php` | `/plugins` first, then `backend/../plugins`. Services autowired from `plugins/<id>/backend/`, excluding `{Entity,migrations,tests}`. |
| PHPStan | `backend/phpstan.neon` | `paths: src, tests, lib/grpc`, level 5, `phpstan-baseline.neon` (523 lines). Plugin tests under `backend/tests/Plugin/` are analysed; the plugin code they call is not. The backend CI job symlinks `plugins/` to `/plugins` before `composer phpstan`; the dev container mounts it there. |
| php-cs-fixer | `backend/.php-cs-fixer.dist.php` | `Finder::in(__DIR__)`: `backend/` only. One plugin file (`TranscriptRenderer.php`) is currently out of style. The CI lint job ("PHP Code Formatting") does **not** symlink `/plugins`. |
| ESLint, Prettier | `frontend/eslint.config.js`, `frontend/.prettierrc`, `npm run lint` / `format:check` | `src/**`, config files, tests. `plugins/*/frontend/*.js` (1,254 lines, four plugins) are never linted or formatted. The frontend container mounts `frontend/` only; `plugins/` is not reachable from it. |
| deptrac | `backend/deptrac.yaml` | `paths: ./src`, by design. Plugins stay outside the layer model (not changed by this track). |
| Plugin page host | `frontend/src/views/PluginView.vue` | Imports `<user assets>/index.js`, calls `mount(el, { userId, apiBaseUrl, pluginBaseUrl, config })`. No locale, no `t()`. Re-mount on locale change does not exist. |
| Plugin assets | `PluginAssetController`, `PluginAssetResponder` | `/api/v1/user/{id}/plugins/{name}/assets/{path}` and, since #2642, `/api/v1/plugins/{name}/assets/{path}` (public, GET and HEAD). Serves any file under `frontend/`, so `frontend/locales/*.json` needs no new route. |
| Synaplan i18n | `frontend/src/i18n/` | vue-i18n, eleven namespaces, five locales, lazy per route; `tests/unit/i18n/localeParity.spec.ts` gates key parity against a frozen ledger and bans jargon. |
| synascriber copy | `plugins/synascriber/frontend/index.js` (`COPY`, 5 × ~25 keys), `jitsi/loader.js` (`COPY`, 5 × ~45 keys) | Two inline tables; language from `localStorage.language` / `navigator.language` and from Jitsi's `APP.translation`. |
| Other plugin copy | `plugins/hello_world/frontend/index.js`, `plugins/castingdata/frontend/index.js` | English only, inline. `hello_world` uses `alert()`. |
| Plugin tests | `backend/tests/Plugin/SynaScriber/` (7 classes), `backend/tests/Integration/Plug/SerperSearchAdapterTest.php` | `plugins/README.md` item 6 says tests live under `plugins/<id>/backend/tests/`; only serper's fixtures do. castingdata and hello_world have no tests. `castingdata-plugin.spec.ts` exists as `@plugin` e2e, excluded from `make test-e2e`. |
| Manifest routes | `plugins/*/manifest.json` → `routes[]` | Documentation only; nothing compares them with the attribute routes. synascriber's were wrong until #2642. |
| App image jobs | `.github/workflows/ci.yml`: `docker-build` → `docker-push-amd64` → `docker-push-arm64` (native `ubuntu-26.04-arm`) → `docker-manifest`; `change-scope` decides `heavy` / `thin` / `promote` | The tested amd64 tar is the published image; arm64 is a native build; the manifest job verifies each tag resolves to exactly the two digests (`scripts/resolve-manifest-digest.sh`); a promote tag never rebuilds and `change-scope` only promotes when both `ci-<sha>-<arch>` tags exist. |
| Transcriber image jobs | `transcriber-sidecar` (build, `node --test` in the image), `transcriber-publish` | Publish builds **again** for `linux/amd64,linux/arm64` under QEMU; tags `latest`, `<version>`, `<major>.<minor>`, short sha; promote retags the short sha. Tested and published are not the same bytes; a failed transcriber publish does not stop a promote. |
| Base image | `synaplan-base-php/.github/workflows/build.yml` | Per-platform native builds pushed by digest, one `imagetools create` manifest job. The shape PQ2's arm64 and manifest jobs copy. |
| Release classes | `.github/mobile-impact-policy.json` | `plugins/**` → backend-only; `frontend/src/**`, `frontend/package.json`, `frontend/scripts/**` → ota-candidate; `.github/**`, `frontend/eslint.config.js`, `frontend/tests/**`, `_devextras/**` → no-app-impact. An unlisted new path fails closed to store-required. |

## 0. Decision checklist

Ticked by the owner before the step that depends on it starts. Each row
names the step.

- [ ] **D1 (PQ1).** Plugin tests live in `backend/tests/Plugin/<PluginId>/`, where PHPUnit, PHPStan, php-cs-fixer and the test kernel already reach them. `plugins/<id>/backend/tests/` keeps fixtures only. `plugins/README.md` item 6 is corrected. *Alternative:* a PHPUnit suite per plugin directory; rejected because it needs `phpunit.xml.dist` surgery per plugin and the Kernel already excludes `backend/tests` from service loading.
- [ ] **D2 (PQ1).** The frontend container gets `./plugins:/plugins:ro` in `docker-compose.yml`, so `make -C frontend lint` and `make -C frontend test` see plugin files the way the backend already does. Every tool resolves plugins as the autoloader does: `/plugins`, then `../plugins`.
- [ ] **D3 (PQ1).** PHPStan analyses `/plugins` at level 5 with `excludePaths` for `*/frontend`, `*/migrations` and test fixtures. Findings are **fixed in the plugin**, not baselined; a baseline entry is allowed only for a finding that needs a design change, with a one-line reason next to it. The first commit of PQ1 records the count before and after.
- [ ] **D4 (PQ1).** ESLint and Prettier run over `plugins/*/frontend/**/*.js` through a small Node script (`frontend/scripts/lint-plugins.mjs`) using ESLint's Node API with `cwd` = the plugins directory and a dedicated flat config `frontend/eslint.plugins.config.js`: `index.js` files as browser ES modules, `jitsi/loader.js` as a browser script with Jitsi's `APP` global. Prettier uses `frontend/.prettierrc`. Wired into `npm run lint` and `npm run format:check`, so no `ci.yml` change. *Fallback* if the API route fights the config base path: an `eslint.config.js` in `plugins/` that imports its presets from `../frontend/node_modules`.
- [ ] **D5 (PQ2).** The transcriber gets its own three jobs in `ci.yml`, duplicated from the app image's, not a reusable workflow: dschmidt's "duplicate first, merge once the transcriber path has run through a release". The short-commit tag goes; `<major>` is added; `change-scope` promotes only when the transcriber's `ci-<sha>-amd64` and `-arm64` exist as well.
- [ ] **D6 (PQ2).** The place for the transcriber image is `synaplan/.github/workflows/ci.yml`, next to the app image jobs: same Dockerfile repository, same version tag, same promote gate, same `All Checks Passed`. Not a repository of its own (synaplan-base-php has one because its cadence is independent) and not a separate workflow file (the gate and `change-scope` live in `ci.yml`).
- [ ] **D7 (PQ3).** The i18n contract is one file per locale, `plugins/<id>/frontend/locales/<lang>.json`, all five locales required, served through the existing asset routes. Keys may be nested. `PluginView.vue` fetches the active locale (and `en` as fallback), merges it into vue-i18n under `plugins.<id>.*`, and passes `t(key, params)` and `locale` in the mount context. A plugin's `t()` resolves the plugin's own keys only; Synaplan's host keys are not exposed (they move between namespaces; a plugin must not break when they do). *Alternative:* host keys first, plugin file as fallback — rejected for that reason.
- [ ] **D8 (PQ3).** On a locale switch the plugin page re-mounts (unload, load). Plugins are small vanilla DOM; a re-mount is simpler and safer than a per-plugin `onLocaleChange` callback and cannot leave stale text behind.
- [ ] **D9 (PQ3).** The Jitsi loader runs outside the SPA and keeps Jitsi's language choice. It fetches `locales/<lang>.json` from the bundled asset route (`en.json` as fallback) before it draws the button and keeps its tiny `{name}` interpolation. No inline table remains.
- [ ] **D10 (PQ3).** Parity is gated: `frontend/tests/unit/i18n/pluginLocaleParity.spec.ts` requires every `plugins/*/frontend/locales/` to have the five files with identical key sets and matching placeholder names, and applies the same banned-jargon list as the Synaplan gate. No ledger: the plugin files start complete.
- [ ] **D11 (PQ3).** `hello_world` becomes the reference plugin for the contract (locales in five languages, `t()` in use, `alert()` replaced by a rendered line). `castingdata` gets no copy change in this track; its English-only strings are listed as a follow-up in §9.
- [ ] **D12 (PQ4).** Two architecture tests guard the plugin set: every `plugins/*/manifest.json` loads through `PluginManifest::fromArray()` and its `routes[]` match the router's attribute routes for that plugin (path and method); every bundled plugin with a `backend/` has at least one test class under `backend/tests/Plugin/<Id>/`. castingdata and hello_world get the minimum test that makes the second assertion true (a controller smoke test each).
- [ ] **D13 (PQ4).** `plugins/README.md` gains a section "Checks and tests" that names, per check, the CI job that runs it ("PHP Code Formatting", "Backend (PHP/Symfony)", "Frontend (Vue/TypeScript)", "Transcriber Sidecar (Node)") and the local `make` target, and links one green run of each as the reference. `docs/DEVELOPMENT.md` → Code Quality states that plugins are inside the gate.
- [ ] **D14 (cross-repo).** After the first release that carries PQ2, synaplan-charts drops the short-commit pin (`images.synaplanTranscriber.tag: "92804be"`) and uses the chart's `appVersion` for both images, as #88 already prepares. That change is dschmidt's, in the #88 follow-up that also adopts #2642's contract.

## 1. Why — facts this plan rests on

1. The plugin code is in the release image. `plugins/` is copied into `ghcr.io/metadist/synaplan`; `DEFAULT_USER_PLUGINS` installs synascriber for every user on openDesk. Untested, unlinted code in the image is a product defect, not a plugin defect.
2. The transcriber image is what openDesk's Prosody and Jicofo talk to. Today the published bytes are not the tested bytes (`transcriber-publish` rebuilds), which is exactly the property the app image jobs were written to guarantee.
3. The synascriber copy tables already drifted from Synaplan's wording once (the `check_owner` label survived two renames) and cannot be reached by the parity gate. Two more plugins have English-only inline text.
4. The manifest route list was wrong for three routes until #2642 found it by accident. A test would have found it on the day it drifted.
5. The house rules already demand all of this for `backend/` and `frontend/`; the plugins directory was simply outside every `paths:` line.

## 2. Target shape

```text
plugins/<id>/
  manifest.json                 routes[] verified against the router (PQ4)
  backend/                      PHPStan level 5, php-cs-fixer (PQ1)
  frontend/
    index.js                    ESLint + Prettier (PQ1); mount(el, { t, locale, … }) (PQ3)
    locales/{de,en,es,fr,tr}.json   parity-gated (PQ3)
backend/tests/Plugin/<Id>/      PHPUnit, at least one class per plugin (PQ4)

ci.yml
  transcriber-sidecar           build amd64 tar, node --test in it, upload tar
  transcriber-push-amd64        push the tested tar as ci-<sha>-amd64 (promote: inspect)
  transcriber-push-arm64        native build on ubuntu-26.04-arm, push ci-<sha>-arm64
  transcriber-manifest          latest | <version>, <major>.<minor>, <major>; verify two digests
  change-scope                  promote needs app + transcriber ci-<sha> tags
```

## 3. Journeys

| Id | Journey | Walked in | Step |
| -- | ------- | --------- | ---- |
| J-PQ-1 | An administrator whose Synaplan is German opens **Apps → Meeting notes**. Every label is German. In Preferences they switch to French; the plugin page shows French without a reload. A key present in English but missing in French would show English, never a raw key (the parity gate makes that case impossible for bundled plugins). Switching back is the undo. | the browser, dev stack, `hello_world` and `synascriber` installed | PQ3 |
| J-PQ-2 | A participant in a French Jitsi sees the **Notes de réunion** button and the French dialog; the strings come from the plugin's `fr.json` through the bundled asset route. | synaplan-charts openDesk e2e (`notes.spec.ts`), with a language assertion added there; locally with a static page that includes the loader against the dev stack | PQ3 (cross-repo for the Jitsi half) |
| J-PQ-3 | A contributor opens a pull request that reads an undefined variable in a plugin's PHP (a level-0 PHPStan finding; an unused local would pass level 5) and leaves plugin JavaScript unformatted. "Backend (PHP/Symfony)" and "Frontend (Vue/TypeScript)" fail with file and line. After the fix the same jobs are green. | GitHub, a throwaway draft pull request opened and closed during PQ1 | PQ1 |
| J-PQ-4 | The owner cuts a release tag. `change-scope` says `promote`; no image is built; the transcriber's `<version>` tag resolves to exactly the two `ci-<sha>` digests of the main run; `docker buildx imagetools inspect` shows `linux/amd64` and `linux/arm64`. synaplan-charts pins that version for both images. | GitHub Actions on `main` and on the next tag; STATUS records both run ids | PQ2 |

J-PQ-1 is the only user-visible journey in the SPA and carries the five
exit bullets of `docs/UX_CONTRACT.md` §6 in its step row below. The other
three are operator and contributor journeys; their proof is a CI run.

## 4. What we do not rebuild

- The plugin discovery and autoload path (`/plugins`, then `../plugins`). Every tool follows it.
- The app image jobs. PQ2 copies their shape for the transcriber and does not touch them beyond `change-scope`.
- The Synaplan i18n loader. Plugin messages are merged into the existing vue-i18n instance under one namespace; no second instance, no new loader.
- The asset routes. Locale files are frontend files.
- deptrac for plugins (§8).

## 5. Step table

One step is one pull request on a branch of its own. The commit subject is
the step's first line. PQ1 and PQ2 do not share files and run in
parallel; PQ3 waits for PQ1 (its new JavaScript and JSON are linted from
the first commit); PQ4 waits for PQ3 (its docs describe the final state).

| Step | Commit subject | Release class | Depends on | Closes |
| ---- | -------------- | ------------- | ---------- | ------ |
| PQ0 | `docs(planning): plugin platform quality — static checks, transcriber image, i18n hook, tested plugins` | no-app-impact | — | — |
| PQ1 | `chore(plugins): PHPStan, php-cs-fixer, ESLint and Prettier cover plugins/` | ota-candidate (`frontend/package.json`, `frontend/scripts/**`) | D1–D4 | #2636 |
| PQ2 | `ci(transcriber): publish the transcriber image like the app image` | no-app-impact | D5, D6 | #2638 |
| PQ3 | `feat(plugins): locale files and t() for plugin frontends` | ota-candidate (`PluginView.vue`) | PQ1, **#2642 merged** (the public asset route the Jitsi loader fetches `locales/` from), D7–D11 | #2635 |
| PQ4 | `test(plugins): bundled plugins are tested and documented like the release` | backend-only + docs | PQ3, D12, D13 | — (owner's fourth row) |

### PQ1 — static checks (#2636)

Three commits in one pull request so the review sees logic apart from
reformatting:

1. `chore(plugins): …` — `phpstan.neon` gets `/plugins` with the excludes of D3; `.php-cs-fixer.dist.php` adds the plugins directory when it exists (`/plugins`, else `dirname(__DIR__).'/plugins'`), so the CI lint job (no symlink) and the dev container (no `../plugins`) both find it; `frontend/eslint.plugins.config.js` and `frontend/scripts/lint-plugins.mjs` as in D4; `npm run lint` and `format:check` call the script; `docker-compose.yml` mounts `./plugins:/plugins:ro` into `frontend` (D2); `.github/mobile-impact-policy.json` lists the new config file under no-app-impact; `plugins/README.md` item 6 corrected (D1) and a new item 8 names the checks.
2. `style(plugins): php-cs-fixer and prettier over plugins/` — the mechanical output of the two formatters, nothing else. `loader.js` (817 lines) will reformat; the commit is isolated so the diff of commit 1 and 3 stays readable. The service containers mount `plugins/` read-only (`backend` today, `frontend` after D2), so the formatters cannot write through them: commit 1 adds a `make plugins-format` target that runs php-cs-fixer and Prettier in one-off containers from the same images with a read-write `plugins/` mount (`docker compose run --rm --no-deps -v ./plugins:/plugins:rw …`), and the normal mounts stay read-only. The check targets (`make -C backend lint`, `make -C frontend lint`) only read.
3. `fix(plugins): PHPStan level 5 findings in the bundled plugins` — each finding fixed in place. The PR body lists the count before (from `composer phpstan` on commit 1) and after.

Exit: `make ci-local` green with plugins inside; J-PQ-3 walked with a draft PR that is red for the right reason and green after the fix (run ids in STATUS); `node scripts/mobile-impact.mjs --base main --head HEAD` classifies the PR as expected.

### PQ2 — transcriber image (#2638)

- `transcriber-sidecar`: gains `needs: [change-scope]` and `if: mode == 'heavy'`, like `docker-build`. Today it builds on every run, including a promote tag, which would contradict J-PQ-4; `thin` runs (catalog pins only) do not touch the sidecar either. In `heavy` mode it builds with buildx for `linux/amd64` to a tar (`type=docker,dest=`), loads it, runs `node --test` in it, uploads the tar as `transcriber-image` (retention 1 day). Every pull request that is not `thin` still runs it.
- `transcriber-push-amd64`: push on `main` and on tags; `always() && !cancelled()` with explicit result checks, as `docker-push-amd64` does, so a sidecar that was **skipped** is accepted only when `mode == 'promote'` and a sidecar that **failed** blocks in every mode. `heavy`: download the tar, tag `ci-<sha>-amd64`, push with the same retry loop the app job uses, output `ref` and `digest`. `promote`: inspect the existing tag.
- `transcriber-push-arm64`: `runs-on: ubuntu-26.04-arm`, build and push `ci-<sha>-arm64`, output `digest`; `promote` inspects.
- `transcriber-manifest`: `imagetools create` the two digests under `latest` (default branch) or `<version>`, `<major>.<minor>`, `<major>` (tags); verify with `scripts/resolve-manifest-digest.sh` (parameterised by image reference already). Gated like `docker-manifest`: never moves a tag when the sidecar tests failed.
- `change-scope`: the promote check requires the transcriber's two `ci-<sha>` tags next to the app's.
- `all-checks-passed`: the three new jobs join `needs`; its result check treats a skipped `transcriber-sidecar` the way it already treats a skipped `docker-build` (allowed in `thin` and `promote`, a failure never). The old `transcriber-publish` is removed with its short-sha tag.
- `sidecars/synaplan-transcriber/README.md` and `docs/OPENDESK_MEETING_NOTES.md` name the tags that exist.

Exit: workflow validated with `actionlint` (installed on the host for this step); the pull request's run shows the sidecar job uploading the tar; after merge, the main run publishes `ci-<sha>-amd64`, `ci-<sha>-arm64`, `latest`; the next release tag promotes without a build (J-PQ-4, both run ids in STATUS); `imagetools inspect <version>` lists exactly two platforms.

### PQ3 — i18n hook (#2635)

**User-flow:** J-PQ-1 (browser), J-PQ-2 (Jitsi, cross-repo). Exit bullets per `docs/UX_CONTRACT.md` §6:

1. J-PQ-1 walked in the browser and logged in STATUS with the locale pair used (U10).
2. Findability: not applicable, no artefact is produced; the plugin page is where it already is (U2 satisfied by the existing Apps entry).
3. Kind-specific copy in all five locales for both bundled plugins that have a page (`hello_world`, `synascriber`), gated by D10 (U3, U9).
4. Empty, error and flag-off states: a plugin without `locales/` renders its keys as before (no regression for `castingdata`); a failed locale fetch falls back to English and logs once; the page is absent when the plugin is not installed, as today (U5, U8, U11).
5. Dark, V2 and 320 px checked on the synascriber page after the change (U9); the copy change does not touch layout, the walk confirms it.

Commits:

1. `feat(plugins): …` — `PluginView.vue` loads `locales/<locale>.json` (+ `en.json`), merges under `plugins.<id>`, passes `t` and `locale`, re-mounts on locale change (D7, D8); `plugins/README.md` item 7 replaced by the contract; `pluginLocaleParity.spec.ts` (D10); a Vitest for `PluginView` with a stubbed import that asserts `t` resolves the plugin key, falls back to English, and the re-mount happens.
2. `refactor(synascriber): copy tables become locale files` — the two `COPY` maps move to `plugins/synascriber/frontend/locales/*.json` under `page.*` and `jitsi.*`; `index.js` uses `context.t`; `loader.js` fetches `jitsi.*` from the bundled asset route (D9).
3. `refactor(hello_world): the reference plugin uses the contract` — locales in five languages, `t()`, no `alert()` (D11).

Exit: `make ci-local` and `make test-e2e` green; J-PQ-1 walked; the Jitsi half of J-PQ-2 is handed to the charts follow-up with the loader URL and the `fr.json` path named in the PR body.

### PQ4 — tested and documented like the release

- `backend/tests/Plugin/PluginManifestRoutesTest.php` and `backend/tests/Plugin/BundledPluginsAreTestedTest.php` (D12); the smallest honest test for castingdata and hello_world (one controller or service each).
- `plugins/README.md` → "Checks and tests" (D13) with the job names, the `make` targets, the test location (D1), and links to one green run per job taken from PQ1–PQ3's STATUS entries.
- `docs/DEVELOPMENT.md` → Code Quality: one paragraph that plugins are inside `make ci-local`.
- `_devextras/planning/20260917-backend-integrations/README.md`: the meeting-notes line points at `plugins/synascriber` instead of the archived repository.

Exit: `make ci-local` green; a deliberately wrong manifest route in a scratch branch makes `PluginManifestRoutesTest` fail with the path named (recorded, then discarded).

## 6. Gates

Every step, in this order, before the pull request is marked ready:

1. `make ci-local` (unit and static). PQ3 adds `make test-e2e`; PQ2 adds `actionlint .github/workflows/ci.yml`; PQ1 and PQ3 add `node scripts/mobile-impact.mjs --base main --head HEAD`.
2. Branch `chore/…`, `ci/…`, `feat/…`, `test/…` per step; Conventional Commit subjects as in §5; no attribution trailers (strip `Co-authored-by: Cursor` if a hook adds it).
3. Pull request with the step's table row, the "Closes #…" line, the counts and run ids the step promises.
4. Copilot review: every finding gets a reply on its thread, either a fixing commit (hash named) or a one-sentence reason. A finding that is right about a property the plan did not foresee goes into §10 of this file in the same pull request.
5. **All Checks Passed** green on the final head; the Playwright matrix included for PQ3.
6. STATUS entry: date, step, pull request, state, Copilot rounds, the run id that is the evidence.
7. Merge by the owner or a reviewer; never by the author alone.

## 7. Risks

| Risk | Where it bites | Mitigation |
| ---- | -------------- | ---------- |
| ESLint's flat config refuses files outside its base path | PQ1 D4 | The Node API with `cwd` set to the plugins directory; fallback config in `plugins/` (D4). Decided in the first commit, not debated in review. |
| PHPStan's `/plugins` does not exist on a host run | PQ1 D3 | Tooling runs in the container or in CI by house rule; a host run fails loudly with the missing path, which is the honest outcome. Documented in `plugins/README.md`. |
| The `style:` commit of `loader.js` hides a logic change | PQ1 | Commit 2 is produced only by the two formatters, with no hand edits; the reviewer can regenerate it. |
| A promote tag arrives before the transcriber's main publish finished | PQ2 | `change-scope` requires all four `ci-<sha>` tags; otherwise the tag run falls back to `heavy` and rebuilds, as today for the app image. |
| A skipped sidecar is mistaken for a passed one | PQ2 | The skip is accepted only in `promote` mode, where the image under the tag was tested by the main run that published its `ci-<sha>` tags; in `heavy` mode a skipped or failed sidecar blocks the push. |
| `#2642` is not merged when PQ3 starts | PQ3 | PQ3 does not start before it; the Jitsi loader's locale fetch has no other route. Listed as a hard dependency in §5. |
| The formatters cannot write through the read-only plugin mounts | PQ1 | `make plugins-format` runs them in one-off containers with a read-write mount; the service mounts stay read-only. |
| The arm64 runner label stops resolving if the repository goes private | PQ2 | Same comment the app job carries; same remedy (a larger arm64 runner). |
| The Jitsi button appears later because it waits for `fr.json` | PQ3 D9 | One small same-origin fetch next to the `state` call the loader already makes; measured in the walk. The asset route caches for an hour. |
| A locale fix takes up to an hour to reach Jitsi | PQ3 | Accepted for v1; noted in README. A `?v=<plugin version>` query is in §9. |
| The parity spec reads plugin files through the filesystem, not Vite | PQ3 D10 | Node `fs` with the `/plugins`, `../plugins` resolver; in the frontend container the D2 mount makes `/plugins` exist. |
| `frontend/package.json` changes classify PQ1 as ota-candidate | PQ1 | True and harmless: no web code ships; the classification is correct by the policy's own rule. Said in the PR body. |

## 8. Non-goals

- A reusable workflow for both images (§9, after one release cycle, dschmidt's condition).
- deptrac rules for plugins.
- Translating `castingdata` (§9).
- Any change to #2642's contract or to synaplan-charts (that is #88's follow-up).
- Out-of-process plugins (row 18 of the roadmap).

## 9. Later (in this order)

1. Reusable image workflow once PQ2 has run through one release.
2. `castingdata` copy to locale files; a Playwright `@ci` spec that opens `hello_world` in two locales.
3. A `?v=<plugin version>` query on plugin asset URLs so a locale fix is seen at once.
4. A deptrac layer for `Plugin\*`: may import `App\Service\*` and `App\Repository\*`, never `App\Controller\*` or `App\Entity\*` directly.

## 10. Change log

| Date | Change |
| ---- | ------ |
| 2026-10-22 | Plan written from issues #2635, #2636, #2638 and the owner's fourth requirement. |
| 2026-10-22 | Copilot review of [#2644](https://github.com/metadist/synaplan/pull/2644), four findings accepted: J-PQ-3 uses an undefined-variable read (level 5 does not flag an unused local); #2642 is a hard prerequisite of PQ3; `make plugins-format` with a read-write one-off mount because the service mounts of `plugins/` are read-only; `transcriber-sidecar` is skipped in `promote` and `thin` mode and the push and gate jobs accept that skip only for `promote`. |
