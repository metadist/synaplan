# Steps

Plan: [`00_master_plan.md`](./00_master_plan.md).
Rules: [`01_rules.md`](./01_rules.md).
Log: [`STATUS.md`](./STATUS.md).

One step is one pull request on a branch off current `main`, unless the
step says it is a series. The commit subject is the step's first line.
A walk is a dated STATUS entry.

## HP0 — this plan

`docs(planning): host the first-party plugins in this repository`

Release class: no-app-impact (`_devextras/**`).

Adds this folder, registers row 43, and corrects the live pointers that
still say the meeting-notes plugin lives in metadist/synaScriber.

Exit: the folder is on `main`, row 43 is in `ROADMAP.md`, §8 is unticked.

## HP1 — synaScriber's address

`docs(plugins): synaScriber's homepage is this repository`

Depends on D2. Release class: no-app-impact.

- `plugins/synascriber/manifest.json` `homepage` becomes the docs page
  for meeting notes (or the in-tree README on GitHub until that docs
  page exists). It stops advertising the archived repository as the
  place to get the code.
- `plugins/synascriber/README.md` says the archived repository is a map,
  not a second copy.
- Roadmap row 10 and `_devextras/planning/README.md` name
  `plugins/synascriber`. HP0 may already have done the roadmap lines;
  this step checks they match the manifest.
- Do not delete the GitHub repository. Do not edit files under
  `2026-archive/`.

Exit: a search of the live planning directory and of `plugins/` finds
the archived URL only where a sentence says it is archived.

## HP2 — leaving a plugin out

`feat(plugins): an operator can disable a bundled plugin without rebuilding`

Depends on D10. Release class: backend-only.

**Operator journey:** J-HP-3 and J-HP-4.

- One filter for `PLUGINS_DISABLED` (comma-separated, empty means every
  directory). Invalid tokens are logged and ignored, the same way the
  provisioner already ignores an invalid default name. The filter is
  applied in all of these, not only at discovery:
  - `Kernel::getPlugins()` — no services, no routes
  - `PluginManager::listAvailablePlugins()` — not offered
  - `PluginManager::installPlugin()` — a direct `app:plugin:install` refuses
  - `PluginManager::listInstalledPlugins()` — omitted even if the symlink remains
  - `PluginAssetController` — both `/api/v1/plugins/<id>/assets/…` and
    `/api/v1/user/<userId>/plugins/<id>/assets/…` answer 404
- The provisioner already skips a name that is not available, so a new
  user is still created when `DEFAULT_USER_PLUGINS` names a disabled id.
- Document both gestures in `plugins/README.md`:
  - source build: delete `plugins/<id>/` and start again
  - pulled image: set `PLUGINS_DISABLED=<id>` in the environment and
    start again
- Say, in the same section, that deleting the directory inside a
  running container is undone by the next image pull.
- Tests:
  - boot with a fixture plugin directory hidden: the app is up, that
    plugin's route is absent, another fixture plugin's route is present
  - install a fixture plugin, then name it in `PLUGINS_DISABLED`: its
    routes are absent, both asset routes 404, `installPlugin` refuses,
    and `listInstalledPlugins` omits it while another plugin still answers
  - `DEFAULT_USER_PLUGINS` naming the hidden plugin does not fail user
    creation
- `.env.example` lists `PLUGINS_DISABLED=` with a one-line comment.
  Docker and CI files stay as they are unless a test harness needs the
  variable passed through; that change is called out in the pull request
  because compose edits are an owner decision (D10 already covers the
  variable).

Exit: the three tests are green in `make -C backend test`. STATUS records
the command. J-HP-4's compose snippet is in `plugins/README.md`.

## HP3 — point the developer docs at the rules

`docs(plugins): rules for a plugin we ship`

Depends on D1 and D9. Release class: no-app-impact.

`plugins/README.md` gains a section "Plugins we ship" that links this
folder, lists the hosted set (synascriber first; the others as they
land), and repeats the disable gestures from HP2 without inventing a
second mechanism. The example checklist is a link to
[`01_rules.md`](./01_rules.md) §3 and §7, not a third copy of the rules.

Exit: a new contributor can answer "where does a Synaplan plugin live,
how is it tested, how does an administrator remove it" from
`plugins/README.md` alone.

## HP4 — Synamail

Worked on since 2026-11-05. The owner moved this step ahead of HP1–HP3.

`feat(plugins): Synamail profiles, with a link to install Outlook`

Depends on D3, D6, D11, D13. Release class: backend-only for
`plugins/**`. An Apps-card change is ota-candidate and is included only
if the existing Outlook card does not already reach this page.

**User-flow:** J-HP-1. Exit bullets (`docs/UX_CONTRACT.md` §6):

1. Walked in the browser on the dev stack, signed in with a seeded
   account, and written in STATUS with the URL the button opened (U10).
2. The person finds it from Plugins, and from Apps → Outlook if that
   card is the door we already ship. The button is **Install in Outlook**.
3. Page copy in `de`, `en`, `es`, `fr`, `tr`. The incoming tree has no
   French file; this step adds it. The profile list, the empty list,
   and the delete confirmation are the strings (U3, U9).
4. Empty: no profiles yet, one sentence and the Outlook button. Error:
   the profile service is off, one sentence, no stack trace. Disabled:
   `PLUGINS_DISABLED=synamail` makes `/plugins/synamail` unknown (U5, U8, U11).
5. Light, dark, and 320 px on the profile list. The page does not invent
   a new button style.

Work:

- Snapshot `synamail-plugin/` from metadist/Synamail at a SHA recorded
  in the pull request. Leave the Outlook add-in, `manifest.xml`, and
  `publish/` in that repository.
- Move `frontend/i18n/` to `frontend/locales/` and add `fr.json`.
- Add `backend/tests/Plugin/Synamail/` for the profile merge: a stored
  profile, an update, a delete. No network. The model call is a fake.
- The page's install control opens `https://docs.synaplan.com/synamail`
  at the install section. If a walk in that step proves an AppSource
  listing installs into Outlook, the control's URL becomes that listing
  and STATUS records the listing URL. Until that walk, the label stays
  honest ("How to install in Outlook") rather than "Install from
  Microsoft".
- Remove `plugins/synamail/` from `.gitignore` and from
  `IGNORED_PLUGINS`.
- `synaplan-docs`: the Synamail page links back to the plugin in the
  Synaplan image; `docs/plugins.md` gains the row.
- Do not set `DEFAULT_USER_PLUGINS` in this repository (D11).

Exit: `make ci-local` and `make test-e2e` green. J-HP-1 walked. The
Outlook add-in's repository stays open. Its README, in its own pull
request, replaces the "copy `synamail-plugin/`" instructions with a
pointer at `plugins/synamail`. The add-in, its CI, and
`addin.synaplan.com` stay.

## HP5 — Desktop download

`feat(apps): Desktop offers the published EXE and DMG`

Depends on D7 and on a **published** GitHub release of
metadist/synaplan-desktop whose assets include the Windows EXE and the
macOS DMG. Release class: ota-candidate (`frontend/src/**`).

**User-flow:** J-HP-2. Exit bullets:

1. Walked in the browser. STATUS names the release tag and the two
   filenames that downloaded (U10).
2. Apps → Desktop. The control sits with the pairing instructions that
   are already on that card (U2).
3. The button and the "not published yet" sentence exist in all five
   locales (U3, U9).
4. Before a release exists, the card shows the not-published sentence
   and no file link. A failed asset (HTTP not 200) shows the same
   sentence, not a broken download (U5, U8). The desktop feature flag
   off still hides the card (U11).
5. Light, dark, 320 px. The two buttons wrap; they do not widen the card.

Work:

- The card reads the latest published release from a small documented
  URL (the GitHub releases page, or the asset URLs of a tag the docs
  name). It does not call the GitHub API from the browser with a token.
- Prefer stable asset names on the release (`Synaplan-Desktop-Setup.exe`,
  `Synaplan-Desktop.dmg`) so the docs URL does not change every version.
  Renaming the draft's current filenames is a change in
  synaplan-desktop, done in that repository before this step's walk.
- Do not link `v0.1.0` while it is a draft.
- `synaplan-docs` `docs/desktop.md` replaces "signed public installers
  are not out yet" in the same step the buttons start working.

Exit: J-HP-2 walked on macOS and Windows asset URLs (the walk can
download on one OS and `curl -I` the other). `make test-e2e` covers the
not-published state with a stubbed release.

## HP6 — synafastbill

`feat(plugins): FastBill in the image, speaking through the shared HTTP client`

Depends on D3, D4, D11, D12, D13. Release class: backend-only.

The behaviour stays the one in synafastbill `planning.md`: `/fastbill`
drafts an invoice, registers a payment, lists overdue invoices, and
asks before every write. M5 (a protocol against a real FastBill
account) is part of this step's walk, not a reason to wait for another
track. "More love" means the list below, not a new product.

- Snapshot `synafastbill-plugin/` plus the offline tests. Re-home the
  tests to `backend/tests/Plugin/Synafastbill/`. Record the SHA.
- Replace `CurlHttpClient` with the shared HTTP client. The egress
  inventory gains nothing, or gains a site that uses the shared client
  and is therefore not a forbidden stack. Confirm with the test, do not
  add a baseline row.
- Add `fr.json`. Move locales to `frontend/locales/`.
- Keep confirm-before-write. A test shows a write is not sent when the
  user has not confirmed.
- README in the plugin directory, gitignore and `IGNORED_PLUGINS`
  updated, docs page updated. The docs stop saying the plugin is a
  separate clone.
- Walk J-HP-3 on this plugin once the directory exists: delete it,
  start, `/fastbill` is absent, restore, it answers `help`.
- A live FastBill walk uses a test account the owner provides. No key
  in the pull request, the log, or STATUS. STATUS says "live walk done"
  and the date, or "live walk not done" and why.

Exit: `make ci-local` green. Offline suite green. J-HP-3 walked.
Example line checked only if the size bar and five locales hold, which
they should after the curl replacement and `fr.json`. One release
later, archive metadist/synafastbill (the repository is only this
plugin): README becomes a map, archive flag on, repository kept.

## HP7 — Synaads

`feat(plugins): Synaads split into reviewable services and shipped`

Depends on D3, D4, D9, D12, D13, and row 24 PQ1 green. Release class:
backend-only. This is a series of two pull requests so the split can be
reviewed apart from the move:

1. In a branch that already contains the snapshot (not merged): split
   `SynaAdsController.php` (2,957 lines) and `ContentGenerator.php`
   (1,180) until every file is under 800 lines and every method under
   100. Split `frontend/index.js` (2,148) into one file per screen,
   loaded by `index.js`. Add `frontend/locales/{de,en,es,fr,tr}.json`
   and move the English strings into them. Add
   `backend/tests/Plugin/Synaads/` for: create a campaign from a brief
   with a fake model, and refuse a public landing page that fails the
   compliance check. No network.
2. The merge pull request is that tree, minus `_devextras/`. The PR body
   records the source SHA and states whether
   `_devextras/test-synaads.sh` held a live key and whether it was
   rotated. The key value is never pasted.

Do not import the manifest homepage `metadist/synaplan-synaads`. Point
`homepage` at the docs page.

Exit of pull request 2: example checklist in [`01_rules.md`](./01_rules.md)
§7 all ticked, `make ci-local` green, docs updated, gitignore line gone.
A browser walk creates a campaign against a fake or a dev model and
downloads the ZIP. STATUS records it. One release later, archive
metadist/Synaads the same way (the repository is only this plugin).

## HP8 — Synaform

Series. Depends on D3, D9, D12, D13. HP7 has merged, so the split has a
template. Release class: backend-only each.

Synaform is the long step. `SynaformController.php` is 11,885 lines and
`frontend/index.js` is 9,218. Landing either file on `main` as an
example is a failure of this track. The series:

| Pull request | What | Done when |
| ------------ | ---- | --------- |
| HP8a | Snapshot onto a branch. No merge. Identify the controller's responsibilities (forms, datasets, templates, extraction, generation) and name the service classes. | A short note in STATUS, the SHA, the class list. |
| HP8b–e | One pull request per responsibility, each leaving the controller thinner and adding a PHPUnit class for that responsibility. The page is split the same way, one screen per file. | Each PR under the size bar for the files it created. `make ci-local` green. |
| HP8f | Locale move to `frontend/locales/`, add `fr.json`, delete the `.gitignore` line and the egress exception, docs page rewritten so nobody clones metadist/Synaform to install it. | Example checklist ticked. |
| HP8g | Archive metadist/Synaform the way synaScriber was archived: README becomes a map, archive flag on, repository kept. | STATUS links the archive. |

The browser walk, once HP8f has merged: create a collection, upload a
small docx fixture, extract with a dev model, download the filled
document, delete the collection by typing its name. Five exit bullets
in that pull request's body, journey id J-HP-6, logged in STATUS.
Dark, light, and 320 px on the collection list.

`minSynaplanVersion` is updated to a version that contains the plugin
APIs the code actually calls, not `1.0.0`.

## HP9 — WordPress chat

Done in metadist/synaplan-ai-support-chat, then a docs pull request
here. Depends on D8 and D13. Does not wait for HP4–HP8, and does not
start by copying 1.0.10 into `plugins/`.

The rewrite, as its own steps in that repository (names there, tracked
from this STATUS):

1. **Stop calling the old host.** Remove `https://app.synaplan.com/api.php`.
   Talk to the widget endpoints this platform documents
   (`/api/v1/integrations/wordpress/*` and the public widget script).
   The base URL is a setting, default `https://web.synaplan.com`.
2. **Stop creating accounts.** The wizard asks for an existing account's
   connection (the flow the current integration controller already
   implements for a signed-in user) or for an API key the person created
   in Synaplan. It does not collect a new password and it does not call
   a register endpoint. This is the row 28 rule.
3. **PHP and WordPress versions** that match what we are willing to
   support (decide in that repo's PR; PHP 7.3 is not it).
4. **Tests.** A PHPUnit suite with WordPress stubbed, covering the
   wizard's happy path and the "refuses to register" path. A smoke
   install is the walk J-HP-5.
5. **Package.** Tag a release and attach the zip. No secrets in the zip.

Then, in this repository and in synaplan-docs: Apps → Widgets (or the
plugins page) links to that release zip and to the install section.
The link text is **WordPress**. The five exit bullets for J-HP-5 live
in the docs pull request: walked on a local WordPress, found from
Widgets, copy on the Synaplan side in five locales, the plugin's own
error when the key is rejected is one sentence, dark and 320 px on the
Synaplan card. The WordPress admin screens stay in that plugin's
languages.

Exit: J-HP-5 in STATUS. The old `api.php` string is gone from the
released tag. `plugins/` has no WordPress package.

## HP10 — SortX

`feat(plugins): SortX snapshot after the security pass`

Depends on D5. Blocked until STATUS contains the security note from
[`00_master_plan.md`](./00_master_plan.md) §6, signed with a date.
Release class: backend-only.

- Copy only `sortx-plugin/`, to `plugins/sortx/`, id `sortx`, display
  name Synasort. Do not rename the id in this step.
- The snapshot has already met the example bar, including the 1,471-line
  controller split, five locales, and tests with a fake model. If it
  has not, this step is a no and the work continues in the private
  repository.
- Homepage becomes the docs page. The Go worker is described there as
  a separate program that talks to this plugin, still private, not
  shipped in the image.
- gitignore: the line is `plugins/synasort/` today and the directory
  will be `plugins/sortx/`. Remove the unused ignore line only when no
  local checkout still needs it; if a workstation keeps a private copy
  under the old name, leave the ignore line and say so in STATUS.
- Update `IGNORED_PLUGINS` in the same commit as the directory that
  actually contains PHP.
- Do not publish metadist/Synasort, and do not archive it. The Go
  sorter is still the product in that repository. Its README points at
  `plugins/sortx` for the Synaplan half.

Exit: example checklist ticked, `make ci-local` green, docs say
"experimental, in the image, the sorter itself is still consulting-only."
A browser walk on the dev stack opens the plugin page and runs a
classification against a fixture document and a fake model.

## After the last step

When HP10 has merged or the owner has recorded that SortX stays
consulting-only, and HP9 has either shipped or been dropped with a
sentence, this folder moves to `2026-archive/` with a dated line at the
top of STATUS. Archiving a source repository is part of the step that
finished that plugin, not a leftover for the archive day.
