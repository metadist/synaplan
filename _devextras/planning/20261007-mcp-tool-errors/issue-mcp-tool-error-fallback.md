Title: fix(multitask): an MCP tool error drops the plan or skips the answer step, and the reply hides the failure

**Kind:** Ready to implement. No open decisions. Do the five steps below and stop.

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

`compose_reply` is hidden and copies text. It does not call a model. The explanation has to be written by `chat` (or `summarize` / `translate` / `rag_query`).

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

Add a constructor and a predicate. Do not add a `NodeStatus` case. The card state stays `failed`, which the UI already renders.

```php
public const META_REPORTABLE = 'reportable_failure';

public static function reportableFailure(string $error, array $metadata = []): self
{
    return new self(NodeStatus::Failed, error: $error, metadata: [self::META_REPORTABLE => true] + $metadata);
}

public function isReportableFailure(): bool
{
    return NodeStatus::Failed === $this->status && true === ($this->metadata[self::META_REPORTABLE] ?? false);
}
```

**Files:** `McpFetchRunner::run()` and `McpActionRunner::run()`

Use `reportableFailure` only after the tool was actually called:

- `isError: true`
- `catch (McpClientException)` — the server was unreachable, returned HTTP 4xx/5xx, or the sign-in failed

Pass the same metadata the success path already passes for the card: `query` = server name + ` · ` + tool name. Put the server name in the error string, for example `Backblaze reported an error: ` plus the tool text, capped at 300 characters. An empty tool text still produces a sentence (`Backblaze reported an error and gave no details.`).

Log a warning before returning:

- `McpFetchRunner: tool reported an error` with `server_id`, `tool`, `error`
- `McpActionRunner: write action reported an error` with `user_id`, `server_id`, `tool`, `argument_keys`, `error`

Leave these as hard `NodeResult::failed()` with no new log line: flags off, missing params, server not owned or disabled, topic not allowed, tool not in the catalog, tool declares itself mutating. Those are our refusals, not an answer from the remote system.

---

## Step 2 — Let the answer step run anyway

**File:** `backend/src/Service/Multitask/Execution/DagExecutor.php`

An answer step may run when a dependency is a reportable failure. Every other capability keeps today's skip. A reportable failure is not permission to send an email, save a file, or call another tool.

Answer capabilities: `chat`, `summarize`, `translate`, `rag_query`, `compose_reply`.

`compose_reply` is on the list because the canonical reply node depends on the fetch and on the chat. If it stays skipped, the reply node is skipped even though the chat already wrote the explanation.

Add one private method and use it from all three existing checks: `failedDependency()`, `blockedByIncompleteDependency()`, and `dependenciesSatisfied()`. Do not patch only one of them.

```php
/** 'ready' | 'failed' | 'pending' */
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

    return 'failed'; // Failed, Skipped, and Stopped (a condition that was not met)
}
```

- `failedDependency()` returns the first dependency whose state is `failed`.
- `blockedByIncompleteDependency()` is true when any dependency is `pending`. It is not true for `ready` or `failed` (`failed` is already handled by the skip above it).
- `dependenciesSatisfied()` is true only when every dependency is `ready`.

Trap, sequential mode: `executeSequential()` walks the plan once. A node it does not run and does not skip stays `pending` forever. `ResultAssembler` treats pending as "still running", so `all_failed` stays false and the legacy fallback does not run either. The user then gets whatever text another step produced, with no explanation. A reportable failure must be `ready` for an answer node, never `pending`.

Trap, parallel mode: the loop exits when a pass starts nothing and nothing is in flight. A node that is neither ready nor skipped is dropped the same way. The same helper prevents that.

Do not treat `NodeStatus::Stopped` as reportable. A condition that evaluated false must still skip its dependents.

In `failureMetadata()`, when the result metadata has a non-empty string `query`, copy it onto the `task_update` the same way `successMetadata()` does. Otherwise the live card has the error but not the "Backblaze · s3_head_bucket" line that the reloaded card gets from `ResultAssembler`.

---

## Step 3 — Put the error in the prompt

**File:** `backend/src/Service/Multitask/Execution/UpstreamHandover.php`

`missing()` currently skips unsuccessful steps. Also collect reportable failures. Do not collect hard failures or skipped steps.

A reportable failure has no `$nX.text`, so the "already in the prompt" check cannot see it. Always include it, labelled `{nodeId} · {query} · FAILED`, value = the error string. Add one line to `render()`, after the existing "never claim it was not provided" line:

```text
A step marked FAILED did run. Say what failed and why, in the user's language. Do not say that the connection or the data source does not exist.
```

`ChatRunner` already calls `UpstreamHandover`. Do not change `ChatRunner`.

**File:** `backend/src/Service/Multitask/Execution/Runner/ComposeReplyRunner.php`

`compose_reply` does not call a model. After resolving `inputs.text`, append `UpstreamHandover::render(UpstreamHandover::missing(...))`. When the chat step already quoted the error, `missing()` finds it in the text and appends nothing. When the reply node copies only the successful fetch, the failed step is still in the reply.

---

## Step 4 — Do not fall back to legacy chat

**File:** `backend/src/Service/Multitask/TaskPlanExecutor.php`

Replace `planRunsCode()` with one check used at both `all_failed` branches (`executeStream` and `execute`):

```php
private const NO_CHAT_FALLBACK = [
    Capability::CodeRun,
    Capability::McpFetch,
    Capability::McpAction,
];
```

True when any node uses one of those. Keep the authored-plan exception as it is.

Do not add `EmailSearch` or `ToolCall` in this change.

On both "DAG produced no successful node" log lines, add `node_errors`: node id → error string, taken from `task_plan_render.cards`.

This path is what a hard failure still hits (unknown tool, feature off). The text it shows is step 5.

---

## Step 5 — Say which step failed when no answer was written

**File:** `backend/src/Service/Multitask/Execution/ResultAssembler.php`

`bestEffort()` runs only when the reply node produced nothing. That is the hard-failure case. When the chat step ran, its text is the reply and this method is not used.

Append one sentence per failed visible step (skip `uiKind() === 'hidden'`). Use the `query` metadata when it is set, otherwise the capability name. Then the error string. The raw error is the sentence. Do not write "see the card".

```text
en: One step could not be completed: {label}. {error}
de: Ein Schritt konnte nicht abgeschlossen werden: {label}. {error}
es: No se pudo completar un paso: {label}. {error}
fr: Une étape n'a pas pu être terminée : {label}. {error}
tr: Bir adım tamamlanamadı: {label}. {error}
```

Put them in a constant map next to `FALLBACK_TEXT`. Add the missing French `FALLBACK_TEXT` entry: `Je n'ai pas pu terminer cette demande entièrement.`

These strings live in PHP on purpose. `ResultAssembler` has no translator. Do not add vue-i18n keys.

---

## Do not

- Do not add a `NodeStatus` value. Persistence, the task card and the API all switch on the current set.
- Do not change Vue, OpenAPI, or the five locale JSON files. The card already shows `error`.
- Do not add a planner example or a migration. `PromptCatalog::planPrompt()` already contains "Pull data from a connected system, then answer (mcp_fetch)", and it already wires `$n1.text`. It has been there since v3.9.0. `tests/Eval/plan_eval_corpus.json` already has `mcp_knowledge_base_query`, which expects an `mcp_fetch` → `chat` edge.
- Do not change `EmailSearch` or `ToolCall` fallback behaviour.
- Playwright is not required. `plan` and `task_update` stay the same shape.

---

## Tests

| File | What to assert |
| ---- | -------------- |
| `tests/Unit/Service/Multitask/Execution/Runner/McpFetchRunnerTest.php` | `isError: true` with a NotFound body → `isReportableFailure()`, error contains the body, one warning logged. A hallucinated tool and a disabled flag → `isReportableFailure()` is false. |
| `tests/Unit/Service/Multitask/Execution/Runner/McpActionRunnerTest.php` | Same for `isError: true`: reportable, and a warning is logged. |
| `tests/Unit/Service/Multitask/Execution/DagExecutorTest.php` | Fetch reportable-failure → chat runs, `all_failed` is false. Fetch reportable-failure → `email_me` is skipped. One fetch ok, one reportable-failure, chat depends on both → chat runs. A `Stopped` dependency still skips its chat dependent. Cover `execute()` sequential. If a test already drives the parallel scheduler, add the same chat case there; do not add a parallel test from scratch if the file has no parallel fixture. |
| `tests/Unit/Service/Multitask/Execution/Runner/ChatRunnerHandoverTest.php` | A reportable failure is appended as a `FAILED` block even when `inputs.text` is `$n1.text`. A hard failure is not appended. |
| `tests/Unit/Service/Multitask/Execution/Runner/ComposeReplyRunnerTest.php` (new; there is no compose-reply test file today) | Reply text is the successful step, and the failed step's error is appended. |
| `tests/Unit/Service/Multitask/TaskPlanExecutorTest.php` | Mirror `testFailedCodeRunDoesNotFallBackToLegacyRouter` for a one-node `mcp_fetch` plan and a one-node `mcp_action` plan: `routeStream` is never called, `plan_discarded` is not emitted, the streamed text is the assembled error. |
| `tests/Unit/Service/Multitask/Execution/ResultAssemblerTest.php` | Reply node produced nothing, one visible step failed → the reply contains the English "could not be completed" sentence and the error. Language `fr` → the French fallback, not the English one. |

Then `make ci-local`. Backend-only: frontend lint, `vue-tsc` and Vitest may be skipped. PHPUnit must be the unfiltered `make -C backend test`, not a `--filter` run.

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
