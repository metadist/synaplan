# Projects and shared chats — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| PJ0 | merged | #3133 | plan written 2026-11-16; §0 partly decided, see below |
| PJ1 | in review | #3141 | corpus and runner; today's numbers from the local stack, see 2026-11-16 below |
| PJ2–PJ28 | not started | — | the owner ordered phase 0 on 2026-11-16; every ☐ and Ask row of §0 still waits |

---

## 2026-11-16 — corpus and runner (PJ1)

**Ordered:** the owner asked for the first steps on 2026-11-16. PJ1 needs no
☐ row and touches no product code.

**What is there:** `backend/tests/Eval/ChatLoop/` — `corpus.json` (40 turns:
10 simple, 8 search, 8 files, 8 multi-step, 6 research; all five locales),
four synthetic documents, three scripted chats (150 messages with a picture
at message 5 and a fact at message 10; a search that always returns the
same; a tool that always fails), and `run.php`. The research turns take
question and gold facts from `tests/Eval/research/corpus-hard.json`, so G2
scores the same words as row 21.

**How it measures:** it signs in like the web client and sends each turn
through `POST /api/v1/messages/stream`. Cost is `usage` and `usage_extra` of
the `complete` event plus `cost.spent` of the research run a turn hands over
to; time to first token is the first non-empty `data` chunk; backed
statements are the research run's own `check` counts, and `--answers`
writes a file for `app:research:citations`. The run exits 1 when a turn has
no terminal result.

**Deviation from the brief:** the two stubs are data only. Planting a tool
stub needs the loop of PJ5; PJ6 wires them.

**Walked on the dev stack** (demo account, test models), 2026-11-16:

```bash
docker compose exec -T -e CHAT_LOOP_EVAL_PASSWORD backend \
  php tests/Eval/ChatLoop/run.php --base-url=http://localhost \
  --email=demo@synaplan.com --only=simple-01,simple-02
```

Both turns answered at no cost, the chats were deleted, the session was
signed out. The test provider has no price and echoes; these are not
today's numbers. With `files-03` added the run stops with exit 1 after that
turn: the planner split it into steps and the instance's planning model, a
real one on this stack, charged 0.0025. The account's own defaults do not
show that model, so the runner stops a run without `--live` at the first
charged turn. The chat and the uploaded file were removed (files and chats
of the account counted before and after).

**Review of the runner, 2026-11-16:** the session is refreshed after the
five-minute access cookie runs out; sorting and planning cost is read from
`usage_extra`; a planned turn's answer is read from its reply card;
uploaded files are deleted with the chat; a research run nobody waits for
is cancelled; an unknown argument ends the run before anything is sent.

**Today's numbers, 2026-11-16.** One run with `--live` on the local
development stack at `main` plus this branch, signed in as the seeded admin
account, whose Chat, Sort and Analyze model is Claude Sonnet 5.5. All 40
turns ended with an answer; none failed and none stayed without a terminal
result.

| Group | Turns | Answered | Cost total | Cost median | First token median / p95 (s) | Total median / p95 (s) | Recall | Backed statements |
| ----- | ----- | -------- | ---------- | ----------- | ---------------------------- | ---------------------- | ------ | ----------------- |
| simple | 10 | 10 | 0.3047 | 0.0301 | 2.56 / 3.67 | 3.64 / 5.47 | 100.0 % | n/a |
| files | 8 | 8 | 0.0417 | 0.0036 | 3.18 / 4.11 | 3.88 / 4.49 | 100.0 % | n/a |
| search | 8 | 8 | 0.3385 | 0.0389 | 4.99 / 5.72 | 7.09 / 10.25 | 100.0 % | n/a |
| multi_step | 8 | 8 | 0.5419 | 0.0187 | 2.45 / 4.13 | 11.51 / 86.74 | 100.0 % | 30.8 % of 13 |
| research | 6 | 6 | 0.2721 | 0.0435 | 4.49 / 6.20 | 6.71 / 8.61 | 100.0 % | n/a |

Commands, from `backend/`, password in `CHAT_LOOP_EVAL_PASSWORD`:

```bash
php tests/Eval/ChatLoop/run.php --base-url=http://localhost --email=<account> --group=<simple|files|search|multi_step> --live
php tests/Eval/ChatLoop/run.php --base-url=http://localhost --email=<account> --only=research-0N --live --turn-timeout=1500
```

The research turns ran one at a time with a look at the cost ledger before
each. The runner counted 1.4989 for the 40 turns, which is the provider
cost plus the 10 % markup the turn reports. The ledger holds provider cost
without markup and shows 1.5767 for the account over the same time: it also
has the calls after the turn, which are not in the turn's `complete` event
(message digest 0.1377, memory extraction 0.0360).

What the numbers say about today:

- **G1 compares against the `simple` row**: 0.0301 per turn, first token
  after 2.56 s, done after 3.64 s.
- **No research turn was handed to a research run.** All six were answered
  as an ordinary search turn in six to nine seconds, so the group has no
  backed statements today. Three `multi_step` turns did hand over (54 to
  93 s) and their fact-check backed 30.8 % of 13 statements.
- **Sorting is most of a `simple` turn.** From the ledger, per turn:
  sorting 0.0223 (10,700 prompt tokens on the account's Sort model, Claude
  Sonnet 5.5, none of them cached), the answer 0.0045 to 0.0072 (2,100
  prompt tokens), and after the turn a message digest of 0.0081 and at
  times a memory extraction of 0.0045. The sorter is 27 calls and 0.6030
  of the run. The fast path that answers a short plain message without the
  sorter exists and is off by default (`CLASSIFIER.FAST_PATH_ENABLED`),
  because its heuristic sent media requests to chat.
- **A `files` turn costs a tenth of that because it is not sorted.** An
  attachment routes the turn before the sorter; the planner then runs on
  `gpt-oss-120b` with a cached prompt for 0.0015, and the answer needs 500
  to 2,600 prompt tokens. The two groups take different paths; the calls
  are counted the same way.
- **For G1 this means** the bar of 0.0301 is mostly the sorter on this
  account's Sort model. A loop that replaces the sorter for a simple turn
  is compared against it; an account with a cheaper Sort model has a lower
  bar. The gate run on staging states the Sort model with the numbers.

Not run: the 150-message scripted chat (G6) and a run on staging. Local
numbers are one machine and one run; the gate run for G1–G7 repeats this on
staging.

---

## 2026-11-16 — plan written (PJ0)

**What the owner decided** in the sessions of 2026-11-12 and 2026-11-13
(marked "Yes — owner" in §0): projects without templates, sources indexed
once; the model decides how long and how it works; no fixed time, no
maximum of steps or rounds; a cost limit and circle detection as the only
guard rails, both ending with a result; web chat and projects, not the
widget; the Chat model for everything, no list of verified models; notes
with a visible "Conversation summarized"; files usable by ID for the whole
chat; a question card with options and free text, several questions, only
the starter answers in a shared chat; chats that several people write in;
sender pays, or the owner sets an amount that only the others draw from,
with a bar and an automatic switch back; no personal memories in shared
chats; apps off by default, per person and per chat, usable only by their
owner; a chip that names whose app data an answer contains; project memory
that names its member and comes only from the project; conflicts pointed
out by the model; the memory choice in a private project chat; push on the
phone, nothing in the browser.

**What is open** (☐ in §0), by weight:

1. Ask-first items: the schema (#63), the push plugin (#61), the relay
   service (#62).
2. Phase B waits for AU26 and AH8, and changes three rows of row 25 for
   chat (#22, #23).
3. The value of the cost limit and the token cap for unpriced models
   (#12, #14).
4. Shared chats: does the AI answer every message (#36), one run per chat
   with queuing (#37), the sender-only check before an app is used
   (#45), writes always ask (#46), the sender's model (#67).
5. Notion "Only these pages" after a spike (#49).
6. Private chats first in a shared project (#55); the default of the
   memory choice (#54).
7. Removing the planner card from Settings → AI Models (#20).

**Corrections against earlier statements in the chat:**

- Dropbox has no read path (save only). There is nothing to narrow.
- WebDAV reads are already limited to the connection's folder; a grant
  narrows further.
- The rolling summary uses the `ANALYZE` model, else the Chat model. The
  `SUMMARIZE` row is not read at runtime.

**Code map:** `main` @ `0d228e323`. Facts and paths in `01_research.md`
§1. Not found in the code: `AgentToolLoop`, `App\Service\Run`, `BRUNS`
(planned in rows 22 and 25), anything named project, a push plugin in
`synaplan-apps`.

**Gate for this PR:** `_devextras/**` only, class `no-app-impact`.
