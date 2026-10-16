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
| tag `chat`, `vectorize`, unknown | **the topic it is given** (empty or `unknown` counts as none), else `general`. The policy: a chat model keeps a custom prompt. |
| topic → intent | the capability registry, then the aliases; unknown → chat |

What moves: an Again on a `/pic` message regenerates the image instead of
chatting; an Again on `docsummary` (and every other registry topic) uses
the registry's intent; a classifier override with a document model now
reaches the document handler; an unknown tag on Again falls back to
`general` instead of `chat`. The classifier's `chat` tag already passes the
message topic, which a new message does not have yet. The S3.1 snapshot
confirms that nothing moves for it.

Alternative the owner may pick instead: the Again semantics win. That
keeps the document route but sends `tools:pic` retries to chat. It is not
recommended: a retry that changes what kind of answer comes back is the
bug this sprint exists to close.

## 3. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S3.1 | `test(message): pin both topic and intent rules side by side` | Table test over every tag and topic above, against both private copies, on the parent. Add routing cases for a model override per tag to `RoutingCharacterizationTest`, and turn cases for Again per tag to `MessageTurnCharacterizationTest`. |
| S3.2 | `refactor(message): one owner for the topic and intent tables` | `App\Service\Message\Capability\TopicIntentRule` (`topicForModelTag(string $tag, ?string $given): string`, `intentForTopic(string $topic): string`), built on `SystemCapabilityRegistry`. The classifier calls it; its private copies are deleted. No snapshot moves. |
| S3.3 | `fix(message): Again routes by the same topic and intent rule` | `TurnClassificationResolver` calls the rule; `topicForModelTag()` / `intentForAgainTopic()` are deleted. `message_turn.json` diff: the Again cases only. |
| S3.4 | `fix(message): a document model override reaches the document handler` | The rule's document row now applies to the classifier. `routing_classification.json` diff: the override cases only. |
| C3 | `chore(planning): close V4 S3 — sprint test, status, ledgers` | Loop §7 |

## 4. Sprint test

- **Proof:** `TopicIntentRuleTest`, one row per line of the D2 table.
  Regression tests red on S2's head: an Again of a `tools:pic` message
  routes to image generation; a document-model override routes to the
  document handler. Mutation check: drop the document row and see two
  tests fail.
- **Regression:** the routing diff limited to the override cases and the
  turn diff limited to the Again cases, both quoted in the pull request
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
