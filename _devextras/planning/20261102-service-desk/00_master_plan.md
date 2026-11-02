# Service desk — how many questions the AI solved, what it could not answer, and a clean handoff into the helpdesk

Row 38. Umbrella: [`20261102-enterprise-readiness/`](../20261102-enterprise-readiness/00_master_plan.md) (C19).

72 % of German companies that use AI use it in customer contact (Bitkom
2026). The products they compare Synaplan with sell one number:

- Intercom Fin charges 0.99 $ per *resolution*;
- moin.ai reports an automation rate;
- Zendesk AI reports deflection.

A customer-service lead decides on that number and on two questions:
"What did the AI not know?" and "Does a ticket land in our Zendesk when a
person is needed?"

Synaplan's widget already has sessions, human takeover, a webhook and
Slack handoff, custom fields, exports and AI summaries with topics, FAQs,
sentiment, issues and recommendations. What is missing:

- a per-conversation **outcome**;
- the visitor's own verdict;
- the loop from "unanswered" to "answered next time";
- tickets in the helpdesk the team already uses.

## 1. Findings (checked 2026-11-02 against `main`)

| # | Prio | Finding | Evidence |
| - | ---- | ------- | -------- |
| F1 | P1 | A session has a mode (`ai`, `human`, `waiting`, `internal`) but no outcome. Nobody can say how many conversations the AI resolved | `Entity/WidgetSession.php:16-19` |
| F2 | P1 | The visitor cannot rate an answer or say "this solved it" | `ChatWidget.vue` has no feedback control (the app chat has feedback, `Service/Feedback/`) |
| F3 | P1 | Summaries aggregate topics, FAQs and issues per period, but unanswered questions are not listed, grouped or turned into knowledge | `Entity/WidgetSummary.php` (`BTOPICS`, `BFAQS`, `BISSUES`, `BRECOMMENDATIONS`), `WidgetSummaryService.php` |
| F4 | P1 | Handoff pages a person (Slack webhook, dashboard). When nobody is available, the visitor waits. No ticket is created in Zendesk, Freshdesk or HubSpot | `Service/Widget/WidgetHandoff.php`, `HumanTakeoverService.php`, `Service/Notification/SlackNotificationService.php` |
| F5 | P2 | Widget metrics are not available as CSV or API for the customer's reporting | `WidgetExportService.php` exports conversations, not metrics |

## 2. What is fine — do not "fix" it

- Human takeover and the waiting state. A ticket is an additional path, not a replacement.
- Summaries and their prompt tab. Outcome labels feed the summary; they do not replace it.
- Custom fields (`BCUSTOM_FIELD_VALUES`) for the visitor's mail address and order number. The ticket uses them.
- The webhook handoff stays for everything that is not one of the three named helpdesks.

## 3. Target model

### 3.1 One outcome per conversation

`BWIDGET_SESSIONS.BOUTCOME` (nullable) plus `BOUTCOMESOURCE`
(`visitor`, `rule`, `model`). The outcome is set when a session goes quiet
for 30 min or is closed:

| Outcome | Rule, in this order |
| ------- | ------------------- |
| `resolved` | the visitor pressed "Yes, solved" — or — the AI answered, no handoff, no negative rating, and the outcome model labels it resolved |
| `handed_off` | a person took over or a ticket was created |
| `unanswered` | the assistant said it has no answer, found no source, or refused, and nothing else followed |
| `abandoned` | the visitor left before any answer, or after the first answer without a reply and the model cannot tell |

- The outcome model is an internal prompt `tools:widget_outcome`. The `tools:` prefix keeps the sorter from choosing it. It runs on the summary model the owner picked and reads only the transcript. When no model is configured, only the rule part runs and sessions without a rule stay `null` ("not rated").
- **Resolution rate** = `resolved` ÷ rated sessions, shown with the share decided by visitors, by rules and by the model, so the owner can judge it.

### 3.2 The visitor's verdict

- Thumbs up/down under each AI answer in the widget. A down asks "What was missing?" with three chips (wrong, incomplete, I need a person) and optional text.
- After the second AI answer, a quiet prompt asks "Did this solve your question? **Yes** / **No**". It shows once per session and can be turned off per widget.
- "I need a person" offers the handoff right away.
- Ratings are stored as widget events and count into §3.1.

### 3.3 Knowledge gaps — from "unanswered" to "answered next time"

- Every `unanswered` session and every answer rated "wrong" or "incomplete" contributes the visitor's question.
- A nightly job embeds the questions with the install's vectorize model and groups them by similarity (threshold 0.82, D3). Each group has a representative question, a count and the last date.
- Widget → **Knowledge gaps** lists the groups, most frequent first. Each row offers:
  - **Write answer** — a short form; the answer is saved as a Markdown file into the widget's knowledge folder and indexed;
  - **Ignore**;
  - **Open conversations**.
- After a written answer the group shows "Answered on {date} — asked 3 times since, resolved 3 times", so the owner sees it worked.

### 3.4 Tickets in the helpdesk

New connection kinds under Connections (credentials encrypted, every call
through `EgressHttpClient`):

| Helpdesk | Auth | Create | Follow-up |
| -------- | ---- | ------ | --------- |
| Zendesk | API token + subdomain | `POST /api/v2/tickets` with requester (visitor mail), subject, transcript as an internal note, summary, tags `synaplan`, custom fields mapped | the ticket id and link are stored on the session |
| Freshdesk | API key + domain | `POST /api/v2/tickets` | same |
| HubSpot | private app token | CRM ticket object + association to a contact by mail | same |

- The widget's handoff settings get **When nobody is available** (or always, D4): *Wait* (today) / *Create a ticket in {helpdesk}*.
- The visitor reads "We created ticket #48213. You will get an answer at ada@example.org." If the visitor gave no mail address, the widget asks for it first (an existing custom field type).
- A failed ticket keeps the session in `waiting` and tells the owner on the session card: "The ticket could not be created: Zendesk refused the token. The visitor is still waiting."

### 3.5 Metrics where the owner works

Widget detail → **Results** (new tab beside the summary):

- resolution rate and handoff rate with a 30-day trend;
- conversations per day;
- top topics (from summaries);
- open knowledge gaps;
- tickets created.

**Download CSV** gives one row per day. The same data is available as
`GET /api/v1/widgets/{id}/metrics` with OpenAPI annotations.

## 4. Decisions

- [ ] **D1 (SD2).** The outcome rules of §3.1. The model only labels what rules cannot decide.
- [ ] **D2 (SD1).** The "Did this solve your question?" prompt is on by default, and the owner can turn it off.
- [ ] **D3 (SD4).** Similarity threshold 0.82, tuned on the first 500 real gaps on the hosted platform.
- [ ] **D4 (SD5).** Ticket creation "when nobody is available" (default) or "always on handoff".
- [ ] **D5 (SD5, SD6).** Zendesk and Freshdesk first; HubSpot second. Salesforce Service Cloud and OTRS / Znuny stay on the webhook until a customer asks.
- [ ] **D6 (ER7).** Whether the hosted platform offers a per-resolution price. This is a packaging decision for the umbrella and is listed here because the metric enables it.

## 5. Journeys

| Id | Journey | Profile |
| -- | ------- | ------- |
| J-SD-1 | A visitor asks about delivery times, gets an answer, presses **Yes**. The owner's Results tab counts one resolved conversation decided by the visitor | Visitor, owner |
| J-SD-2 | A visitor asks something the knowledge does not cover; the assistant says it does not know. The next morning Knowledge gaps lists "Do you ship to Switzerland?" (asked 4 times). The owner writes the answer; the next visitor gets it, and the row says "resolved 1 time since" | Owner |
| J-SD-3 | Outside office hours a visitor presses "I need a person", enters a mail address, and reads "We created ticket #48213…". The ticket in Zendesk has the transcript and the summary | Visitor, support agent |
| J-SD-4 | The Zendesk token is revoked. The next handoff shows the owner the failure sentence on the session card; the visitor still waits for a person | Owner |
| J-SD-5 | The owner downloads the October Results CSV and pastes the resolution rate into the monthly report | Owner |

## 6. Steps

| Step | Commit subject | Release class | Depends on | Gate |
| ---- | -------------- | ------------- | ---------- | ---- |
| SD1 | `feat(widget): visitors rate answers and say when their question is solved` — thumbs + reason chips, the solved prompt (D2), "I need a person" shortcut, events stored; widget works without `design-v2`; five locales | ota-candidate + widget build | D2 | `make ci-local && make test-e2e`; `make -C frontend build-widget` |
| SD2 | `feat(widget): one outcome per conversation` — `BOUTCOME`/`BOUTCOMESOURCE` (migration), rules + `tools:widget_outcome` seeded prompt, quiet-session job on the scheduler, characterization snapshot for the prompt contract | backend-only | D1, SD1 | `make ci-local` (routing snapshots must not drift — the prompt is `tools:`) |
| SD3 | `feat(widget): results tab with resolution and handoff rate` — Results tab, trend, CSV, `GET /api/v1/widgets/{id}/metrics` (OpenAPI → generate-schemas); J-SD-1, J-SD-5 | ota-candidate + backend-only | SD2 | `make ci-local && make test-e2e` |
| SD4 | `feat(widget): knowledge gaps from unanswered questions` — nightly grouping, Knowledge gaps list, Write answer → knowledge folder file + index, Ignore, the "since" counter; J-SD-2 | ota-candidate + backend-only | D3, SD2 | `make ci-local && make test-e2e` |
| SD5 | `feat(widget): create a Zendesk or Freshdesk ticket on handoff` — connection kinds, ticket adapter interface, handoff setting (D4), visitor sentence, mail prompt, failure state on the session card, `ticket.created` widget event; J-SD-3, J-SD-4 | backend-only + ota-candidate + widget build | D4, D5 | `make ci-local && make test-e2e`; walk against a Zendesk and a Freshdesk trial logged in STATUS |
| SD6 | `feat(widget): create a HubSpot ticket on handoff` — adapter + contact association | backend-only + ota-candidate | SD5 | `make ci-local` |

Every UI step carries the five exit bullets of `docs/UX_CONTRACT.md` §6
before its first `.vue`:

- **Journeys walked:** J-SD-1 to J-SD-5.
- **Ten-second findability:** Results and Knowledge gaps are tabs on the widget the owner already opens; tickets appear on the session card with the helpdesk link.
- **Consequence copy in five locales:** "Visitors will be offered a ticket in Zendesk when nobody is available. They are asked for their mail address first."
- **Empty, error and flag-off states:** no rated conversations yet → "Results appear after the first conversations. Share your widget to get started." + **Copy embed code**; no gaps → "No open questions. Your assistant answered everything it was asked this week."; no helpdesk connected → the option names **Connect Zendesk**.
- **Dark, V2 and 320 px:** the widget is also checked on a 320 px host page.

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| The model over-reports "resolved" | The tab shows the share decided by visitors, rules and the model; a visitor "No" always wins; the model only decides undecided sessions |
| Feedback controls clutter a small widget | Thumbs appear on hover or focus on desktop and as small icons on mobile; the solved prompt is once per session and can be turned off |
| Grouping merges different questions | Threshold tuned (D3); the owner sees three example questions per group before writing |
| Personal data in helpdesk tickets | The ticket carries what the visitor wrote, as with a mail to support; documented in the AVV template (ER1); masking (row 41) applies to model calls, not to tickets |
| Helpdesk API limits | One ticket per handoff; retry with backoff; the failure state is visible |

## 8. Non-goals

- A Synaplan helpdesk (ticket lists, SLAs, agent seats).
- Two-way sync of ticket replies back into the widget (a later step if customers ask).
- Phone or voice channels.

## 9. Change log

| Date | Change |
| ---- | ------ |
| 2026-11-02 | Plan written from competitor positioning (Fin, moin.ai, Zendesk AI), the Zendesk, Freshdesk and HubSpot ticket APIs, and a read of the widget session, summary, handoff and export code on `main`. No code changed. |
