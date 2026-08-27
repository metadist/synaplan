# 02 — API Enhancement: Address Custom Prompts Directly

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
- `PromptService.getPromptWithMetadata(string $topic, int $userId, string $lang)` exists and works

### Key Discovery: `fixed_task_prompt` Already Exists!

Widget mode already implements **exactly this mechanism**:

```php
// StreamController.php line 448-449
if ($fixedTaskPromptTopic) {
    $processingOptions['fixed_task_prompt'] = $fixedTaskPromptTopic;
}

// MessageProcessor.php line 71, 81-98
$hasFixedPrompt = isset($options['fixed_task_prompt']) && !empty($options['fixed_task_prompt']);
if ($hasFixedPrompt) {
    // Still runs classification for language detection
    $classification['topic'] = $options['fixed_task_prompt'];
    $classification['source'] = 'widget';
}
```

This means `promptTopic` is **near-zero effort**: just accept the parameter in `StreamController` and pass it as `fixed_task_prompt`. The only change needed is to set `source` to `'api'` instead of `'widget'`.

### Prompt CRUD (Today)

Full CRUD at `/api/v1/prompts` — well annotated with OpenAPI. Users can create/read/update/delete prompts via API. But they **can't use them** when sending messages via API.

### Scope Implementation Detail

`ApiKey` entity has `hasScope(string $scope): bool` which uses literal `in_array()`. **No wildcard support.** So `hasScope('prompts:*')` only matches if `prompts:*` is literally stored in the scopes array. Design scopes accordingly:
- Use `prompts:*` as a literal "all prompts access" scope
- Check like: `$apiKey->hasScope('prompts:read') || $apiKey->hasScope('prompts:*')`

## What Changes

### Step 2.1 — Add `promptTopic` Parameter to Stream Endpoint

**Where:** `StreamController.php`

Add optional `promptTopic` parameter to `/api/v1/messages/stream`:

```php
$promptTopic = $request->get('promptTopic');
```

When `promptTopic` is provided:
1. Validate the topic exists for this user via `PromptService::getPromptWithMetadata()`
2. If not found → return 400 with clear error message
3. Pass as `$processingOptions['fixed_task_prompt'] = $promptTopic`
4. Classification still runs (for language detection), but topic is overridden

In `MessageProcessor`, differentiate API source from widget source:
```php
$classification['source'] = $isWidgetMode ? 'widget' : 'api';
```

**Files to change:**
- `backend/src/Controller/StreamController.php` — accept `promptTopic`, validate, pass to processor
- `backend/src/Service/Message/MessageProcessor.php` — set `source` to `'api'` when not widget mode

**Test:** Send message with `promptTopic=legal` → correct prompt used. Invalid topic → 400. No topic → auto-classification (existing behavior).

### Step 2.2 — Add `promptId` Parameter (Alternative Selector)

Allow selecting by ID for precision:

- `promptId` takes precedence over `promptTopic`
- Resolve ID to topic via `PromptRepository::find($id)`, validate ownership
- Return 403 if prompt belongs to another user, 404 if not found
- Then pass the resolved topic as `fixed_task_prompt` (same mechanism)

**Files to change:**
- `backend/src/Controller/StreamController.php` — accept `promptId`, resolve to topic
- `backend/src/Repository/PromptRepository.php` — ensure `find()` returns owner info

**Test:** Send with `promptId=42` → correct prompt. Wrong user's prompt → 403. Non-existent → 404.

### Step 2.3 — Scope Enforcement on Prompts API

Add scope checking to `PromptController` for API key access:

```php
$apiKey = $request->attributes->get('api_key');
if ($apiKey instanceof ApiKey) {
    if (!$apiKey->hasScope('prompts:read') && !$apiKey->hasScope('prompts:*')) {
        return $this->json(['error' => 'INSUFFICIENT_SCOPE', ...], 403);
    }
}
// Note: If user is authenticated via session (not API key), skip scope check
```

Define scopes:
| Scope | Allows |
|-------|--------|
| `prompts:read` | List, get prompts |
| `prompts:write` | Create, update prompts |
| `prompts:delete` | Delete prompts |
| `prompts:*` | All prompt operations (literal string) |

**Files to change:**
- `backend/src/Controller/PromptController.php` — add scope checks (only for API key auth, not session auth)
- Document scopes in OpenAPI annotations

**Test:** API key without scope → 403. With `prompts:read` → can list but not create. With `prompts:*` → full access. Session auth → no scope check needed.

### Step 2.4 — OpenAPI Annotation Updates

Update the streaming endpoint's OpenAPI annotations to document the new parameters.

Also add a "Prompt Selection" section explaining precedence:
1. `promptId` (explicit ID, highest priority)
2. `promptTopic` (topic name)
3. Auto-classification (default)

**Files to change:**
- `backend/src/Controller/StreamController.php` — add parameter annotations

**Test:** `GET /api/doc.json` includes new parameters with correct types and examples.

## Implementation Order

```
2.1 (promptTopic) → 2.2 (promptId) → 2.3 (scopes) → 2.4 (annotations)
```

## Notes

- **Steps 2.1 and 2.2 are trivial** — the `fixed_task_prompt` mechanism already does the heavy lifting
- Step 2.3 (scopes) is a security improvement that should ship together
- No new services needed — purely parameter plumbing and validation
