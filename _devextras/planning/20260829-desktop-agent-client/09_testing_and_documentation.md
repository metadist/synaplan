# Testing and documentation (both repositories)

This is the quality contract for [`00_master_plan.md`](./00_master_plan.md).
A sprint is not done when pairing works on one laptop. It is done when this
file’s gate is green and the sprint’s documentation table is updated.

---

## 1. Principles

1. **Two repos, two gates.** Touching `synaplan/` runs the Synaplan
   unfiltered gate. Touching `synaplan-desktop` runs `make ci-local` there.
   A cross-repo change (Sprint 6) needs **both** green before either PR
   is merged — land server first if the client would 404.
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

### 2.3 Cross-repo (Sprint 6)

1. Merge server PR (flag off is fine; tools exist).
2. Point desktop at that API (or a contract fixture that matches OpenAPI).
3. Merge client PR.
4. Manual evidence in the client PR: one queued job, one refusal of
   `unknown_skill`.

---

## 3. Compatibility regression suite (every `synaplan/` sprint)

Maps to [`00_master_plan.md`](./00_master_plan.md) §10.

| Inv. | Test | Where |
| ---- | ---- | ----- |
| C1 | Empty-scope and legacy webhook keys still reach `/v1` and `/mcp` | New scope matrix + existing gateway tests |
| C2 | `/v1` and `/mcp` `tools/list` are additive | Existing contract tests; snapshot **superset** after Sprint 6 |
| C3 | Routing characterization byte-identical | `tests/Characterization/` — do not touch |
| C4 | Widget E2E / widget i18n namespace unchanged | Existing widget specs; PR review of `en.json` keys |
| C5 | New paths classified; `node scripts/mobile-impact.mjs` if required | `.github/mobile-impact-policy.json` |
| C6 | `security.yaml` only adds desktop routes on existing API firewalls | PR review + login E2E still green |
| C7 | M365 / Saved Tasks / Synamail tests untouched and green | Do not edit those suites unless fixing a true break |

---

## 4. Test matrix by sprint

| Sprint | Synaplan | Desktop | Manual |
| ------ | -------- | ------- | ------ |
| 0 | Scope matrix, flag resolver, grandfather keys | — | — |
| 1 | Pairing Redis TTL, revoke, OpenAPI, Vue + i18n | — | `pair.sh` against demo user |
| 2 | `docs/DESKTOP.md` only if touched | Pairing, keychain mock, SSE chat | One real PONG turn |
| 3 | — | Loader, confine, tool loop, env leak | — |
| 4 | — | Zip slip, symlink zip, disable | Install fixture zip in UI |
| 5 | Docs / NOTICE if any | Parse pptx SKILL.md, doctor, hermetic pptx | One real deck on a human OS |
| 6 | Jobs, lease, MCP tools, enqueue 404 | Check-in mock, unknown skill, ignore `command` | Queue from web → file in chat |

---

## 5. Documentation table (update in the same PR)

| Doc | Owner | When |
| --- | ----- | ---- |
| `docs/DESKTOP.md` | synaplan | Sprint 2 (stub), 5 (pptx/Outlook honesty), 6 (queue) |
| `docs/ANTHROPIC_COMPATIBLE_API.md` Related | synaplan | Sprint 2 |
| `docs/OPENAI_COMPATIBLE_API.md` scopes note | synaplan | Sprint 0 |
| `docs/BUNDLED_SKILLS.md` | desktop | Sprint 5 |
| `docs/DEVELOPMENT.md` | desktop | Sprint 2 |
| `AGENTS.md` | desktop | Sprint 2 (repo birth) |
| This planning folder status lines | synaplan | When a sprint merges |

User-facing `docs/**` rides with the code PR. Do not “document in a follow-up”.

---

## 6. Definition of done (every D-step)

1. Gate of the touched repo is green (unfiltered).
2. New branches have tests; security fixes have a regression test.
3. OpenAPI + schemas if HTTP changed.
4. Four locales if copy changed.
5. PR lists which invariants the diff can touch.
6. Characterization diff is empty.
7. Docs in the same PR.
8. No `sk_` or pairing codes in the diff.

---

## 7. Cache / local traps

Synaplan: after `docker compose down`, reset `var/cache/test` as in
`AGENTS.md`. Desktop: tests must set `HOME` / XDG dirs to a temp path so
developers’ real `~/.synaplan-desktop` is never used in CI.
