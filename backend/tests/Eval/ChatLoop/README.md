# Chat-loop corpus

Fixed turns and what they cost and deliver, for roadmap row 46
([plan](../../../../_devextras/planning/20261116-projects-and-shared-chats/00_master_plan.md),
step PJ1). Gates G1–G7 of that plan compare against numbers from this
runner, not against memory.

| File | What |
| ---- | ---- |
| `corpus.json` | 40 turns in five groups: `simple` (no tool needed), `search` (one search), `files` (the chat's files), `multi_step`, `research` (taken from `../research/corpus-hard.json`) |
| `fixtures/` | Synthetic documents the `files` and `multi_step` turns attach. No customer data. |
| `scripted/long-chat-150.json` | One chat of 150 messages with a picture at message 5 and a fact at message 10, then two probes (G6) |
| `scripted/stub-*.json` | A search that always returns the same and a tool that always fails (G5). They need the loop of PJ5 to plant the stub; until then they are only validated. |
| `run.php` | The runner |

## Run

The runner signs in like the web client and sends every turn through
`POST /api/v1/messages/stream`, each in a chat of its own. Afterwards it
deletes the chat and the files it attached, stops a research run it no
longer waits for, and signs out. What it could not remove it names on
stderr. It needs an account on the stack and that account's password in
`CHAT_LOOP_EVAL_PASSWORD`.

```bash
docker compose exec -T -e CHAT_LOOP_EVAL_PASSWORD backend \
  php tests/Eval/ChatLoop/run.php --base-url=http://localhost --email=<account> \
  --report=var/chat-loop-eval/today.md --answers=var/chat-loop-eval/today.answers.json
```

| Option | Meaning |
| ------ | ------- |
| `--base-url` | Origin of the stack. Plain HTTP is accepted for this machine only. |
| `--email` | The evaluation account. Use one made for this: its usage rows stay, and a run that breaks off can leave chats named "chat-loop eval …". The report prints `--email=<account>`, not the address. |
| `--group`, `--only` | One group, or a comma-separated list of turn ids |
| `--script` | Run a conversation from `scripted/` in one chat instead of the corpus, for example `long-chat-150`. Not together with `--group` or `--only`. |
| `--live` | Required when the account does not answer with the built-in test models. Without it the run refuses to start, and stops at the first turn the account was charged for. |
| `--turn-timeout` | Seconds the runner waits for one turn (default 1800). It is the runner's patience, not a limit of the product. |
| `--report`, `--answers` | Where to write the Markdown report and the answers for `app:research:citations` |
| `--keep-chats` | Leave the chats and their files in the account |

An argument the runner does not know ends it before anything is sent, so a
mistyped `--group` cannot turn into a run of the whole corpus.

The run exits with 1 when a turn has no terminal result: the stream closed
without `complete` or an error, or a research run was still working at the
timeout. The numbers of such a run do not count.

**Spending.** Without `--live` the runner reads the account's default models
and refuses to start unless sorting, chat, memories, file reading and (for
`--script`) picture models are the built-in test models. It cannot read the
instance's planning, tool and summary models, and a search provider is not a
model; so a run without `--live` also stops at the first turn with a cost
above zero and exits with 1.

**What is measured.** Cost is what the account was charged, from the `usage`
and `usage_extra` of the `complete` event plus the research run's `cost.spent`.
Time to first token is the first non-empty `data` chunk; the clock starts when
the turn is sent, after its files are uploaded. The answer is the
bubble; for a turn the planner split into steps it is the card of the plan's
reply step (`task_chunk`, `task_update`). Recall is the share of a turn's
`expect` assertions found word for word in that answer. Backed
statements come from the research run's own fact-check; to judge the
citations of every answer with models, pass the `--answers` file to
`bin/console app:research:citations`.

Not part of the PHPUnit gate: live models cost money. The corpus shape and
the runner's rules are tested in `tests/Unit/Eval/ChatLoop/`.
