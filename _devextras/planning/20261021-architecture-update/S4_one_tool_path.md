# S4 — One tool execution path

Master plan §5 rows AU23–AU26; architect direction 4. Contract:
[`03_contracts.md`](./03_contracts.md) §4. Loop:
[`01_vibe_coding_loop.md`](./01_vibe_coding_loop.md).

**Shape of the sprint:** pin every loop (AU23), put one invoker under all of
them without changing a decision (AU24), then close the two gaps — the gate
skipped by document tools and MCP reads, and the missing audit (AU25) — and
finally share the loop itself (AU26). This is also the single place the
out-of-process plugin plan (row 18) attaches its call token to MCP calls.

---

## AU23 — Characterization of every tool loop (backend-only)

### Goal

- Do: `tests/Characterization/ToolLoopCharacterizationTest.php` with
  `__snapshots__/tool_loops.json`. Scripted model turns that call tools
  through: `ChatToolLoop` (document edit), `GatewayToolLoop` (complete and
  stream; MCP read, MCP write, web search, image analysis, code run, custom
  HTTP, a client-owned tool, a mix), `OpenAiGatewayToolLoop` (same, no custom
  HTTP), `ToolCallRunner`, `McpActionRunner`, `McpFetchRunner`,
  `CodeRunRunner`, approval resume, Synaplan's own MCP server (`memory_add`,
  `file_ingest`).
- Each case records: which tools executed, the gate's decision (or "no
  gate"), approval rows, `BAUDITLOG` rows, `BUSELOG` rows, the result text
  after clamping, the stop reason (iterations, deadline, client tool, done).
- Approvals on and off, unattended and interactive.

### Vibe brief

> Read the STATUS tool map of 2026-10-21. Implement AU23 as a `test(tools)`.
> Script the model with the test provider's tool-call support or a scripted
> `ChatProviderInterface` double; MCP servers with the E2E MCP stub or a
> MockHttpClient. Record on the untouched code; the snapshot must show the
> known gaps (document tools and MCP reads without a gate; no audit row for
> executed tools; no usage row on the OpenAI loop) — list them in STATUS.
> Mutation: remove the gate call in `ToolCallRunner`, see the snapshot fail.
> `make ci-local`.

### Exit criteria

1. Every loop and runner of the list has cases; approvals on and off.
2. The gaps listed in STATUS with file and line.

---

## AU24 — One tool invoker behind every loop (backend-only)

### Goal

- Do: `App\Service\Tool\Execution\` with `ToolInvocation`, `ToolCaller`,
  `ToolOutcome`, `ToolLimits`, `ToolInvoker`, `ToolExecutorInterface` and one
  executor per source (`03` §4); the loops and runners call the invoker.
- Do: in this step the invoker reproduces today's decisions exactly, including
  the gaps (a `GateMode::AsToday` value per caller, removed in AU25) — so the
  snapshot is unchanged.
- Do: metrics `synaplan_tool_calls_total` and `tools[]` in the request
  timeline (AU13).
- Do not: change a decision, a result text or a row.

### Vibe brief

> Implement AU24 as a `refactor(tools)`. One loop per commit. The tool-loop
> snapshot and every stream transcript must stay identical. Unit tests per
> executor and for the clamp. `GatewayToolLoop` and `McpServerFactory` must
> shrink. `make ci-local`, `make test-e2e` (multitask, Saved Task, approvals,
> gateway specs named in the pull request), `make perf-runtime`.

### Exit criteria

1. Snapshots unchanged; `GatewayToolLoop` smaller.
2. Every loop and runner calls `ToolInvoker` (grep in STATUS).
3. Gates green.

---

## AU25 — Every tool call passes the approval gate and is audited (backend-only)

### User-flow

Journey **J-AU-5**: Ada opens People → Audit and finds, for one afternoon, a
document edit Ben made in chat, an MCP write Ben's coding assistant made
through `/v1/messages`, and a Saved Task step that created an issue — each
with who, which tool, the outcome and the approval if there was one. The
Audit tab exists (`components/people/AuditTab.vue`); this step adds rows, not
a screen.

### Goal

- Do: remove `GateMode::AsToday`: the gate runs on every call. Document
  tools on the person's own files are `write` with policy `auto` (no new
  prompt, §0 #11); MCP reads go through the gate (read ⇒ `auto`).
- Do: `tool.executed` rows in `BAUDITLOG` for write and destructive calls
  (`03` §4); usage rows on every loop (the OpenAI loop gains `TOOLS`).
- Do: Synaplan's own MCP server tools that change data go through the
  invoker (§0 #12).
- Do not: prompt anyone who was not prompted before (the snapshot diff must
  show no new `needs_approval` for an interactive person on their own files);
  touch the frontend — the Audit tab maps actions to labels in
  `AuditTab.vue` (lines 84–94), so its label is AU25b, stacked on this
  branch and merged right after it.

### Vibe brief

> Implement AU25 as a `fix(tools)`. Change the snapshot expectations in this
> commit only: every changed line is a closed gap (gate ran, audit row,
> usage row); quote the diff in the pull request grouped by gap. Walk J-AU-5
> on the dev stack and enter it in STATUS. `make ci-local`, `make test-e2e`.

### Exit criteria

1. The snapshot diff contains only the closed gaps of AU23's list; no new
   approval prompt for interactive own-file edits.
2. Gates green. (J-AU-5 is walked in AU25b.)

---

## AU25b — Tool calls in the audit log (ota-candidate)

### User-flow

Journey **J-AU-5** as above, on People → Audit.

```text
People › Audit     [ All ] [ Sharing ] [ Groups ] [ Tool calls ] …

  14:02  Ben Weber      Tool call · Edited "Q3 report.docx" (document tool) · done
  14:05  Ben Weber      Tool call · create_issue on "Jira (company)" via coding assistant · waited for approval #812 · done
  14:30  Saved Task     Tool call · create_issue on "Jira (company)" · step "File the ticket" · done
```

### Goal

- Do: the `tool.executed` label and a **Tool calls** filter chip in
  `AuditTab.vue`, one sentence per row built from the audit row's fields
  (tool display name, source, outcome, approval link), five locales; the
  approval id links to the Approvals inbox entry.
- Do not: show arguments or results (the row does not have them).

### Vibe brief

> Copy and the row sentence in five locales first (U1), then the component.
> Unit test for the row sentence per source; Playwright `@ci` spec: a
> document edit in chat and an MCP write appear under **Tool calls**. Walk
> J-AU-5, light / dark / 320 px, enter it in STATUS. `make ci-local`,
> `make test-e2e`, layout suite.

### Exit criteria (with the UX contract §6 bullets)

1. J-AU-5 walked (U10).
2. Findability: Ada finds the three calls in ten seconds with the chip (U2).
3. Row sentence says what the tool did on this kind of tool, in five locales (U3).
4. Empty state of the chip: one sentence and the link to Approvals (U5).
5. Dark + V2 + 320 px (U9).

---

## AU26 — One agent loop for document chat and both gateways (backend-only)

### Goal

- Do: `AgentToolLoop` (iterations, deadline, tools per turn, partition of
  Synaplan-owned and client-owned calls, error-as-result) with three thin
  adapters (`03` §4); `ToolLimits` the only place for the caps.
- Do: `GatewayToolLoop` leaves the size ledger.
- Do not: change a wire byte or a stop reason.

### Vibe brief

> Implement AU26 as a `refactor(tools)`. Snapshots and transcripts unchanged;
> the three copies of the caps and the iteration loop gone (grep in STATUS).
> `make ci-local`, `make test-e2e`, ledger regenerated with removals only.

### Exit criteria

1. One loop, three adapters; no duplicated cap constants.
2. `GatewayToolLoop` out of the size ledger; snapshots unchanged.

---

## S4 exit

- Every tool call on every channel passes one gate and leaves one audit row
  when it writes; J-AU-5 walked.
- The architect's fourth direction answered; row 18 (out-of-process plugins)
  attaches its call token in the MCP executor only.
