# Sprint B2 — Tools and policy

**Phase B (`synaplan/`), sprint 2 of 4.** Steps `CS11`–`CS17`.

**Goal:** Compute becomes a governed tool: `code_execution` is offered in both `/v1` gateways (Anthropic
Messages and OpenAI) only to API keys carrying the `compute:run` scope, `code_run` is registered as a
write-class tool in the track-4 registry with the decided defaults (interactive `auto`, unattended
`approve`, hosters may set `approve` instance-wide), an assistant whose track-2 skill allow-list excludes
`code_run` can never plan or call it, and every invocation path writes the same audit row.
**Depends on:** B1; track 4 S1–S3 (`ToolRegistry`, `ApprovalPolicy`, `waiting_approval` resume); track 2 S4
(assistant skill allow-list seam in `SkillCatalog`).
**Unlocks:** B3 (egress policy rides on the same tool policy). **Repos:** `synaplan/` only.
**Flag:** `COMPUTE.ENABLED` (B1). New `BCONFIG` settings in group `COMPUTE`: `POLICY_INTERACTIVE`
(default `auto`), `POLICY_UNATTENDED` (default `approve`).

---

## 0. Why this sprint exists

B1 made compute reachable from the planner in the user's own chat. Two more doors exist — the `/v1` gateways
used by coding clients and desktop, and unattended saved-task runs — and both need the answer to "who may
open this?" before they open. Master plan §0 row 10 fixes the answer (`write` class; `auto` interactive,
`approve` unattended) and §11 decision 4 lets a hoster tighten it. This sprint wires those decisions to code
paths that already exist in track 4 rather than inventing a compute-specific gate.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/AI/Messages/Tools/GatewayToolLoop.php` (`injectTools`, `stripServerTools`, `runComplete`, `runStream`) | Where server tools are injected and executed for `/v1/messages` |
| `backend/src/AI/OpenAI/OpenAiGatewayToolLoop.php` | Same for the OpenAI-compatible gateway |
| `backend/src/AI/Messages/Translator/{OpenAiMessagesTranslator,GeminiMessagesTranslator}.php` (`code_execution_*` comments) | Upstream server-tool names already pass through; ours must not collide |
| `backend/src/Security/ApiKeyScope.php` (`requiredScopesForPath`, `grants`, `isRestricted`) | Scope vocabulary and the grandfather rule |
| `../202609_tools_approval_workflows/00_master_plan.md` §4.2 (policy resolution), §4.3 (pause/resume) | `code_run` enters as one more `ToolDescriptor` |
| `backend/src/Service/Multitask/Skill/SkillCatalog.php` + the track-2 allow-list seam | Where an assistant's `code_run` exclusion is enforced |
| `backend/src/Service/SavedTask/` (`allow_unattended`, run states); `backend/src/Service/Compute/CodeRunRunner.php` (B1) | Unattended path that must pause; the single executor every door calls |

---

## 2. Developer steps

### 2.1 Scope `compute:run` (`CS11`)

`ApiKeyScope::COMPUTE_RUN = 'compute:run'`. It is not a path scope: `/v1/*` stays gated by `desktop:messages`.
It is a **tool grant** read by the loops: the `code_execution` tool is injected only when the key's normalized
scope list contains `compute:run` or `*`. Legacy empty-scope and webhook-only keys do **not** receive it —
running code is new, so no existing integration loses anything, and the grandfather rule stays untouched for
paths. Pairing and add-in scope sets are unchanged; the API-key UI offers the scope as a checkbox only when
`ComputeConfig::isEnabled()`.

### 2.2 `code_execution` in both gateways (`CS12`, `CS13`)

`backend/src/AI/Messages/Tools/CodeExecutionTool.php` — one class, two adapters. Input schema:

```json
{
  "type": "object",
  "additionalProperties": false,
  "required": ["language", "code"],
  "properties": {
    "language": { "enum": ["python", "node"] },
    "code": { "type": "string", "maxLength": 65536 },
    "input_file_ids": { "type": "array", "items": { "type": "integer" }, "maxItems": 10 },
    "timeout_sec": { "type": "integer", "minimum": 1, "maximum": 300 }
  }
}
```

Result content: `{ status, exit_code, stdout (truncated), stderr (truncated), artefacts: [ { file_id, name,
mime, size } ] }`. `GatewayToolLoop::injectTools` adds it when the key grants the scope and
`ComputeConfig::isEnabled()`; `stripServerTools` treats an upstream `code_execution_*` server tool as replaced
by ours (the translator comments already anticipate this). `OpenAiGatewayToolLoop` exposes the same tool as a
function `code_execution` with the same schema. Both call `CodeRunRunner` through a thin `CodeExecutionInvoker`
so the runner stays the only place that talks to `ComputeClient`. `BCOMPUTERUNS.BINVOKEDVIA` =
`gateway_anthropic` | `gateway_openai`.

### 2.3 Track-4 policy wiring (`CS14`)

`backend/src/Service/Compute/ComputeToolSource.php` tagged `app.tool.source` publishes `ToolDescriptor{ name:
'code_run', sideEffect: 'write', source: 'compute' }` only when enabled. `ApprovalPolicy::decide` receives, as
the tool-level base, `COMPUTE.POLICY_INTERACTIVE` for `interactive` and `COMPUTE.POLICY_UNATTENDED` for
`unattended`; `mostRestrictive(base, group, agent, task, hard)` still applies, so a hoster setting
`POLICY_INTERACTIVE = approve` in Operate → System config cannot be loosened by an assistant, and
`allow_unattended` turns `approve` into `auto` only where the instance allows it. `CodeRunRunner` and
`CodeExecutionInvoker` ask the policy before submit: `approve` → `BAPPROVALS` row, `ApprovalCard.vue` in chat
or `waiting_approval` node in a saved-task run, resume after decision; `block` → readable refusal. Egress or a
persistent workspace in the request never lowers the decision below the tool policy (checked again in B3).

### 2.4 Assistant skill allow-list (`CS15`)

The track-2 allow-list on the assistant definition is consulted in three places with one helper
`AssistantSkillGate::allows($assistant, 'code_run')`: `SkillCatalog` (planner never sees the capability for that
assistant), `GatewayToolLoop` / `OpenAiGatewayToolLoop` (tool not injected when the request runs under an
assistant that excludes it), and `CodeRunRunner` (defence in depth: refuse with `assistant_forbids_code_run` if
a plan arrives anyway). Default for existing assistants: **excluded** — an admin or owner opts an assistant in.

### 2.5 Audit rows for every door (`CS16`) and gateway fixtures (`CS17`)

`BCOMPUTERUNS.BINVOKEDVIA ∈ planner | gateway_anthropic | gateway_openai | saved_task`, `BPROMPTID` (assistant),
`BSAVEDTASKRUNID`, and a new nullable `BAPPROVALID` (raw `ALTER TABLE … ADD COLUMN IF NOT EXISTS`). The approval
decision itself stays in `BAPPROVALS` / `BAUDITLOG` (track 4 / IAM); the compute row links to it.
`_devextras/testing/messages-gateway/` gains `code_execution_request.json`, `code_execution_result.json`
(Anthropic) and `openai_code_execution_call.json`; the gateway contract tests assert the tool list **unchanged**
without the scope and a **superset** with it.

---

## 3. Tests and invariants

- **C8**: `GatewayCodeExecutionToolTest::testToolAbsentWithoutScope`, `::testToolAbsentForLegacyEmptyScopeKey`,
  `::testToolPresentWithScope`, `OpenAiGatewayCodeExecutionToolTest` mirror; existing gateway snapshot tests
  unchanged with the flag off; widget and mobile suites untouched; `node scripts/mobile-impact.mjs` classifies
  every PR `backend-only`.
- **C1**: characterization snapshots unchanged (planner text changes only for opted-in assistants, which no
  fixture is). **C3**: `CodeExecutionInvokerTest::testOwnerIsKeyOwner`, `::testForeignFileIdsRefused`,
  `::testTimeoutClampedToCaps`.
- Policy: `ComputePolicyTest::testInteractiveDefaultAuto`, `::testUnattendedDefaultApprove`,
  `::testHosterApproveCannotBeLoosened`, `::testBlockWins`; `SavedTaskComputePauseTest` (pause → approve →
  resume → artefacts) and `…RejectTest` (readable failure).
- Allow-list: `AssistantSkillGateTest` for all three enforcement points; `UtterancePlanCharacterizationTest`
  with an opted-out assistant fixture shows no `code_run` node.
- Audit: `ComputeRunAuditTest::testEveryDoorWritesRow`, `::testNoScriptText`. API-key scope UI strings in
  five locales; `localeParity.spec.ts` green.

---

## 4. Exit criteria / demo

1. A `/v1/messages` call with a key lacking `compute:run` lists no `code_execution`; with the scope it runs a
   script and returns an artefact `file_id` that downloads through `/api/v1/files`. The OpenAI gateway does
   the same with a function call.
2. A scheduled saved task with a `code_run` step pauses; the owner approves from the inbox; the run
   completes; `BCOMPUTERUNS.BAPPROVALID` is set.
3. Instance set to `POLICY_INTERACTIVE = approve`: the user's own chat shows the approval card first; an
   assistant definition cannot switch it back.
4. An assistant without `code_run` never plans it and gets a readable refusal if called through a gateway
   with an otherwise valid key.
5. Full gate green; every PR classified `backend-only`.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| CS11 | `feat(compute-client): compute:run api-key scope as a tool grant` | backend-only | B1 |
| CS12 | `feat(compute-client): code_execution tool in the anthropic gateway loop` | backend-only | CS11 |
| CS13 | `feat(compute-client): code_execution function in the openai gateway loop` | backend-only | CS12 |
| CS14 | `feat(compute-client): code_run as a write-class tool with policy defaults` | backend-only | B1, track 4 S3 |
| CS15 | `feat(multitask): assistant skill allow-list gate for code_run` | backend-only | CS14, track 2 S4 |
| CS16 | `feat(compute-client): invocation path and approval id in BCOMPUTERUNS` | backend-only | CS14 |
| CS17 | `test(compute-client): gateway contract fixtures for code_execution` | backend-only | CS13 |
