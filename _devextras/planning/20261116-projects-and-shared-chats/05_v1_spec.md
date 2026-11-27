# Projects v1: the spec

**Status:** 2026-11-27. Owner decisions from a discussion of the plan
(`00` §0 #120–#130). Where this page and `00`–`04` differ, this page wins
until those files are brought in line in the step that builds the part.
**Builds on:** [`00_master_plan.md`](./00_master_plan.md) §0 #96–#119.

## 1. The bet

One question decides everything after v1: **does the AI keep the board
right enough that people trust it and come back to the project?** Mail,
calendar, push, watches, guests and sharing all stand on that. v1 answers
the question with as little as possible and measures the answer (§6).

## 2. What v1 is

One person. A project page with the chat on the left and the board on the
right. The board fills itself from the chat. Households first (§0 #128).

| In v1 | Step |
| ----- | ---- |
| A chat area that a page can embed | PJ29 |
| Project items: task, date, decision, cost, note (§4) | PJ30 |
| The board fills itself from the chat, in three stages (§3) | PJ31 |
| Project page with chat and board side by side | PJ32 |
| A written status and a first plan from two questions; the plan is a proposal (§3) | PJ33 |
| "What I remember" as the list of notes, and the memory choice per chat | PJ22 (reduced, §4) |
| Search all chats of a project | PJ34 |
| Counting what people do with the board (§6) | in PJ31 and PJ32 |

| Not in v1 | When |
| --------- | ---- |
| Sharing a project, project budget | Second step (§5) |
| Reminders, weekly overview, mail preview, calendar subscription (phase D) | Third step (§7) |
| Guest link, apps in shared chats, push, forwarded mail, watches | Each decided on the numbers of §6 |
| "Can write" in plain chats | Later, maybe (§0 #124) |
| `BPROJECTMEMORIES` | Not built (§4) |

## 3. Writing to the board: three stages

The line is not the kind of item. It is one question: **did the person say
it as a fact or a commitment, or did the AI derive it?**

| Stage | When | What the person sees |
| ----- | ---- | -------------------- |
| **1 · Written** | The person says it plainly and bindingly ("I'll deal with the measurer on Monday", "Measuring is now Tuesday", "Paid the 8,400 € deposit"), or says yes to a proposal ("Yes, please") | The item is on the board at once; under the message "Added: … · Undo" |
| **2 · Proposed in the chat** | The AI derives or suggests it, or the person hedges ("we'll probably take Steinwerk", "should ask the electrician"); the first plan of a project | Under the message "Add?" with one tap; the same item under "To confirm" with the source "from the chat" |
| **3 · To confirm** | Everything from outside: a forwarded mail, a file, a watch; later a member's message about someone else | A card under "To confirm" |

Rules that hold in every stage:

- **A proposal stays visible until someone decides.** It does not expire.
  "To confirm" offers "Reject all" per source, so the list never becomes a
  chore.
- **A decision is never stage 1.** "We'll take X" is often thinking aloud,
  and a wrong decision costs most because later answers argue from it.
- **Closing is never silent.** Done, dropped and deleted are always
  proposed (§0 #75).
- **An estimated cost is stage 2** ("about 500"). A paid or fixed amount
  from the person is stage 1.
- **Only confirmed items are facts.** The open items block of a turn
  (§0 #108, #115) holds items in state `open` or `done`. A `proposed` item
  is never given to the model as a fact, so a wrong proposal cannot
  repeat itself through later answers.
- **The first plan is one proposal.** The steps show as a list with "Add
  all" and remove per step (changes J-PJ-12).
- **The answer claims nothing.** The receipt line is the only confirmation
  (§0 #114).

This replaces §0 #75 and #113 with one rule. G14 also checks the stage: the
expensive mistake is a derived statement written as stage 1.

## 4. One store for board and memory

A project has one store, `BPROJECTITEMS`. Project memory is not a second
table.

- **A fifth kind, `note`:** a fact or a preference ("The niche is
  560 × 590 mm", "Ben does not like green"). A note has no due date and
  states `proposed`, `open` (active) and `dropped`. It has author, source,
  visibility (§0 #99), the item log and the stages of §3, like every item.
- **Where it shows:** "Remembered" behind "Show more" on the overview, as
  in the mockup (§0 #123). "More → What I remember" lists all notes; each
  can be corrected or deleted.
- **In a turn:** notes get their own budget next to the open items block.
  Up to a threshold (start: 60 notes or 1,000 tokens) all go in; above it
  the most relevant are searched, as personal memory does today.
- **Conflicts** (§0 #52) need no store of their own: "Ben does not like
  green" is in the context when Ana asks for green fronts. G12 runs on
  notes.
- **Unchanged:** personal memories are never copied in (#51); the choice
  "Project memory only" / "Project memory and my memories" per chat (#53,
  #54); who may delete what (#56) follows the items.
- **Consequences:** `BPROJECTMEMORIES` is dropped from `03` §2; PJ21 is
  not built; PJ22 becomes the notes list plus the memory choice; the one
  extraction call of #96 returns items only, so #98 ("one statement, one
  place") is true by construction. A wrong kind is one click to change,
  never two entries.

## 5. After v1: sharing with the partner

The common case is the partner who chats along, not the tradesman who needs
a date and a file (§0 #127).

- **Writing together happens in projects only** (§0 #124). Plain chats keep
  "Can read" and "Can continue as a copy". "Can write" in a plain chat may
  come later.
- **What a shared project needs anyway:** an author per message, names and
  avatars, "Reply to {name}", `@Name` keeps the AI quiet, one run per chat
  with a queue, personal memories off in shared chats (§0 #35–#37, #41).
- **Apps** (§0 #125): off in shared chats of a project in this step. On in
  a member's private chats inside a shared project (#55), whose items stay
  private (#99); sharing such a chat shows the warning of #42. Apps in
  shared chats, with the per-person switches of #43–#46, follow after this
  step.
- **Project budget** (§0 #126), replacing the chat allowance of #39:
  - The creator sets an amount per month for the members of a project.
  - The creator's own use never counts against it.
  - When it is used up, a member's messages draw on their own budget,
    automatically.
  - A member without an own budget gets one sentence, not an error: "The
    budget for this project is used up for this month. {name} can raise
    it." The creator gets a notice.
  - Members see a bar and a percentage (#40); the creator sees the amount
    and the use per member.
  - Reserving per model call stays atomic (`03` §5), keyed by project.
- **Guest link, later** (§0 #127): a person without an account (measurer,
  electrician) opens a link and sees only the dates and tasks that name
  them. They confirm or decline a date, upload a file and tick a task. No
  chat, no costs, budgets or other items. What their actions cost (reading
  an uploaded PDF) is counted to the creator.

## 6. What counts as success

The thresholds are set before v1 ships, so no result is talked up later.
They are starting values; the owner adjusts them after the first weeks.

| Question | Measure | Green when |
| -------- | ------- | ---------- |
| Is the board right? | Undo rate of stage-1 items | below 10 % |
| Are the proposals useful? | Accept rate of stage-2 and stage-3 proposals | above 60 % |
| Do people want it? | Projects still used in week 3, with board against without | clearly higher with board |
| Is it affordable? | G20: project turns against the same turns outside | at most 15 % dearer |

**What follows:**

- All green → build §5.
- The board is wrong (undo high) → build nothing new; fix the extraction
  first (model, prompt, the stage line).
- The board is right but nobody looks → the board is not wanted. Rethink
  before anything of §7 is built on it.

**How it is counted.** Synaplan has no product analytics today. None is
added. Server-side counts only, never content:

- `BPROJECTITEMLOG` (`03` §2.9) gets the actions `accepted`, `rejected`
  and `undone` and a column `BSTAGE` (1, 2, 3). Undo and accept rates come
  from it.
- Activity in week 3 comes from the timestamps of messages in project
  chats.
- One new counter: the board was opened (project, day, count).
- `PROJECTS.BOARD_ENABLED` is switched on for part of the people who use
  projects, so "with board" and "without board" can be compared.

**How to get there:** two to four weeks of real use in the team (a move, a
renovation, a client project), then a beta behind the flag.

## 7. Order

1. **v1** (§2), measured (§6). Phase B goes on beside it; the board does not
   wait for the loop (§0 #96). The first plan needs the question card (PJ9).
2. **Sharing with the partner and the project budget** (§5).
3. **Remind and write back** (phase D): reminders and the weekly overview
   by e-mail, the mail preview, the calendar subscription.
4. **Each decided on the numbers:** guest link, apps in shared chats, push,
   forwarded mail, watches.

This refines §0 #107 and #119.

## 8. Still open

- **The extraction model** (§0 #109). Matching against open items (#112),
  relative dates, the person and the stage in five languages is the
  hardest call in the feature. G14 runs on the memory model and on the
  Chat model before PJ31 picks one.
- **When the status text is written** (§0 #95). Proposal: when the page is
  opened and the text is older than the last change or the day, instead of
  a morning call for every project with open items.
- **A mockup of v1:** one person, no price watch, no classified ads, no
  second member; the tabs of #102; the quick-add line of #100; the second
  case of #110.
- **Superseded rows in `00` §0** (#83, #88, #90 and the rows this page
  replaces) are folded into their replacements, so a reader sees one
  answer per question.
