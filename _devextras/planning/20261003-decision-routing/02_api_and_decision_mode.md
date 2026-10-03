# Decision models as a product — API + decision mode in chat

Part of [`00_master_plan.md`](00_master_plan.md) (steps P1–P6).
Binding UX: [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md).

---

## 1. The professional shape, in one paragraph

A decision model is not a chat model, so we do not pretend it is one. The
industry treats typed, non-generative models (embeddings, moderation,
rerank) as **their own endpoint with their own schema**, and gives people a
**playground** that shows the exact request it sends. We do the same:

1. **One execution core** — `DecisionService` — used by every entry point:
   routing (D-track), the public API, and the chat.
2. **Two API doors** onto that core:
   - `POST /v1/systemone` — **wire-compatible with Ollama System One**, so
     any client written against Ollama works by changing the base URL and
     adding a Synaplan key (same idea as our `/v1/chat/completions` and
     `/v1/messages`).
   - `POST /api/v1/decisions` — **Synaplan-native**, a superset: acceptance
     thresholds, an `outcome`, and optional escalation to a chat model when
     the decision model is unsure (the cascade pattern as a service).
3. **Decision mode in chat** — a distinct state of the composer that builds
   a decision request visually, runs it, renders probabilities as charts,
   and shows the equivalent API call. Every run is a normal chat turn, so
   the result is findable later.

---

## 2. Vocabulary (one term per concept, five locales)

The API keeps its field names. The UI never shows `state`, `noul`,
`criteria`, `instructions`.

| API field | UI term (en) | de | es | fr | tr |
| --------- | ------------ | -- | -- | -- | -- |
| (feature) | decision mode | Entscheidungsmodus | modo de decisión | mode décision | karar modu |
| (model) | decision model | Entscheidungsmodell | modelo de decisión | modèle de décision | karar modeli |
| `state` | What to judge | Was bewertet wird | Qué evaluar | Ce qu'il faut évaluer | Değerlendirilecek metin |
| `choice` | Choose one | Eins auswählen | Elegir una | Choisir une | Birini seç |
| `noul` | Yes or no | Ja oder nein | Sí o no | Oui ou non | Evet veya hayır |
| `score` | Rate on a scale | Auf einer Skala bewerten | Valorar en una escala | Noter sur une échelle | Ölçekte değerlendir |
| `criteria` | Options / Levels | Optionen / Stufen | Opciones / Niveles | Options / Niveaux | Seçenekler / Seviyeler |
| `confidence` | How clearly one answer wins | Wie eindeutig eine Antwort gewinnt | Qué tan clara es la respuesta ganadora | À quel point une réponse l'emporte | Bir cevabın ne kadar net öne çıktığı |

Icon: **`ScaleIcon`** (Heroicons outline 24) for "decide" — add it to the
icon map in `docs/FRONTEND_CONVENTIONS.md` in the same PR (one action, one
glyph).

---

## 3. API

### 3.1 Shared rules (both doors)

- Auth: Bearer `sk_…` API key or the app session. New scope
  **`decisions:run`** in `ApiKeyScope` (path map: `/v1/systemone`,
  `/api/v1/decisions*`). Empty-scope and `*` keys keep full access
  (grandfather rule); restricted keys need the scope explicitly.
- Models: only catalog entries with tag `decide`. `model` is the catalog
  `providerId` (`nimble`, `clef-flash`, `clef`); omitted ⇒
  `DEFAULTMODEL.DECIDE`. `GET /v1/models` lists them with
  `capabilities: ["synaplan:decision"]` (and `"synaplan:vision"` for Clef).
- Limits enforced **before** the upstream call, with the same numbers as
  Ollama: 1–64 questions, 2–26 options, body ≤ 64 KiB (32 MiB with
  images), images only on vision decision models.
- Rate limit bucket **`DECISIONS`** (`RateLimitConfigSeeder`), usage booked
  through `RateLimitService::recordUsage()` with source `DECISION_API`
  (chat: `DECISION_CHAT`, routing: `DECISION_ROUTER`).
- Module gate: `DecisionRoutingModule` inactive ⇒ both endpoints 404 via
  the module gate, `/v1/models` lists no decision models.
- No request text is stored for API calls beyond the existing usage-row
  policy.

### 3.2 `POST /v1/systemone` (Ollama-compatible)

Request and response are **exactly** the Ollama schema (see master plan
§2). Errors use Ollama's shape `{"error": "…"}` with its status codes
(400, 404, 413, 500) plus ours (401, 403 scope, 429). `keep_alive` from the
client is ignored — the server owns model residency.

```bash
curl https://web.synaplan.com/v1/systemone \
  -H "Authorization: Bearer $SYNAPLAN_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
    "model": "nimble",
    "state": "Our checkout has returned 500 errors since 9am.",
    "questions": {
      "label": {"type": "choice", "instructions": "Which label fits this ticket?",
        "criteria": {"billing": "Payments and refunds", "bug": "Software errors", "account": "Login and account access"}}
    }
  }'
```

### 3.3 `POST /api/v1/decisions` (Synaplan-native)

Superset of the System One request:

```json
{
  "model": "nimble",
  "state": {"ticket": "I was charged twice. Please refund the extra payment."},
  "questions": {
    "refund":  {"type": "noul", "instructions": "Is the customer requesting a refund?"},
    "urgency": {"type": "score", "instructions": "How urgently does this need a response?",
                "criteria": ["Routine", "Soon: a customer is inconvenienced", "Immediate: a critical service is down"]}
  },
  "accept": {
    "refund":  {"min_probability": 0.95},
    "urgency": {"min_confidence": 0.5}
  },
  "escalation": "none"
}
```

| Field | Meaning |
| ----- | ------- |
| `accept` | Optional per-question rule. `choice`: `min_probability` of the winning option. `noul`: `min_probability` applies to whichever side wins (p or 1−p). `score`: `min_confidence`. |
| `escalation` | `none` (default) or `chat_model`: questions that miss their rule are re-asked to the account's tools model with structured output (enum per choice, boolean per yes/no, integer level per score). |

Response:

```json
{
  "id": "dec_01J…",
  "model": "nimble",
  "outcome": "accepted",
  "answers": {
    "refund":  {"type": "noul", "noul": 0.9989, "accepted": true, "decided_by": "decision_model"},
    "urgency": {"type": "score", "score": 0.83, "legend": {"0": "Routine", "1": "Soon…", "2": "Immediate…"},
                "probabilities": {"0": 0.31, "1": 0.55, "2": 0.14}, "confidence": 0.21,
                "accepted": false, "decided_by": "decision_model"}
  },
  "usage": {"input_tokens": 212, "output_tokens": 2},
  "latency_ms": 141
}
```

- `outcome`: `accepted` (every question with a rule passed, or no rules),
  `needs_review` (a rule failed, `escalation: none`), `escalated` (a rule
  failed and the chat model answered those questions).
- An escalated answer is honest: `decided_by: "chat_model"`, the chat
  model's pick, **`probabilities: null`** — an LLM's self-reported number
  is not a probability and we do not invent one.
- Full OpenAPI annotations (request, response, every error) so
  `make -C frontend generate-schemas` yields the Zod schemas the chat uses.

### 3.4 Errors in plain words (API `message` + UI copy)

| Case | Status | UI sentence |
| ---- | ------ | ----------- |
| Model not pulled | 404 | "This decision model isn't installed on the server yet. An admin can add it under AI infrastructure." |
| Ollama too old / unreachable | 503 | "Decision models are unavailable right now. Your text was not sent anywhere — try again in a minute." |
| Body too large | 413 | "This text is too long for a quick decision. Shorten it, or ask in normal chat." |
| > 26 options | 400 | Inline on the field: "Up to 26 options per question." |
| Rate limit | 429 | "You've reached your decision limit for now. It resets at {time}." |

---

## 4. Decision mode in chat

### 4.1 Entry, exit, findability

- **Enter:** Tools menu → **Decide** (`ScaleIcon`), slash `/decide`, or
  Ctrl/Cmd+K "Start a decision". All three absent when the module is
  inactive (U11).
- **Exit:** the mode badge's ×, Backspace in an empty field (same as the
  existing tool badges), or Esc. The draft survives exit/re-enter in the
  same chat (composable state, not a store write).
- **Find it later (U2):** each run is a chat turn; a chat started in
  decision mode gets the title "Decision: {first question}". The chat list
  filter gains "Decisions".

### 4.2 Desktop layout (≥ md)

The composer grows upward into a builder; the chat stays visible above.

```
┌─ Decision mode ─────────────────────────────── Nimble ▾ ── [×] ─┐
│ Examples:  [Ticket triage] [Reply needed?] [Tone check] [Photo]  │
│                                                                   │
│ WHAT TO JUDGE                                  [☐ Use this chat]  │
│ ┌───────────────────────────────────────────────────────────────┐ │
│ │ Our checkout has returned 500 errors since 9am …              │ │
│ └───────────────────────────────────────────── 1.2 / 64 KB ─────┘ │
│                                                                   │
│ QUESTIONS                                                         │
│ ┌ 1 ─ [Choose one | Yes or no | Rate on a scale] ──────── 🗑 ┐   │
│ │ Which label fits this ticket?                               │   │
│ │ (billing · Payments and refunds) (bug · Software errors)    │   │
│ │ (account · Login and account access) [+ option]             │   │
│ │ Accept only at ≥ [ 90 %]  ─────────●──                       │   │
│ └─────────────────────────────────────────────────────────────┘   │
│ ┌ 2 ─ [Yes or no] ────────────────────────────────────── 🗑 ┐    │
│ │ Is the customer asking for a refund?                       │    │
│ └────────────────────────────────────────────────────────────┘    │
│ [+ Add question]                                                  │
│                                                                   │
│ ☐ Live preview   ☐ Ask the chat model when unsure   </> API call  │
│                                              [ ⚖ Decide  ⌘↵ ]     │
└───────────────────────────────────────────────────────────────────┘
```

- **Examples** fill the whole builder in one click — they double as the
  first-run guidance (U5: one sentence + one action on the empty state:
  "Ask a question with fixed answers. Start from an example.").
- **Use this chat** sends the last turns (trimmed, size-checked) as the
  thing to judge: "Is the customer in this conversation satisfied?"
- **Live preview** (the thing only fast models can afford): runs the
  native endpoint 500 ms after typing stops, with an `AbortController`
  cancelling the previous request, and animates the bars in place. Nothing
  is saved until **Decide**. Counted against `DECISIONS`; off by default.
- **</> API call** opens a side panel with the exact request for the
  current draft: tabs **curl** · **Python** · **JavaScript** · **JSON**,
  door switch **Ollama-compatible / Synaplan**, and a link "Create an API
  key" to the API keys page. The key is always a placeholder.
- Photo example and image attach only when the selected model has vision
  (Clef / Clef Flash).

### 4.3 Mobile (320 px)

Full-height sheet with three steps and a sticky bottom action:
**1 Text → 2 Questions → 3 Decide**. Question cards collapse to one line
("Choose one · 3 options · ≥ 90 %"), tap to edit. API call panel becomes a
full-screen sheet.

### 4.4 The result card (`decision` message part)

```
⚖ Decision · Nimble · 141 ms                          [Accepted ✓]
──────────────────────────────────────────────────────────────────
Which label fits this ticket?                                     
  bug      ███████████████████████████████████████░  97.8 %  ◀ pick
  billing  ▌                                           1.3 %
  account  ▍                                           0.9 %
  How clearly one answer wins: ████████░░ 0.89     threshold ┊90 %

Is the customer asking for a refund?
  No ├──────────────────────────────────────────●──┤ Yes   99.9 %

How urgent is it?
  Routine ─────── Soon ──●──── Immediate      score 0.83 of 2
  ▁▃ ▇▅ ▂                                    (distribution)
──────────────────────────────────────────────────────────────────
Read only — this scored your text; nothing was sent or changed.
[Edit and rerun]  [Run on Clef Flash]  [</> API call]  [Copy JSON]
```

- Choice: bars sorted by probability, winner marked, threshold drawn as a
  line, `confidence` shown with its plain meaning and a tooltip "This is
  how clearly one answer wins, not a guarantee it is right."
- Yes or no: one horizontal gauge, the winning side labelled.
- Scale: the level axis with a marker at the weighted score and a small
  distribution histogram.
- Outcome chip: **Accepted** / **Needs a person** (a rule failed; the
  sentence names which question and by how much) / **Asked the chat
  model** (the escalated questions show the chat model's pick, labelled,
  without bars).
- **Run on …** adds a second result for another decision model under the
  first, so models can be compared on the same input with their latency.
- History reload renders the same card from the stored result; the
  message text is a one-line summary so search, share, and the chat list
  show something meaningful ("bug 98 % · refund: yes 99.9 % · urgency
  0.83/2").

### 4.5 How a decision turn flows

```
ChatInput (decision mode) ─ send { text: state, decision: {model, questions, accept, escalation, use_chat} }
  → StreamController (same SSE path as chat)
  → MessageClassifier: `decision` option present ⇒ RoutingDecision::deterministic(RoutingLayer::DecisionRequest)
  → InferenceRouter → DecisionHandler → DecisionService::run()
  → SSE `decision` event (result JSON) → `complete`
  → OUT message: BTEXT = summary sentence, meta DECISION_RESULT = JSON
     IN message: meta DECISION_REQUEST = JSON (questions, model, accept)
```

Works in incognito (transient, nothing stored). Not in the embeddable
widget (non-goal for v1).

### 4.6 Frontend structure (no 2 000-line component grows)

`ChatInput.vue` only gains the mode toggle and mounts the builder (target
≤ 40 added lines). Everything else is new and under 300 lines each:

| File | Role |
| ---- | ---- |
| `components/decision/DecisionBuilder.vue` | Shell: header, model picker, examples, footer actions |
| `components/decision/DecisionQuestionCard.vue` | Type switch, question text, rule slider |
| `components/decision/DecisionOptionsEditor.vue` | Option chips (key + description), ordered levels for scale |
| `components/decision/DecisionApiPreview.vue` | curl / Python / JS / JSON, door switch, copy |
| `components/decision/DecisionResultCard.vue` | Result card + outcome chip + actions |
| `components/decision/DecisionChoiceBars.vue`, `DecisionYesNoGauge.vue`, `DecisionScaleChart.vue` | Pure visualisations, token colours only, both themes |
| `components/MessageDecision.vue` | `decision` part → `DecisionResultCard` |
| `composables/useDecisionDraft.ts` | Draft, validation (limits), → request JSON, examples |
| `composables/useDecisionLivePreview.ts` | Debounced native call with `AbortController` |
| `services/api/decisionsApi.ts` | `httpClient` + generated Zod schemas |
| `i18n/locales/{en,de,es,fr,tr}/decision.json` | New namespace (register in `namespaces.ts`) |

Charts are plain divs/SVG with CSS tokens — no chart dependency.

---

## 5. The test example (ships in the product and in the tests)

**"Ticket triage"** is both the first example in the builder and the
fixture every test uses:

- What to judge: "Our checkout has returned 500 errors since 9am. I was
  charged twice — please refund the extra payment."
- Q1 *Choose one* "Which label fits this ticket?": billing / bug / account
  (accept ≥ 90 %).
- Q2 *Yes or no* "Is the customer asking for a refund?"
- Q3 *Rate on a scale* "How urgent is it?": Routine / Soon / Immediate.

Expected with a real model: `bug`, refund ≈ yes, urgency between Soon and
Immediate. The other examples ("Reply needed today?", "Tone check",
"Photo shows damage?" for Clef) are localized in all five languages.

| Layer | Test |
| ----- | ---- |
| Backend unit | `DecisionService` limits, outcome logic, escalation mapping (mocked port + mocked chat), `probabilities: null` on escalation |
| Backend contract | `/v1/systemone` request/response fixtures copied from the Ollama docs examples (choice, multiple, error 404/413) — byte-compatible JSON shape |
| Backend functional | Scope enforcement (`decisions:run`), module-off 404, rate limit 429 |
| Frontend Vitest | `useDecisionDraft` limits and request JSON; chart components render probabilities; API preview matches the draft for both doors |
| E2E `decision-mode.spec.ts` (@ci) | Ollama stub gains `POST /v1/systemone` with a deterministic fixture for Ticket triage and the existing `shouldSimulateError` hook. Journey: Tools → Decide → Ticket triage → Decide → bars show `bug` 97.8 % → "Accepted" → raise Q1 rule to 99 % → rerun → "Needs a person" → open API call → copy → exit mode → reload → card still there. Error run: stub 404 ⇒ the "isn't installed" sentence. |
| E2E layout | Same journey at 320 px dark (`test-e2e-layout`) |
| Live eval (manual) | `app:decide:probe` with the Ticket triage example against native Ollama; numbers into `STATUS.md` |

---

## 6. Journeys (named before the first `.vue`)

| Id | Who | Path | Step |
| -- | --- | ---- | ---- |
| **J-DM-1** | A new user | Tools → Decide → "Ticket triage" → Decide → reads the bars → edits the text → reruns. Next day finds it in the chat list under "Decisions". | P4, P5 |
| **J-DM-2** | A developer | Result card → API call → curl → "Create an API key" → runs it in a terminal → same answer. Switches the door to Ollama-compatible and runs it with an Ollama client by changing the base URL. | P2, P5 |
| **J-DM-3** | A team lead | Sets "accept only at ≥ 95 %" → "Needs a person" with the reason → turns on "Ask the chat model when unsure" → "Asked the chat model", labelled honestly. | P1, P5 |
| **J-DM-4** | Anyone, module off / model missing | No Decide tool, no `/decide`, no palette entry (U11). Admin sees the module status sentence and the fix. | P3, D7 |
| **J-DM-5** | Anyone | Leave decision mode with ×; delete a decision turn like any message (U3). | P4 |

### Exit bullets for P4 and P5 (UX contract §6)

1. Journeys walked in the browser end to end: click, type, find, undo (U10).
2. Ten-second findability: Decide in the Tools menu, `/decide`, palette
   entry, "Decisions" filter in the chat list (U2).
3. Copy in all five locales with the §2 vocabulary; no API jargon outside
   the API call panel (U3, U9).
4. Empty state = one sentence + "Start from an example"; every error in
   §3.4 has its sentence; module off ⇒ surface absent (U5, U8, U11).
5. Light, dark, design v2, 320 px; bars and gauges meet WCAG AA contrast
   in both themes (U9).
