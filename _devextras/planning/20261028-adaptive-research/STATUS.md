# Adaptive research — status

Every number here names the command, the model and the stack it came from.
Router numbers come only from `app:research:router-eval`; quality, cost and
latency numbers from `app:research:eval` or the Desktop use-case runner. A
walk is a dated entry with what was clicked, typed, found and undone, not a
chat message.

| Step | State | Note |
| ---- | ----- | ---- |
| AR0 | done | Plan written 2026-10-28; registered as roadmap row 31. §0 unticked. No code. |
| AR1 | planned | The sorter rates how much a question needs; router corpus and `app:research:router-eval`; gate of `01` §4.2 |
| AR2 | planned | Depth `auto`, the ceiling from the plan, `hint`, `answer_now`, `RESEARCH.AUTO_ENABLED`, `TOOL_MODE_MODELS` seeded |
| AR3 | planned | The chat researches on its own: gate, notice, card on the message, Answer now, Look deeper, limit sentences; Tools → Research and `ResearchComposer.vue` removed |
| AR4 | planned | Automatic research switch on the admin tab |
| AR5 | planned | Gateway: `WEB_SEARCH_MAX_ROUNDS` 2 → 4, effort paragraph, `x-synaplan-effort` / `x-synaplan-research-run` |
| AR6 | planned | Synaplan Desktop: chip removed, header sent, card attached, Answer now, Look deeper |
| AR7 | planned | Router gate after AR3/AR5, cost curve, the marriage question live, J-AR-2 walked |
| AX1 | planned | User docs: the chat chooses how deep to look |

---

## 2026-10-28 — owner decision and plan

**Owner, 2026-10-28:** *"In Cursor the agentic loop simply runs; there is no
option. Synaplan should not need one either. It should adapt itself: when a
question is harder, it searches longer."* Two clarifications in the same
interview:

1. **No mode anywhere.** Tools → Research in the web app and the Research
   chip in Desktop are removed. The only controls a person sees are in the
   moment: **Answer now** while a run is going, **Look deeper** under an
   answer that did not run, **Go deeper** on a report as before.
2. **All surfaces together.** Web chat, Synaplan Desktop and the gateway
   route `POST /v1/messages` are planned as one track, not web first.

What was checked before writing (dev stack `main`, 2026-10-28):

- The web chat has no effort decision today: `tools:sort` votes
  `BWEBSEARCH`, `BREADPAGES`, `BMULTI`; `TurnWebSearch` runs at most one
  search and reads at most three pages; nothing calls
  `ResearchRunService::start` except the composer and the API.
- The gateway loop allows two search rounds (`MESSAGES_GATEWAY.WEB_SEARCH_MAX_ROUNDS = 2`),
  then forces a wrap-up.
- `RESEARCH.TOOL_MODE_MODELS` is seeded `[]`, so a run uses round mode
  unless an administrator lists a model; conformance 2026-10-26 gave Sonnet
  5 and 5.5 20/20, gpt-oss-120b 12/20.
- Row 21's measured gap (STATUS 2026-10-23): on the hard corpus the engine
  and the gateway-with-search both reach recall 0.96; citation precision
  87.3 % against 64.0 %; 0.070 against 0.059 USD per answer; 35 s against
  10 s p50. The engine's gain is backed, complete answers on multi-part
  questions — the owner's example question is one.
- Competitors (web, 2026-10-28): ChatGPT's GPT-5 Auto router (and the
  manual Fast / Thinking it had to add back), Perplexity's Auto classifier
  that "errs toward Pro", Anthropic's effort-scaling rules and `effort`
  parameter, Gemini's own search decision with Deep Research as a separate
  agent, Adaptive-RAG's three-way complexity router. Summarised in
  `00` §1.3.

Decisions proposed in `00` §0 (#1–#15), to be ticked by the owner before
AR1. Supersedes row 21 §0 #5 and #15 (dated line in that STATUS). Size
ratchet and deptrac respected by putting every new rule in
`App\Service\Message\Effort\*` and new Vue components. Cost of this entry:
$0 (no model called).

Open for the owner at §0: #4 (seed tool mode for the two Sonnet keys), #9
(the gateway header name and the 2 → 4 ceiling), #11 (the gate numbers), and
row 21 #25 (runs per day by plan — becomes a real need once runs start on
their own).
