Title: fix(multitask): an MCP tool error drops the plan or skips the answer step, and the reply hides the failure

**Kind:** Implemented on this branch, pending merge.

**Reported by:** partner hoster running the Backblaze B2 MCP server
**Version:** 5.2.0 (upgraded from 5.0.6). These code paths are unchanged on current `main`.
**Follow-up to:** [#2327](https://github.com/metadist/synaplan/pull/2327)
**Release:** commit subject starts with `fix:` (patch). Backend only. No frontend, no new `NodeStatus`, no prompt migration.

---

## What the partner saw

Read-only lookups work (bucket reachability 6/6, listing buckets and objects works). Message sorting is Luna. The planner is the default PLAN model, which falls back to that same Luna, reached as `~openai/gpt-luna-latest` through OpenRouter.

1. **A bucket that does not exist.** B2 returns `404 NotFound`. This happened 5/5 times, on two different prompts. The plan is dropped and the legacy chat answers that there is no Backblaze connection. That is false: the connection exists and B2 answered. Nothing is logged about the error itself.
2. **One real bucket and one missing bucket in the same message.** The real check succeeds, the missing one fails with the 404, and the answer step is skipped. The reply only mentions the bucket that exists. The failure shows only on the task card.
3. **Every successful run** logs `ChatRunner: plan did not hand over upstream step output, appending it to the prompt`. The answers are still correct. This is not the bug to fix here — see the last section.

They asked whether MCP steps should skip the chat fallback the way `code_run` already does. Yes. That alone is step 4. It is not enough on its own: the user would then get "I couldn't fully complete that request." and still not be told the bucket was not found. Steps 1–3 make the answer step explain the error.

---

## What the code does

This is the path for "is bucket `does-not-exist-123` reachable?":

1. B2's 404 comes back as a normal MCP tool result with `isError: true`. `McpClient::callTool()` returns it. It does not throw, so the JSON-RPC error log in `McpClient::decodeRpcResult()` never runs. This matches "nothing in the logs". A transport failure throws `McpClientException` and is already logged; that is a different case.
2. `McpFetchRunner::run()` turns `isError` into `NodeResult::failed(...)` and does not log. `McpActionRunner::run()` has the same gap.
3. The answer step lists the fetch in `depends_on`. `DagExecutor::failedDependency()` treats any failed dependency as fatal, so the answer step is stored as `skipped` and never runs. Same rule in the parallel scheduler.
4. `ResultAssembler` sets `all_failed` when nothing succeeded and nothing is still running. Skipped steps do not count as in progress, so one failed fetch plus a skipped answer is a dead plan.
5. `TaskPlanExecutor::execute()` and `executeStream()` then discard the plan (`plan_discarded`, which removes the task card) and re-run the turn through `InferenceRouter`. The only exception is an authored Saved Task or a plan that contains `code_run` (`planRunsCode()`). The legacy router cannot see MCP connections, so it answers that none exists.
6. **Mixed request:** the successful fetch keeps `all_failed` false, so there is no legacy fallback. The answer step is still skipped. `ResultAssembler::bestEffort()` returns the last successful text, which is the raw output of the fetch that worked, and adds no sentence about the one that failed. Its class comment promises that sentence. The sentence does not exist.

`$nX.text` on a failed step resolves to empty (`NodeContext::resolveNodeRef()` reads `NodeResult::$text`, and `failed()` sets no text). `UpstreamHandover::missing()` also skips any step that is not successful. So even if the answer step did run, it would not see the 404.

The canonical plan shape (already in the planner prompt, `PromptCatalog::planPrompt()`, heading "Pull data from a connected system") is:

```text
n1 mcp_fetch
n2 chat        depends_on n1, inputs.text contains $n1.text
n3 compose_reply   depends_on n1 and n2, inputs.text = $n2.text, reply_node = n3
```

`compose_reply` is hidden and copies text. It does not call a model. The explanation has to be written by `chat` (or `summarize` / `translate` / `rag_query` — all four share the one `ChatRunner::run()`, so one handover change covers them).

---

## Done when

- A missing bucket: the reply names the bucket and says the connected system reported it was not found. The fetch card stays, in the failed state, with the same reason. The reply does not say that no connection exists.
- Two buckets, one missing: one reply covers both. One card done, one card failed.
- The log contains `McpFetchRunner: tool reported an error` with server id, tool name and the error text.
- A fetch that fails Synaplan's own checks (feature off, unknown tool, topic not allowed) still does not fall back to legacy chat. The reply is the existing "couldn't fully complete" sentence plus one sentence naming the failed step and its error.
- A successful lookup answers as it does today.

---

## Step 1 — Mark "the remote system answered with an error"

**File:** `backend/src/Service/Multitask/Execution/NodeResult.php`

Add a named constructor (static factory, not `__construct`) plus a predicate. Do not add a `NodeStatus` case. The card state stays `failed`, which the UI already renders. Set the flag with a plain assignment, not a `+` union or `array_merge` (both have silent key-precedence behaviour a later edit could flip):

```php
public const META_REPORTABLE = 'reportable_failure';

public static function reportableFailure(string $error, array $metadata = []): self
{
    $metadata[self::META_REPORTABLE] = true;

    return new self(NodeStatus::Failed, error: $error, metadata: $metadata);
}

public function isReportableFailure(): bool
{
    return NodeStatus::Failed === $this->status && true === ($this->metadata[self::META_REPORTABLE] ?? false);
}
```

The flag lives only in memory. It is never read off persisted message meta, and the card builders below only copy `text` / `url` / `error` / `query` / counts — do not forward `META_REPORTABLE` into any user-visible metadata yourself.

**Files:** `McpFetchRunner::run()` and `McpActionRunner::run()`

Use `reportableFailure` only on the two branches where the tool was actually called:

- `isError: true`
- `catch (McpClientException)` — unreachable server, HTTP 4xx/5xx, failed sign-in

Keep the metadata shape the success path already uses, so cards and logs keep working: `mcp` (`server_id`, `server`, `tool`, plus `write: true` in the action runner) and `query` (`server name . ' · ' . tool`). Build the error as `sprintf('%s reported an error: %s', $server->getName(), mb_substr($text, 0, 300))`. When the tool text is empty or whitespace-only, return `sprintf('%s reported an error and gave no details.', $server->getName())` instead of a sentence that ends with a bare colon.

Log a warning on both branches before returning:

- `McpFetchRunner: tool reported an error` with `server_id`, `tool`, `error`
- `McpActionRunner: write action reported an error` with `user_id`, `server_id`, `tool`, `argument_keys`, `error`

Leave everything before the tool call as hard `NodeResult::failed()` with no new log line: flags off, missing params, server not owned or disabled, write opt-in missing, topic not allowed, tool not in the catalog, tool self-declares mutating/destructive, and the fetch's `returned no usable content` empty case. Those are our refusals (or an empty answer), not an error the remote system reported.

---

## Step 2 — Let the answer step run anyway

**File:** `backend/src/Service/Multitask/Execution/DagExecutor.php`

An answer step may run when a dependency is a reportable failure. Every other capability keeps today's skip. A reportable failure is not permission to send an email, save a file, or call another tool: any action node whose direct dependency is a reportable failure is still skipped.

Answer capabilities (`Capability::Chat`, `Capability::Summarize`, `Capability::Translate`, `Capability::RagQuery`, `Capability::ComposeReply`):

```php
/** Capabilities that turn upstream output into the user's answer. */
private const ANSWER_CAPABILITIES = [
    Capability::Chat,
    Capability::Summarize,
    Capability::Translate,
    Capability::RagQuery,
    Capability::ComposeReply,
];

private function isAnswerNode(TaskNode $node): bool
{
    return in_array($node->capability, self::ANSWER_CAPABILITIES, true);
}

/**
 * 'ready' if the node may consume this dependency now, 'failed' if it must
 * skip, 'pending' if the dependency has not settled yet.
 */
private function dependencyState(NodeContext $context, TaskNode $node, string $depId): string
{
    $result = $context->getResult($depId);
    if (null === $result || $result->isRunning() || $result->isWaitingApproval()) {
        return 'pending';
    }
    if ($result->isSuccessful()) {
        return 'ready';
    }
    if ($result->isReportableFailure() && $this->isAnswerNode($node)) {
        return 'ready';
    }

    // Failed, Skipped, and Stopped (a condition that evaluated false).
    return 'failed';
}
```

Two methods, not one: the capability list lives in `isAnswerNode()`, the state machine in `dependencyState()`. Rewrite the three existing checks on top of it, with no other behaviour change:

- `failedDependency()` returns the first dependency whose state is `failed`, else null. (`settledFailedDependency()` in the parallel scheduler delegates to it — no fourth patch needed.)
- `blockedByIncompleteDependency()` is true when any dependency state is `pending`. It is false for `ready` and for `failed` (`failed` is already handled by the skip above it in sequential mode).
- `dependenciesSatisfied()` (parallel scheduler) is true only when every dependency state is `ready`.

`compose_reply` is on the list because the canonical reply node depends on the fetch and on the chat. If it stays skipped, the reply node is skipped even though the chat already wrote the explanation.

Do not treat `NodeStatus::Stopped` as reportable. A condition that evaluated false must still skip its dependents (locked by test, see below).

Trap, sequential mode: `executeSequential()` walks the plan once. A node it neither runs nor skips stays `pending` forever, and `ResultAssembler` counts pending as "still running" — so `all_failed` stays false, the legacy fallback does not run either, and the user gets whatever text another step produced with no explanation. A tolerated reportable failure must resolve to `ready`, never `pending`. The helper above does that; do not special-case it at the call sites.

Trap, parallel mode: the loop exits when a pass starts nothing and nothing is in flight. A node that is neither ready nor skipped is dropped the same way. Same helper, same reason.

In `failureMetadata()`, keep the existing `error` entry and ADD `query` when the result metadata holds a non-empty string `query` (mirror the `successMetadata()` lines for search-style nodes). Do not replace the error with the query. Otherwise the live card carries the error but not the "Backblaze · s3_head_bucket" line that the reloaded card gets from `ResultAssembler`.

Regression watch (accepted, not a blocker): a summary node between a failed fetch and an action (`fetch → summarize → email_me`) now runs and re-explains the error, so the mail says the lookup failed instead of the turn falling back to chat. That is the U8 outcome — the user asked for mail about the lookup. Direct action dependents still skip; write actions additionally stay behind the approval gate.

---

## Step 3 — Put the error in the prompt

**File:** `backend/src/Service/Multitask/Execution/UpstreamHandover.php`

`missing()` currently skips every unsuccessful step. Also collect reportable failures; keep skipping hard failures and skipped steps. A reportable failure has no `$nX.text`, so the "already in the prompt" verbatim check cannot see it — always include it, labelled by reusing the existing `self::label($dep, $result)` plus a suffix: `self::label($dep, $result).' · FAILED'`. The value is the error string. Apply the same `MAX_CHARS_PER_STEP` cap as the other blocks.

Add one line to `render()`, after the existing "never claim it was not provided" line, and only when the map contains a `· FAILED` entry. A successful handover must not grow a sentence that says a step failed:

```text
A step marked FAILED did run. Say what failed and why, in the user's language. Do not say that the connection or the data source does not exist.
```

`ChatRunner` already calls `UpstreamHandover`. Do not change `ChatRunner`.

**File:** `backend/src/Service/Multitask/Execution/Runner/ComposeReplyRunner.php`

`compose_reply` does not call a model, so without this change a plan whose reply copies fetch output directly (no chat in between) still answers with nothing. After resolving the inputs and before the empty-check, append **only the `· FAILED` entries** from `missing()` (filter the keys). Passing the whole map also lists successful steps and file references, and that leaks raw file paths into a reply that already copied `$nX.text` (`RunnersTest::testComposeReplyGathersTextAndAttachments`).

```php
$failures = array_filter(
    UpstreamHandover::missing($node, $context, $text, $inputs),
    static fn (string $label): bool => str_ends_with($label, ' · FAILED'),
    ARRAY_FILTER_USE_KEY,
);
$text .= UpstreamHandover::render($failures);
```

then keep the existing `'' === $text ? null : $text` empty-check on the combined string. The dedupe inside `missing()` means a chat text that already quotes the error verbatim gains nothing.

Accepted redundancy: in the canonical shape the chat already explained the failure and the reply node copies the chat text, so the appended FAILED block repeats it in raw form. Keep it anyway — the block is labelled, it only appears when something actually failed, and it is the only thing that speaks when no chat ran. Do not "fix" this by dropping the compose change; that reopens silence for chat-less plans.

---

## Step 4 — Do not fall back to legacy chat

**File:** `backend/src/Service/Multitask/TaskPlanExecutor.php`

Replace `planRunsCode()` with the generalisation below, and point both `all_failed` branches (`executeStream` and `execute`) at it. Delete the old method; do not leave two overlapping checks. Keep the `$plan->authored` exception exactly as it is.

```php
/**
 * Capabilities the legacy chat router cannot perform. Falling back to it
 * after such a plan failed produces an answer that denies the capability
 * ("I can't execute code", "there is no Backblaze connection") and hides
 * the real error (U8). Surface the node's own failure instead.
 */
private const NO_CHAT_FALLBACK = [
    Capability::CodeRun,
    Capability::McpFetch,
    Capability::McpAction,
];

private function planNeedsCapabilityChatCannotPerform(TaskPlan $plan): bool
{
    foreach ($plan->nodes as $node) {
        if (in_array($node->capability, self::NO_CHAT_FALLBACK, true)) {
            return true;
        }
    }

    return false;
}
```

`Capability` is already imported in this file. Do not add `EmailSearch` or `ToolCall` in this change.

On both `DAG produced no successful node …` log lines, add `node_errors`: node id → error string, built from `$assembled['metadata']['task_plan_render']['cards']` (present at that point), keeping only cards with a non-empty `error`. This is what makes the next "nothing in the logs" report answerable.

This path is what a hard failure still hits (unknown tool, feature off). The text it shows is step 5.

---

## Step 5 — Say which step failed when no answer was written

**File:** `backend/src/Service/Multitask/Execution/ResultAssembler.php`

`bestEffort()` runs only when the reply node produced nothing. That is the hard-failure case (unknown tool, feature off): step 2 never let an answer run, so nothing explained anything. When the chat step ran, its text is the reply and this method is not used — do not touch that path.

After the existing fallback-text resolution, append one sentence per failed visible step, **only when the reply is the generic fallback** (no successful step produced text). A recovered summary or tool result stays unchanged — appending `provider 500` rewrote the partial-media reply (`DagExecutorTest::testParallelMediaFailureIsIsolated`). The failed step is already on its card. Iterate `$plan->nodes` in order with `$context->getResult()`: keep nodes whose result is `Failed` with a non-empty error and whose `uiKind()` is not `hidden` (this skips `compose_reply`). The label is the result's `query` metadata when it is a non-empty string, else the capability value. The error string follows it verbatim — it is already a full sentence from step 1, so no "see the card" pointer:

```text
en: One step could not be completed: {label}. {error}
de: Ein Schritt konnte nicht abgeschlossen werden: {label}. {error}
es: No se pudo completar un paso: {label}. {error}
fr: Une étape n'a pas pu être terminée : {label}. {error}
tr: Bir adım tamamlanamadı: {label}. {error}
```

Put them in a constant map next to `FALLBACK_TEXT`, keyed by the same language resolution `bestEffort()` already uses (`classification['language']`, else message language, else `en`). Add the missing French `FALLBACK_TEXT` entry: `Je n'ai pas pu terminer cette demande entièrement.`

The label and error are appended, never embedded in translated grammar — word order stays correct in all five languages. These strings live in PHP on purpose: `ResultAssembler` has no translator. Do not add vue-i18n keys.

---

## Do not

- Do not add a `NodeStatus` value. Persistence, the task card and the API all switch on the current set.
- Do not change `ChatRunner`. All four text capabilities share its `run()`.
- Do not change Vue, OpenAPI, or the five locale JSON files. The card already shows `error`.
- Do not add a planner example or a migration. `PromptCatalog::planPrompt()` already contains "Pull data from a connected system, then answer (mcp_fetch)", and it already wires `$n1.text`. It has been there since v3.9.0. `tests/Eval/plan_eval_corpus.json` already has `mcp_knowledge_base_query`, which expects an `mcp_fetch` → `chat` edge.
- Do not change `EmailSearch` or `ToolCall` fallback behaviour.
- Playwright is not required. `plan` and `task_update` stay the same shape.

---

## Tests

| File | What to assert |
| ---- | -------------- |
| `tests/Unit/Service/Multitask/Execution/Runner/McpFetchRunnerTest.php` | `isError: true` with a NotFound body → `isReportableFailure()`, error contains the body and the server name, `query` and `mcp` metadata present, one warning logged. Empty-body `isError` → the "gave no details" sentence. A hallucinated tool and a disabled flag → `isReportableFailure()` is false. |
| `tests/Unit/Service/Multitask/Execution/Runner/McpActionRunnerTest.php` | Same for `isError: true` (note: the fixture needs a write-enabled server, otherwise the gate refuses before the tool call). Reportable, warning logged with `user_id` and `argument_keys`. A destructive tool and a missing tool stay hard failures. |
| `tests/Unit/Service/Multitask/Execution/DagExecutorTest.php` | Fetch reportable-failure → chat runs, `all_failed` is false. Fetch reportable-failure → `email_me` is skipped. One fetch ok, one reportable-failure, chat depends on both → chat runs. A `Stopped` dependency still skips its chat dependent. Cover `execute()` sequential. If a test already drives the parallel scheduler, add the same chat case there; do not build a parallel fixture from scratch if the file has none. |
| `tests/Unit/Service/Multitask/Execution/Runner/ChatRunnerHandoverTest.php` | A reportable failure is appended as a `· FAILED` block even when `inputs.text` is `$n1.text`. A hard failure is not appended. (The existing `testMissingSkipsFailedEmptyAndAlreadyPresentUpstreamText` uses a hard failure and must keep passing unchanged.) |
| `tests/Unit/Service/Multitask/Execution/Runner/ComposeReplyRunnerTest.php` (new; no compose-reply test file exists today) | Reply text is the successful step, and the failed step's error is appended. Second case: reply text already quotes the error verbatim → nothing appended twice. |
| `tests/Unit/Service/Multitask/TaskPlanExecutorTest.php` | Mirror `testFailedCodeRunDoesNotFallBackToLegacyRouter` for a one-node `mcp_fetch` plan and a one-node `mcp_action` plan: `routeStream` is never called, `plan_discarded` is not emitted, the streamed text is the assembled error. |
| `tests/Unit/Service/Multitask/Execution/ResultAssemblerTest.php` | Reply node produced nothing, one visible step failed → the reply contains the English "could not be completed" sentence, the label and the error. A failed hidden `compose_reply` alone produces no sentence. Language `fr` → the French fallback, not the English one. |

Then the backend gate, unfiltered: `make -C backend lint && make -C backend phpstan && make -C backend test`. PHPUnit must be the full `make -C backend test`, not a `--filter` run. Frontend lint, `vue-tsc` and Vitest are unaffected (no frontend or OpenAPI change); a full `make ci-local` also passes by virtue of touching nothing it checks.

Also update the failure paragraph of `docs/MULTITASK_DATA_NODES.md` (the "Three layers" handover section): one short paragraph on reportable failures — card stays `failed`, answer dependents still run, hard refusals still skip. No other docs change.

---

## Check by hand

Against a B2 MCP mock, as the demo user:

1. Ask whether a bucket that does not exist is reachable. The reply names it and says it was not found. The card stays failed. The log has the new warning.
2. Ask about one real bucket and one missing bucket. The reply mentions both.
3. Ask about a real bucket only. The answer matches today's behaviour.
4. Stop the mock and ask again. The reply says the system could not be reached. No legacy chat fallback.

---

## Not this bug

On every successful run the partner sees `plan did not hand over upstream step output`. That line is `ChatRunner` appending the fetch output because the resolved chat prompt did not already contain it verbatim. The #2327 safety net is doing its job, and the answers are correct.

The planner prompt already shows the right shape (`inputs.text` containing `$n1.text`). This model still lists the dependency and leaves the text unwired; the corpus checks the edge, not the `$n1.text` splice. Do not try to fix that here. A prompt change would not be verifiable without their OpenRouter model, and it is not what makes the 404 case wrong.
