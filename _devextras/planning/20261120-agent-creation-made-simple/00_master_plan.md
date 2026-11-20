# Creating an agent: describe it, don't configure it

**Status:** Plan written 2026-11-20 from the owner's request the same day
("mehr Beispiele, was man überhaupt alles damit machen kann", simpler
description and user handling). No code yet. Owner decisions in §0 are
open.
**Owner:** product owner.
**Commit class per step:** `feat:` — visible product change, minor bump
(AGENTS.md). One step, one branch, one PR. Copy in all five locales in
the same PR as the first paint.
**Binding:** UX contract U1–U12.

This plan is the layer *above* [`20261106-assistant-onboarding-ux`](../20261106-assistant-onboarding-ux/00_master_plan.md)
(row 44, steps AX1–AX15, still unstarted; its §0 decisions D1–D3 are
still open and are **not** re-decided here). Row 44 fixes the builder
from inside. This plan changes how a person *arrives* at a working
agent: examples first, one sentence in, a tested agent out. Where a step
below needs an AX step (form restructure, Activate vs Publish), it says
so instead of duplicating it.

Already shipped and **not** to be rebuilt:

| Piece | State | Evidence |
| ----- | ----- | -------- |
| Template gallery, eleven built-in templates | on `main`; picker is the first screen of "Create agent" | [#2939](https://github.com/metadist/synaplan/pull/2939), `frontend/src/components/assistants/TemplateGallery.vue`, `AssistantsView.vue` (`pickingTemplate`) |
| Objects are called agents | on `main` | [#3214](https://github.com/metadist/synaplan/pull/3214) |
| Delivery is an explicit choice (chat / email to me), self-mail needs no approval, card says where the result goes | on a branch, PR open | [#3219](https://github.com/metadist/synaplan/pull/3219), `SavedTaskDelivery` |
| "Email me" hidden as one of twelve skill chips (finding F9 of row 44) | fixed by #3219 on the schedule path | — |

---

## 0. Decisions for the owner

- [ ] D1 — The primary creation path is **one sentence**: "Describe your
  agent" generates name, description, instructions, triggers and
  delivery; the seven-section builder becomes the *edit* surface, not
  the *create* surface. Recommended: yes.
- [ ] D2 — Examples are framed as **outcomes**, not templates: cards say
  what you get ("Every morning at 7: an open-source briefing in your
  inbox"), grouped by trigger (on a schedule / when mail arrives / in
  the chat). Recommended: yes; the eleven templates get outcome titles,
  their content stays.
- [ ] D3 — **Description and greeting are generated**, not typed. The
  short description is only required when an agent is *shared*; it is
  asked at share time, prefilled, editable. Recommended: yes.
- [ ] D4 — **"Runs as" is hidden for personal agents** (always: as you).
  It appears only for group/department agents, worded as one plain
  sentence ("Mail is sent in your name" / "from the shared mailbox").
  Recommended: yes.
- [ ] D5 — Order: S1 (examples) and S4/S5 (fields and runs-as) need no
  backend work and can land first; S2 (generate from a sentence) is the
  headline and needs D1; S3 (three-field fast create) depends on row
  44's form decisions (its D2/D3) and should not start before those are
  ticked. S6/S7 are independent. Recommended order: S1, S4, S5, S2, S6,
  S7, S3.

## 1. Where creation stands today

Counted 2026-11-20 on `main` (bf637ae73) plus branch
`feat/task-delivery-target`.

| Surface | Today | Gap |
| ------- | ----- | --- |
| Create entry | `/ai/agents` → "Create agent" → template picker (`TemplateGallery`) or "Start empty" → `AssistantBuilder` with seven sections (Basics, Instructions, Models, Knowledge, Tools & Skills, Triggers, Publish) plus test panel | The picker is framed as *templates*, not as "what you can do"; "Start empty" drops into the endless form (row 44 F1) |
| Examples | Eleven built-in templates exist (#2939) | They read as starting points for builders, not as inspiration; a newcomer cannot tell what an agent is *for* from the gallery page itself |
| Text fields | `BuilderBasics` asks Name, Description (3 rows), Greeting as three separate inputs before anything runs | Three fields for one thought; the description's purpose (shown when shared) is invisible at create time |
| Identity | Triggers speak of runs-as-you / runs-as-person / runs-as-app-user (`assistants.triggers.runsAs*`) | An identity concept a personal agent never needs, shown to everyone |
| Agent card | `AssistantCard` (gallery) | No one-sentence statement of what the agent does, when it runs and where results go — the task card learned this in #3219 (`SavedTaskSummary` codes), the agent card has no equivalent |
| Schedules | Builder schedules materialize as Saved Tasks (`AgentTriggerMaterializer`), keyed with random schedule ids | Deleting the task does not remove the draft trigger, so the next publish recreates it — the owner met three near-identical tasks on `/tasks` on 2026-11-20. The schedule belongs to the agent page, the task is an implementation detail |

## 2. The bar

The acceptance journey J-C1, walked in the browser before any step is
called done:

> From `/ai/agents`, a person who has never built an agent reaches a
> **tested, active** agent for "every morning at 7, mail me the
> open-source news" in **at most three interactions** after choosing
> how to start (pick the example, or type the sentence), without seeing
> the words *model*, *skill*, *publish* or *runs as*, and the agent
> card afterwards states in one sentence when it runs and where the
> result goes.

Every step below either removes an interaction from J-C1 or makes one
of its screens say plainly what happens.

## 3. Steps

### S1 — Examples as outcomes (D2)

Reframe the existing picker and the gallery empty state: the eleven
templates get outcome titles and a one-line promise each (schedule,
reads, delivers — the same three-part sentence the task card renders
from `SavedTaskSummary` codes). Grouped: *On a schedule*, *When mail
arrives*, *In the chat*. One click installs the template as today
(checklist flow stays). No backend change; copy in five locales.
**Done when:** the picker answers "what can I do with this?" without
the word *template* in the first paint, and J-C1's first interaction is
picking the briefing card.

### S2 — Describe it in one sentence (D1)

A "Describe your agent" field at the top of the create picker. The AI
Setup Assistant turns the sentence into a complete draft: name,
description, instructions, suggested triggers (schedule/mail/chat) and
delivery (reusing #3219's `SavedTaskDelivery` choices, never inventing
a second store). The person lands in the builder with everything
prefilled and the test panel focused; nothing is active until they
press Activate (row 44 D2 wording) or save. Generated parts are marked
as generated and editable in place.
**Done when:** typing J-C1's sentence produces a draft whose schedule
(07:00, account time zone) and delivery (email) match the sentence, and
the first test run can be started with one more click.
**Ask first:** none — it composes existing pieces (setup assistant,
agent draft validation, delivery). No new provider, no schema change.

### S3 — Three-field fast create (needs row 44 D2/D3 ticked)

"Start empty" becomes: *What should it do?* (one textarea) + *Where
does it work?* (chat / schedule / mail, with the delivery choice when
scheduled). Name is suggested from the first words and editable inline.
Creates the draft and opens the test panel; Models, Knowledge and
Tools & Skills move under an "Advanced" disclosure (this is row 44's
AX10 territory — implement once, there, and let this step consume it).
**Done when:** J-C1 via "Start empty" needs no section scrolling and
no field the person cannot explain afterwards.

### S4 — Description and greeting generate themselves (D3)

On create, description and greeting are derived from the instructions
(one short model pass or first-sentence extraction — implementer's
choice, but deterministic in tests). Both stay editable under
Advanced. When an agent is shared or published to a group, the share
dialog shows the generated description and asks for confirmation —
that is the first moment anyone must read it.
**Done when:** `BuilderBasics` shows no empty description/greeting
fields on a fresh draft, and sharing an agent without a description
asks exactly once, prefilled.

### S5 — "Runs as" only where it matters (D4)

Personal agents: the choice is removed; the agent runs as its owner,
 and the triggers section says so in one line ("Runs as you"). Group
 and department agents keep the choice, reworded to consequences:
 "Sends mail in your name" / "Sends mail from the shared mailbox" /
 "Only reads, never sends". The stored values (`runsAs*` keys) do not
 change; only the surface and its timing do.
**Done when:** a personal agent's builder contains no identity
vocabulary, and the department form explains the choice without the
word *identity*.

### S6 — One honest sentence on the agent card

`AssistantCard` gains the task card's sentence, derived from the draft
the same way `SavedTaskSummary` derives it from a graph: when it runs,
what it reads, where the result goes (delivery via #3219's derivation,
not a new field). Drafts say "Not active yet" instead of a schedule
they do not have.
**Done when:** every gallery card can be understood without opening
the builder, and the sentence matches what a test run actually does.

### S7 — Schedules live on the agent, once

Builder schedules keep one stable identity across publishes (no
recreation after a task-side delete: deleting the materialized task
either removes the draft trigger or the card says it will come back —
owner picks in review; recommended: deletion on `/tasks` offers
"also turn off this schedule on the agent"). The agent page lists its
schedules with next run and the same delivery switch as the task card.
**Done when:** the 2026-11-20 situation (three near-identical tasks,
unclear which is live) cannot be reproduced by publishing twice and
deleting once.
**Ask first:** this touches `AgentTriggerMaterializer` keying — no
schema change expected, but it is behaviour shared with mail rules;
confirm before implementing.

## 4. Non-goals

- No rename of wire identifiers, i18n file names or `data-testid`
  values (same line as the agents rename, D7 there).
- No new AI provider, no new built-in templates in this plan (content
  is a separate editorial pass once S1 shows which outcomes land).
- No database schema change. If a step appears to need one, it stops
  and asks (AGENTS.md ask-first).
- Row 44's insides (sticky test panel, search honesty F2–F5, single
  knowledge entry) stay row 44's. This plan only changes how a person
  arrives and what the cards say.

## 5. Registration

Wants a ROADMAP row from the owner (next free number after 46). Until
then this folder is the plan of record for "agent creation made
simple"; row 44 remains the plan of record for the builder's insides.
