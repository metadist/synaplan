---
name: Synaplan
description: Coding-agent rules — land the pull request, green CI, clean source
---

# Synaplan

PHP 8.4 / Symfony 8.1, Vue 3 / TypeScript / Vite, MariaDB, Docker Compose.
Tooling runs in containers through `make`. App `http://localhost:5173`, API
`http://localhost:8000`. This file is the gate. Longer references are at the
end. The previous guides are `_devextras/bak_AGENTS_root.md` and
`_devextras/bak_AGENTS_planning.md`.

## Loop

The agent lands every code change in this repository. The request to
implement a topic is already the request to commit it, push it, and open
the pull request. Do not stop after the files are edited, and do not ask
for a separate go-ahead to commit, push, or open the pull request. A topic
is finished only when Copilot has reviewed the current head and **All
Checks Passed** is green on that head.

1. **Branch** off current `main`. One topic, one branch, one pull request.
   Conventional Commits (`feat:`, `fix:`, `refactor:`, `docs:`, `chore:`,
   `test:`). A subject that **starts with `feat`** — any casing, including
   a squash-merged PR title — bumps the **minor** release; anything else
   bumps the patch. A major bump is explicit. No AI attribution
   (`Co-Authored-By`, "Generated with…"). Never commit or push to `main`.
   Never force-push `main` or `master`. Code, comments, and commits are
   English. Merge conflicts by hand and keep both sides' behavior. Never
   `git checkout --ours` or `--theirs` on code. If unsure, ask.
2. **Implement** that topic only. A user-visible change is done when its
   journey has been walked in the browser on the running stack: click,
   type, find, undo. A screenshot is not that walk. Sign in with a seeded
   account (`docs/DEVELOPMENT.md`). Never print, log, or commit a provider
   key. The product bar is `docs/UX_CONTRACT.md` (U1–U12). Before the
   commit, run `make ci-local` unfiltered. Before the push that touches
   the UI, a route, OpenAPI the UI consumes, chat, or auth, also run
   `make test-e2e`. Backend-only PHP with no HTTP or UI contract may skip
   Playwright. A bad selector, i18n key, or route shows up only in E2E.
   Push only after that local gate is green.
3. **Commit and push** the branch.
4. **Open the pull request** on GitHub with `gh pr create` against `main`,
   ready for review. The title is the conventional commit subject. The
   body says what changed, how to see it, and `Fixes #NNNN` when an issue
   exists.
5. **Wait for the Copilot review** of that head. Copilot reviews every
   pull request to `main`. Poll until `copilot-pull-request-reviewer` has
   submitted a review of the current head SHA. Every thread — Copilot's or
   a person's — gets a reply: the hash of the fixing commit, or one
   sentence on why it does not apply. Resolve a thread only after the code
   or the text matches the reply. Push the fix to the same branch, then
   wait for Copilot on the new head.
6. **Wait until `All Checks Passed` is green** on that head. Reproduce a
   red check locally, fix it on the same branch, push, and wait again. A
   pending check is still open. Then say the pull request is ready and
   give its URL. The owner merges. Do not merge. Do not start the next
   topic before this one is ready.

Once the local gate is green, a review or CI fix re-runs only the side it
touches: frontend gets `make -C frontend lint`, `vue-tsc`, and the full
Vitest; backend gets the backend targets. `--filter` is diagnosis. Do not
rebuild the stack or re-run every local E2E job when CI already runs the
failing one. Fix red on the same branch.

## Gate

```bash
make format      # PSR-12 + Prettier (plugins: make plugins-format)
make ci-local    # lint, phpstan, deptrac, PHPUnit, Vitest, vue-tsc
make test-e2e    # Playwright @ci, headless, on the host
```

`make ci-local` is the unit and static mirror. It is not full CI.
Playwright is its own required check. The database client inside `db` is
`mariadb`, not `mysql`.

| You changed | Also run |
| --- | --- |
| Layout, nav, viewport | `make -C frontend test-e2e-layout` |
| A `@crossbrowser` flow | `cd frontend && npm run test:e2e:firefox` |
| Auth or OIDC | `cd frontend && npm run test:e2e:oidc` |
| Chat model or the Ollama path | `cd frontend && npm run test:e2e:ollama` |
| Docker, compose, or shipped `dist/` | `make test-e2e-full` |
| OpenAPI annotations | `make -C frontend generate-schemas`, then vue-tsc |

Install host Playwright once: `make -C frontend deps-host`, then
`npx playwright install --with-deps`.

- A filtered run (`phpunit --filter`, `phpstan analyse <path>`,
  `vitest <file>`, one spec) is not the gate. `make -C backend phpstan`
  covers `src/` and `tests/`.
- Playwright runs on the host. `docker compose exec frontend npm run test:e2e`
  talks to the wrong localhost. Stay headless unless asked to watch.
- A flaky E2E failure starts from the trace timeline:
  `python3 _devextras/e2e/playwright-trace-timeline.py path/to/trace.zip`.
  Raising a timeout is not a fix. Every E2E job dying on
  `Unable to download artifact` is Actions; re-run the workflow.
- A failure in code you did not touch: read the known local-only failures
  in `docs/E2E_TESTING.md` first. Unwritable `var/cache/test`:
  `docker compose exec -T backend rm -rf var/cache/test`.

## Do not silence a red check

These ledgers only shrink. Do not add a row to hide a new violation.

- **Size.** `backend/tests/Architecture/sizeRatchetBaseline.json` freezes
  files over 800 lines, constructors over 10 parameters, and methods over
  100 lines. New code goes in a new class. After a real split,
  `UPDATE_SIZE_RATCHET=1` may drop dead entries only.
- **Layers.** `backend/deptrac.yaml`: Entrypoint → Application → Repository
  → Entity → Shared. An entity does not import a service. A repository does
  not import `App\AI`. Move the value to `App\Model\` or pass it in.
  `deptrac.baseline.yaml` shrinks via `make -C backend deptrac-baseline`.
- **Routing snapshots.** Changes to `MessageClassifier`, `MessageSorter`, or
  the sorter response drift `backend/tests/Characterization/`. Re-record
  with `UPDATE_ROUTING_SNAPSHOTS=1` and review every line.
- **Locales.** A new UI key lands in all five files,
  `frontend/src/i18n/locales/{en,de,es,fr,tr}/`, in the namespace that
  already owns its siblings. `localeParityBaseline.json` only shrinks.
  Placeholder names (`{count}`) match English in every locale.
- **Frontend tests.** Provide Pinia, i18n, and `useMarkdown`, or stub the
  heavy component. Do not mount the whole app.
- **Flags.** A code path whose `BCONFIG` default is off does nothing in
  production. A seeder edit does not reach existing installs; ship a new
  default as a migration `UPDATE`.

## Source stays clean

### PHP

PSR-12, `declare(strict_types=1)`, and a type on every parameter and return.
`final` classes, `readonly` properties, specific exceptions, named
constants. No comments about deleted code.

A controller method does HTTP only: validate, delegate, stay under 50
lines. Services hold the logic (`final readonly`, constructor injection).
Repositories hold every query. No `EntityManager` and no DQL in a
controller.

DBAL 4: `bindValue()`, then `executeQuery()` with no arguments. Read the
request with `$request->query->get()`, `$request->request->get()`, or
`$request->attributes->get()`.

Every endpoint has complete OpenAPI annotations (`@OA\Response`,
`@OA\Property`, required fields, examples). Regenerate the Zod schemas
after they change. Model names come from `ModelRepository`. Schema changes
are Doctrine migrations (`docs/MIGRATIONS.md`). Catalog data is an
idempotent seeder. Demo data stays in fixtures and never runs in
production. Never `doctrine:schema:update --force`. Production migrations
use raw idempotent SQL, not the Schema API.

An internal prompt topic starts with `tools:`. A memory badge uses an ID
from the current prompt list only. An optional feature is a `FeatureModule`
in `backend/src/Module/`.

### Vue and TypeScript

`<script setup lang="ts">`. Prefer `ref()` and `computed()`. Never mutate a
prop. Never put `v-if` and `v-for` on the same element. No semicolons, no
`any`, no leftover `console.log`. Drop listeners in `onUnmounted()`. Keep a
component under 300 lines.

API types come from generated Zod
(`frontend/src/generated/api-schemas.ts`) through `httpClient` and
`services/api/`. Do not hand-write an interface for an API payload. No
`VITE_*` variable for runtime config. The app uses
`useConfigStore().apiBaseUrl`. The widget uses `detectApiUrl()`. Never
hardcode `http://localhost:8000`.

User-facing copy goes through `vue-i18n`. Confirmations use `useDialog()`.
Toasts use `useNotification()`. Never `alert()`, `confirm()`, or `prompt()`.

Colors are tokens in `frontend/src/style.css` (glass overrides live in
`style-v2.css` under `.design-v2`). No raw Tailwind palette colors and no
one-off classes. Light and dark both meet WCAG AA: 4.5:1 for text, 3:1 for
large text and icons. A new color is defined for both themes. Do not
recolor `.pill`, `.txt-secondary`, or `.icon-ghost` from an ancestor;
overlays inside that subtree inherit the ink.

A button has a house class and padding:
`btn-primary px-4 py-2.5 text-sm font-medium` (secondary the same; danger
plus its own radius). Do not put `rounded-lg` on `.btn-primary` or
`.btn-secondary`. Never a raw `<button>`.

Every `input`, `textarea`, and `select` carries
`px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm`
and the brand focus ring. Layout classes alone draw an invisible field.
`var(--danger)` is undefined; errors use
`text-sm text-red-600 dark:text-red-400`.

One Heroicon outline per action (`docs/FRONTEND_CONVENTIONS.md`). `mdi:`
is for glyphs Heroicons lack. File rows use `FileRowActions.vue`.

## Ask first

Database schema, a new dependency, Docker / CI / build config, a new AI
provider.

Never commit a secret or a credentialed `.env`. Never edit `vendor/` or
`node_modules/`. Never commit `dist/`. Never skip the gate. Never put
app-signing, store-account, or OTA-hosting details in this public repo.
Classify a new path in the same PR (`.github/mobile-impact-policy.json`);
an unlisted path fails closed as `store-required`.

## Docs

- `docs/README.md` — full documentation index and 5-track learning path
- `docs/PHP_CONVENTIONS.md`, `docs/FRONTEND_CONVENTIONS.md` — style and tokens
- `docs/ARCHITECTURE.md` — layers and size limits
- `docs/API_PATTERNS.md` — Zod, OpenAPI, `httpClient`
- `docs/MIGRATIONS.md` — migrations and seeders
- `docs/UX_CONTRACT.md` — U1–U12
- `docs/E2E_TESTING.md` — Playwright
- `docs/DEVELOPMENT.md` — setup and test users
- `docs/PROVIDERS.md` — adding a provider
