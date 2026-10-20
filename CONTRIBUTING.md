# Contributing

Bugs, small fixes, and translations are welcome. For anything large, open an issue and wait for a reply before you start.

## Set up

You need Docker.

```bash
git clone https://github.com/metadist/synaplan.git
cd synaplan
make up
```

Open <http://localhost:5173> and sign in as `admin@synaplan.com` / `admin123`. The first start takes a few minutes.

## Pull requests

Work on a branch. Keep the pull request to one change. Fill in the template: what changed, and how you checked it.

Code, comments, and commit messages stay in English. Subjects follow [Conventional Commits](https://www.conventionalcommits.org/), for example `fix:`, `docs:`, or `test:`. A subject that starts with `feat` is shipped as a minor release, so save that for a real feature.

Run `make ci-local` before you push. That is the unit and lint check CI runs. A translation-only change can skip it and run the locale check below instead.

## Translations

Locales are German, English, Spanish, French, and Turkish, under `frontend/src/i18n/locales/`. English is the source. A missing translation is shown in English, so the screen does not tell you anything is missing.

Translate only the keys named in the issue.

- Leave placeholders such as `{count}` and `{name}` exactly as they are.
- Delete those keys from `frontend/tests/unit/i18n/localeParityBaseline.json` in the same change. The test compares that file as a list. A translated key that is still listed fails, and so does a new English string that the other locales do not have.

With the stack running:

```bash
docker compose exec -T frontend npm test -- --run tests/unit/i18n/localeParity.spec.ts
```
