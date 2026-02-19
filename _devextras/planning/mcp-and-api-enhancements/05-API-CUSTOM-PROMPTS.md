# 05 — API Enhancement: Address Custom Prompts Directly

## Goal

API callers can specify **which custom prompt** (task prompt) to use when sending a chat message. Currently, the streaming endpoint doesn't accept a prompt selector — the system picks the prompt via classification. API users need direct control.

## Current State

### Prompt Selection Flow (Today)

```
User sends message → MessageClassifier detects topic → PromptService loads prompt for topic
```

- `StreamController` (`/api/v1/messages/stream`) accepts: `message`, `chatId`, `webSearch`, `reasoning`, `modelId`, `fileIds`, `voiceReply`
- **No `promptTopic` or `promptId` parameter** — callers can't choose a prompt
- The classifier picks the topic automatically
- `PromptService.getPromptForTopic(string $topic, int $userId, string $lang)` exists and works

### Prompt CRUD (Today)

Full CRUD at `/api/v1/prompts` — well annotated with OpenAPI. Users can create/read/update/delete prompts via API. But they **can't use them** when sending messages via API.

### Missing Scope Enforcement

`PromptController` doesn't check API key scopes. Any authenticated key can access prompts. Should add `prompts:*` scope.

## What Changes

### Step 5.1 — Add `promptTopic` Parameter to Stream Endpoint

**Where:** `StreamController.php`

Add optional `promptTopic` parameter to `/api/v1/messages/stream`:

```php
#[OA\Parameter(
    name: 'promptTopic',
    description: 'Topic of the custom prompt to use (e.g., "customersupport", "legal-review"). Overrides auto-classification.',
    in: 'query',
    required: false,
    schema: new OA\Schema(type: 'string', example: 'customersupport')
)]
```

When `promptTopic` is provided:
1. Skip classification (or still classify for logging, but don't use the result for prompt selection)
2. Load the prompt for the given topic via `PromptService`
3. If topic not found → return 400 with clear error message

**Files to change:**
- `backend/src/Controller/StreamController.php` — accept `promptTopic`, pass to processor
- `backend/src/Service/Message/MessageProcessor.php` — respect overridden topic

**Test:** Send message with `promptTopic=legal` → correct prompt used. Invalid topic → 400. No topic → auto-classification (existing behavior).

### Step 5.2 — Add `promptId` Parameter (Alternative Selector)

Allow selecting by ID for precision:

```php
#[OA\Parameter(
    name: 'promptId',
    description: 'ID of a specific prompt to use. Takes precedence over promptTopic.',
    in: 'query',
    required: false,
    schema: new OA\Schema(type: 'integer', example: 42)
)]
```

- `promptId` takes precedence over `promptTopic`
- Validate that the prompt belongs to the user (or is a system prompt)
- Return 403 if prompt belongs to another user

**Files to change:**
- `backend/src/Controller/StreamController.php`
- `backend/src/Service/Message/MessageProcessor.php`
- `backend/src/Service/PromptService.php` — add `getPromptById(int $id, int $userId): ?Prompt`

**Test:** Send with `promptId=42` → correct prompt. Wrong user's prompt → 403. Non-existent → 404.

### Step 5.3 — Scope Enforcement on Prompts API

Add scope checking to `PromptController`:

```php
// In each endpoint method:
$apiKey = $request->attributes->get('api_key');
if ($apiKey instanceof ApiKey) {
    if (!$apiKey->hasScope('prompts:read') && !$apiKey->hasScope('prompts:*')) {
        return $this->json(['error' => 'INSUFFICIENT_SCOPE', 'message' => 'Requires prompts:read scope'], 403);
    }
}
```

Define scopes:
| Scope | Allows |
|-------|--------|
| `prompts:read` | List, get prompts |
| `prompts:write` | Create, update prompts |
| `prompts:delete` | Delete prompts |
| `prompts:*` | All prompt operations |

**Files to change:**
- `backend/src/Controller/PromptController.php` — add scope checks
- Document scopes in OpenAPI annotations

**Test:** API key without scope → 403. With `prompts:read` → can list but not create. With `prompts:*` → full access.

### Step 5.4 — OpenAPI Annotation Updates

Update the streaming endpoint's OpenAPI annotations to document the new parameters:

```php
#[OA\Post(
    path: '/api/v1/messages/stream',
    summary: 'Stream a chat message with optional prompt selection',
    // ... existing annotations ...
    parameters: [
        // ... existing params ...
        new OA\Parameter(name: 'promptTopic', ...),
        new OA\Parameter(name: 'promptId', ...),
    ]
)]
```

Also add a "Prompt Selection" section to the API docs explaining the precedence:
1. `promptId` (explicit ID, highest priority)
2. `promptTopic` (topic name)
3. Auto-classification (default)

**Files to change:**
- `backend/src/Controller/StreamController.php` — update annotations

**Test:** `GET /api/doc.json` includes new parameters with correct types and examples.

### Step 5.5 — API Usage Example in Docs

Add a clear usage example showing how to use custom prompts via API:

```bash
# List available prompts
curl -H "X-API-Key: sk-xxx" https://web.synaplan.com/api/v1/prompts

# Send message using a specific prompt topic
curl -X POST -H "X-API-Key: sk-xxx" \
  "https://web.synaplan.com/api/v1/messages/stream?chatId=1&promptTopic=legal-review" \
  -d "message=Review this contract for compliance issues"

# Send message using a specific prompt ID
curl -X POST -H "X-API-Key: sk-xxx" \
  "https://web.synaplan.com/api/v1/messages/stream?chatId=1&promptId=42" \
  -d "message=Review this contract for compliance issues"
```

**Where:** OpenAPI description fields + optional `_devextras/planning/api-examples.md`

## Implementation Order

```
5.1 (promptTopic) → 5.2 (promptId) → 5.3 (scopes) → 5.4 (annotations) → 5.5 (docs)
```

## Notes

- Steps 5.1 and 5.2 are the core value — users can finally address prompts from the API
- Step 5.3 (scopes) is a security improvement that should ship together
- This is a **small, focused change** — mostly parameter plumbing, no new services needed
