# Synaplan Screen Recordings

Automated demo screen recordings using Playwright. These scripts log into a Synaplan instance, perform use-case scenarios at a human-watchable pace, and produce video files you can share with users or embed in documentation.

There are two modes:

- **Silent recordings** (`.webm`) — just the browser actions, no audio. Works out of the box.
- **Narrated recordings** (`.mp4`) — browser actions with spoken TTS commentary. Requires the `synaplan-tts` service and `ffmpeg`.

## Quick Start

```bash
cd _devextras/screenvideo

# 1. Install dependencies
npm install

# 2. Install Chromium for Playwright
npm run install:browsers

# 3. Configure
cp .env.example .env
# Edit .env — set SYNAPLAN_URL and demo credentials
```

Then record:

```bash
# All scenarios, headless (fastest)
npm run record

# All scenarios with visible browser window (you can watch)
npm run record:headed

# Single scenario by name
npm run record:scenario -- "knowledge question"
```

Videos are saved to `videos/` (gitignored).

## Setup for Narrated Videos (Optional)

Narrated scenarios use the `synaplan-tts` service to generate spoken commentary that gets merged into the video. This is entirely optional — silent scenarios work without it.

### What you need

1. **synaplan-tts** — the Piper TTS service from the `synaplan-tts/` repository:

   ```bash
   cd /path/to/synaplan-tts
   docker compose up -d
   # First run downloads ~350 MB of voice models automatically
   # Verify: curl http://127.0.0.1:10200/health
   ```

2. **ffmpeg** — for merging audio into the video:

   ```bash
   # macOS
   brew install ffmpeg

   # Ubuntu/Debian
   sudo apt install ffmpeg
   ```

3. **TTS settings in `.env`:**

   ```bash
   TTS_URL=http://127.0.0.1:10200
   TTS_VOICE=en_US-lessac-medium
   TTS_SPEED=1.0
   ```

### How narration works

Playwright cannot capture browser audio in its video recordings. Instead, we use a two-phase approach:

```
Phase 1: Recording (Playwright)
┌─────────────────────────────────────────────────┐
│  narrator.say("Welcome to Synaplan")            │
│    → calls TTS API → gets WAV clip (2.1s)       │
│    → records timestamp (t=0ms)                  │
│    → pauses browser for 2.1s (keeps video sync) │
│                                                 │
│  ... browser actions (login, click, type) ...   │
│                                                 │
│  narrator.say("Now let us start a chat")        │
│    → calls TTS API → gets WAV clip (1.8s)       │
│    → records timestamp (t=12400ms)              │
│    → pauses browser for 1.8s                    │
│                                                 │
│  narrator.buildAudioTrack()                     │
│    → combines clips with silence gaps → WAV     │
└─────────────────────────────────────────────────┘
                      ↓
Phase 2: Merge (ffmpeg, in afterAll hook)
┌─────────────────────────────────────────────────┐
│  silent video.webm + narration.wav → output.mp4 │
└─────────────────────────────────────────────────┘
```

The key insight: each `narrator.say()` pauses the browser for exactly the duration of the spoken clip, so the silent video has "gaps" at the right moments. When the audio track is merged in, the narration lines up perfectly with the on-screen actions.

## Writing Scenarios

### Silent scenario (no TTS needed)

```typescript
import { test } from '@playwright/test'
import { login, startNewChat, sendMessage, scrollResponse, pause } from '../helpers'

const DEMO_EMAIL = process.env.DEMO_EMAIL || 'demo@synaplan.com'
const DEMO_PASSWORD = process.env.DEMO_PASSWORD || 'demo'

test('Demo: Your scenario description', async ({ page }) => {
  await login(page, DEMO_EMAIL, DEMO_PASSWORD)
  await startNewChat(page)
  await sendMessage(page, 'Your prompt here')
  await scrollResponse(page)
  await pause(page, 2000)
})
```

### Narrated scenario (requires TTS + ffmpeg)

```typescript
import { test } from '@playwright/test'
import { login, startNewChat, sendMessage, scrollResponse, pause } from '../helpers'
import { Narrator } from '../narrate'
import * as fs from 'fs'
import * as path from 'path'
import { execSync } from 'child_process'

const DEMO_EMAIL = process.env.DEMO_EMAIL || 'demo@synaplan.com'
const DEMO_PASSWORD = process.env.DEMO_PASSWORD || 'demo'
const VIDEO_DIR = path.join(__dirname, '..', 'videos')

let narrator: Narrator
let audioTrackPath: string

test('Demo: My narrated scenario', async ({ page }) => {
  narrator = new Narrator('my-scenario')  // name used for output files
  narrator.start()

  await narrator.say(page, 'Welcome! Let me show you this feature.')
  await login(page, DEMO_EMAIL, DEMO_PASSWORD)
  await narrator.say(page, 'We are now logged in.')
  // ... more actions and narration ...

  audioTrackPath = narrator.buildAudioTrack()  // must call before test ends
})

test.afterAll(async () => {
  if (!audioTrackPath) return
  // Find the .webm Playwright wrote, merge with audio → .mp4
  // See scenarios/03-narrated-chat-demo.spec.ts for the full pattern
})
```

Name files with ascending numbers: `01-`, `02-`, `03-`, etc.

## Pacing

Recordings are intentionally slowed down so viewers can follow along:

| Setting | Default | Purpose |
|---------|---------|---------|
| `STEP_DELAY` | 1500ms | Playwright `slowMo` — delay between every browser action |
| `ACTION_PAUSE` | 2000ms | Extra pause after scripted steps (login, send message) |

For narrated scenarios, the `Narrator` adds its own pauses based on the duration of each spoken line, so the video naturally slows down during commentary.

## Helpers Reference

### Browser helpers (`helpers.ts`)

| Helper | Purpose |
|--------|---------|
| `login(page, email, password)` | Log in and wait for chat page |
| `startNewChat(page)` | Click "New Chat" and wait for empty state |
| `sendMessage(page, text)` | Type a message human-style and wait for AI response |
| `scrollResponse(page, steps?)` | Slowly scroll through the response |
| `humanType(page, selector, text)` | Type character-by-character |
| `pause(page, ms?)` | Wait between actions |

### Narrator (`narrate.ts`)

| Method | Purpose |
|--------|---------|
| `new Narrator(name, { voice?, speed? })` | Create narrator; name is used for output filenames |
| `narrator.start()` | Begin timing (call at scenario start) |
| `narrator.say(page, text, extraPauseMs?)` | Synthesize speech, record timestamp, pause browser |
| `narrator.buildAudioTrack()` | Combine all clips into a single timed WAV |
| `narrator.mergeIntoVideo(videoPath, out?)` | One-shot: build audio + merge with video |

### Manual merge (`merge-narration.sh`)

If automatic merging fails, merge manually:

```bash
./merge-narration.sh <video.webm> <narration.wav> [output.mp4]
```

## TTS Service Quick Reference

The `synaplan-tts` service (separate repo) runs [Piper TTS](https://github.com/rhasspy/piper) with pre-loaded voices:

| Language | Voice Key | Speaker |
|----------|-----------|---------|
| English (US) | `en_US-lessac-medium` | lessac |
| German | `de_DE-thorsten-medium` | thorsten |
| Spanish | `es_ES-davefx-medium` | davefx |
| Turkish | `tr_TR-dfki-medium` | dfki |
| Russian | `ru_RU-irina-medium` | irina |
| Persian | `fa_IR-reza_ibrahim-medium` | reza_ibrahim |

```bash
# Verify TTS is running
curl http://127.0.0.1:10200/health

# List voices
curl http://127.0.0.1:10200/api/voices

# Generate speech (WAV)
curl -X POST http://127.0.0.1:10200/api/tts \
  -H "Content-Type: application/json" \
  -d '{"text":"Hello world","voice":"en_US-lessac-medium"}' \
  -o output.wav

# Auto-select voice by language
curl "http://127.0.0.1:10200/api/tts?text=Hallo+Welt&language=de" -o output.wav
```

| Parameter | Default | Description |
|-----------|---------|-------------|
| `text` | (required) | Text to speak (max 5000 chars) |
| `voice` | `en_US-lessac-medium` | Exact voice key |
| `language` | — | Auto-select voice by language code (`en`, `de`, `es`, `tr`, `ru`, `fa`) |
| `length_scale` | `1.0` | Speed: <1.0 = faster, >1.0 = slower |
| `volume` | `1.0` | Volume multiplier (0.0–5.0) |
| `stream` | `false` | Return Opus/WebM stream instead of WAV |

## Security

- **Never commit `.env`** — it is gitignored
- **Only use dedicated demo accounts** — never record with real user credentials
- The `.env.example` ships with placeholder values only
- Narration text is sent to the local TTS service, not to any external API
