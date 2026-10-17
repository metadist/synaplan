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

The classifier becomes a short cascade in the order `classify()` really
runs today, each step a class with one reason to change. No routing
decision moves; the routing snapshot proves it.

The class docblock lists a different order (Again → slash command →
attachment → embedding router → deferral → sorter). Following it would
change routing under a `refactor:` label. The existing agent-pin test,
for example, requires a bare `/pic` to return its usage hint before the
pin resolves. The executable order is the contract, and S6.1 corrects the
docblock to match it.

| # | Step, as executed (`MessageClassifier.php`) | Line |
| - | ------------------------------------------- | ---- |
| 1 | Slash commands and their usage hints (#2280) | 115 |
| 2 | Agent pin, fast return | 133 |
| 3 | `/docs <query>` without a pin → knowledge search | 148 |
| 4 | Fast path (`CLASSIFIER.FAST_PATH_ENABLED`) | 172 |
| 5 | Again: model override, prompt override | 237 |
| 6 | Document / audio / video attachments → file analysis (#595, #722) | 293 |
| 7 | Embedding-router cascade (Phase 8); file-production work | 354 |
| 8 | Routing deferral to the answering call (Phase 9) | 449 |
| 9 | AI sorter | 514 |
| 10 | Overlays on the verdict: media type, describe reroute, code execution, spoken output, forced web search, duration, resolution, input mode | 565–680 |

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
MessageClassifier             the cascade in the executed order of §1; classify() ≤ 80 lines; ≤ 10 deps
```

The fast path is off by default (`CLASSIFIER.FAST_PATH_ENABLED`, AGENTS.md
"Heuristic changes ≠ production effect"). Its tests run with the flag on
and off.

## 4. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S6.1 | `test(message): routing cases for every classifier seam` | Add routing cases to `RoutingCharacterizationTest` where a seam has none: each override, each slash command, each attachment kind, a follow-up on a generated file and on generated media, the fast path on and off, a low-confidence language. Add cases where two steps compete (a bare `/pic` with a pinned agent, an Again on a message the fast path would take, an attachment with a slash command), so the order itself is pinned. Recorded on the parent. The class docblock is corrected to the executed order (a `docs(message):` commit). |
| S6.2 | `refactor(message): thread artifacts and confident language as classes` | The two leaf seams first; they have no dependencies on the rest. |
| S6.3 | `refactor(message): attachment intent as a class` | With its patterns and the office engine check. |
| S6.4 | `refactor(message): overrides, Again and slash commands as classes` | — |
| S6.5 | `refactor(message): the fast path as a class` | — |
| S6.6 | `refactor(message): classify() runs the cascade` | `classify()` ≤ 80 lines, the cascade in the executed order of §1. |
| C6 | `chore(planning): close V4 S6 — sprint test, status, ledgers` | Loop §7 |

## 5. Sprint test

- **Proof:** the extended `routing_classification.json`, recorded on S5's
  head, unchanged after every step. A unit test per new class. Mutation
  check: move the agent pin before the slash commands and see the bare
  `/pic` case fail.
- **Regression:** `utterance_plans.json`, `planner_system_prompt.txt`,
  `message_turn.json` unchanged; `MessageClassifierTest` green with
  unchanged assertions. Unfiltered gates. (`make -C backend sort-eval`
  calls a live model and is not a gate. The sorter itself does not change.)
- **Journey:** `chat.spec.ts`, `chat-again.spec.ts`, `file-manage.spec.ts`,
  `multitask.spec.ts`, `summarize-tool.spec.ts` in `make test-e2e`.

## 6. Reviewer focus

The cascade order against §1 and every early return: a step that used to
return before the sorter must still return before it. That is a paid
call per message.

## 7. Exit

- `MessageClassifier` < 800 lines, ≤ 10 deps, `classify()` ≤ 80 lines,
  `canFastPathClassify()` < 100; it leaves all three ledgers.
- `docs/ARCHITECTURE.md` "Message turn" shows the cascade.

## 8. Not in S6

The sorter prompt and `MessageSorter`. The decision-routing track (row 11)
builds on this cascade later.

## 9. As delivered (2026-10-17)

`MessageClassifier` leaves all three size ledgers: 1,962 → 130 lines,
21 → 9 constructor parameters, `classify()` 574 → 51 lines, and
`canFastPathClassify()` 208 → 69 in `FastPathClassifier`. The branch starts
from S5's head (`01d14a180`). Where the result differs from the plan:

- **The overrides are two classes.** §3 named one `ClassificationOverrides`
  for the overrides, Again and the agent pin. The pin is step 2 and Again
  step 5, with a different reason to change each, so they are
  `AgentPinRoute` (which also attaches the profile of a published assistant
  the sorter picked) and `AgainOverride`.
- **The last three steps are classes too.** Nine collaborators were only
  reachable with the embedding router, the deferral and the sorter out of
  the classifier: `EmbeddingRoute` answers with an `EmbeddingVerdict`, so a
  match declined for its language still keeps the deferral off;
  `RoutingDeferral` owns the provider pre-check; `SorterVerdict` holds the
  sorter call and its overlays, in their order. `SorterInput` (the message
  as the sorter reads it, shared with the pin's web vote),
  `SelfAwareQuestion` (the guard of every sorter-skipping step) and
  `SlashOutcome` are the other new types.
- **The heuristic shrank as it moved.** The follow-up checks on generated
  work became a method of their own and the three trigger lists constants
  behind one check, so it is 69 lines, not just under the limit. The
  sorter's overlays split the same way: the three that hand the turn to the
  planner are one method, called where they ran.
- **The tests build through a factory first.** Twenty-one constructions in
  four test files passed the classifier's arguments positionally; a first
  `test:` commit moved them to `MessageClassifierFactory`, so each step
  rewired the factory and no assertion changed. The constructor could not grow at any commit, so
  each new class took over dependencies the classifier had (21 → 19 → 16 →
  15 → 9).
- **Thirty routing cases, recorded additively.** S6.1 recorded them on the
  untouched classifier (124 cases in all); `routing_classification.json` did
  not move after that. The harness comment that called a missing fast-path
  setting "on" was wrong: it means off, and the new cases switch it on.
- **Found, carried; the snapshot pins it.** With the fast path switched on
  (off by default), it runs before Again. `POST /api/v1/messages/again`
  hands the turn no options and relies on the message's `MODEL_ID` /
  `PROMPTID` meta, so a short plain message regenerated there is fast-pathed
  to the default chat model, and the user's pick is lost
  (`fastpath_on_wins_over_an_again_override`). The streamed web Again
  (`is_again`) never asks the classifier. Moving Again ahead of the fast
  path is a routing change, so it is the owner's call for V5.
