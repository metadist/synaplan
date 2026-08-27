# Status — Conversation Continuity & Deep Memory

| Sprint | Branch | State | Notes |
| ------ | ------ | ----- | ----- |
| 1 — Summary eval harness | `feat/summary-eval-harness` | not started | |
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

## Summary quality — per-provider eval results (fill in after Sprint 1)

| Provider:model | Size compliance | Fact retention | Hallucination | Language | Notes |
| -------------- | --------------- | -------------- | ------------- | -------- | ----- |
| anthropic:? | | | | | |
| openai:? | | | | | |
| google:? | | | | | |
| huggingface:? | | | | | |
| groq:openai/gpt-oss-120b (current default) | | | | | |
| ollama:? (big model) | | | | | |

## Digest retrieval tuning (fill in after Sprint 4)

| Setting | Default | Tuned | Eval hit@5 / MRR |
| ------- | ------- | ----- | ---------------- |
| MIN_SCORE | 0.5 | | |
| RECENCY_HALF_LIFE_DAYS | 180 | | |
| PULL_MIN_SCORE | 0.6 | | |
