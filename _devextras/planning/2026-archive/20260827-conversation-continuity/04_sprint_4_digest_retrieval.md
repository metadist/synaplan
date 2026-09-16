# Sprint 4 — Digest Retrieval in Chat + Message Pull + Badges

Branch: `feat/digest-retrieval`
Answers: *"a prompt from that user MUST find the old message and be able to pull
it"* — the actual acceptance use case.

## Design

### Retrieval (in `ChatHandler`, both `handle` and `handleStream` — parity from day one)

1. **Reuse the existing per-turn embedding** (the one already computed for memory
   search) — one embed call per turn, no extra latency.
2. Search Qdrant `user_message_digests`: filter `user_id` + `active`, `limit`
   top-k (default 5), min score (default 0.5 — to be tuned with the eval, see
   below).
3. **Recency-aware re-rank**: `effective = score * decay(source_date)` with a slow
   half-life (e.g. 180 days) — old but highly relevant beats recent but vague; the
   rent letter from 3 months ago must survive this easily.
4. Exclude digests pointing into the CURRENT chat's recent window (already in
   context verbatim).

### Injection format

New block via `KnowledgeContextFormatter::formatDigestContext()`:

```
## Older conversations (references to past messages)
[Msg: 1234 | 2026-05-14 | email] office rent letter to realtor about the increase of payments
...
REFERENCES: cite as [Message:ID]. Only IDs from this list. Never invent IDs.
```

### Message pull (two stages, budget-controlled)

- **Stage 1 (always)**: the digest lines themselves (cheap, ~100 chars each).
- **Stage 2 (top hits only)**: for the best `DIGEST_PULL_TOP_N` (default 2) hits
  above `DIGEST_PULL_MIN_SCORE` (default 0.6), load the referenced `BMESSAGES` row
  and append a clipped excerpt (`BTEXT` + `BFILETEXT`, cap ~1500 chars each) under
  the reference — so the model can quote the actual letter, not just know it exists.
- **Size discipline**: the whole digest block (lines + pulled excerpts) has a hard
  cap (default 4000 chars) carved out of the existing window: when the digest block
  is present, reduce the verbatim-history budget passed to the summary tail
  accordingly. Total context stays inside the 10–15k band.

### `[Message:ID]` badges

- Backend: extend the reference rules in the system prompt (as above); external
  channels (WhatsApp/email plain text) resolve `[Message:1234]` to a readable
  inline form via a `resolveMessageTags()` analog of
  `UserMemoryService::resolveMemoryTags()`.
- Frontend: `MessageText.vue` renders `[Message:ID]` as a clickable badge like
  `[Memory:ID]`; click navigates to the chat/message (reuse existing chat routing;
  new endpoint only if message→chat lookup isn't already exposed — check first, and
  if needed: full OpenAPI annotations + `make -C frontend generate-schemas` +
  `vue-tsc`).
- i18n: all four locales (`en`, `de`, `es`, `tr`) for any new UI strings
  (badge tooltip, "from an older conversation" label).

### Config (BCONFIG group `DIGEST`, constants-backed like `CONVERSATION_SUMMARY`)

| Key | Default |
| --- | ------- |
| `ENABLED` | 1 |
| `TOP_K` | 5 |
| `MIN_SCORE` | 0.5 |
| `RECENCY_HALF_LIFE_DAYS` | 180 |
| `PULL_TOP_N` | 2 |
| `PULL_MIN_SCORE` | 0.6 |
| `BLOCK_MAX_CHARS` | 4000 |

## Retrieval eval — `app:digest:eval`

Same pattern as Sprint 1: golden corpus (`tests/Eval/digest_eval_corpus.json`) of
(digest set, query, expected message_id ranks). Runs embedding + search against a
seeded Qdrant (dev) and reports hit@k / MRR. Used to tune `MIN_SCORE` and the decay.
**The office-rent case is corpus case #1.**

## Steps

1. `DigestSearchService` (search + re-rank + budget assembly) — pure, mock-friendly.
2. Wire into `ChatHandler` (both paths) behind `DIGEST.ENABLED`.
3. Formatter block + reference rules; `resolveMessageTags()` for external channels.
4. Frontend badge + navigation + i18n; regenerate schemas if an endpoint is added.
5. `app:digest:eval` + corpus; tune thresholds; record results in `STATUS.md`.

## Tests (sprint gate)

- `DigestSearchServiceTest` — re-rank math (recency decay), current-chat exclusion,
  budget caps (block never exceeds `BLOCK_MAX_CHARS`, pull respects `PULL_TOP_N`).
- `ChatHandlerTest` — digest block lands in the system prompt in `handle` AND
  `handleStream`; disabled flag removes it; widget/guest never get it.
- **Acceptance integration test**: fixtures = one user, a 3-month-old message with
  rent-letter file text + digest row + mock Qdrant hit; a new prompt about the rent
  → assert system prompt contains the digest line AND the pulled excerpt AND the
  reference rules. This test IS the use case from the request.
- `resolveMessageTags` unit tests (found/missing/foreign-user ID).
- Frontend Vitest: badge rendering, click emits navigation, no badge for invented
  IDs outside the delivered list (mirror Memory badge tests; stub heavy deps).
- Full unfiltered gate incl. frontend (`lint`, `vue-tsc`, Vitest).

## Risks

- Score threshold too low → noise pollutes every chat; too high → the use case
  fails. Hence the eval corpus BEFORE tuning, and conservative pull defaults.
- Token/size creep: budgets are hard caps, enforced in code, tested.
