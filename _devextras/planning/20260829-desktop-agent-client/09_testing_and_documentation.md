# Testing and documentation (both repositories)

This is the quality contract for [`00_master_plan.md`](./00_master_plan.md).
A sprint is not done when pairing works on one laptop. It is done when this
file’s gate is green and the sprint’s documentation table is updated.

---

## 1. Principles

1. **Two repos, two gates, and never in the same PR.** Touching `synaplan/`
   runs the Synaplan unfiltered gate. Touching `synaplan-desktop` runs
   `make ci-local` there. Under the server-first order there is **no
   cross-repo change to sequence**: all of Phase A merges before the client
   repo exists, and Phase B only touches `synaplan/` for documentation
   (`DC5`, `DC21`).
2. **Unfiltered gate = CI.** `phpunit --filter` and `vitest path` are
   diagnostic only.
3. **Deterministic and offline in CI.** No live LLM, no Graph, no Agent37,
   no real LibreOffice on the PR runner (except an optional nightly).
   Fixture upstreams and tempdirs.
4. **Characterization is a contract.** This epic must **not** change
   sorter / classifier / planner snapshots. If a PR does, it is out of
   scope — stop and split.
5. **Widget invariant.** No Desktop UI, job hooks, or new i18n keys under
   `widget.*`. Value-only edits to shared keys follow the streamlining
   compatibility rules.
6. **Four locales.** Any user-visible string: `en`, `de`, `es`, `tr` in
   the **same** change, in whichever repo owns the UI.
7. **OpenAPI → Zod** on every new `/api/v1/desktop` field, then `vue-tsc`.
8. **Mobile impact.** New `synaplan/` paths go in
   `.github/mobile-impact-policy.json`. Default: PHP = `backend-only`;
   Channels Desktop page = `ota-candidate`.
9. **Galera.** Prod migrations: raw idempotent `addSql` only.
10. **No secrets in fixtures.** Pairing codes = `AB3K7Q2M`; keys =
    `sk_test_…`; URLs = `https://synaplan.test`.
11. **Path confinement tests are not optional.** A sprint that touches
    Read/Write/Bash without a symlink-escape case is incomplete.
12. **A consumer-less server feature must prove itself twice.** Phase A ships
    before any client, so every Phase A behaviour needs (a) a PHPUnit test and
    (b) an assertion in the fake-device harness (`DS17`). “We will find out
    when the client calls it” is not a test strategy.
13. **The frozen contract is test input, not test output.** After `DS18`, the
    fixtures in `_devextras/testing/desktop/fixtures/` are asserted against
    the live schema on the server side and vendored verbatim on the client
    side. A fixture edit is a `protocol: 2` decision (C9).

---

## 2. Mandatory gates

### 2.1 `synaplan/` (every server PR)

From `synaplan/` with Docker up:

```bash
make lint \
  && make -C backend phpstan \
  && make test \
  && docker compose exec -T frontend npm run check:types \
  && make -C frontend test
```

If OpenAPI changed:

```bash
make -C frontend generate-schemas
docker compose exec -T frontend npm run check:types
```

If you believe routing could have moved (it should not):

```bash
docker compose exec -T backend \
  ./vendor/bin/phpunit tests/Characterization/RoutingCharacterizationTest.php
```

Diff must be empty. Do not re-record.

Planning-only changes under `_devextras/planning/` do not require the
PHP gate.

### 2.2 `synaplan-desktop/` (every client PR)

```bash
make ci-local
```

Must include at least: lint/format, `vue-tsc` (or equivalent), unit tests,
production Tauri/Vite build on the CI OS (Linux).

### 2.3 Phase gate (replaces the old cross-repo dance)

Before the first `DC*` PR exists, confirm on `main`:

1. Every `DS*` step merged; breakdown §0 status table fully ticked for Phase A.
2. Full unfiltered Synaplan gate green on `main`, characterization diff empty.
3. `_devextras/testing/desktop/fake-device.sh` green against a local stack,
   including every refusal row in sprint A3 §2.6.
4. Flag off on a fresh install: no nav item, `/api/v1/desktop/*` 404,
   `tools/list` unchanged, reaper a no-op (C8).
5. `docs/DESKTOP.md` published and fixtures committed (C9).

Only then create `synaplan-desktop` (`DC1`).

Within Phase B, a client PR needs `make ci-local` only. The two
documentation steps (`DC5`, `DC21`) are docs-only `synaplan/` PRs and take
the docs path, not the PHP gate.

---

## 3. Compatibility regression suite (every `synaplan/` sprint)

Maps to [`00_master_plan.md`](./00_master_plan.md) §10.

| Inv. | Test | Where |
| ---- | ---- | ----- |
| C1 | Empty-scope and legacy webhook keys still reach `/v1` and `/mcp` | New scope matrix + existing gateway tests |
| C2 | `/v1` and `/mcp` `tools/list` are additive | Existing contract tests; snapshot **superset** after Sprint A3 |
| C3 | Routing characterization byte-identical | `tests/Characterization/` — do not touch |
| C4 | Widget E2E / widget i18n namespace unchanged | Existing widget specs; PR review of `en.json` keys |
| C5 | New paths classified; `node scripts/mobile-impact.mjs` if required | `.github/mobile-impact-policy.json` |
| C6 | `security.yaml` only adds desktop routes on existing API firewalls | PR review + login E2E still green |
| C7 | M365 / Saved Tasks / Synamail tests untouched and green | Do not edit those suites unless fixing a true break |
| C8 | Flag off on `main` is inert: no nav, 404 routes, `tools/list` unchanged, reaper no-op | Flag-off variants of every Phase A endpoint test + a `tools/list` snapshot with the flag off |
| C9 | The A3 contract does not move in Phase B | Server: fixtures asserted against live OpenAPI/MCP schema. Client: vendored fixtures compared to the recorded source commit |

---

## 4. Test matrix by sprint

| Sprint | Repo | Automated | Manual / evidence |
| ------ | ---- | --------- | ----------------- |
| A1 | synaplan | Scope matrix, flag resolver, grandfather keys | — |
| A2 | synaplan | Pairing Redis TTL, revoke, OpenAPI, Vue + i18n | `pair.sh` against the demo user |
| A3 | synaplan | Jobs, lease, expiry, MCP tools, enqueue 404, flag-off `tools/list`, ignored extra keys, fixture-vs-schema | `fake-device.sh`: full loop + every refusal row |
| B1 | desktop | Pairing, keychain mock, SSE chat, vendored-fixture parity | One real PONG turn |
| B2 | desktop | Loader, confinement, symlink escape, tool loop, env leak | — |
| B3 | desktop | Zip slip, symlink zip, disable, git URL parse | Install fixture zip in the UI |
| B4 | desktop | Parse pptx SKILL.md, doctor, hermetic pptx, `curl` denied | One real deck on a human OS |
| B5 | desktop | Check-in mock, unknown/disabled skill, ignore `command`, unattended default | Queue from web → file in chat, plus a screenshotted refusal |

Phase A rows carry both an automated and a harness assertion (principle 12);
Phase B rows reuse the Phase A fixtures rather than inventing payloads.

---

## 5. Documentation table (update in the same PR)

| Doc | Owner | When |
| --- | ----- | ---- |
| `docs/OPENAI_COMPATIBLE_API.md` scopes note | synaplan | `DS4` |
| `docs/DESKTOP.md` — what it is, pairing, flag, **job contract** | synaplan | `DS18` (created here, not in Phase B) |
| `docs/ANTHROPIC_COMPATIBLE_API.md` Related | synaplan | `DS18` |
| `_devextras/testing/desktop/` README + fixtures | synaplan | `DS17`, `DS18` |
| `docs/DESKTOP.md` — install / pairing walkthrough | synaplan | `DC5` (docs-only PR) |
| `docs/DESKTOP.md` — queue walkthrough, Outlook honesty | synaplan | `DC21` (docs-only PR) |
| `AGENTS.md`, `docs/DEVELOPMENT.md` | desktop | `DC1` (repo birth) |
| `docs/BUNDLED_SKILLS.md` | desktop | `DC15` |
| This planning folder status lines | synaplan | When a step merges |

User-facing `docs/**` rides with the code PR. Do not “document in a follow-up”.

---

## 6. Definition of done (every `DS*` / `DC*` step)

1. Gate of the touched repo is green (unfiltered).
2. New branches have tests; security fixes have a regression test.
3. OpenAPI + schemas if HTTP changed.
4. Four locales if copy changed.
5. PR lists which invariants the diff can touch.
6. Characterization diff is empty.
7. Docs in the same PR.
8. No `sk_` or pairing codes in the diff.
9. `DS*` only: the flag-off behaviour is asserted, and the harness covers any
   new device-facing behaviour.
10. `DC*` only: no `synaplan/` file in the diff unless this is `DC5` / `DC21`.

---

## 7. Cache / local traps

Synaplan: after `docker compose down`, reset `var/cache/test` as in
`AGENTS.md`. Desktop: tests must set `HOME` / XDG dirs to a temp path so
developers’ real `~/.synaplan-desktop` is never used in CI.
