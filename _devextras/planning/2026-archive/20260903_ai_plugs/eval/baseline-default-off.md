# RAG rerank eval — baseline (default stays off)

Date: 2026-09-09

Corpus: `golden-extraction-s1` (S1 extraction fixtures). This file is the
committed report for S4. A live `app:rag:eval-rerank` run against a user's
vector store was not used to flip the default.

| | Off | On |
| --- | ---: | ---: |
| recall@5 | n/a | n/a |
| p95 ms | n/a | n/a |

Decision: **`PLUGS.RERANK.ENABLED` stays `0`**. Decision 8 requires a winning
live report (recall@5 up, p95 on under the budget) before a seeder change.
The mechanism, tab and eval command ship in this sprint; the default-flip
is a later PR that must link its report here and in `STATUS.md`.
