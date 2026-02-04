# BroGent — Task DSL (v0)

## Design Goals

- **Deterministic** execution (reliable automation, debuggable)
- **Composable** building blocks (“steps”)
- **Portable** between:
  - Browser extension executor
  - Playwright executor (CI)
- **Safe by default** (explicit approvals for risky actions)

## Task Object (conceptual)

```json
{
  "taskId": "task_01H...",
  "name": "WhatsApp: Send message",
  "enabled": true,
  "site": { "key": "whatsapp_web", "domainPatterns": ["https://web.whatsapp.com/*"] },
  "inputsSchema": {
    "type": "object",
    "required": ["to", "message"],
    "properties": {
      "to": { "type": "string" },
      "message": { "type": "string" }
    }
  },
  "steps": [ /* see below */ ],
  "requiredScopes": ["whatsapp:send"],
  "risk": "high"
}
```

## Step Structure

Every step has:

- `id`: stable step id
- `type`: enum
- `timeoutMs`: optional
- `retry`: optional `{ attempts, backoffMs }`
- `onError`: `fail | continue | goto:<stepId>`

```json
{ "id": "s1", "type": "navigate", "url": "https://web.whatsapp.com/" }
```

## Selectors (Scoped)

Selectors must be portable and robust. Site Packs define an `allowList` of selectors.

Preferred:

1) `role` + `name` (ARIA-like)
2) `data-testid` (for our mock sites)
3) CSS selector as fallback (must not match sensitive fields)
4) text selector as last resort (brittle, locale-dependent)

Selector object:
```json
{
  "kind": "role|testid|css|text",
  "value": "button[name=\"Send\"]"
}
```

## Core Step Types (v0)

### Navigation & readiness

- `navigate { url }`
- `wait_for_url { pattern }`
- `wait_for_visible { selector }`
- `sleep { ms }`

### Interaction (Hardened)

- `click { selector }`
- `type { selector, text, clear?: boolean }`
- `press { selector?, key }` (Enter, Escape)
- `select { selector, value }`
- `scroll_into_view { selector }`

**Safety Constraint**: `type` and `click` are blocked on `input[type="password"]` or sensitive ARIA roles unless the task is system signed.

### Extraction

- `extract_text { selector, saveAs }`
- `extract_attr { selector, attr, saveAs }`
- `extract_list { selector, itemSelector, fields, saveAs }`

### Control flow

- `if { condition, then: steps[], else: steps[] }`
- `foreach { listVar, itemVar, steps[] }`
- `set_var { name, value }`

### Safety / approvals

- `require_approval { kind, summaryTemplate, risk }`

### Artifacts

- `screenshot { label }`
- `save_html_snippet { selector?, label, maxBytes }`

## Conditions (v0)

Simple conditions, evaluated by executor:

- `exists(selector)`
- `text_contains(selector, "…")`
- `var_equals("name", "value")`

Represented as:
```json
{ "op": "exists", "selector": { "kind": "css", "value": "#compose" } }
```

## Variable Interpolation

Allow `${inputs.xxx}` and `${vars.xxx}` in strings.

Example:
```json
{ "type": "type", "selector": { "kind": "css", "value": "#msg" }, "text": "${inputs.message}" }
```

## Example: WhatsApp send message (pseudo)

```json
[
  { "id": "s1", "type": "navigate", "url": "https://web.whatsapp.com/" },
  { "id": "s2", "type": "wait_for_visible", "selector": { "kind": "css", "value": "[data-testid='chat-list']" } },
  { "id": "s3", "type": "click", "selector": { "kind": "css", "value": "[data-testid='new-chat']" } },
  { "id": "s4", "type": "type", "selector": { "kind": "css", "value": "[data-testid='search']" }, "text": "${inputs.to}", "clear": true },
  { "id": "s5", "type": "click", "selector": { "kind": "css", "value": "[data-testid='contact-result-0']" } },
  { "id": "s6", "type": "require_approval", "kind": "send_message", "risk": "high",
    "summaryTemplate": "Send WhatsApp message to ${inputs.to}: “${inputs.message}”"
  },
  { "id": "s7", "type": "type", "selector": { "kind": "css", "value": "[data-testid='composer']" }, "text": "${inputs.message}" },
  { "id": "s8", "type": "press", "key": "Enter" },
  { "id": "s9", "type": "screenshot", "label": "after-send" }
]
```

## Versioning & Compatibility

- `dslVersion`: integer, start at 1.
- Executors must:
  - reject unknown required fields
  - ignore unknown optional fields

## Planned Extensions (later)

- `wait_for_network_idle`
- `drag_drop`
- `upload_file`
- `clipboard_copy/paste`
- `otp_prompt` (human-in-loop)
- richer selectors (nth-match, within, fuzzy)

## Context windows (coding guide)

### Window A: DSL schema

- Define Zod schema for `Task`, `Step`, `Selector`.
- Include `dslVersion`, `requiredScopes`, `risk`.
- Test: valid task, invalid step.

### Window B: Interpreter core

- Implement step dispatch for navigate, wait, click, type.
- Enforce selector allowList and password blocking.
- Test: block unsafe selector.

### Window C: Playwright executor

- Map each step to Playwright calls.
- Emit events defined in `02-PROTOCOL.md`.
- Test: mock site run.

