# Bug sprint — newest open Bugs (2026-07-15)

Branch: `fix/20260715-bug-sprint-newest-10`

Source: [metadist/synaplan issues](https://github.com/metadist/synaplan/issues)
(Feedback project **Status = Bug**; GitHub token lacks `read:project`, so Status was
taken from the public Issues UI + issue bodies marked `issue-type: Bug`.)

## prio:1 status

**No open `prio:1` issues.** All recent ASAP items (#1271, #1225, #1223, #1222,
#1218, …) are already closed. This sprint therefore targets the newest open
**Bugs** (max 10).

## Selection (newest 10 Bugs)

| # | Title | Description quality | Code status (main @ sprint start) | Sprint action |
| --- | --- | --- | --- | --- |
| [#1344](https://github.com/metadist/synaplan/issues/1344) | Vectorization success with 0 chunks | Excellent (root cause + fix) | **Still broken** | Fix |
| [#1343](https://github.com/metadist/synaplan/issues/1343) | Multi-task card content empty after reload | Excellent | **Still broken** (schema/persist) | Plan + partial notes; not full ship |
| [#1311](https://github.com/metadist/synaplan/issues/1311) | Gemini 3.5 Flash duplicated in picker | Excellent (prod data) | Catalog OK; **prod fingerprint drift** | Document ops fix; optional migration later |
| [#1268](https://github.com/metadist/synaplan/issues/1268) | Search "View file" does not open file | Excellent | **Still broken** | Fix |
| [#1257](https://github.com/metadist/synaplan/issues/1257) | WhatsApp operator looks like AI | Good | Still present → **fixed (prefix)** | Fix |
| [#1251](https://github.com/metadist/synaplan/issues/1251) | TTS: duration as text + routing | Excellent | Routing fixed; **extraction still broken** | Finish extraction + store source text |
| [#1224](https://github.com/metadist/synaplan/issues/1224) | KB indexes generation prompt | Excellent | **Already fixed** in code | Close after verify |
| [#1154](https://github.com/metadist/synaplan/issues/1154) | PluginView infinite "Loading…" | Excellent | **Already fixed** in code | Close after verify |
| [#1152](https://github.com/metadist/synaplan/issues/1152) | Widget session restored as active chat | Excellent | **Already fixed** (+ tests) | Close after verify |
| [#1115](https://github.com/metadist/synaplan/issues/1115) | Mistral empty-assistant cascade | Excellent | **Still broken** | Fix |

Also already fixed on main (close with comment): [#1110](https://github.com/metadist/synaplan/issues/1110),
[#1105](https://github.com/metadist/synaplan/issues/1105).

## Strategy

1. **Ship small, high-confidence fixes first** (#1344, #1268, #1115, #1251 remainder).
2. **Close issues that are already fixed on main** with a verification note (#1224,
   #1154, #1152, #1110, #1105) — improve labels (`Bug`, area tags) where missing.
3. **Do not pretend #1343 / #1311 / #1257 are done** — leave clear follow-ups.
4. Prefer `FileTypeResolver` for any extension/kind check (AI note: do not
   re-implement generic-kind fallbacks).

## Fix sketches (implementable)

### #1344 — VectorizationService

After the embed loop, if `$chunksCreated === 0` return `success: false` (same
shape as empty-text / no-chunks). Downstream `describeVectorizeAndSort` already
refuses to set `BSTATUS=vectorized` when `success` is false.

### #1268 — RagSearchView → FilesView

Pass `?file=<id>` from search; on FilesView mount, open `viewFileContent(id)`.

### #1115 — ChatHandler history builders

Skip assistant turns whose text (after file-text append) is empty before sending
to any provider. Apply in both `buildStreamingMessages` and `buildMessages`.

### #1251 remainder — TTS content

1. `FileUploadService::{describeVectorizeAndSort,reVectorize}` resolve extension via
   `FileTypeResolver` (never pass bare `'audio'` into `extractText`).
2. Prefer existing `BFILETEXT` for audio when non-empty (TTS source text).
3. `GeneratedFileRegistrar::register(..., ?string $fileText = null)` + pass
   synthesized text from MediaGenerationHandler / Text2SoundRunner descriptors.

### #1343 — follow-up (large)

Persist card text/URL/error into `BMESSAGE_TASKS` (or `BRESULTREF`) as nodes
settle; extend `TaskPlanStore::loadCards` + `inProgressTurn` OpenAPI; stop
hardcoding `text: ''` in `mapInProgressTurn`. Needs Galera-safe migration.

### #1311 — ops

One-shot UPDATE on prod BID that still shows as "Gemini 3.5 Flash" but should
be 2.5 Flash (or deactivate), then re-seed fingerprints. Seeder alone will not
heal fingerprint-protected rows.

### #1257 — product

Choose: prefix operator forwards (`👤 Operator: …`) vs stop forwarding operator
prompts. Needs UX decision before code.

## Sprint outcome (this branch)

| Issue | Outcome |
| --- | --- |
| #1344 | **Fixed** — `VectorizationService` fails when `chunks_created === 0` |
| #1268 | **Fixed** — `/files?file=` deep-link opens modal |
| #1115 | **Fixed** — empty assistant turns skipped in ChatHandler builders |
| #1251 | **Fixed (remainder)** — FileTypeResolver + TTS `fileText` persistence |
| #1128 | **Fixed** — guest rate limit removes empty assistant bubble |
| #1140 | **Fixed (residual)** — skip `getSseToken` when refresh fails |
| #1257 | **Fixed** — WhatsApp operator prompts prefixed `👤 Operator:` |
| #1058 | **Fixed** — real thinking duration (no hardcoded 8s) |
| #1224, #1154, #1152, #1110, #1105 | **Closed** — already fixed on main |
| #1078, #1138, #1077, #1071, #1027 | **Closed** — already fixed on main (round 2) |
| #1343 | Deferred (large persist/API work) |
| #1311 | Deferred (prod data / ops UPDATE) |

## Gate

After code changes:

```bash
make -C backend lint && make -C backend phpstan && make -C backend test
make -C frontend lint && docker compose exec -T frontend npm run check:types && make -C frontend test
```

(Backend-only vs frontend-only subsets OK when only one side changes.)
