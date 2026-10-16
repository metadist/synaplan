# S11 — Strict types on the message turn

| | |
| - | - |
| **V3 §7** | #11 |
| **Branch** | `refactor/cleanup-v4-s11-strict-types`, from S10's branch |
| **Pull request** | `refactor(message): strict types on the message turn` |
| **Class** | backend-only |
| **Owner decision** | — |
| **Chore** | C11, which also closes V4 (§7) |

## 1. Goal

The message turn stops relying on PHP's silent type coercion. Values that
arrive as the wrong type are converted once, where they enter, by code
that says so. Then the files declare strict types, so a new coercion
fails a test instead of a user's turn.

## 2. Measured (2026-10-16)

`MessageProcessor.php` and five of the six files in `Message\Processing\`
(`LinkedContentReader`, `TurnClassificationResolver`, `TurnDispatcher`,
`TurnHistoryLoader`, `TurnWebSearch`) declare no strict types; only
`TurnStatus` does. V3's K7 kept it that way on purpose. With strict types
on, the first snapshot run failed: a tracking id arrived as an `int`
where a `string` is declared. Prompt-metadata flags read from the database
can do the same. Across `src/`, 1,598 of 1,757 PHP files declare strict
types.

## 3. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S11.1 | `test(message): turn inputs in the types callers really send` | Extend `MessageTurnCharacterizationTest` and the stage unit tests with the input types seen in production: tracking ids as `int`, model ids as numeric strings (query parameters, `BMESSAGEMETA`), prompt-metadata flags as `'0'` / `'1'`, a missing language. Then turn strict types on in a scratch commit (not pushed), run the unfiltered suite and list every `TypeError` in STATUS. That list is the scope of S11.2. |
| S11.2 | `refactor(message): convert turn inputs once, at the entry` | A `Processing\TurnOptions` reader (`final readonly`, typed getters) built once in `process()` / `processStream()`. A typed accessor for prompt-metadata flags. Each conversion handles exactly one listed `TypeError` and carries the test that showed it. |
| S11.3 | `refactor(message): strict types across the message turn` | `declare(strict_types=1)` in the six files. |
| C11 | `chore(planning): close V4 S11 and code cleanup V4` | Loop §7, plus §7 below |

## 4. Sprint test

- **Proof:** the S11.1 tests are green under strict types; each was red in
  the scratch run with its `TypeError` quoted in STATUS. Mutation check:
  remove one conversion and see its test fail with a `TypeError`.
- **Regression:** `message_turn.json`, `sse_transcript.json`,
  `routing_classification.json` unchanged; unfiltered gates.
- **Journey:** the full `make test-e2e`. This is the last sprint and the
  whole chat path runs under strict types.

## 5. Reviewer focus

Conversions that hide bad input: an id that is not numeric must fail
loudly at the entry, not become `0`.

## 6. Exit

- Every file under `Service/Message/` that the turn runs through declares
  strict types: `rg --files-without-match 'declare\(strict_types=1\)'
  backend/src/Service/Message/Processing
  backend/src/Service/Message/MessageProcessor.php` prints nothing.
- No snapshot moved.

## 7. Closing V4 (part of C11)

- STATUS: the exit of master plan §4, item by item, with the evidence.
- `ROADMAP.md` row 19: all twelve merged, with their pull requests.
- After S11 merges: one `docs(planning): archive code cleanup V4` pull
  request moves this folder to `2026-archive/` with a dated note, and
  re-points `docs/ARCHITECTURE.md`. It is reviewed and green like every
  other pull request in V4.

## 8. Not in S11

Classification and prompt metadata as value objects across every handler
(V5). Strict types outside the message turn.
