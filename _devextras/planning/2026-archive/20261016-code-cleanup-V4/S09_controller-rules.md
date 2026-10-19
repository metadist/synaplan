# S9 — Thin controllers, as a gate

| | |
| - | - |
| **V3 §7** | #9 |
| **Branch** | `refactor/cleanup-v4-s9-controller-rules`, from S8's branch |
| **Pull request** | `refactor(api): thin-controller gate and first pay-down` |
| **Class** | backend-only |
| **Owner decision** | — |
| **Chore** | C9 |

## 1. Goal

AGENTS.md says controllers are thin: HTTP only, under 50 lines per method,
no EntityManager work, business logic in services. Nothing enforces it.
S9 makes the rule a gate that freezes today's offenders and lets them only
shrink. Then it pays the first part down.

## 2. Measured (2026-10-16)

- 115 of 809 controller methods have more than 50 lines (constructors not
  counted; `SourceMetrics` over `src/Controller/`).
- 25 of 131 controller files use `EntityManagerInterface`; 9 use
  `AiFacade`.
- S4 already removes `WidgetPublicController::message()` and
  `WebhookController::email()`.

## 3. The gate

`tests/Architecture/ThinControllerTest.php` with
`thinControllerBaseline.json`, built like `SizeRatchetTest`. Three rules
for every class under `src/Controller/`:

| Rule | Measured by | Frozen as |
| ---- | ----------- | --------- |
| A method body has at most 50 lines | `SourceMetrics` | `Controller::method` → lines |
| No `Doctrine\ORM\EntityManagerInterface` constructor or action parameter | reflection | controller name |
| No `App\AI\Service\AiFacade` parameter | reflection | controller name |

An unlisted offender fails; a listed one may shrink or disappear, never
grow. `UPDATE_THIN_CONTROLLER=1` regenerates the ledger (removals only).

**Why an architecture test and not deptrac:** deptrac sees layers.
`AiFacade` is Application code, which the Entrypoint layer may use.
Carving one class into a layer of its own, and the vendor
`EntityManagerInterface` into another, would bend the layer model for two
names. A reflection check is exact, and it reads like the size ratchet
next to it. Layers stay deptrac's job.

## 4. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S9.1 | `test(api): thin-controller gate with a shrink-only ledger` | The test, the ledger with today's offenders, `make -C backend metrics` extended with the three counts. |
| S9.2 | `refactor(api): move entity work out of the controllers` | Starting with the smallest: each controller whose `EntityManagerInterface` use is persist / flush / find moves that into its repository or service. One commit per controller, each with a functional test (an existing `WebTestCase`, or a response snapshot recorded first). |
| S9.3 | `refactor(api): AI calls behind their domain services` | The `AiFacade` controllers whose call belongs to an existing service. |
| S9.4 | `refactor(api): split the longest controller actions` | The longest actions outside S4's, into request DTO + service call + response. |
| C9 | `chore(planning): close V4 S9 — sprint test, status, ledgers` | Loop §7 |

## 5. Sprint test

- **Proof:** the gate fails on a scratch controller with a 51-line method
  and on one injecting `EntityManagerInterface` (both tests in
  `ThinControllerTest`). For every moved action, a functional test
  recorded on S8's head and unchanged after the move. Mutation check: drop
  an ownership filter in one moved query and see its test fail.
- **Regression:** `generate-schemas` zero diff (no route, request or
  response changes); `lint:container`; unfiltered gates.
- **Journey:** `make test-e2e`: the specs of every touched controller
  (named in the pull request).

## 6. Reviewer focus

**Security impact:** every query that moves must keep its user / owner /
organisation filter. The pull request lists each moved query with the
filter it keeps.

## 7. Exit

- The gate runs in `make -C backend test` and CI.
- `EntityManagerInterface` in ≤ 18 controllers (from 25); `AiFacade` in
  ≤ 6 (from 9); controller methods > 50 lines ≤ 105 (from 115).
- `docs/ARCHITECTURE.md` "Layers" names the thin-controller gate next to
  deptrac and the size ratchet.

## 8. Not in S9

Routes, request validation, OpenAPI annotations: unchanged.

## 9. As delivered (2026-10-17)

The gate runs in `make -C backend test`: `ThinControllerTest` with
`thinControllerBaseline.json`, and `make -C backend metrics` prints the
three counts. Measured on S8's head (`ed4157f13`), they were 112 methods
over 50 lines, 25 controllers with the entity manager and 8 with the AI
facade (S4 had already removed three long actions, and one facade
controller was gone). They are now 105, 17 and 5. The branch starts from
S8's head. Where the result differs from the plan:

- **Eight controllers lost the entity manager, not seven:** message
  versions, the chat library, model health, live-support uploads, the
  profile, widget sessions, widget summary prompts and chats. Every move
  was a write (persist, flush, remove), and each went to the repository
  that owns the entity (`ChatRepository::save()`,
  `ModelHealthRepository::save()`, `PromptRepository::save()` / `remove()`
  and `WidgetSessionRepository::removeAll()` are new). Each controller got a
  functional test, recorded on the parent first; they share
  `CreatesTestEntities`.
- **No query moved, so every filter stayed where it was.** The ownership
  checks (a message, chat, file or widget of the user; admin-only model
  health) are unchanged in the controllers. The mutation check therefore
  drops the owned-file filter in the file summary's lookup, which did move
  (into `summaryDocument()`), and the foreign-file case fails.
- **The AI calls went behind domain services:** `DocumentSummarizer` (the
  two document summaries, one service), `MemoryInputParser` (the memory
  parse with its three answer shapes) and `InputEnhancer` (the message
  enhancer, beside `EnhanceChangeSummary`). Three controllers left the
  facade list. `MessageController` keeps the facade for sending, and the
  OpenAI-compatible controller keeps it for its plain-call branch, which
  could move into `OpenAiGatewayToolLoop` later.
- **Not the longest actions first.** S9.4 took the long actions whose work
  had a home: the summary (251 → 47), the file summary (123 → 44), the
  memory parse (162 → 32, its 54-line helper with it), the enhancer
  (118 → 38), the memory edit (58 → 46, one failure mapper for create and
  edit) and the chat edit (53 → 48, with the pin rule now on `Chat`). The
  longest remain — sending a message (252), the chat file upload (225),
  the message stream (182) and the Keycloak callback (178). Each is a whole
  flow (chat send, file intake, streaming, sign-in) that needs a sprint of
  its own with a characterization snapshot first: V5.
- **One edge aligned.** A database failure while resolving the summary
  model no longer aborts the file summary: both summaries now fall back to
  the provider default, as `/summary/generate` always did.
- **The test model cannot rewrite.** With the test provider, the enhancer
  and the memory parse can only answer "no rewrite" and "not understood",
  so their success paths are covered by the services' unit tests.
