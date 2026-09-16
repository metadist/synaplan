# Status — Conversation Continuity & Deep Memory

| Sprint | Branch | State | Notes |
| ------ | ------ | ----- | ----- |
| 1 — Summary eval harness | `feat/summary-eval-harness` | implemented, eval run recorded below | `app:summary:eval`, `make -C backend summary-eval` |
| 2 — Durable summary + channel parity | `feat/durable-summary-channel-parity` | implemented (stacked on Sprint 1 branch) | `BCHATSUMMARIES` durable store behind Redis; summary injected + refreshed on web, WhatsApp, email, MCP, widget; cleanup on chat delete |
| 3 — Message digest foundation | `feat/message-digest-foundation` | implemented (stacked on Sprint 2 branch) | `BMESSAGEDIGESTS` + Qdrant `user_message_digests`; `tools:message_digest` prompt; `app:digest:run` (daily scheduler) + `app:digest:backfill`; per-user BCONFIG cursor + cost caps; verified live end-to-end (rent-letter case digested + vector-indexed) |
| 4 — Digest retrieval + badges | `feat/digest-retrieval` | implemented (stacked on Sprint 3 branch) | `DigestSearchService` (recency re-rank + top-N message pull) injected via `ChatHandler` on stream + non-stream; `[Message:ID]` badges (web SSE + reload endpoint) and plain-text resolution for WhatsApp/email/MCP; `app:digest:eval` retrieval harness (results below) |
| 5 — Hardening, admin, docs, E2E | `feat/continuity-hardening` | implemented (stacked on Sprint 4 branch) | Admin UI knobs for the `DIGEST` group (Routing → Deep memory) with validation; per-user cap (5000, prune-oldest-first) enforced after every digest run; chat delete deactivates digests + drops Qdrant points; `app:digest:reindex` rebuilds the vector index from MariaDB; `docs/CONVERSATION_CONTINUITY.md` runbooks; load sanity + mobile-impact verified (see below) |

## Investigation baseline (2026-08-27)

- Rolling summary implemented (`ConversationSummaryService`, PR #1282) but:
  Redis-only store (TTL 3600 s), injection only on the streaming path, refresh
  dispatch only from `StreamController` + `ProcessMessageCommandHandler`.
  Email / MCP / generic webhook get no summary; WhatsApp / widget-public never
  refresh one. Details: `00_master_plan.md`.
- No search of any kind over `BMESSAGES` — old messages unreachable unless
  extracted as a memory.
- Eval pattern to copy: `PlanEvalCommand` + golden corpus.

## Summary quality — per-provider eval results (2026-08-27, 8-case corpus)

Run: `php bin/console app:summary:eval --models=...` with `SUMMARY_MAX_CHARS = 4000`.

| Provider:model | Result | Size compliance | Language | Typical latency | Notes |
| -------------- | ------ | --------------- | -------- | --------------- | ----- |
| anthropic:claude-sonnet-5 | **8/8** | max ~1.9k chars | de/ru respected | 7–10 s | |
| openai:gpt-5.5 | **8/8** | max ~1.9k chars | de/ru respected | ~7 s | |
| google:gemini-3.1-pro-preview | **8/8** | max ~2.0k chars | de/ru respected | ~8 s | |
| huggingface:moonshotai/Kimi-K3:deepinfra | **8/8** | max ~2.0k chars | de/ru respected | up to ~21 s (slowest) | |
| groq:openai/gpt-oss-120b (**current SUMMARIZE default**) | **7/8 typical** | max ~1.7k chars | de/ru respected | 1–2 s (fastest by far) | Flaky **date retention**: drops the concrete effective date in ~1 of 4 runs of the rent-letter case (verified with `--repeat`). Everything else stable. |
| ollama:gpt-oss:120b | SKIPPED | — | — | — | model not pulled in this environment (`ollama pull gpt-oss:120b`) |

**Conclusions**

1. The production prompts hold up well across all four requested cloud providers —
   structure, language, size, and hallucination probes were clean everywhere.
2. Size is not a concern in practice: no model came near the 4000-char cap
   (max observed ~2.0k) at `tokenBudget(4000)`.
3. The current Groq default is by far the fastest/cheapest but occasionally drops
   concrete dates when folding long factual threads. Options (later PR, measured
   with this harness): tighten the prompt ("always keep concrete dates, amounts,
   deadlines") or switch the seeded SUMMARIZE default.
4. Corpus authoring learnings baked in: forbidden probes must not appear anywhere
   in the source conversation; date probes must accept all common formats
   ("1 Sept", "14 Nov", "09-01", ...). The `--json` output now includes the raw
   summary per case for exactly this kind of inspection.

## Digest retrieval tuning (Sprint 4, 2026-08-27)

Run: `php bin/console app:digest:eval` (4-case corpus, 8 queries, bge-m3 embeddings,
cosine + the production recency decay via `DigestSearchService::effectiveScore`).

| Setting | Default | Tuned | Eval hit@5 / MRR |
| ------- | ------- | ----- | ---------------- |
| MIN_SCORE | 0.5 | kept | hit@1 6/8, hit@5 **8/8**, MRR 0.823 |
| RECENCY_HALF_LIFE_DAYS | 180 | kept | recency tie-break case correctly prefers the newer report |
| PULL_MIN_SCORE | 0.6 | kept | |

**Observations**

1. Every query retrieves its target within top-5, including the acceptance
   rent-letter case in both English and German — defaults kept.
2. The two hit@1 misses are paraphrase-heavy queries ("how much is our new
   office rent?", "what did the agency quote originally?") where near-duplicate
   distractors outrank the target; the target still lands in the injected block,
   so the model can cite it. Tuning `MIN_SCORE` up would drop recall — not worth it.
3. bge-m3 handles cross-language retrieval (German query → English digest and
   vice versa) without any special handling.

## Sprint 5 verification notes (2026-08-27)

- **Load sanity:** seeded a 10k-message user, ran
  `app:digest:backfill --user=… --since-days=400 --max-batches=4 --dry-run`:
  1.9 s wall for 4 model batches (100 messages scanned) under a 256M PHP
  memory limit — hydration is bounded by `BATCH_SIZE` keyset pages by
  construction. The model correctly proposed 0 digests for the deliberately
  boring seeded messages. Seeded data removed afterwards.
- **Mobile impact:** `node scripts/mobile-impact.mjs --base origin/main --head HEAD`
  classifies the whole feature as `ota-candidate` (frontend badge work) +
  `backend-only` (everything else). Nothing store-required.
- **Badge E2E deferred:** there is no Playwright E2E for `[Memory:ID]` badges
  either — house precedent is component-level coverage. The digest badge flow
  is covered by `ChatHandlerDigestAcceptanceTest` (full backend stack, rent
  letter case), `MessageTextMessageRefs.spec.ts` (render + click + navigation),
  and the `messageDigests` store tests. A stub-model E2E would need the E2E
  Ollama stub to emit `[Message:ID]` deterministically plus seeded Qdrant
  state — noted as follow-up, not a sprint gate.
