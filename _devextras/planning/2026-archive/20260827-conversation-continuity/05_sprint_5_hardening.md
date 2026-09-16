# Sprint 5 — Hardening, Admin Config, Docs, E2E

Branch: `feat/continuity-hardening`

## Scope

Everything that makes Sprints 1–4 operable, observable, and safe at scale.

### Admin & operations

- `SystemConfigService`: expose the `DIGEST` group knobs (enabled, top-k, min
  score, half-life, pull caps, daily batch caps) next to the existing
  `CONVERSATION_SUMMARY_*` block; admin UI section + i18n (all four locales).
- Per-user digest cap (default 5000 entries) with prune-oldest-inactive-first on
  overflow (mirror the 500-memory discipline; digests are cheaper, cap is higher).
- Deletion hygiene: deleting a chat or message deactivates/removes its digests
  (DB + Qdrant delete by point ID) and the chat's summary row. Account deletion
  path (`UserLifecycleService` / existing purge) covers both new tables and the new
  Qdrant collection.
- Re-embedding: extend the existing re-vectorize/migrate tooling
  (`MigrateLegacyPointIdsCommand` pattern) so an embedding-model change can rebuild
  `user_message_digests` from MariaDB (authoritative store makes this cheap).
- Observability: counters in logs (digests created/run, search hit rates, summary
  refresh failures); `app:digest:run` summary line suitable for cron mail.

### Documentation

- `docs/` entry describing the continuity architecture (summary + digest), the
  eval commands, and operational runbooks (backfill, re-embed, disable switches).
- Update `_devextras/planning/20260827-conversation-continuity/STATUS.md` with
  final eval numbers per provider and the tuned thresholds.
- `synaplan-platform` follow-up: cron documentation for `app:digest:run`
  (private repo PR, command name only — no infra details here).

### E2E / quality

- Playwright E2E smoke (per `docs/E2E_TESTING.md`): long chat → old-topic prompt →
  `[Message:ID]` badge appears and navigates correctly.
- Mobile impact classification: frontend badge work is `ota-candidate`; backend
  work is `backend-only`; verify `.github/mobile-impact-policy.json` covers any new
  frontend paths (`node scripts/mobile-impact.mjs --base <base> --head <head>`).
- Load sanity: `app:digest:backfill` on a seeded 10k-message user — runtime + cost
  logged; no memory blowup (batched hydration).

## Tests (sprint gate)

- Admin config tests (knobs readable/writable, defaults intact) — mirror
  `SystemConfigServiceTest` CONVERSATION_SUMMARY coverage.
- Prune-on-overflow unit test; deletion-hygiene tests (chat delete → digests +
  summary row gone, Qdrant delete called).
- Re-embed command test with `QdrantClientMock`.
- E2E suite green; full unfiltered backend + frontend gates.

## Release notes

- All features ship default-ON for summaries (existing behavior, now durable) and
  default-ON for digest **collection**, default-ON for digest **retrieval** —
  single kill switches: `CONVERSATION_SUMMARY.ENABLED`, `DIGEST.ENABLED`.
- BCONFIG defaults are bootstrap-only: any default changed after first release
  needs an explicit UPDATE migration to reach existing installs.
- No `store-required` mobile impact expected; badge UI ships OTA.
