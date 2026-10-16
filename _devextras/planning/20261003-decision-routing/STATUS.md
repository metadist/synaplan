# Decision-model routing — status

Every number here names the command, model, Ollama version and machine it
came from.

| Step | State | Note |
| ---- | ----- | ---- |
| D0 | done | Master plan and sprint file |
| D1 | planned | Plug port + Ollama System One adapter + `app:decide:probe` |
| D2 | planned | Question set, state builder, `sort-eval --decision`, corpus ≥ 300; go / no-go |
| D3 | planned | Shadow mode — needs §0 #7 answer |
| D4 | planned | Pinned-assistant web vote |
| D5 | planned | Cascade layer `decision_model` |
| D6 | planned | Planner allow-list |
| D7 | planned | Admin slot, status, routing note |
| D8 | planned | Production rollout (synaplan-platform) |
| P1 | planned | `DecisionService` thresholds, outcome, escalation; `DECISIONS` bucket |
| P2 | planned | `/v1/systemone` (Ollama-compatible) + `/api/v1/decisions`; scope `decisions:run` |
| P3 | planned | Decision turns in the chat pipeline (`decision_request` layer, SSE `decision`) |
| P4 | planned | Decision mode in the composer (builder, examples, mobile sheet) |
| P5 | planned | Result card, live preview, API call panel |
| P6 | planned | E2E on the Ollama stub + docs page |

## Baseline (fill in during D2)

| Metric | Value | Command / source |
| ------ | ----- | ---------------- |
| Sorter latency p50 / p95 | | `app:sort-eval --json` |
| Sorter topic accuracy | | `app:sort-eval --json` |
| Sorter fallback rate (prod) | | logs: `routing_fallback_reason` |
| Decision latency p50 / p95 | | `app:sort-eval --decision --json` |
| Coverage at thresholds | | `app:sort-eval --decision --json` |
