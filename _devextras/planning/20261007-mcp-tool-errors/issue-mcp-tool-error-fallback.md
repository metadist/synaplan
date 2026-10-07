Title: fix(multitask): an MCP tool error drops the plan or skips the answer step, and the reply hides the failure

**Kind:** Ready to implement. Steps 1–4 fix the reported behaviour; steps 5–6 harden it.

**Reported by:** partner hoster running the Backblaze B2 MCP server
**Version:** 5.2.0 (upgraded from 5.0.6). Code paths below are unchanged on `main` / 5.3.0.
**Follow-up to:** [#2327](https://github.com/metadist/synaplan/pull/2327) (fix(multitask): hand connected-system results to the answer step reliably)
**Release impact:** `fix:` → patch. Mobile classification: **backend-only** (`backend/**` plus one prompt migration).

Permalinks point to `e00af5ffb7c13a973647738494b16a795ea36b01` (current `main`).

---

## Summary

Read-only MCP lookups now work: the bucket reachability check passed 6 of 6 runs, and listing buckets and objects works too. When the MCP **tool itself returns an error**, though, the turn breaks in two ways, and neither is logged:

1. **A single failing MCP step**, for example a bucket that does not exist, where B2 returns `404 NotFound`. The plan is discarded and the **legacy chat router** answers. It cannot see connected systems, so it says *"there is no Backblaze connection available"*. That is false: the connection exists and B2 answered. Reproduced 5 of 5 times across two different prompts.
2. **A mixed request** asking about one real bucket and one missing bucket. The first check succeeds and the second fails with the 404. The **answer step is skipped** because one of its dependencies failed, and the reply is the raw output of the step that succeeded. The failure only shows on the task card. The reply never says that the second bucket was not found.

There are two more findings from the same test run:

3. **The tool error is never logged.** The `isError: true` path in `McpFetchRunner` returns a failed node and writes nothing to the log.
4. **The planner never wires the upstream result into the answer step.** The planner runs on the default PLAN model, which for this hoster is Luna via OpenRouter (`~openai/gpt-luna-latest`). On every successful run the log shows `ChatRunner: plan did not hand over upstream step output, appending it to the prompt`. The safety net from #2327 does the work every time, which means the planner prompt does not teach the wiring for `mcp_fetch`.

The partner asked whether MCP steps could skip the chat fallback the way `code_run` plans already do. **Yes, and that is part of the fix.** On its own, though, it would only replace a false answer with a generic *"I couldn't fully complete that request."* The complete fix also lets the answer step run when a data source reports an error, so the AI can explain what failed: *"The bucket `foo` does not exist in your Backblaze account."*

---

## Reporter setup

| Setting | Value |
| ------- | ----- |
| Synaplan | 5.2.0 (upgraded from 5.0.6) |
| MCP server | Backblaze B2 MCP (`s3_head_bucket`, `s3_get_bucket_location`, list buckets/objects) |
| Message sorting (SORT) | Luna, switched to match our setup |
| Planner (PLAN) | default (falls back to SORT → Luna), via OpenRouter `~openai/gpt-luna-latest` |

---

## Bug 1: a single failing MCP step falls back to legacy chat, which says no connection exists

### Steps to reproduce

1. Connect the Backblaze B2 MCP server (Manage → Connections → MCP Servers). `tool_mcp` is on for the `general` topic, which is the seeded default.
2. Ask: *"Is my Backblaze bucket `does-not-exist-123` reachable?"*

### Observed

- A task card appears briefly, then the plan is retracted (`plan_discarded`).
- The reply says there is no Backblaze connection available.
- The backend log has no line about the B2 error. It only shows `TaskPlanExecutor: DAG produced no successful node, falling back to legacy router`.

### Expected

- The reply says that B2 reported the bucket as not found and names the bucket, for example *"Backblaze reports that the bucket `does-not-exist-123` does not exist (NotFound). Check the name in your B2 account."*
- The task card stays and shows the same error.
- The log has a warning with the server, tool and error text.

### Root cause (code path)

1. **The tool answers with `isError: true`.** `McpClient::callTool()` passes `result.isError` through without throwing ([`McpClient.php` L98–119](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Mcp/McpClient.php#L98-L119)). A JSON-RPC-level error *would* be logged at L304–308, but B2 reports the 404 as a tool result. That fits the "nothing in the logs" observation.
2. **`McpFetchRunner` fails the node silently** ([`McpFetchRunner.php` L131–134](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/Execution/Runner/McpFetchRunner.php#L131-L134)):
   ```php
   $text = $this->formatContent($result['content']);
   if ($result['isError']) {
       return NodeResult::failed('the data source reported an error: '.mb_substr($text, 0, 300));
   }
   ```
   Only the transport-exception branch (L121–128) logs anything.
3. **The answer node is skipped.** The plan is `n1 mcp_fetch` → `n2 chat (depends_on n1)`. `DagExecutor::executeSequential()` skips every node whose dependency failed ([`DagExecutor.php` L112–118](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/Execution/DagExecutor.php#L112-L118)). The parallel scheduler does the same at L153–164, and both go through `failedDependency()` at L517–527.
4. **The plan counts as dead.** With `n1 = failed` and `n2 = skipped`, `ResultAssembler::assemble()` sets `all_failed = true` ([`ResultAssembler.php` L81](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/Execution/ResultAssembler.php#L81)).
5. **Only `code_run` is protected from the chat fallback.** `TaskPlanExecutor::executeStream()` ([L154–183](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/TaskPlanExecutor.php#L154-L183)) and `execute()` ([L231–252](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/TaskPlanExecutor.php#L231-L252)) skip the fallback only when `$plan->authored || $this->planRunsCode($plan->plan)`. `planRunsCode()` ([L296–305](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/TaskPlanExecutor.php#L296-L305)) checks for `Capability::CodeRun` only. Every other plan calls `discardPlan()`, which removes the card, and then re-runs the turn through `InferenceRouter`.
6. **The legacy chat router has no access to the connected system,** so it answers from its own knowledge and denies a capability the product has. This is the same failure mode the `planRunsCode()` docblock describes for code ("I can't execute code…"), now for MCP. It breaks U8 (honest outcome copy).

---

## Bug 2: in a mixed request (one good bucket, one missing), the answer step is skipped and the reply hides the failure

### Steps to reproduce

Ask: *"Check whether my buckets `real-bucket` and `does-not-exist-123` are reachable."*

### Observed

- `n1 mcp_fetch(real-bucket)` succeeds, `n2 mcp_fetch(does-not-exist-123)` fails with the 404, and `n3 chat (depends_on n1, n2)` is **skipped**.
- The reply only covers `real-bucket`, and it is the raw text of the `n1` tool output. The failure for the second bucket appears only on its task card.

### Expected

One answer that covers both buckets: *"`real-bucket` is reachable (region …). `does-not-exist-123` was not found in your Backblaze account."*

### Root cause (code path)

1. **Skipping on a failed dependency is all-or-nothing.** Point 3 of Bug 1 applies: one failed input skips the answer node, even though the answer node could explain the failure.
2. **There is no fallback to the chat router here.** `n1` succeeded, so `all_failed = false` and `TaskPlanExecutor` streams the assembled content directly.
3. **`ResultAssembler::bestEffort()` returns the last successful text node, which here is the raw MCP output, with no note about the failure** ([`ResultAssembler.php` L90–97 and L292–320](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/Execution/ResultAssembler.php#L292-L320)). The class docblock (L16–17) promises *"a best-effort answer (any successful text) with a short error note"*. No note is ever added. This breaks U8: the reply has to say what did **and did not** happen.
4. **`UpstreamHandover::missing()` drops failed dependencies** ([`UpstreamHandover.php` L44–54](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/Execution/UpstreamHandover.php#L44-L54), the `!$result->isSuccessful()` → `continue` check). If the answer node did run, it would still never learn that `n2` failed or why.

---

## Bug 3: the tool error is not logged

- `McpFetchRunner` has no log line on the `isError` path ([L131–134](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/Execution/Runner/McpFetchRunner.php#L131-L134)).
- `McpActionRunner` has the same gap for write actions ([L148–151](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/Execution/Runner/McpActionRunner.php#L148-L151)). For an external write, this is also an audit gap.
- The fallback line `TaskPlanExecutor: DAG produced no successful node, falling back to legacy router` (L165) logs only `message_id`. It does not say which node failed or why. The per-node errors are already in `$assembled['metadata']['task_plan_render']['cards'][*]['error']`.

---

## Finding 4: the planner never wires `$nX.text` from an `mcp_fetch` node

On every successful run, `ChatRunner` logs `plan did not hand over upstream step output, appending it to the prompt` ([`ChatRunner.php` L121–128](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/Execution/Runner/ChatRunner.php#L121-L128)). The safety net works as designed, but it should be the exception, not the rule.

Likely cause: rule **9c** in the `tools:plan` prompt ([`PromptCatalog.php` L931–939](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Prompt/PromptCatalog.php#L931-L939)) only says *"feed `$nX.text` into the answering node"*. The **"Canonical multi-step examples (MEMORIZE these patterns)"** section (from L971) has examples for media, documents and email, but **none for `mcp_fetch`**. Models copy the examples far more reliably than they follow rule text. The answer node then gets `"$message.text"` or the data under an unknown key, and `UpstreamHandover` has to fix it.

We cannot see the exact shape Luna emits, because the handover log line records only the node ids it appended, not the node's raw `inputs`.

---

## Minor: the French degraded-path text is missing

`ResultAssembler::FALLBACK_TEXT` ([L28–33](https://github.com/metadist/synaplan/blob/e00af5ffb7c13a973647738494b16a795ea36b01/backend/src/Service/Multitask/Execution/ResultAssembler.php#L28-L33)) has `en`, `de`, `es` and `tr`, but not `fr`, which is one of the five supported locales. French users get the English sentence.

---

## Proposed fix

The steps are ordered by impact. Steps 1–4 fix the reported behaviour (step 4 is the missing log line), and steps 5–6 harden it.

### 1. Let the answer step run when a data source reports an error (fixes Bugs 1 and 2)

A tool that *answered* with an error is information the user asked for: "that bucket does not exist" is the answer to "is that bucket reachable?". Treat it as a **reportable failure**: the card still shows `failed`, but answer-type dependents still run and get the error as input.

**`NodeResult`**: add a named constructor and a predicate, so runners and the executor do not have to pass around a magic metadata key:

```php
public const META_REPORTABLE = 'reportable_failure';

/**
 * The step failed, but its error is something the answering step should
 * explain to the user (a connected system answered with an error), so
 * answer-type dependents still run.
 */
public static function reportableFailure(string $error, array $metadata = []): self
{
    return new self(NodeStatus::Failed, error: $error, metadata: [self::META_REPORTABLE => true] + $metadata);
}

public function isReportableFailure(): bool
{
    return NodeStatus::Failed === $this->status && true === ($this->metadata[self::META_REPORTABLE] ?? false);
}
```

**`McpFetchRunner::run()`**: use it for errors the remote system reported, after the gate checks:

- `isError: true`: `NodeResult::reportableFailure(...)` with the server name and tool, and `query` metadata so the card summary still reads `Backblaze · s3_head_bucket`.
- `McpClientException` (unreachable, HTTP error, expired sign-in): also reportable, so the AI can say *"Backblaze could not be reached"* instead of the legacy router claiming there is no connection.
- Gate failures stay hard `NodeResult::failed()`: flags off, topic not entitled, hallucinated tool, mutating tool, missing params. These are planning or configuration errors, and the user should not get an AI paraphrase of them. Step 2 covers what they show instead.

**`DagExecutor`**: an answer node tolerates reportable failures. All three dependency checks must agree, or the sequential loop stalls on `blockedByIncompleteDependency()`:

```php
/** Capabilities that turn upstream output into the user's answer. */
private const ANSWER_CAPABILITIES = [
    Capability::Chat, Capability::Summarize, Capability::Translate,
    Capability::RagQuery, Capability::ComposeReply,
];

private function toleratesFailureOf(TaskNode $node, NodeResult $dep): bool
{
    return $dep->isReportableFailure() && in_array($node->capability, self::ANSWER_CAPABILITIES, true);
}
```

- `failedDependency()` (L517): a dependency that is settled and unsuccessful does not count when `toleratesFailureOf($node, $r)`.
- `blockedByIncompleteDependency()` (L533) and `dependenciesSatisfied()` (L496): treat a tolerated reportable failure as satisfied.
- Media, email, save and other action nodes keep today's skip. A step that *needs* the data must never run on an error string.

**`UpstreamHandover::missing()` / `render()`**: hand over reportable failures, labelled as failures. This has to happen even when the plan wired `$n2.text` correctly, because a failed node has no text and the reference resolves to empty:

```text
---
Data returned by the previous steps of this request.
Use it as the source of truth for your answer and never claim it was not provided.
A step marked FAILED did run: tell the user plainly what failed and why, and never claim the connection or data source does not exist.

[n1 · Backblaze · s3_head_bucket]
{ "bucket": "real-bucket", "region": "eu-central-003" }

[n2 · Backblaze · s3_head_bucket · FAILED]
the data source reported an error: NotFound: bucket does-not-exist-123 does not exist
```

`ComposeReplyRunner` needs nothing new: it depends on the chat node, and the chat node now succeeds.

**Result:**
- **Bug 1**: `n2 chat` runs and succeeds, so `all_failed = false` and there is no legacy fallback. The answer explains the 404, and the card stays with the error.
- **Bug 2**: `n3 chat` runs with `n1`'s data and `n2`'s error, so one answer covers both buckets.

### 2. Never fall back to legacy chat after a failed MCP plan (the partner's suggestion, for what step 1 does not cover)

Step 1 removes the fallback for tool errors. A plan can still fail completely for other reasons, for example a stale plan that names a tool the server no longer has. The legacy router cannot reach connected systems either, so the same false "no connection" answer would come back. Generalise `planRunsCode()` in `TaskPlanExecutor`:

```php
/**
 * Capabilities the legacy chat router cannot perform. Falling back to it
 * after such a plan failed produces an answer that denies the capability
 * ("I can't execute code", "there is no Backblaze connection") and hides
 * the real error (U8). Surface the node's own failure instead.
 */
private const NO_CHAT_FALLBACK_CAPABILITIES = [
    Capability::CodeRun,
    Capability::McpFetch,
    Capability::McpAction,
];

private function planNeedsCapabilityChatCannotPerform(TaskPlan $plan): bool
{
    foreach ($plan->nodes as $node) {
        if (in_array($node->capability, self::NO_CHAT_FALLBACK_CAPABILITIES, true)) {
            return true;
        }
    }

    return false;
}
```

Replace both `$this->planRunsCode($plan->plan)` call sites (L155 and L232) with it. `EmailSearch` and `ToolCall` belong to the same class, because the legacy router cannot search a mailbox or call a custom tool either. Add them in the same change if their degraded answers show the same symptom; otherwise, leave a follow-up note.

When this path triggers, the content is `ResultAssembler::bestEffort()`'s text, so step 3 has to make that text honest.

### 3. Make the degraded reply say what did not happen (Bug 2 safety net and U8)

`ResultAssembler::bestEffort()` should do what its docblock promises. When a reply is assembled without the reply node, add one plain sentence per failed **visible** step, built from `Capability::uiKind()` and the card summary. The raw error stays on the card:

- `en`: *"One step could not be completed: Backblaze · s3_head_bucket — see its card for the reason."*
- The same sentence in `de`, `es`, `fr` and `tr`, in the same constant map as `FALLBACK_TEXT`, plus the missing `fr` entry for `FALLBACK_TEXT` itself.

When nothing succeeded, the reply is the localized fallback sentence plus those lines. It is never empty, and it never claims that something did not exist.

### 4. Log the tool error (Bug 3)

- `McpFetchRunner`, `isError` branch:
  ```php
  $this->logger->warning('McpFetchRunner: tool reported an error', [
      'server_id' => $serverId,
      'tool' => $tool,
      'error' => mb_substr($text, 0, 300),
  ]);
  ```
- The same in `McpActionRunner` (`'McpActionRunner: write action reported an error'`, plus `user_id` and `argument_keys`, matching the success audit line).
- `TaskPlanExecutor`: add `'node_errors' => [nodeId => error]` (from the render cards) to both `DAG produced no successful node …` log lines.

### 5. Teach the planner the `mcp_fetch` wiring (Finding 4)

- Add two canonical examples under *"Canonical multi-step examples"* in `PromptCatalog::planPrompt()`: one lookup, and two lookups answered together.
  ```json
  {
    "version": 1, "language": "en", "reply_node": "n3",
    "tasks": [
      { "id": "n1", "capability": "mcp_fetch", "inputs": { "arguments": { "bucket": "real-bucket" } }, "params": { "server_id": 7, "tool": "s3_head_bucket" } },
      { "id": "n2", "capability": "mcp_fetch", "inputs": { "arguments": { "bucket": "other-bucket" } }, "params": { "server_id": 7, "tool": "s3_head_bucket" } },
      { "id": "n3", "capability": "chat", "depends_on": ["n1","n2"], "inputs": { "text": "Answer the user's question about both buckets based on:\n$n1.text\n\n$n2.text" } }
    ]
  }
  ```
  Mark the `server_id` and tool as placeholders ("use ids from the capability list, never these").
- Roll the change out to existing installs with an **idempotent, anchor-guarded migration** in the same style as `Version20260921010000`: global row only, skip it if a marker is present or the anchor is missing (operator-customised prompt), single-row `UPDATE`, no Schema API (Galera-safe).
- Extend the `ChatRunner` handover log line with `'raw_inputs' => $node->inputs` (truncated). The next report will then show the exact shape the planner emitted.
- Add an `mcp_fetch` case to the `app:multitask:plan-eval` corpus, so the wiring rate can be measured per planner model, Luna included (`--filter mcp --repeat 5`).

### 6. Card copy (small, optional)

The card error is `the data source reported an error: <raw tool text>`: lowercase, and the server is not named. Prefix it with the server name (`Backblaze reported an error: NotFound …`) so the card reads as one sentence a non-technical user can follow (U8).

---

## Tests to add or update

| File | Test |
| ---- | ---- |
| `tests/Unit/Service/Multitask/Execution/Runner/McpFetchRunnerTest.php` | `testToolErrorIsAReportableFailureAndIsLogged`: `tools/call` returns `isError: true` with NotFound text, so the result is `isReportableFailure()`, the error contains the text, and a logger `warning` is expected once. `testGateFailuresStayHardFailures`: a hallucinated tool or disabled flag gives `isReportableFailure() === false`. |
| `tests/Unit/Service/Multitask/Execution/DagExecutorTest.php` | `testAnswerNodeRunsWhenDataNodeReportsAnError`: `n1 mcp_fetch` (reportable failure) → `n2 chat`, so `n2` runs and `all_failed === false`. `testActionNodeStillSkipsOnReportableFailure`: `n1` reportable failure → `n2 email_me` is skipped. `testMixedLookupAnswersBoth`: `n1 ok`, `n2` reportable failure, `n3 chat(n1, n2)` runs. Cover both the sequential and the parallel scheduler. |
| `tests/Unit/Service/Multitask/Execution/UpstreamHandoverTest.php` (or the existing `ChatRunnerHandoverTest.php`) | A failed reportable dependency is rendered as a `· FAILED` block even when `$n2.text` was wired; a hard-failed dependency is not rendered. |
| `tests/Unit/Service/Multitask/TaskPlanExecutorTest.php` | `testFailedMcpPlanDoesNotFallBackToLegacyRouter`, mirroring `testFailedCodeRunDoesNotFallBackToLegacyRouter`, for `mcp_fetch` and `mcp_action`; `router->expects(never())->method('routeStream')`, and no `plan_discarded` event. |
| `tests/Unit/Service/Multitask/Execution/ResultAssemblerTest.php` | `testBestEffortNamesFailedSteps`: the reply contains the "could not be completed" line; `fr` gets French text. |
| `tests/Unit/PromptCatalogTest.php` + a migration test | The planner prompt contains the `mcp_fetch` example; the migration is idempotent (run twice → one insert) and leaves a customised prompt alone. |

Then run the full gate: `make ci-local`. Playwright is not required, because this changes no UI contract. The `plan` / `task_update` SSE shapes stay the same.

---

## Acceptance criteria (journey to walk in the browser against a B2 MCP mock)

1. *"Is my Backblaze bucket `does-not-exist-123` reachable?"* The reply names the bucket and says B2 reports it does not exist. The task card stays, in the failed state, with the same reason. The reply never says that no connection is available. The log has `McpFetchRunner: tool reported an error`.
2. *"Check `real-bucket` and `does-not-exist-123`."* One reply covers both buckets: the first is reachable, the second was not found. Two cards appear: one done, one failed.
3. Correct bucket only: unchanged. On a planner that wires correctly, the reply arrives **without** `plan did not hand over upstream step output` in the log after step 5.
4. B2 unreachable (stop the mock): the reply says Backblaze could not be reached, and the card shows the transport error. The turn does not fall back to legacy chat.
5. A stale plan with a tool that does not exist: no legacy fallback. The reply is the localized "could not fully complete" sentence plus the failed step line, and the card shows the reason.
6. Check all five locales for the new degraded-path sentences (`en`, `de`, `es`, `fr`, `tr`).

---

## Notes for the reporter

- The `plan did not hand over upstream step output` line on successful runs is the #2327 safety net doing its job. Answers are correct, and step 5 makes it the exception again.
- The PLAN model: Luna through OpenRouter (`~openai/gpt-luna-latest`) can differ from the Luna version we tested with. With the extended log line from step 5, one successful run is enough to show us the exact plan shape it produces.
