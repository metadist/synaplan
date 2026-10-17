# S3 — One topic and intent rule

| | |
| - | - |
| **V3 §7** | #3 |
| **Branch** | `fix/cleanup-v4-s3-topic-intent`, from S2's branch |
| **Pull request** | `fix(message): one topic and intent rule for model overrides and Again` |
| **Class** | backend-only |
| **Owner decision** | **D2** — which semantics win (§2). Tick before step S3.2. |
| **Chore** | C3 |

## 1. Goal

"The user picked model X: which topic and which intent?" gets one answer,
written once. Today it is written twice and the two copies disagree. A
model override in the classifier and a retry (Again) in the processor
route the same tag differently.

## 2. Measured (2026-10-16) and D2

| Rule | Classifier (`MessageClassifier`) | Again (`Processing\TurnClassificationResolver`) |
| ---- | -------------------------------- | ------------------------------------------------ |
| tag → topic | `mapModelTagToTopic()` (line 1,100): media tags → `mediamaker`; `pic2text`, `analyze`, `chat`, `vectorize` → `general`; else the message topic, else `general` | `topicForModelTag()` (line 465): media tags → `mediamaker`; `pic2text`, `analyze`, `vision` → `general`; `document`, `officemaker`, `text2doc` → `officemaker`; else the previous topic, else `chat` |
| topic → intent | `mapTopicToIntent()` (line 1,069): `SystemCapabilityRegistry::topicToIntentMap()` (`general` → chat, `mediamaker` → image generation, `officemaker` → document generation, `docsummary`, …), then aliases (`text2*`, `tools:pic`, `tools:vid`, `tools:tts` → image generation; `pic2text`, `analyze`, `analyzefile` → file analysis) | `intentForAgainTopic()` (line 455): own `match`; `officemaker` → document generation; `tools:pic` and every registry topic it does not list → chat |

**D2 proposed — the classifier's semantics win, plus one policy:**

| Input | One rule |
| ----- | -------- |
| tag `text2pic` / `text2vid` / `text2sound` | `mediamaker` |
| tag `pic2text` / `analyze` / `vision` | `general` (vision is answered by `ChatHandler`) |
| tag `document` / `officemaker` / `text2doc` | `officemaker` |
| tag `chat`, `vectorize`, unknown | **the topic it is given**, else `general`. Empty, `unknown` and `UNKNOWN` (the default topic of a new `Message`) count as none, case-insensitively. The policy: a chat model keeps a custom prompt. |
| topic → intent | the capability registry, then the aliases; unknown → chat |

What moves:

- **Again:** a `/pic` message regenerates the image instead of chatting;
  `docsummary` and every other registry topic use the registry's intent;
  an unknown tag falls back to `general` instead of `chat`.
- **Classifier, document models:** an override with a document model
  reaches the document handler.
- **Classifier, `chat` / `vectorize` models:** today the classifier maps
  both straight to `general` and drops a topic the message already
  carries, a custom prompt or a media topic (`mapModelTagToTopic()`,
  lines 1,108–1,112). Under the rule that topic is kept. This is a real
  routing change for messages that carry a topic, so it gets its own
  `fix:` commit with a reviewed routing diff (S3.5).
- **Classifier, `vision` models:** the classifier has no `vision` row, so
  an existing topic such as `mediamaker` survives the override. The rule
  says `general` (S3.6).
- **Classifier, unmapped tags:** the fallback `$fallbackTopic ?: 'general'`
  keeps `UNKNOWN`, the default topic of a new `Message` (`Message.php`
  line 63), because the string is not empty. The rule treats it as no
  topic and answers `general` (S3.7).

Alternative the owner may pick instead: the Again semantics win. That
keeps the document route but sends `tools:pic` retries to chat. It is not
recommended: a retry that changes what kind of answer comes back is the
bug this sprint exists to close.

## 3. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S3.1 | `test(message): pin both topic and intent rules side by side` | Table test over every tag and topic above, against both private copies, on the parent. Add routing cases for a model override per tag to `RoutingCharacterizationTest`, **each with and without a topic already on the message** (a custom prompt topic, `mediamaker`), and the fallback inputs: an empty topic, `unknown`, `UNKNOWN`. Add turn cases for Again per tag to `MessageTurnCharacterizationTest`. |
| S3.2 | `refactor(message): one owner for the topic and intent tables` | `App\Service\Message\Capability\TopicIntentRule`, built on `SystemCapabilityRegistry`. It moves both copies **unchanged**: today's two policies sit side by side as named methods (`forModelOverride()`, `forAgain()`), so no snapshot moves. The private copies in both callers are deleted. |
| S3.3 | `fix(message): Again routes by the same topic and intent rule` | Again switches to the D2 rule. `message_turn.json` diff: the Again cases only. |
| S3.4 | `fix(message): a document model override reaches the document handler` | The classifier gets the document row. `routing_classification.json` diff: the document-override cases only. |
| S3.5 | `fix(message): a chat model override keeps the message's topic` | The classifier's `chat` / `vectorize` row keeps a given topic. Routing diff: exactly the S3.1 cases that carry a topic. |
| S3.6 | `fix(message): a vision model override routes to the chat answer` | The classifier has no `vision` row today, so a vision override falls back to the message's topic and keeps `mediamaker`. The rule maps it to `general`. Routing diff: the vision cases with an existing topic. |
| S3.7 | `fix(message): an unmapped model tag on a new message routes to general` | The classifier's fallback treats empty, `unknown` and `UNKNOWN` as no topic. Routing diff: the S3.1 fallback cases only. |
| S3.8 | `refactor(message): one topic and intent rule` | After S3.3–S3.7 the two policies agree on every input of the D2 table, the fallback inputs included (the S3.1 table test proves it), so `forModelOverride()` / `forAgain()` collapse into the one rule and no snapshot moves. |
| C3 | `chore(planning): close V4 S3 — sprint test, status, ledgers` | Loop §7 |

## 4. Sprint test

- **Proof:** `TopicIntentRuleTest`, one row per line of the D2 table.
  Regression tests, each run red on S2's head first (quoted in STATUS) and
  committed with its fix: an Again of a `tools:pic` message routes to
  image generation; a document-model override routes to the document
  handler; a chat-model override on a message with a custom prompt topic
  keeps that topic; a vision-model override on a `mediamaker` message
  routes to `general`; an unmapped tag on a new (`UNKNOWN`) message routes
  to `general`. Mutation check: drop the document row and see two tests
  fail.
- **Regression:** S3.1 records new cases only (additive, loop §5); S3.2 and
  S3.8 move no snapshot. The routing diffs of S3.4–S3.7 and the turn diff
  of S3.3 are limited to their cases and quoted in the pull request
  (`UPDATE_ROUTING_SNAPSHOTS=1` / `UPDATE_CLEANUP_V2_SNAPSHOTS=1`). Every
  other snapshot unchanged. Unfiltered gates.
- **Journey:** `chat-again.spec.ts`, `chat.spec.ts`, `operate-topics.spec.ts`,
  `multitask.spec.ts` green in `make test-e2e`.

## 5. Reviewer focus

Custom task prompts: a user prompt's topic must survive a `chat`-tag
retry. Every caller that read `mediamaker`, `officemaker` or `general`
from the old copies.

## 6. Exit

- No `mapModelTagToTopic`, `mapTopicToIntent`, `topicForModelTag` or
  `intentForAgainTopic` left; one `TopicIntentRule` with its table test.
- The routing and turn snapshot diffs are reviewed and quoted.

## 7. Not in S3

The classifier split (S6). The fast-path heuristic and the sorter prompt
(unchanged).

## 8. As delivered (2026-10-17)

Every exit line holds. The branch starts from `origin/main`, because S2
had merged. Where the result differs from the plan:

- **S3.7 ran before S3.5 and S3.6.** With the fallback fixed first, the
  chat-model change never sends a new message to the topic `UNKNOWN`. The
  commits run S3.3, S3.4, S3.7, S3.5, S3.6, S3.8.
- **The one rule is `forModel()`.** S3.2 moved the Again's copy as
  `forAgain()`; S3.3 replaced it with the D2 rule under the name both
  callers now use, and S3.8 removed `forModelOverride()` with its table.
- **The table test is `OverrideAndAgainRoutingTest`.** It runs the
  classifier and the resolver through their public entries on 36 rows of
  tag and topic. On S2's head 23 rows disagreed; now each row has one
  expectation both entries must answer.
- **A tag matches in any case on both paths**, as the Again's did.
  Catalog tags are lower case, so no route moves.
- **Where the change reaches users.** The web chat's Again streams with
  `isAgain` and classifies in `TurnClassificationResolver` (S3.3), as does
  Telegram's. The classifier's model override (S3.4–S3.7) runs for
  `POST /api/v1/messages/again` (`AgainHandler`), which stores the picked
  model on a copy of the message, topic included. The web Again menu
  offers models of the answer's own kind, so a chat model is offered on a
  chat answer only.
- **Review round 1 added two rules.** The placeholders `CHAT` and
  `CONTINUE`, which entry points store before a message is classified,
  count as no topic: the web Again's message always carries `CHAT`, so a
  chat-model retry now answers on the general prompt instead of the
  built-in default. A media route takes a media model: a chat model kept
  on a media topic no longer reaches the image provider, and the media
  handler picks its default.
