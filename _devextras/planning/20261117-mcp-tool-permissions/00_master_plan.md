# MCP tool permissions: see every tool, choose auto, approve or block

**Status:** 2026-11-17. §0 #1–#14 are decided by the owner. TP1 is in
review; see [`STATUS.md`](./STATUS.md).
**Owner:** product owner.
**Roadmap:** row 47. Steps `TP0`–`TP6`, journeys `J-TP-1`–`J-TP-6`.
**Binding:** [`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) (U1–U12)
and root [`AGENTS.md`](../../../AGENTS.md).
**Code map taken on:** `main` @ `ac43ab7df` (2026-11-17).

## Goal

A person opens one of their MCP connections (Notion, Jira & Confluence,
GitHub, n8n, Higgsfield, a custom server) and sees every tool that
connection offers, each with a short description of what it does. Each
tool has one choice:

| Choice | Meaning in chat |
| ------ | --------------- |
| **Auto** | The AI uses the tool without asking. |
| **Approve** | The AI asks first. An approval card waits for a click. |
| **Block** | The AI never uses the tool. It is not offered to the model, and a call that names it anyway is refused on the server. |

Tools are grouped in categories (read, write, destructive). A category has
its own choice, which sets every tool in that category at once. The model
is the tool-permissions page of Claude's connector settings
(Overview | Permissions, one row per tool, one choice per row and per
group).

## §0 Decisions

| # | Decision | State |
| - | -------- | ----- |
| 1 | Permissions are stored **per connection**, not per assistant. | ✅ owner 2026-11-17 |
| 2 | An **Auto** set in chat does not unlock unattended runs (Saved Tasks, workflows, schedules). A write or destructive tool that resolves to Auto still asks in an unattended run unless the step sets `allow_unattended`. Block stays Block; Read Auto stays Auto. | ✅ owner 2026-11-17 |
| 3 | **Defaults:** every tool is Auto, write tools included. Destructive tools (delete, move to trash, cancel, overwrite) are Approve. Nothing is Block by default. The person may set any tool to any of the three, destructive tools to Auto included. | ✅ owner 2026-11-17 |
| 4 | Storage is **one JSON column** on `BMCPSERVERS`. It holds only the tools whose choice differs from the default. | ✅ owner 2026-11-17 |
| 5 | Notion's fixed block list shrinks to the tools that **cannot work** through a tool call (`create-file-upload`, `create-attachment`, `download-attachment`: they need a file transfer outside the call). They show greyed out as "not available" with that reason. Every other Notion tool (calendar, agents and sessions, skills, memory, delete, move) follows the defaults of §0 #3. | ✅ owner 2026-11-17 |
| 6 | **Existing connections switch to the new defaults.** No per-tool overrides are migrated from `BALLOWWRITE` / `BWRITEAUTO`. After the release the AI may write on connections where it asked or was refused before. | ✅ owner 2026-11-17 |
| 7 | On a **shared** Notion connection only the owner (the admin) sets permissions. Members see the list read-only. For members writing stays impossible, as today (`McpClientToolSource::sharedReads()` publishes read tools only). | ✅ owner 2026-11-17 |
| 8 | A call whose **arguments** delete content (Notion `update-page` with `replace_content`, any call with `allow_deleting_content: true`) is treated as a destructive call. Recommended: it always asks (Approve), even when the tool is set to Auto, and is refused when the tool is Block. Today `NotionWriteGuard` refuses it outright. | ✅ owner 2026-11-17 |
| 9 | A tool on a **custom server without hints** (no `readOnlyHint`, no `destructiveHint`) counts as **write**, so Auto. The MCP spec assumes `destructiveHint: true` when it is missing. Recommended: keep `SideEffect::fromHints()` (write) in line with §0 #3, and show "no hints from the server" on the row so the person can tighten it. For Notion tools the list in `NotionToolHints` decides, unknown Notion tools without hints count as destructive. | ✅ owner 2026-11-17 |
| 10 | The connection's choice **replaces the instance default** (`POLICY.write`, `POLICY.destructive` in `ToolsConfig`) for MCP tools. An admin restricts through the group policy, which still wins (most restrictive). Without this, the shipped instance default `POLICY.destructive = block` would block every destructive tool and contradict §0 #3. Recommended. | ✅ owner 2026-11-17 |
| 11 | **"Always allow"** on an approval card for an MCP tool sets that tool to Auto on its connection (one model, visible and reversible on the Permissions tab). Today it lands in a per-assistant list in `ToolsConfig` that no page shows. Recommended. | ✅ owner 2026-11-17 |
| 12 | The Notion app's switch **"The AI may change Notion"** (`PATCH /api/v1/apps/notion`) goes away. The Notion panel gets the same Permissions tab as every other connection. Recommended. | ✅ owner 2026-11-17 |
| 13 | A **one-time notice** on each existing connection after the release: "The AI may now use write tools here without asking. Review permissions." Needed because §0 #6 changes behaviour silently otherwise (U3). Recommended. | ✅ owner 2026-11-17 |
| 14 | `BALLOWWRITE` and `BWRITEAUTO` are **dropped** one release after TP2, once nothing reads them. Until then they are ignored. | ✅ owner 2026-11-17 |

### Decided while building TP1 (owner, 2026-11-17)

| # | Decision |
| - | -------- |
| 15 | The instance flag `TOOLS.APPROVALS_ENABLED` off: a connection's Block still holds, a tool that must ask (Approve, or a call that deletes content) is **refused** with a message, Auto runs. A call a person approved earlier still runs, unless the tool was blocked since. |
| 16 | A step that cannot pause for an approval (chat `mcp_fetch`) **refuses** a tool set to Approve instead of running it as Auto. |

## 1. Today (code map)

The three outcomes already exist. What is missing is a per-tool choice per
connection and any page that shows it.

| Piece | Where | What it does today |
| ----- | ----- | ------------------ |
| Policy | `backend/src/Service/Tool/Policy/ApprovalPolicy.php` | `auto` / `approve` / `block`, most restrictive wins across instance default, group policy, assistant definition and the MCP hard block. Afterwards `approve` may loosen to `auto` (unattended `allow_unattended`, per-assistant "always allow", connection `writeAuto`). |
| Instance default | `ToolsConfig::defaultOutcome()` | read `auto`, write `approve`, destructive `block` unless configured. |
| Connection switches | `Entity/McpServerConfig.php` (`BALLOWWRITE`, `BWRITEAUTO`) | `allowWrite` off = every non-read tool is Block (`ApprovalPolicy::hardBlock()`). `writeAuto` on = writes skip the card in interactive chat. |
| Assistant policy | `DefinitionAssistantPolicyProvider` | `tools.policy` per tool name or per class in an assistant definition. No UI. |
| Gate | `Service/Tool/ToolExecutionGate.php` | Runs the policy for chat tool calls, creates the `Approval`. |
| Class of a tool | `SideEffect::fromHints()` via `McpClientToolSource::describe()` | read if `readOnlyHint`, destructive if `destructiveHint`, else write. |
| Notion list | `Service/Mcp/Notion/NotionToolHints.php` | READ, WRITE and BLOCKED lists; BLOCKED and unknown tools get `destructiveHint: true`, which hides them. `update-page` is forced to write. |
| Destructive hard refusals | `McpActionRunner` (l. 151, 354), `McpFetchRunner` (l. 541), `McpToolCatalogAdapter::isMutatingTool()` | A tool with `destructiveHint: true` is hidden from planner catalogs and refused at run time, regardless of policy. |
| Argument guard | `Notion/NotionWriteGuard.php` | Refuses `allow_deleting_content`, unsafe `update-page` commands and some `update-data-source` changes. |
| Shared connection | `Notion/NotionSharing.php`, `McpClientToolSource::sharedReads()` | Admin shares read-only; members get read tools only. |
| Tool list API | `GET /api/v1/mcp-servers/{id}/tools` | Cached; returns `name` and `description` only. |
| UI | `components/config/McpServersConfiguration.vue` (790 lines, over the 300 limit) | Shows "N tools discovered" and chips after "Test". No choice per tool. |
| Notion UI | `components/apps/panels/NotionAppPanel.vue`, `notion/NotionConnectedSection.vue` | One switch "The AI may change Notion". |
| Approval UI | `components/chat/ApprovalCard.vue`, `config/ApprovalsInbox.vue`, `stores/approvals.ts` | Card in chat, inbox for unattended runs. |

## 2. Target model

### 2.1 Resolution for one MCP tool call

```
connection outcome  = override[tool] ?? default(class)          (§0 #3, #4)
                      default: read → auto, write → auto, destructive → approve
                      unusable (§0 #5) → block, not changeable
argument escalation = call deletes content → at least approve    (§0 #8)
restrictions        = group policy, assistant tools.policy, node override
resolved            = most restrictive(connection outcome, escalation, restrictions)
unattended          = resolved auto on write/destructive → approve,
                      unless the step sets allow_unattended       (§0 #2)
```

The connection outcome takes the place of today's instance default,
`hardBlock()` and `writeAuto` for MCP tools (§0 #10). Group policy and the
assistant definition can still only tighten. Nothing loosens a Block.

### 2.2 Storage (§0 #4)

New column `BMCPSERVERS.BTOOLPOLICY` (`LONGTEXT`, JSON, default `'{}'`):

```json
{
  "v": 1,
  "tools": { "notion-update-page": "approve", "notion-delete-pages": "block" },
  "seen": ["notion-search", "notion-fetch", "..."]
}
```

- `tools`: only overrides. An empty object means "all defaults".
- `seen`: tool names the owner has already been shown. A tool the server
  ships later is not in `seen`; it gets its class default and a "New"
  badge until the owner opens the Permissions tab (§3.4).
- Keys are the tool names as the server reports them. A key whose tool
  disappeared is kept (the server may bring it back) and not shown.
- Migration: raw idempotent SQL (`ALTER TABLE … ADD COLUMN IF NOT EXISTS`),
  per `docs/MIGRATIONS.md`. No data migration (§0 #6).

### 2.3 Categories

| Category | Members | Default |
| -------- | ------- | ------- |
| Read | `SideEffect::Read` | Auto |
| Write | `SideEffect::Write` | Auto |
| Destructive | `SideEffect::Destructive` | Approve |
| Not available | unusable tools (§0 #5) | Block, fixed |

A category's choice is a bulk action: it writes the chosen outcome for
every tool of the category into `tools`. When the tools of a category
differ, the category shows "Mixed". Setting a category to its default
removes those keys again, so `tools` stays minimal.

### 2.4 Notion re-classification (§0 #5)

`NotionToolHints` keeps READ and WRITE, adds DESTRUCTIVE, and BLOCKED
shrinks to UNUSABLE:

- **Destructive:** `delete-pages`, `delete-databases`, `delete-comment`,
  `move-pages`, `restore-version`, `calendar-cancel-events`,
  `stop-session`.
- **Write (new):** `update-folder`, `create-folder` (already),
  `calendar-create-events`, `calendar-update-events`, `spawn-session`,
  `send-message-to-session`, `upload-skill`, `convert-page-to-skill`,
  `update-enabled-skills`, `create-suggested-edit`,
  `resolve-suggested-edit`.
- **Read (new):** `calendar-list-*`, `search-agents`, `get-session-status`,
  `wait-session`, `query-sessions`, `search-sessions`,
  `list-session-events`, `read-session-event`, `get-enabled-skills`,
  `download-skill`, `memory-search`, `connector-search`,
  `get-version-changes`, `view-version`, `get-suggested-edit`.
- **Unusable:** `create-file-upload`, `create-attachment`,
  `download-attachment`.
- **Unknown, no hints:** destructive (§0 #9).

The seven tools missing from today's lists (`connector-search`, the three
version tools, the three suggested-edit tools) are added above.
`show-advanced-analysis-next-steps` is no longer documented by Notion and
is dropped.

## 3. Surfaces and copy

Copy is reviewed before the component is built (UX contract §6). English
first; the other four locales in the same step.

### 3.1 Where

- **Apps → MCP:** each connection row gets a **Permissions** action. It
  opens the connection detail with two tabs, **Overview** (today's
  status, test, edit, OAuth) and **Permissions**.
- **Apps → Notion:** the panel gets the same two tabs. The write switch
  goes away (§0 #12).
- Deep link: `/apps/mcp?server={id}&tab=permissions` (and
  `/apps/notion?tab=permissions`), used by the approval card (§3.5).

### 3.2 Permissions tab (wireframe)

```
Notion                                                   [connected]
Tool permissions
Choose when the AI may use these tools. Saved Tasks and workflows still
ask before they write, unless the step allows it.

v Read tools  (31)                                  [✓ Auto        v]
    Search                                          [✓ Auto        v]
    Find pages and databases by topic or title.
    Show more
    > Takes 4 inputs: query, filters, page_size and 1 more
    ...
v Write tools  (24)                                 [✓ Auto        v]
    Update page                                     [✋ Approve    v]  (changed)
    ...
v Destructive tools  (7)                            [✋ Approve    v]
    Delete pages                     New            [✋ Approve    v]
    Move pages to Trash. Notion keeps them in Trash for 30 days.
    ...
> Not available  (3)
    Create file upload                              Not available
    Needs a file transfer outside the chat.
```

- One row per tool: title (the tool's `title`, else the name humanised),
  description clamped to two lines with **Show more**, a collapsed
  "Takes N inputs" line from `inputSchema` with a raw-schema toggle.
- The selector has three items with one Heroicon outline each:
  `check-circle` **Auto**, `hand-raised` **Approve**, `no-symbol`
  **Block**. Category selector adds **Mixed** as a display state only.
- Locked rows (group policy, shared member, not available) show the
  selector disabled and one line why.
- No-hints rows (§0 #9) show "The server does not say what this tool
  changes."
- Descriptions are the server's own text and are not translated.

### 3.3 Saving

- Every change saves at once (`PUT`), with a toast and **Undo**
  (`useNotification()`), no Save button.
- Setting a **destructive** tool or the destructive category to **Auto**
  first asks through `useDialog()`: "The AI will delete or overwrite in
  Notion without asking you. Continue?"
- A category change that touches more than one tool says how many in the
  toast ("12 write tools set to Approve").

### 3.4 States (U5, U8, U11)

- Not connected / OAuth expired: the tab says so and links to Connect.
- Tool discovery failed: last cached list with "Could not refresh the
  tools of this connection", retry button; no list at all: empty state.
- MCP client disabled for the account: the tab explains it, no selectors.
- New tools: "New" badge until the tab was open once (`seen`).
- Shared member: read-only list with "{owner} manages these permissions".

### 3.5 Chat

- The approval card names the tool by title and the connection, and has
  **Change permission** (deep link, §3.1). "Always allow" sets the tool
  to Auto on its connection (§0 #11).
- A blocked tool: the model is not offered it. When the person asks for
  something only that tool can do, the answer says which tool is blocked
  and links to the Permissions tab, instead of failing silently.

### 3.6 One-time notice (§0 #13)

Shown once per existing connection after the release, on its Overview tab
and as a dot on its Permissions tab: "The AI may now use write tools on
this connection without asking. Destructive tools still ask. Review
permissions."

## 4. API

| Method | Path | Body / answer |
| ------ | ---- | ------------- |
| `GET` | `/api/v1/mcp-servers/{id}/tools` (extended) | per tool: `name`, `title`, `description`, `input_count`, `input_names`, `input_schema`, `category` (`read`/`write`/`destructive`/`unavailable`), `outcome`, `default_outcome`, `hinted` (bool), `is_new` (bool), `locked_reason` (`null`/`group_policy`/`shared`/`unavailable`). Top level: `can_edit`. |
| `PUT` | `/api/v1/mcp-servers/{id}/tool-permissions` | `{ "tools": { "<name>": "auto" \| "approve" \| "block" \| "default" } }` or `{ "category": "write", "outcome": "approve" }`. Answers the same shape as `GET`. 403 for a shared member. 422 for an unusable tool or an unknown outcome. |
| `POST` | `/api/v1/mcp-servers/{id}/tool-permissions/seen` | Marks every current tool as seen. |

Complete OpenAPI annotations; regenerate Zod
(`make -C frontend generate-schemas`) and use them through `httpClient` and
`services/api/`. A new controller `McpToolPermissionController` keeps
`McpServerConfigController` from growing; logic lives in services. The
Notion app keeps its status endpoint; `PATCH /api/v1/apps/notion` is
removed with the switch (§0 #12).

## 5. Steps

| Step | What | Release class | Needs |
| ---- | ---- | ------------- | ----- |
| TP0 | This plan | `no-app-impact` | — |
| TP1 | Storage and resolution: migration for `BTOOLPOLICY`; entity accessors; `McpToolPermissions` service (override ?? default, categories, unusable); `ApprovalPolicy` uses the connection outcome for MCP tools instead of instance default, `hardBlock()` and `writeAuto` (§2.1); unattended rule (§0 #2); destructive hard refusals removed from `McpActionRunner`, `McpFetchRunner` and `McpToolCatalogAdapter` so the gate decides; `NotionToolHints` re-classified (§2.4); `NotionWriteGuard` refusals become an escalation (§0 #8). Unit tests for every row of §2.1. Routing snapshots re-recorded if the planner catalog drifts. | `backend-only` | §0 #8, #9, #10 |
| TP2 | API of §4 with OpenAPI, Zod regenerated, "always allow" for MCP tools writes the connection (§0 #11), Notion write endpoint removed (§0 #12). | `backend-only` | TP1, §0 #11, #12 |
| TP3 | Copy and wireframe review (§3) in all five locales, then the Permissions tab: `McpToolPermissions.vue`, `McpToolCategory.vue`, `McpToolRow.vue`, `ToolOutcomeSelect.vue` (each under 300 lines), Overview/Permissions tabs on the MCP connection and the Notion panel, deep link, states of §3.4. `McpServersConfiguration.vue` must not grow; the tabs are a new component. | per `.github/mobile-impact-policy.json` (web UI) | TP2 |
| TP4 | Chat: approval card title, connection and **Change permission**; blocked-tool answer with link (§3.5); one-time notice (§3.6). | per policy file | TP3, §0 #13 |
| TP5 | Playwright `@ci` spec for J-TP-1 to J-TP-6; `docs/CONNECTIONS.md` and `docs/ADMIN.md` updated (connection choice vs. group policy); changelog line about §0 #6. | `no-app-impact` (tests, docs) | TP4 |
| TP6 | Drop `BALLOWWRITE` / `BWRITEAUTO` one release later (§0 #14). | `backend-only` | §0 #14 |

TP1 and TP2 may land as one pull request if the diff stays reviewable;
TP3 and TP4 are separate. One topic, one branch, one pull request, green
before the next (root `AGENTS.md`).

## 6. Journeys

| ID | Journey |
| -- | ------- |
| J-TP-1 | I open my Notion connection, switch to Permissions and find what "Delete pages" does and what it is set to within ten seconds (U2). |
| J-TP-2 | I set "Update page" to Approve, see the toast, press Undo, and it is Auto again. In chat, renaming a page now shows an approval card. |
| J-TP-3 | I set the Write category to Block. All write tools show Block. In chat the AI says it cannot change Notion and links to Permissions. I set the category back to Auto. |
| J-TP-4 | I set "Delete pages" to Auto, confirm the dialog, and the AI moves a test page to Trash without asking. |
| J-TP-5 | An approval card for "Create pages" appears; I press "Always allow"; the Permissions tab now shows Create pages as Auto; a Saved Task that creates pages still waits in the approvals inbox (§0 #2). |
| J-TP-6 | As a member using the admin's shared Notion connection I see the list read-only with the admin's name and no write tools. |

## 7. Exit and gates

- Every step: `make ci-local` unfiltered; TP2–TP5 also `make test-e2e`;
  TP2 `make -C frontend generate-schemas` and `vue-tsc`.
- TP3/TP4 exit bullets (UX contract §6): journeys walked in the browser on
  the running stack (U10); findability in ten seconds (U2); consequence
  copy in five locales (U3), in particular the destructive-Auto dialog and
  the one-time notice; empty, error and flag-off states (U5, U8, U11);
  dark, V2 and 320 px (U9).
- Size ratchet, deptrac and locale parity only shrink.
- A unit test proves that a Block cannot be reached as Auto through any of:
  assistant "always allow", `allow_unattended`, node override, shared
  connection, or a tool name the model invents.
- A unit test proves that every direct MCP call outside the gate
  (`NotionGateway`, `NotionPageWrite`, the linked-page readers,
  `NotionAccountReader`) only reaches read tools or user-triggered app
  actions, and none of them reaches a tool the owner set to Block.

## 8. Risks

- **Silent behaviour change** for existing connections (§0 #6). Mitigated
  by the notice (§0 #13) and the changelog.
- **Overlap between tools.** Blocking `delete-pages` does not stop
  `update-page` from emptying a page. §0 #8 covers the Notion arguments
  that delete content; the Permissions copy does not promise more than
  per-tool control.
- **Wrong hints from a server.** A tool that says read but writes gets
  Auto. An explicit Block still holds. §0 #9 shows missing hints.
- **Catalog size.** Offering calendar, agent and skill tools to the
  planner adds tokens on every planned turn. Measure the catalog size
  before and after TP1 and record it in STATUS.
