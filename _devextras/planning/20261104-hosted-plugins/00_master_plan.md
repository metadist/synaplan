# Hosted plugins — the ones we ship, test, and document

Row 43. An administrator who downloads Synaplan gets the plugins we
stand behind inside `plugins/`. They are covered by this repository's
CI, documented on their own, and written so a stranger can copy the
pattern. The same administrator can leave one out and still have a
working Synaplan.

This track is the homecoming. synaScriber already came home. The others
still live in their own repositories, and `.gitignore` still calls them
out as "developed externally, copied to server manually."

## 1. Findings (checked 2026-11-04)

The image copies `plugins/` at build time (`_docker/backend/Dockerfile`,
`COPY plugins/ /plugins/`). A directory that git ignores is on a
developer machine and on a server someone copied it to. It is not in
`ghcr.io/metadist/synaplan`, and CI never sees it.
`EgressInventoryTest` keeps a list of those directories and skips them
on purpose. The dev compose file bind-mounts `./plugins`, so a
workstation that has the ignored folders behaves as if they shipped.

| Plugin | Kind today | Code we would boot | What is wrong |
| ------ | ---------- | ------------------ | ------------- |
| **synaScriber** (Meeting notes) | In this repo: `plugins/synascriber` (0.4.1). External repo [metadist/synaScriber](https://github.com/metadist/synaScriber) is **archived** (pushed 2026-10-26). Its README already points at `plugins/synascriber`, `sidecars/synaplan-transcriber`, synaplan-charts, and synaplan-stt. | Already here. The archived tree is plugin 0.3.0 plus a `deploy/opendesk` installer the README says no longer works. | Do not delete the GitHub repo (§4). This plan corrects roadmap row 10. The manifest `homepage` still points at the archived repository (HP1). |
| **Synaform** | Public [metadist/Synaform](https://github.com/metadist/Synaform), last commit 2026-08-14, v4.4.3. Gitignored as `plugins/synaform/` (a local copy of `synaform-plugin/`, 9 files). Docs: [docs.synaplan.com/plugins](https://docs.synaplan.com/plugins). | `SynaformController.php` is 11,885 lines. `frontend/index.js` is 9,218. Locales are `frontend/i18n/{de,en,es,tr}.json` — no French. Tests under `tests/` are scripts, not the PHPUnit suite this repo runs. Manifest homepage is `metadist/synaplan-synaform`, which is not a repository. | Copying it in as-is would put an untested god class in the image and call it an example. |
| **Synaads** | Public [metadist/Synaads](https://github.com/metadist/Synaads), v1.0.8. Not present under `plugins/` on this workstation (the gitignore line is ready). | `SynaAdsController.php` 2,957 lines, `ContentGenerator.php` 1,180, `frontend/index.js` 2,148. No locale files. No tests. Manifest homepage is `metadist/synaplan-synaads`. `_devextras/test-synaads.sh` matches a credential-shaped token; that file must not be imported (§5, D4). | Same as Synaform, smaller. |
| **synafastbill** | Public [metadist/synafastbill](https://github.com/metadist/synafastbill), v0.1.0, last commit 2026-08-04. Its own `planning.md` says M0–M4 are done and M5 (a real FastBill account, release polish) is what is left for 1.0. | Plugin tree is already small (largest PHP file 277 lines) and has an offline PHPUnit suite **outside** the plugin directory. `CurlHttpClient` is a raw curl stack. Locales skip French. The committed default in this repo's egress gate ignores `plugins/synafastbill/`. | The pipeline works. It is not yet an example: curl, missing French, tests that CI cannot see, and no live confirmation protocol. |
| **Synamail** | Two products in [metadist/Synamail](https://github.com/metadist/Synamail). The Outlook add-in (Office.js, its own `make ci-local`, hosted at `https://addin.synaplan.com`). The server companion `synamail-plugin/` (476-line controller, 503-line page, locales without French, no plugin tests). | The companion is a normal Synaplan plugin: contact profiles. The add-in README already says it works without the plugin and then shows an install hint. Docs ([synamail](https://docs.synaplan.com/synamail)) tell people to install from AppSource; the repo still has an AppSource resubmission checklist, so the store listing is not something this plan may assume is one click. | The profile plugin belongs under `plugins/synamail`. The add-in does not: Outlook loads it from its own HTTPS origin. |
| **synaplan-desktop** | Public [metadist/synaplan-desktop](https://github.com/metadist/synaplan-desktop). Apps → Desktop already exists (`frontend/src/apps/catalog.ts`, id `desktop`). | Tauri, Rust, Vue. Not a `manifest.json` plugin. Docs say signed public installers are not out yet. GitHub release `v0.1.0` is a **draft** and already contains `Synaplan.Desktop_0.1.0_x64-setup.exe` and `Synaplan.Desktop_0.1.0_universal.dmg`. | A download link belongs on the Desktop card. The application stays in its repository. A draft release is not a public URL. |
| **Synasort** | Private [metadist/Synasort](https://github.com/metadist/Synasort). Docs call it experimental and consulting-only, with a public release hoped for by the end of 2026. Gitignored as `plugins/synasort/`, not on this workstation. | Two pieces. `sortx-plugin/` is a Synaplan plugin whose manifest id is `sortx` (v5.0.0), homepage `github.com/synaplan/synaplan-sortx` (that repository is not `metadist/Synasort`). The controller is 1,471 lines. The other piece is a Go worker plus a PHP web UI, its own compose file, basic auth, and a mounted document folder. PR #18 (`security/prepare-public-release`, merged 2026-07-28) removed committed credentials from the tip. The history of a private repo is still not safe to merge into this public one. | Security pass and a migration note before any file is copied (§6). The Go sorter does not become a PHP plugin. |
| **synaplan-ai-support-chat** | Public [metadist/synaplan-ai-support-chat](https://github.com/metadist/synaplan-ai-support-chat). Version 1.0.10. Last push 2025-11-19, branch `fix/wp1_0_10`. Requires PHP 7.3. Zero tests. | WordPress plugin. `class-synaplan-wp-api.php` calls `https://app.synaplan.com/api.php`. The wizard creates an account with an email and a password. This product's widget setup lives at `/api/v1/integrations/wordpress/*`. The hosted platform no longer issues free accounts (row 28). | A rewrite in its own repository. It must not be autoloaded from `plugins/`. |

Already in `plugins/` and not part of this homecoming: `hello_world`,
`serper_search`, `castingdata`. Row 24 holds them to the same gate.
`plugins/brogent/` stays gitignored; it was not in the list.

The kernel discovers `plugins/*/manifest.json`, autoloads `backend/`,
and skips a missing directory (`Kernel::getPlugins()`). Per-user install
is a symlink plus SQL (`PluginManager::installPlugin`). A missing name
in `DEFAULT_USER_PLUGINS` is skipped (`DefaultUserPluginProvisioner`);
the committed default of that variable is `[]`. openDesk sets
`["synascriber"]`. Deleting a folder inside a running container does
not survive the next image pull, because the image still contains the
copy.

## 2. Two homes

**In-process plugins** are a directory under `plugins/<id>/` with a
manifest. They ship in the image, run in this CI, and are documented as
part of Synaplan. An administrator who does not want one leaves it out
(§7).

| Id | Directory after this track | Source today |
| -- | -------------------------- | ------------ |
| `synascriber` | `plugins/synascriber` (done) | this repo |
| `synamail` | `plugins/synamail` | `Synamail/synamail-plugin` |
| `synafastbill` | `plugins/synafastbill` | `synafastbill/synafastbill-plugin` |
| `synaads` | `plugins/synaads` | `Synaads/synaads-plugin` |
| `synaform` | `plugins/synaform` | `Synaform/synaform-plugin` |
| `sortx` | `plugins/sortx`, display name Synasort, only after §6 | `Synasort/sortx-plugin` |

The directory name, the manifest `id`, and the argument of
`app:plugin:install` are the same string. Synasort's id stays `sortx`
until a migration says otherwise, because existing consulting installs
use that id.

**Companions** install somewhere else. Their source stays in the
repository that already has the right CI. Synaplan shows a link and a
short page, and a contract test notices when the link or the API the
companion calls has gone.

| Companion | Stays in | What Synaplan hosts |
| --------- | -------- | ------------------- |
| Outlook add-in | metadist/Synamail | The `synamail` plugin page always offers the install link. Target: the docs install section, replaced by the AppSource listing after a walk proves the listing installs. |
| Desktop | metadist/synaplan-desktop | Apps → Desktop offers the EXE and the DMG of the latest **published** release. |
| WordPress chat | metadist/synaplan-ai-support-chat | Apps → Widgets points at the plugin zip once the rewrite is the example. The zip is built by that repo's CI. |
| SortX sorter (Go + local web UI) | metadist/Synasort, private | Nothing in the public image until the owner publishes it. The Synaplan half is the `sortx` row above. |
| Meeting-notes transcriber, charts, speech server | already moved | Unchanged. Row 24 owns the transcriber image. |

A companion is not given a Synaplan `manifest.json` so that the kernel
will not try to boot Office.js, a WordPress plugin, or a Tauri app.

## 3. Why

1. The plugins we tell people about (the docs page leads with Synaads and Synaform) are not in the image those people download. The install instructions say clone a second repository and copy a folder.
2. CI cannot fail a pull request that breaks a gitignored plugin, and a gitignored plugin cannot fail CI.
3. synaScriber showed the other shape: the code that runs is in this repository, the sidecar image is published with the app, and the old repository is an archive with a map.
4. An example that is 12,000 lines in one controller teaches the wrong lesson. The bar in [`01_rules.md`](./01_rules.md) is the same bar this repository already uses for its own code.
5. A plugin that ships in the image must be removable. Otherwise "optional" is only a per-user uninstall, and the code is still on disk after the administrator said no.

## 4. synaScriber — keep the archived repository

Do not delete [metadist/synaScriber](https://github.com/metadist/synaScriber).

It is already archived. The README is the map: plugin, transcriber
sidecar, charts, speech server. Deleting the repository removes that
map, the history, the notice, and the iabg acknowledgement, and turns
every old link into a 404. Nothing in this repository's workflows or in
synaplan-charts still clones it. The archived plugin (0.3.0) and
`deploy/opendesk` are marked dead in that README; they are not a second
source of truth.

The live roadmap sentence names `plugins/synascriber` from this plan
onward. HP1 points the manifest `homepage` at the docs page. Frozen
plans under `2026-archive/` keep the old links.

## 5. Secrets

A file from another repository enters this one as a **snapshot of a
reviewed tree**, never as that repository's git history. History is how
a removed key comes back.

Checked 2026-11-04, current files only, values not recorded:

| Tree | Result |
| ---- | ------ |
| Synasort tip | PR #18 removed committed credentials and default logins. A fresh scan did not find a long token in the tip. The history was not scanned. That scan is part of the security pass, and any key that ever was committed is rotated before a snapshot is taken. |
| Synaads | `synaads-plugin/` itself did not match. `_devextras/test-synaads.sh` did. `_devextras/` is not imported. If that match is a live key, rotate it in the same week, in the Synaads repository. |
| synafastbill | The hits are the words "API key" in locale files. The repo already runs `scripts/check-secrets.php`. `FastBillCredentials` is a class, not a committed key. |
| Synaform, `synamail-plugin` | No committed key file in the plugin tree. |

Synasort's pass is a step of its own (§6) and blocks the snapshot.
The other imports still exclude tests that embed a personal key, `.env`,
and `_devextras/`.

## 6. Synasort — security pass, then a migration note

Done in the private repository. Nothing is copied here until the note
in STATUS says the snapshot is clean.

1. **History.** Run a secret scan over every commit, not only the tip. Rotate every credential the scan names, including keys that #18 already deleted from the tip. They were public to everyone who had the private repo, and they must not work anymore.
2. **Tip.** Confirm `.env` is untracked, no default password boots the UI, and basic auth is still required. The README already says there is no default password; the pass checks the code agrees.
3. **Boundary.** List what the Go worker sends to Synaplan (URL, user id, API key, document text) and what it stores in SQLite. The API key stays in the sorter's env, never in `sortx-plugin`.
4. **Migration note**, one page in the private repo: current id `sortx`; tables it creates; what a consulting install must do on the day the plugin is also in the Synaplan image (install from the image, retire the copied folder, keep the id). Renaming `sortx` → `synasort` is a later decision and is not part of the purge.
5. **Snapshot.** `sortx-plugin/` only, after it meets [`01_rules.md`](./01_rules.md). The Go module, compose file, and web UI stay in metadist/Synasort.

Publishing the private repository is a separate owner decision. This
track does not do it.

## 7. An administrator can leave a plugin out

Two gestures, both already half-true:

| How they run Synaplan | How they leave a plugin out | Survives the next start |
| --------------------- | --------------------------- | ----------------------- |
| Source checkout or their own image build | Delete `plugins/<id>/` before `docker build` or before `docker compose up` | Yes. The kernel never sees the manifest. |
| Pulled image `ghcr.io/metadist/synaplan` | `rm` inside the container | No. The next container is the image again. |

HP2 adds `PLUGINS_DISABLED`, a comma-separated list of ids (empty by
default). The kernel does not load those directories. The provisioner
already skips a plugin that is not available, so a deployment's
`DEFAULT_USER_PLUGINS` may still name it. Document both gestures in
`plugins/README.md` and on the docs site: deleting the directory is the
source-build gesture; `PLUGINS_DISABLED` is the gesture for a pulled
image.

Per-user uninstall (`app:plugin:install` in reverse, the existing
`uninstallPlugin`) stays. It removes one person's symlink. It does not
remove the code from the image.

A test boots with one bundled plugin hidden and asserts the application
is up, that plugin's routes are absent, and the other plugins still
answer. A second test sets `PLUGINS_DISABLED` to a plugin that is on
disk and asserts the same.

## 8. Decisions

Ticked by the owner before the step that depends on it. The
recommendation is the text of the row.

- [ ] **D1 (all).** Two homes, as in §2. In-process plugins live under `plugins/<id>/`. Companions stay in their repositories. Synaplan links to them and tests the link.
- [ ] **D2 (HP1).** metadist/synaScriber stays archived. It is not deleted.
- [ ] **D3 (every import).** Copy a reviewed snapshot. Do not import the other repository's git history, and do not add a submodule.
- [ ] **D4 (HP6, HP7).** Do not import `_devextras/`, `.env`, or a script that holds a personal key. Rotate the Synaads script match if it is live.
- [ ] **D5 (HP8).** Synasort enters this repository only after §6 is signed in STATUS. Only `sortx-plugin/`, id `sortx`. The Go sorter stays private.
- [ ] **D6 (HP4).** The Outlook add-in stays in metadist/Synamail. `plugins/synamail` is the profile API, and its page always shows the install link.
- [ ] **D7 (HP5).** Desktop stays in metadist/synaplan-desktop. The Apps card links to the EXE and the DMG of the latest published release. The draft `v0.1.0` is not that link.
- [ ] **D8 (HP9).** The WordPress plugin is rewritten in metadist/synaplan-ai-support-chat against `/api/v1` and the current widget. It does not create accounts. It is not placed under `plugins/`.
- [ ] **D9 (rules).** A hosted plugin that we call an example meets [`01_rules.md`](./01_rules.md), including five locale files and the size bar, before its "example" line in `plugins/README.md` is checked. The import pull request may land the code earlier only when STATUS calls it `present, not yet an example` and names the step that finishes the bar.
- [ ] **D10 (HP2).** `PLUGINS_DISABLED` as in §7. Unset means every directory on disk is offered. Deleting a directory remains valid for a source build.
- [ ] **D11 (HP3–HP7).** Shipping the code does not install it for every user. `DEFAULT_USER_PLUGINS` stays a deployment choice. The public default stays `[]`. openDesk's `synascriber` entry stays. Whether web.synaplan.com adds `synamail` is an owner choice in `synaplan-platform`, recorded in that repo, not smuggled into the public default.
- [ ] **D12 (HP3–HP7).** Row 24's static checks (PQ1) should be green before Synaform and Synaads merge, because those diffs are large. Synamail and synafastbill may merge earlier if the pull request itself is green under the checks that already exist, and the new PHP is formatted and at PHPStan level 5.
- [ ] **D13 (each import).** `synaplan-docs` `docs/plugins.md` (and the companion page, when there is one) changes in the same step as the install instructions. The public docs stop saying "clone a second repository and copy the folder" for a plugin that is now in the image.
- [ ] **D14.** `plugins/brogent/` and metadist/Synabrogent stay out of this track.

## 9. Target shape

```text
plugins/<id>/                      one directory, one manifest id
  manifest.json                    homepage points at the docs page
  README.md                        what it does, how to leave it out
  backend/                         PHPStan level 5, php-cs-fixer
  frontend/
    index.js                       ESLint + Prettier; t() once row 24's hook exists
    locales/{de,en,es,fr,tr}.json
  migrations/
backend/tests/Plugin/<Id>/        the suite CI runs

plugins/README.md                  hosted set, example checklist, how to disable
```

Companions stay where they are. The Apps directory and the plugin page
are the doors.

## 10. Journeys

| Id | Journey | Walked in | Step |
| -- | ------- | --------- | ---- |
| J-HP-1 | An administrator opens the Synamail plugin page. The page explains contact profiles and offers **Install in Outlook**, which opens the install instructions (or AppSource, once that walk has succeeded). They can delete a profile. A second administrator with the plugin disabled never sees the page. | the browser, dev stack | HP4 |
| J-HP-2 | A person opens Apps → Desktop and uses **Download for this computer**. On macOS the file is the DMG; on Windows it is the EXE. Both are the assets of the latest published release. Before that release exists, the card says the installers are not published yet and does not offer the draft. | the browser | HP5 |
| J-HP-3 | An operator of a source checkout deletes `plugins/synafastbill` and starts Synaplan. Chat works. `/fastbill` is absent. Putting the directory back and restarting brings the command back. | dev stack, then the CI test from HP2 | HP6, using the fixture from HP2 until synafastbill has landed |
| J-HP-4 | An operator of the pulled image sets `PLUGINS_DISABLED=synaform` and restarts. Synaform's routes are gone. The next container from the same image stays that way without anyone deleting a file inside it. | CI test; a compose snippet in the docs | HP2, re-walked when Synaform is in the image |
| J-HP-5 | A person who installed the WordPress plugin connects it to an existing Synaplan account and sees the widget on a post. The plugin does not ask them to invent a password for a new Synaplan account. | a local WordPress and the dev stack | HP9 |

J-HP-1, J-HP-2, and J-HP-5 carry the five exit bullets of
`docs/UX_CONTRACT.md` §6 in [`02_steps.md`](./02_steps.md). J-HP-3 and
J-HP-4 are operator journeys; their proof is the test plus a STATUS line.

## 11. What we do not rebuild

- The kernel's discovery (`/plugins`, then `backend/plugins`, `PLUGINS_DIR` already overrides the directory).
- Per-user symlinks and `plugin_data`.
- Row 24's i18n hook, PHPStan scope, and transcriber image jobs. This track consumes them (D12) and does not redesign them.
- Row 18 (out-of-process plugins). SortX's Go worker may become one later. It is not pulled forward.
- Row 38 (service desk: ratings, outcomes, Zendesk). The WordPress plugin is only the distributor of the widget that already exists.
- The Outlook add-in, the Desktop application, and the transcriber sidecar.
- `hello_world`, `serper_search`, `castingdata`, `brogent`.

## 12. Step table

One step is one pull request, except HP8 and HP9, which are sequences
named in [`02_steps.md`](./02_steps.md). Details, files, and exit
bullets are there. Rules are [`01_rules.md`](./01_rules.md).

| Step | Subject | Release class | Depends on | Plugin |
| ---- | ------- | ------------- | ---------- | ------ |
| HP0 | `docs(planning): host the first-party plugins in this repository` | no-app-impact | — | this plan |
| HP1 | `docs(plugins): synaScriber's homepage is this repository` | no-app-impact | D2 | synascriber |
| HP2 | `feat(plugins): an operator can disable a bundled plugin without rebuilding` | backend-only | D10 | the contract |
| HP3 | `docs(plugins): rules for a plugin we ship` | no-app-impact | D1, D9 | `plugins/README.md` points at the rules |
| HP4 | `feat(plugins): Synamail profiles, with a link to install Outlook` | backend-only (`plugins/**`) plus ota-candidate if the Apps card changes | D3, D6, D11, D13 | synamail |
| HP5 | `feat(apps): Desktop offers the published EXE and DMG` | ota-candidate | D7; a published release | desktop |
| HP6 | `feat(plugins): FastBill in the image, speaking through the shared HTTP client` | backend-only | D3, D4, D11, D12, D13 | synafastbill |
| HP7 | `feat(plugins): Synaads split into reviewable services and shipped` | backend-only | D3, D4, D9, D12, D13; PQ1 green | synaads |
| HP8 | Synaform, as a series of pull requests that split first and then delete the gitignore line | backend-only | D3, D9, D12, D13; HP7's split is the template | synaform |
| HP9 | WordPress chat rewrite in metadist/synaplan-ai-support-chat, then a docs link | that repo; docs here are no-app-impact | D8, D13; row 28's account rule | support chat |
| HP10 | `feat(plugins): SortX snapshot after the security pass` | backend-only | D5, §6 signed, D9 | sortx |

Order: HP0, then HP1–HP3 in any order, then HP4 (smallest real import
and the Outlook link), HP6, HP7, HP8. HP5 waits on a published Desktop
release and can otherwise start any time. HP9 starts when someone is
ready for a WordPress rewrite; it does not block the PHP plugins. HP10
is last and stays blocked on the private security note.

## 13. Other rows

| Row | Relationship |
| --- | ------------ |
| 24 Plugin platform quality | The gate (PHPStan, formatter, ESLint, locales, "tested like the release"). D12. |
| 18 Out-of-process plugins | Not this track. SortX's Go worker is a candidate later. |
| 10 Wave 6 integrations | Meeting notes already shipped here. HP1 corrects the live sentence that still points at the archived repository. |
| 28 Admission sign-up | The WordPress wizard must not create a free account. |
| 38 Service desk | Different product. Do not fold the WordPress rewrite into it. |
| 15 Config pyramid | `PLUGINS.ENABLED` there and `PLUGINS_DISABLED` here are the same idea from two ends. HP2 uses an env list so it does not wait for the pyramid. When row 15 lands, it should read this list rather than invent a second one. |
