# S6 — Split `MessageClassifier` along its seams

| | |
| - | - |
| **V3 §7** | #6 |
| **Branch** | `refactor/cleanup-v4-s6-classifier`, from S5's branch |
| **Pull request** | `refactor(message): split MessageClassifier along its seams` |
| **Class** | backend-only |
| **Owner decision** | — (S3 already decided the topic / intent rule this sprint moves) |
| **Chore** | C6 |

## 1. Goal

The classifier becomes the short cascade its own docblock describes
(Again → slash command → attachment → embedding router → deferral → AI
sorter), each step a class with one reason to change. No routing decision
moves; the routing snapshot proves it.

## 2. Measured (2026-10-16)

`Service/Message/MessageClassifier.php`: 2,025 lines, 21 constructor
parameters, 39 methods. `classify()` (line 109) has 575 lines and
`canFastPathClassify()` has 208. Safety nets: `RoutingCharacterizationTest`
(`routing_classification.json`), `UtterancePlanCharacterizationTest`,
`PlannerPromptCharacterizationTest`, `MessageClassifierTest`.

| Seam | Methods today |
| ---- | ------------- |
| Overrides and Again | `checkPromptOverride`, `checkModelOverride`, `againOverride`, `tryPinAgent`, `pinnedWebSearchVote`, `attachRoutableAgent` |
| Slash commands | `resolveSlashCommand`, `applySlashOverlaysToClassification`, `withForceWebSearch` |
| Attachment intent | `attachmentDocumentFileIntent`, `officeEngineReadyFor`, `combineEligibleAttachments`, `shouldRouteOfficeEditToOfficemaker`, `messageHas…Attachment` (4), `attachedFileIsAnalyzableNonImage`, `messageRequestsCodeExecution`, `messageRequestsFileProduction`, `officePdfGenerationEnabled`, the five intent patterns (`MERGE_INTENT_PATTERN`, …) |
| Thread artifacts | `lastAssistantTurnIs`, `anyAssistantTurnIs`, `isGeneratedFileMessage`, `isGeneratedMediaMessage`, `mentionsMediaReference`, `mentionsDocumentReference` |
| Fast path | `isClassifierFastPathEnabled`, `canFastPathClassify`, `isSelfAwareQuestion`, `accountChatModelCanRouteNatively`, `SELF_AWARE_GUARD_PATTERN` |
| Language | `resolveConfidentLanguage`, `detectLanguageConfident` |
| Sorter input | `buildMessageData` |

The topic / intent tables left the class in S3 (`TopicIntentRule`).

## 3. Target shape

```
Service/Message/Classification/
  ClassificationOverrides     prompt / model override, Again, agent pin
  SlashCommandRoute           /pic, /vid, /search, … and their overlays
  AttachmentIntent            attachment → analysis / office edit / code run / file production
  ThreadArtifacts             what the previous turns produced
  FastPathClassifier          the heuristic (BCONFIG CLASSIFIER.FAST_PATH_ENABLED)
  ConfidentLanguage           language detection with confidence
MessageClassifier             the cascade; classify() ≤ 80 lines; ≤ 10 deps
```

The fast path is off by default (`CLASSIFIER.FAST_PATH_ENABLED`, AGENTS.md
"Heuristic changes ≠ production effect"). Its tests run with the flag on
and off.

## 4. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S6.1 | `test(message): routing cases for every classifier seam` | Add routing cases to `RoutingCharacterizationTest` where a seam has none: each override, each slash command, each attachment kind, a follow-up on a generated file and on generated media, the fast path on and off, a low-confidence language. Recorded on the parent. |
| S6.2 | `refactor(message): thread artifacts and confident language as classes` | The two leaf seams first; they have no dependencies on the rest. |
| S6.3 | `refactor(message): attachment intent as a class` | With its patterns and the office engine check. |
| S6.4 | `refactor(message): overrides, Again and slash commands as classes` | — |
| S6.5 | `refactor(message): the fast path as a class` | — |
| S6.6 | `refactor(message): classify() runs the cascade` | `classify()` ≤ 80 lines, the cascade in the docblock's order. |
| C6 | `chore(planning): close V4 S6 — sprint test, status, ledgers` | Loop §7 |

## 5. Sprint test

- **Proof:** the extended `routing_classification.json`, recorded on S5's
  head, unchanged after every step. A unit test per new class. Mutation
  check: swap attachment intent and slash command in the cascade and see
  the routing snapshot fail.
- **Regression:** `utterance_plans.json`, `planner_system_prompt.txt`,
  `message_turn.json` unchanged; `MessageClassifierTest` green with
  unchanged assertions. Unfiltered gates. (`make -C backend sort-eval`
  calls a live model and is not a gate. The sorter itself does not change.)
- **Journey:** `chat.spec.ts`, `chat-again.spec.ts`, `file-manage.spec.ts`,
  `multitask.spec.ts`, `summarize-tool.spec.ts` in `make test-e2e`.

## 6. Reviewer focus

The cascade order and every early return: a step that used to return
before the sorter must still return before it. That is a paid call per
message.

## 7. Exit

- `MessageClassifier` < 800 lines, ≤ 10 deps, `classify()` ≤ 80 lines,
  `canFastPathClassify()` < 100; it leaves all three ledgers.
- `docs/ARCHITECTURE.md` "Message turn" shows the cascade.

## 8. Not in S6

The sorter prompt and `MessageSorter`. The decision-routing track (row 11)
builds on this cascade later.
