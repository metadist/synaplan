# Frontend Voice Reply Toggle

**Phase 2 of Voice Conversations**

## Overview

Add a toggle in the chat input that tells the backend: "also generate an MP3 for this response." Auto-enabled when the user uses the microphone.

## UI Design

```
┌─────────────────────────────────────────────────────────┐
│  [📎]  Type your message...                  [🔊] [🎙] [➤] │
└─────────────────────────────────────────────────────────┘
                                                ^^^^
                                          Voice reply toggle
                                          (blue when active)
```

The `🔊` button (speaker icon) toggles `voiceReply` state:
- **Off** (default): Normal text response
- **On** (blue/highlighted): Backend generates text + MP3
- **Auto-on**: Activates when mic recording starts; stays on for that message

## Files to Modify

### 1. `frontend/src/components/ChatInput.vue`

**Add state:**
```typescript
const voiceReply = ref(false)
```

**Add toggle button** (in the primary actions section, before mic button):
```html
<button
  type="button"
  :class="[
    'h-[44px] min-w-[44px] flex items-center justify-center rounded-xl pointer-events-auto',
    voiceReply ? 'bg-[var(--brand)] text-white' : 'icon-ghost',
  ]"
  :aria-label="$t('chatInput.voiceReply')"
  :title="$t('chatInput.voiceReplyTooltip')"
  data-testid="btn-voice-reply"
  @click="voiceReply = !voiceReply"
>
  <Icon icon="mdi:volume-high" class="w-5 h-5" />
</button>
```

**Auto-enable on mic use:**
```typescript
const toggleRecording = async () => {
  if (!isRecording.value) {
    voiceReply.value = true // Auto-enable voice reply
  }
  // ... existing logic
}
```

**Pass to emit:**
```typescript
const sendMessage = () => {
  emit('send', {
    message: message.value,
    voiceReply: voiceReply.value,
    // ... existing fields
  })
  voiceReply.value = false // Reset after send
}
```

### 2. `frontend/src/views/ChatView.vue`

**Forward `voiceReply` to stream:**
```typescript
const handleSendMessage = async (payload: SendPayload) => {
  // ... existing logic
  await streamAIResponse(content, {
    ...options,
    voiceReply: payload.voiceReply,
  })
}
```

**Add to SSE params:**
```typescript
const streamAIResponse = async (message: string, options?: StreamOptions) => {
  // ... existing logic
  const stopFn = chatApi.streamMessage(
    userId, message, trackId, chatId, handleStreamUpdate,
    includeReasoning, webSearch, modelId, fileIds,
    options?.voiceReply // new param
  )
}
```

### 3. `frontend/src/services/api/chatApi.ts`

**Add `voiceReply` param to `streamMessage`:**
```typescript
streamMessage(
  userId: number,
  message: string,
  trackId: number | undefined,
  chatId: number,
  onUpdate: (data: any) => void,
  includeReasoning: boolean = false,
  webSearch: boolean = false,
  modelId?: number,
  fileIds?: number[],
  voiceReply?: boolean // NEW
): () => void {
  const paramsObj: Record<string, string> = { ... }
  if (voiceReply) paramsObj.voiceReply = '1'
  // ... rest unchanged
}
```

### 4. `frontend/src/views/ChatView.vue` — Handle `audio` SSE event

In the `handleStreamUpdate` callback:
```typescript
if (data.status === 'audio') {
  // TTS audio ready — add audio part to current streaming message
  historyStore.addAudioToLastMessage(data.url)
}
```

### 5. `frontend/src/stores/history.ts`

**Add helper:**
```typescript
function addAudioToLastMessage(url: string) {
  const lastMsg = messages.value[messages.value.length - 1]
  if (lastMsg && lastMsg.role === 'assistant') {
    lastMsg.parts.push({ type: 'audio', url })
  }
}
```

### 6. i18n Translations

**`en.json`:**
```json
"chatInput": {
  "voiceReply": "Voice reply",
  "voiceReplyTooltip": "Include audio response (MP3)"
}
```

**`de.json`:**
```json
"chatInput": {
  "voiceReply": "Sprachantwort",
  "voiceReplyTooltip": "Audioantwort (MP3) anhängen"
}
```

## Backend: StreamController Changes

### `StreamController::streamMessage()`

**Accept param:**
```php
$voiceReply = (bool) $request->query->get('voiceReply', '0');
```

**After streaming completes (after outgoing message is saved):**
```php
if ($voiceReply && !empty($responseText)) {
    // GUARD 1: Mediamaker double-generation prevention
    $handlerIntent = $classification['intent'] ?? 'chat';
    if ('image_generation' === $handlerIntent) {
        $voiceReply = false;
    }
    
    // GUARD 2: Rate Limit Check
    // We check 'AUDIOS' limit. If exceeded, we skip audio but still deliver text.
    if ($voiceReply) {
        $limitCheck = $this->rateLimitService->checkLimit($user, 'AUDIOS');
        if (!$limitCheck['allowed']) {
            $this->logger->warning('Voice reply skipped: Rate limit exceeded', ['user_id' => $user->getId()]);
            // Optional: Send warning toast to frontend
            // $this->sendSSE('error', ['message' => 'Audio limit reached']);
            $voiceReply = false;
        } else {
            // Record usage only if we actually proceed
            $this->rateLimitService->recordUsage($user, 'AUDIOS');
        }
    }
}

if ($voiceReply && !empty($responseText)) {
    try {
        $language = $classification['language'] ?? 'en';
        // Strip [Memory:ID] badges, markdown, code blocks, <think> tags
        $ttsText = TtsTextSanitizer::sanitize($responseText);
        $ttsText = mb_substr($ttsText, 0, 4000);

        $ttsResult = $this->aiFacade->synthesize($ttsText, $user->getId(), [
            'format' => 'mp3',
            'language' => $language,
        ]);

        $audioUrl = '/api/v1/files/uploads/'.$ttsResult['relativePath'];

        // Store on outgoing message
        $outgoingMessage->setFile(1);
        $outgoingMessage->setFilePath($audioUrl);
        $outgoingMessage->setFileType('audio');
        $this->em->flush();

        $this->sendSSE('audio', ['url' => $audioUrl]);
    } catch (\Throwable $e) {
        $this->logger->warning('Voice reply TTS failed', [
            'error' => $e->getMessage(),
        ]);
        // Don't fail the response — text was already delivered
    }
}
```

## UX Details

- Voice reply toggle is **session-scoped** (resets on page reload)
- After sending with voiceReply, the flag resets to off
- When mic is used, voiceReply auto-enables but user can manually disable before sending
- Audio player appears below the text response after streaming completes
- Loading indicator: small spinner next to speaker icon while TTS generates
- If TTS fails, text response is unaffected — no error shown to user

## SSE Event Sequence (with voiceReply)

```
data: {"status":"token","chunk":"Hello"}
data: {"status":"token","chunk":" world"}
data: {"status":"complete","messageId":123,"provider":"openai","model":"gpt-4.1"}
data: {"status":"audio","url":"/api/v1/files/uploads/13/000/00013/2026/02/tts_abc123.mp3"}
```

The `audio` event arrives **after** `complete` because TTS runs post-streaming.
