# Status — Conversation Continuity & Deep Memory

| Sprint | Branch | State | Notes |
| ------ | ------ | ----- | ----- |
| 1 — Summary eval harness | `feat/summary-eval-harness` | implemented, eval run recorded below | `app:summary:eval`, `make -C backend summary-eval` |
| 2 — Durable summary + channel parity | `feat/durable-summary-channel-parity` | not started | |
| 3 — Message digest foundation | `feat/message-digest-foundation` | not started | |
| 4 — Digest retrieval + badges | `feat/digest-retrieval` | not started | |
| 5 — Hardening, admin, docs, E2E | `feat/continuity-hardening` | not started | |

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

## Digest retrieval tuning (fill in after Sprint 4)

| Setting | Default | Tuned | Eval hit@5 / MRR |
| ------- | ------- | ----- | ---------------- |
| MIN_SCORE | 0.5 | | |
| RECENCY_HALF_LIFE_DAYS | 180 | | |
| PULL_MIN_SCORE | 0.6 | | |
