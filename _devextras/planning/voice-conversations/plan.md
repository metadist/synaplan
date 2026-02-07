# Voice Conversations — Master Plan

**Date:** 2026-02-07

## Goal

When the user speaks (mic button, WhatsApp voice, or email audio attachment), Synaplan answers with **text + MP3 audio** attached. The user can also explicitly toggle "reply with voice" in the web chat.

## Current State

| Component | Status |
|-----------|--------|
| Mic button (Web Speech / Whisper) | ✅ Working — transcribes to text, sends as text message |
| WhatsApp audio → TTS response | ✅ Working — `WhatsAppService::shouldSendAudioResponse()` + `generateTtsResponse()` |
| Email audio handling | ❌ No audio response logic |
| TTS providers (OpenAI, Google) | ✅ Registered in BMODELS (`text2sound`) |
| Self-hosted Piper TTS | ✅ Running at `synaplan-tts:10200` — **not integrated** |
| Frontend `MessageAudio.vue` | ✅ Audio player exists for playback |
| Chat history: WhatsApp/Email chats | ❌ Not visible in web UI sidebar |
| Chat entity `source` field | ❌ Missing — no channel differentiation |

## Architecture

```
User speaks ──► Transcription (Whisper/WebSpeech) ──► Text
                                                        │
                              voiceReply flag ───────────┤
                                                        ▼
                                               AI processes text
                                                        │
                                                        ▼
                                               Text response (streamed)
                                                        │
                                         ┌──── voiceReply? ────┐
                                         │                     │
                                    Yes  ▼                No   ▼
                              TTS synthesize            Done (text only)
                                    │
                                    ▼
                           MP3 file saved
                                    │
                              ┌─────┼──────────┐
                              │     │          │
                         Web  ▼  WhatsApp   Email
                      SSE 'audio'  sendMedia  attach MP3
                      event        API call   in reply
```

## Phases

### Phase 1: Piper TTS Provider (backend)

Register `synaplan-tts` (Piper) as a provider in the AI system so it can be selected as default TTS.

**See:** `piper-provider.md`

**Deliverables:**
- `PiperProvider.php` implementing `TextToSpeechProviderInterface`
- BMODELS fixture entry (tag `text2sound`, service `Piper`)
- Config: `SYNAPLAN_TTS_URL` env var (dev: `http://host.docker.internal:10200`, prod: `http://10.0.1.10:10200`)
- WAV→MP3 conversion (ffmpeg) since Piper outputs WAV
- Set as default TTS model via BCONFIG seed

### Phase 2: Voice Reply Toggle (frontend + SSE)

Add a `voiceReply` flag that tells the backend "also generate MP3 for this response."

**See:** `frontend-voice-reply.md`

**Deliverables:**
- `voiceReply` toggle button in `ChatInput.vue` (speaker icon, sticky per session)
- Auto-enable when mic input is used
- SSE param `voiceReply=1` passed to `StreamController`
- `StreamController` calls TTS after text streaming completes, sends `audio` SSE event
- `MessageAudio.vue` renders inline below text response
- History store persists audio part on reload

### Phase 3: Backend Voice Reply Pipeline

Wire the TTS generation into `StreamController` so the web chat can get audio replies.

**Changes to `StreamController::streamMessage()`:**
1. Accept `voiceReply` query param
2. After streaming completes and `$responseText` is assembled:
   - **Mediamaker guard:** If `intent === 'image_generation'`, skip voice reply.
   - **Rate Limit Check:** Verify user has quota for `AUDIOS`. If exceeded, log warning and skip TTS (do not fail the text response).
   - If `voiceReply=1` and checks pass:
     - Call `AiFacade::synthesize($responseText, $userId)`
     - Save file, get relative path (files are protected by `FileServeController` ownership checks)
     - Send SSE event: `$this->sendSSE('audio', ['url' => $displayUrl])`
     - Store `BFILEPATH` + `BFILETYPE=audio` on outgoing message
3. Frontend already handles `audio` part type in history store (line ~389)

**Changes to `ChatController::messages()`:**
- Already returns `file.type=audio` with `file.path` — no change needed
- Verify WAV/MP3 MIME types are served correctly by `FileServeController`

### Phase 4: WhatsApp & Email Audio Detection Improvements

**WhatsApp (verify existing):**
- `shouldSendAudioResponse()` already detects voice-only → ✅
- Ensure chat is created/assigned so messages appear in web history → Phase 5

**Email:**
- In `WebhookController::handleInboundEmail()` and `InboundEmailHandlerService`:
  - Detect audio attachments (mp3, wav, ogg, m4a)
  - Transcribe via Whisper (already in `MessagePreProcessor`)
  - Set `voiceReply` metadata on message: `$message->setMeta('voice_reply', '1')`
- In `WebhookController` (response logic after `MessageProcessor` returns):
  - Check `voice_reply` meta + `AUDIOS` rate limit
  - If true: `AiFacade::synthesize()` → get path
  - Pass attachment path to `InternalEmailService::sendAiResponseEmail()`
  - `InternalEmailService` needs update to accept optional attachment path

### Phase 5: Channel Unification — WhatsApp & Email in Chat History

Show WhatsApp and Email conversations in the web UI sidebar and chat view.

**See:** `channel-unification.md`

**Deliverables:**
- Add `BSOURCE` column to `BCHATS` table (`web`, `whatsapp`, `email`, `widget`)
- Migration: backfill from first message `BPROVIDX` per chat
- `ChatController::list()` returns `source` field
- Frontend sidebar: channel icons (💬 🟢 📧 🔌) next to chat titles
- Frontend sidebar: disclosure group or filter for channel type
- `ChatController::messages()`: include file attachments from WhatsApp/Email messages
- Frontend history store: render attached files (images, audio, documents) for all channels

## Dependencies

```
Phase 1 ──► Phase 2 ──► Phase 3
                              │
Phase 4 (parallel) ──────────┤
                              │
Phase 5 (parallel) ──────────┘
```

Phase 1 is prerequisite (need a working free TTS provider). Phases 4 and 5 can start in parallel with Phase 2/3.

## Database Changes

| Table | Change | Migration |
|-------|--------|-----------|
| `BMODELS` | INSERT Piper TTS model row | Fixture / SQL |
| `BCONFIG` | INSERT default TTS provider = `piper` | Fixture / SQL |
| `BCHATS` | ADD `BSOURCE VARCHAR(16) DEFAULT 'web'` | Doctrine migration |

## Environment Changes

| Variable | Dev default | Production | Where |
|----------|-------------|------------|-------|
| `SYNAPLAN_TTS_URL` | `http://host.docker.internal:10200` | `http://10.0.1.10:10200` | `docker-compose.yml` (dev default), `backend/.env` (prod override), Admin UI |

Named after the service (`synaplan-tts`), not the engine (Piper). Follows the same pattern as `OLLAMA_BASE_URL`, `TIKA_BASE_URL`, `QDRANT_SERVICE_URL`. See `piper-provider.md` §5 for all 4 config layers.

## Memory Integration

Memories are loaded by `ChatHandler` from Qdrant and injected into the system prompt. The AI is instructed to reference them as `[Memory:ID]` badges in its response. This works perfectly for text — `MessageText.vue` renders them as clickable badges.

**Problem:** When `voiceReply=1`, the raw `$responseText` is fed to TTS. It contains:
- `[Memory:42]` badges → TTS speaks "Memory colon forty-two"
- Markdown: `**bold**`, `## heading`, `[link](url)` → spoken as-is
- Code blocks: ` ```php ... ``` ` → spoken as code
- `<think>...</think>` tags → reasoning tokens leaked into audio

**Solution:** Add a `TtsTextSanitizer` utility (or static method) that strips non-speakable artifacts before synthesis. Used by all TTS callers.

### New: `backend/src/Service/TtsTextSanitizer.php`

```php
final class TtsTextSanitizer
{
    /**
     * Strip non-speakable artifacts from AI response text.
     * Call this BEFORE passing text to AiFacade::synthesize().
     */
    public static function sanitize(string $text): string
    {
        // 1. Remove <think>...</think> reasoning blocks
        $text = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $text);

        // 2. Remove [Memory:ID] badges
        $text = preg_replace('/\[Memory:\d+\]/', '', $text);

        // 3. Remove code blocks (```...```)
        $text = preg_replace('/```[\s\S]*?```/', '', $text);

        // 4. Remove inline code (`...`)
        $text = preg_replace('/`[^`]+`/', '', $text);

        // 5. Remove markdown links [text](url) → keep text
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);

        // 6. Remove markdown formatting (**bold**, *italic*, ~~strike~~)
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/', '$1', $text);
        $text = preg_replace('/~~([^~]+)~~/', '$1', $text);

        // 7. Remove headings (## ...)
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);

        // 8. Remove HTML tags
        $text = strip_tags($text);

        // 9. Collapse whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }
}
```

### Where to call it

| Caller | Location |
|--------|----------|
| `StreamController` (Phase 3) | Before `AiFacade::synthesize($ttsText, ...)` |
| `WhatsAppService::generateTtsResponse()` | Before `AiFacade::synthesize($text, ...)` |
| `WebhookController` email flow (Phase 4) | Before `AiFacade::synthesize(...)` |

### Memory behavior per channel

| Channel | Memories in prompt | Badges in text | TTS text |
|---------|-------------------|----------------|----------|
| Web (text only) | ✅ loaded | ✅ rendered as clickable badges | N/A |
| Web (voiceReply) | ✅ loaded | ✅ rendered in text | ✅ stripped by sanitizer |
| WhatsApp (text) | ✅ loaded | Visible as `[Memory:42]` (harmless in text) | N/A |
| WhatsApp (audio) | ✅ loaded | N/A (text not shown) | ✅ stripped by sanitizer |
| Email | ✅ loaded | Visible in email text | ✅ stripped if TTS attached |

**Note:** Memories should still be loaded and used to personalize the response — only the badge references need to be stripped from the TTS input. The text response shown to the user keeps the badges intact.

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Piper WAV output is large | Convert to MP3 via ffmpeg (already in container) |
| TTS adds latency to chat response | Generate async after text stream completes; SSE `audio` event arrives separately |
| Long responses exceed TTS text limit | Truncate at 4000 chars (same as WhatsApp flow) |
| Piper service down | Fallback to OpenAI TTS if configured; log warning |
| Rate Limit Abuse | `StreamController` must check `AUDIOS` limit before TTS generation |
| Chat history performance with channel data | Index on `BSOURCE`; lazy-load file attachments |
| Memory badges spoken by TTS | `TtsTextSanitizer::sanitize()` strips `[Memory:ID]`, markdown, code, think tags |
| Mediamaker audio vs voice reply double-TTS | Guard in StreamController: skip voice reply when `intent=image_generation` |

## Testing Checklist

- [ ] Piper health check from backend container
- [ ] TTS synthesis via `AiFacade::synthesize()` with `provider=piper`
- [ ] Web chat: toggle voiceReply, get audio player in response
- [ ] Web chat: mic input auto-enables voiceReply
- [ ] WhatsApp: voice message in → MP3 response back
- [ ] Email: audio attachment in → MP3 attached in reply
- [ ] Sidebar shows WhatsApp/Email chats with icons
- [ ] Opening a WhatsApp chat shows full message history with files
- [ ] Audio files served correctly (Content-Type, CORS)
- [ ] TTS sanitizer strips `[Memory:ID]` badges from spoken text
- [ ] TTS sanitizer strips markdown, code blocks, think tags
- [ ] Memories still personalize response when voiceReply is active
- [ ] WhatsApp TTS responses don't contain `[Memory:42]` in spoken audio
