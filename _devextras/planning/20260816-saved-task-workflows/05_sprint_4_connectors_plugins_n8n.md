# Sprint 4 — Outbound connectors, plugin graph nodes, n8n interface

**Goal:** Close the “then do something in the outside world” loop **without embedding n8n**. Ship: outbound webhook action, platform event webhooks (optional but recommended), plugin `graphNodes` seam (first consumer can be a stub or SortX if ready), MCP tools to list/run Saved Tasks, and `docs/N8N.md`.

**Depends on:** Sprints 1–3. **Does not depend on:** Office 365. Graph/Outlook write is a **follow-up epic** (see §7).

---

## 0. n8n decision (implement this, do not reopen)

From [master plan §4](./00_master_plan.md#4-n8n-embed-vs-interface):

- **Do not** add n8n to Docker / platform.
- **Do** treat n8n as a first-class *peer*: inbound (already works) + outbound (this sprint).
- User-facing action name: **Send to webhook**, not “n8n node”.

Prior research remains the implementation guide: [`../n8n-integration-research.md`](../n8n-integration-research.md), recipes in [`../n8n-integration-recipes.md`](../n8n-integration-recipes.md).

---

## 1. Outbound webhook action (Saved Task node)

### 1.1 Capability

New `Capability::OutboundWebhook = 'outbound_webhook'` **or** a Saved-Task-only action not in the planner catalog.

**Lock:** keep it **out of `tools:plan`** by default (do not let the LLM invent POSTs to arbitrary URLs). Palette: authored graph only. Planner must **not** see it in `[CAPABILITYLIST]` unless a future flag says otherwise.

### 1.2 Runner

- Config on the node: HTTPS URL, HMAC secret (encrypted at rest, never logged, never in error strings).
- SSRF guard (`SsrfGuard`) on the URL. Cloud: block link-local / private ranges unless operator env allowlist (same as MCP client).
- POST JSON: `{ "event": "saved_task.completed", "task": {…}, "run": {…}, "result": { "text": "…", "files": […] } }` (no secrets, no API keys).
- Header `X-Synaplan-Signature` HMAC-SHA256 (mirror Stripe verification already in repo).
- Timeout bounded. Isolated `NodeResult::failed()`.
- Counts against rate limits of the owning user.
- `allow_unattended` required to schedule.

### 1.3 Tests

- SSRF: `http://127.0.0.1` rejected.
- Signature fixture verified by a small PHP unit that computes the same HMAC.
- Secret not present in logs (spy on logger).
- Planner catalog does not include `outbound_webhook`.

---

## 2. Platform outbound events (Pattern B)

Per n8n research §6 — **optional in the same sprint if timeboxed**, otherwise a fast follow.

Events (start with two):

- `saved_task.completed` / `saved_task.failed`
- `message.classified` (topic + message id) — high value for “sales → CRM”

Entity: per-user outbound webhook records (`url`, `secret`, `events[]`, `active`). Delivery via **Messenger** (retry). Not a Saved Task node — this is “always emit when X happens”.

UI: Channels or Settings, **not** inside every Task Prompt. Saved Task node is for per-task calls; this is for platform-wide automation.

If only one ships: **prefer the Saved Task node** (user’s story). Platform events are for power users / n8n.

---

## 3. MCP tools

Add to `McpServerFactory` (flag-gated):

| Tool | Kind | Behaviour |
| ---- | ---- | --------- |
| `list_saved_tasks` | read | id, name, enabled, trigger type |
| `run_saved_task` | write | same as HTTP Run now; rate-limited; requires `message` argument |

n8n AI Agent can then trigger a Saved Task without a custom node.

Update MCP docs + characterization of tool list if tests snapshot tools.

API key scopes: research already notes scopes are **not** enforced per route. **Do not silently claim they are.** If you add `run_saved_task`, document that a leaked key can run tasks. Optional follow-up: enforce scopes (`saved_tasks:run`). Record as open question in the PR; do not block the sprint on a full scope redesign.

---

## 4. Plugin `graphNodes` seam

### 4.1 Manifest

Extend `PluginManifest` with optional `graphNodes` (empty default). Unknown keys ignored. Validate: `id`, `kind` (`process` \| `action` \| `trigger`), `endpoint` (plugin-local), `confirmation` (`none` \| `always`).

### 4.2 Runtime

`PluginGraphNodeRegistry`: union of installed plugins for the user. Palette in the editor lists them. Executor: HTTP call to the plugin’s existing namespaced route **as the user** (internal kernel subrequest or service call — **prefer a PHP service interface**, not HTTP-to-self).

If no plugin implements `graphNodes` yet:

1. Still ship the parser + empty registry + tests with a **fixture plugin** under `tests/` or `hello_world`.
2. SortX / Synafastbill / Synamail **do not** need to ship nodes in this sprint. Document the contract for those repos.

### 4.3 Confirmation

`confirmation: always` → interactive runs show `useDialog()`; scheduled runs require `allow_unattended` **and** the plugin node listed in the danger copy.

Synafastbill invariant: no write without confirmation — `graphNodes` for invoice create **must** be `always`. Do not add a FastBill node in core.

---

## 5. Documentation for n8n (required)

Create **`docs/N8N.md`** (user/operator facing, English):

1. Synaplan does not ship n8n.
2. Create an API key.
3. Pattern A: MCP Client Tool → `/mcp` (Streamable HTTP); OpenAI node → `/v1`; HTTP Request → `/api/v1/webhooks/generic`.
4. Pattern B: Saved Task action **Send to webhook** (HMAC). Optional platform events if shipped.
5. Pattern C: n8n as channel bridge (already in research).
6. Link recipes; keep `_devextras/planning/n8n-integration-recipes.md` as the long form **or** move the useful bits into `docs/N8N.md` and leave planning as historical.
7. Transport warning: force `httpStreamable` (copy from research).

Add a short “Integrations → n8n” paragraph to `docs/FEATURES.md` and `README.md` (README already mentions n8n as an example MCP server — distinguish **n8n calling Synaplan** vs **Synaplan calling n8n**).

Do **not** add n8n to `docker-compose.yml`.

---

## 6. Frontend

- Palette: **Send to webhook** action; form = URL + secret (secret write-only, never echoed back — show last-4 or “saved”).
- Plugin nodes: only if registry non-empty.
- i18n four locales.
- Contrast / V2 overlay rules.

---

## 7. Follow-up epics (do not implement here)

Track in the PR description as **not done**:

| Epic | Why later |
| ---- | --------- |
| Microsoft Graph calendar write | OAuth app, tenant admin consent, privacy manifests if mobile, store-required if in app, mutating calendar |
| Google Calendar | same class of work |
| Mutating MCP | violates current data-node contract; needs confirm + flags |
| `n8n-nodes-synaplan` community node | discoverability only |
| Synamail / Synasort / Synaform / Synafastbill graphNodes | separate PRs in those repos + plugin manifest bump |
| API key scope enforcement | cross-cutting security |

---

## 8. Testing

| Layer | Assert |
| ----- | ------ |
| Unit | OutboundWebhook runner: SSRF, HMAC, timeout → failed node, secret not logged |
| Unit | Planner catalog excludes outbound_webhook |
| Unit | PluginManifest parses `graphNodes`; invalid entry skipped/logged |
| Unit | Fixture plugin node appears in registry only when “installed” |
| Feature | Saved Task with webhook action (mock HTTP client) POSTs signed body |
| MCP | tools/list includes new tools only when flag on |
| Vitest | Webhook node form; secret not rendered after save |
| Docs | `docs/N8N.md` exists; markdownlint if this path is gated (planning folder is usually not CI-gated — **user docs in `docs/` may be**; check repo) |
| Gate | Unfiltered + generate-schemas |
| Characterization | Planner snapshots: **must not** start emitting outbound_webhook. Review diff |

---

## 9. Release gate

- [ ] No n8n container, submodule, or iframe anywhere in the diff.
- [ ] `docs/N8N.md` published; README distinction clear.
- [ ] Outbound POST SSRF-guarded, signed, secret-safe.
- [ ] Planner cannot invent outbound HTTP.
- [ ] Plugin seam tested with a fixture even if no real plugin ships nodes.
- [ ] Scheduled mutating webhook requires `allow_unattended`.
- [ ] Unfiltered gate green; mobile-impact updated.

---

## 10. Definition of done for the epic

Sprints 0–4 complete **and** the [master plan success criteria](./00_master_plan.md#10-success-criteria-epic) can be demoed:

1. Task Prompt “Extract meeting requests”.
2. Saved Task graph: schedule or Run now → `email_search` → `chat` → `calendar_event` → optional `email_me` / webhook.
3. Run history shows the executed graph (Sprint 0 UI).
4. n8n (external) can receive the webhook **or** call `run_saved_task` via MCP.
5. Flag off disables the product surface.
