# Rules — a plugin we ship

Read with [`00_master_plan.md`](./00_master_plan.md). These rules are the
bar for every directory this track adds under `plugins/`. `hello_world`
remains the small pattern in `plugins/README.md`. A hosted plugin is the
pattern for a real product: it does one job, it can be left out, and
the next person can see why it is shaped this way.

Row 24's checks (PHPStan, php-cs-fixer, ESLint, Prettier, locale parity)
apply the moment they exist. Until then, a pull request that adds a
plugin still runs those tools on the files it adds and fixes what they
find. It does not wait for a baseline entry.

## 1. One directory, one job

- The folder is `plugins/<id>/`. `<id>` matches `manifest.json` `"id"`
  and `"name"`, and it is the argument of `app:plugin:install`.
- Lowercase, `[a-z0-9_]`, the same pattern the kernel already accepts.
- The manifest `namespace` is `Plugin\<StudlyId>`.
- The manifest lists real routes (path and method). A test compares
  them with the attribute routes, the way row 24's PQ4 describes.
- `minSynaplanVersion` is a version this repository has actually shipped.
- `homepage` is the docs page (`https://docs.synaplan.com/...`), not a
  repository that is about to be archived.
- License is Apache-2.0, the same as this repository, unless the
  incoming tree is already under a license we cannot relicense. Record
  that case in the pull request. Do not silently change a license header.
- A `README.md` in the directory says what the plugin does for a person,
  how to install it for one user, and how to leave it out
  (`PLUGINS_DISABLED` and deleting the directory). It does not say
  "clone another repository and copy this folder."

## 2. The kernel must boot without it

- No class outside `plugins/<id>/` may `use` that plugin's namespace.
- No migration in `backend/migrations/` may create that plugin's tables.
  Plugin SQL stays in `plugins/<id>/migrations/` and runs on install.
- A missing directory is a skipped manifest. `PLUGINS_DISABLED=<id>`
  is the same outcome when the directory is still in the image (HP2):
  no routes, no install, no installed-list entry, and both asset routes
  answer 404, including for a person who already had it installed.
- `DEFAULT_USER_PLUGINS` may name it. Provisioning skips a name that is
  not available and does not fail user creation. That is already true;
  a test locks it for every plugin this track adds.
- Frontend routes, the Apps directory, and chat slash-commands render
  the plugin only when it is installed for that user. A disabled plugin
  is an unknown URL, not an empty page and not a stack trace.
- Secrets the plugin needs are per user or per install, stored the way
  the platform already stores plugin settings. They are not env vars in
  the Synaplan image and not rows in the committed `.env.example`.

## 3. An example is a size, a test, and five languages

A plugin is listed as an **example** in `plugins/README.md` only when
all of the following hold. Until then STATUS says `present, not yet an
example` and names the step that finishes the list.

- No PHP file over 800 lines, no method over 100 lines, no constructor
  over 10 parameters. These are the numbers in
  `backend/tests/Architecture/sizeRatchetBaseline.json`. Plugins are
  outside that ratchet on purpose; this track still refuses to add a
  file that would have been rejected in `backend/src`.
- A controller method does HTTP only: validate, delegate, respond.
  Services hold the logic. Repositories are unnecessary while the
  plugin stores rows in `plugin_data`; a plugin that opens its own
  tables puts the queries in a class that is not the controller.
- `final` classes, `declare(strict_types=1)`, a type on every parameter
  and return.
- At least one PHPUnit class in `backend/tests/Plugin/<Id>/` that fails
  if the main behaviour is removed. Fixtures may live next to the
  plugin. The suite runs inside `make -C backend test` with no network.
- Outbound HTTP uses the shared client. `curl_init`, `file_get_contents`
  of a URL, and a hand-built Guzzle client fail `EgressInventoryTest`
  the day the directory leaves `.gitignore`. That is the point of
  removing the ignore line and the matching `IGNORED_PLUGINS` entry in
  the same commit.
- `frontend/locales/{de,en,es,fr,tr}.json`. Identical key sets,
  identical placeholder names. English is the fallback. No locale file,
  no example. Incoming trees that use `frontend/i18n/` move to
  `frontend/locales/` in the import.
- User-facing strings go through the plugin `t()` once row 24's hook is
  on `main`. Before that, the strings live in the locale files and the
  page reads them. Hardcoded sentences in `index.js` are not the end
  state.
- Copy avoids the banned list in `docs/UX_CONTRACT.md`. Buttons use the
  house classes. Confirmations use the platform dialog, not `alert()`,
  `confirm()`, or `prompt()`.
- A plugin that writes to a third-party system (FastBill, an ad
  account, a mailbox) shows the action and waits for an explicit
  confirmation. The model proposes. PHP performs the call.

## 4. What may be copied, and what may not

From the source repository, copy:

- `manifest.json`, `backend/`, `frontend/`, `migrations/`, `README.md`,
  `LICENSE` if it differs from this repo's.

Do not copy:

- `.git`, which is how a deleted secret returns
- `.env`, `.env.*`, keys, tokens, customer documents, real invoices
- `_devextras/`, one-off smoke scripts, `node_modules/`, `vendor/`
- a homepage URL that points at a repository we are about to archive,
  until it has been replaced with the docs URL

The pull request body names the source commit (full SHA) and the files
that were deliberately left behind.

Archive a source repository only when the whole repository moved here
and nothing in it is still the product. That is Synaform, Synaads, and
synafastbill, one release after each is an example: README rewritten
into a map, archive flag on, repository not deleted. Do that in the
source repository's own pull request, linked from STATUS.

Repositories that still hold a companion stay open. metadist/Synamail
keeps the Outlook add-in, its CI, and `addin.synaplan.com`; only the
"copy `synamail-plugin/`" instructions become a pointer at
`plugins/synamail`. metadist/Synasort stays private and active because
the Go sorter still lives there. metadist/synaplan-desktop and
metadist/synaplan-ai-support-chat are companions and are not archived
by this track.

## 5. CI and docs, every time

In this repository, the same pull request:

- removes `plugins/<id>/` from `.gitignore` and from
  `EgressInventoryTest::IGNORED_PLUGINS` together (the test checks they
  match)
- adds the PHPUnit class
- runs `make ci-local` unfiltered
- if the page, a route, or a slash-command is new or changed, runs
  `make test-e2e` for that journey
- classifies the diff with `scripts/mobile-impact.mjs`
  (`plugins/**` is backend-only; a change under `frontend/src/` is
  ota-candidate)

In `synaplan-docs`, the same step:

- updates `docs/plugins.md` so the row says the plugin is in the
  Synaplan image, how to install it for a user, and how to disable it
- stops telling the reader to clone the old repository
- gives the plugin its own section: what a person can do, what is
  stored, what leaves the server

A green test with no docs change is an unfinished step.

## 6. Companions

Office add-ins, desktop installers, WordPress packages, and the SortX
Go worker follow a shorter list, because their code is not in
`plugins/`:

- Synaplan shows one honest link. A store listing, an EXE, or a DMG is
  linked only after a person has installed from that URL and written
  the walk in STATUS.
- A draft GitHub release is not a public download.
- The companion's CI stays in its repository. This repository tests
  that the link target answers and, when the companion calls an API,
  that the route it calls still exists (a contract test with the
  pinned request shape, not a second copy of the companion).
- The WordPress plugin does not call `api.php`, does not create a
  Synaplan account, and does not embed a password field for one.

## 7. Review checklist

Copied into the pull request body of every import:

- [ ] Source SHA recorded, history not imported
- [ ] No `.env`, no `_devextras/`, no customer data
- [ ] Secret scan of the snapshot is clean
- [ ] Directory, manifest id, and install argument match
- [ ] Kernel boots with the directory removed and with `PLUGINS_DISABLED`
- [ ] PHPUnit covers the main behaviour offline
- [ ] Shared HTTP client, no new curl
- [ ] Five locale files, or STATUS says not yet an example
- [ ] Size bar, or STATUS says not yet an example and names the split
- [ ] `plugins/README.md` and `synaplan-docs` updated
- [ ] Egress ignore list and `.gitignore` updated together
