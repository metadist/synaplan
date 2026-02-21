# Synaplan Screen Recordings

Automated demo screen recordings using Playwright. These scripts log into a Synaplan instance, perform use-case scenarios at a human-watchable pace, and produce `.webm` video files you can share with users or embed in documentation.

## Setup

```bash
cd _devextras/screenvideo

# Install dependencies
npm install

# Install Chromium browser for Playwright
npm run install:browsers

# Configure your demo instance
cp .env.example .env
# Edit .env with your demo URL and demo account credentials
```

## Recording Videos

```bash
# Record all scenarios (headless — fastest)
npm run record

# Record all scenarios with visible browser window
npm run record:headed

# Record a single scenario by name
npm run record:scenario -- "knowledge question"
```

Videos are saved to `videos/` (gitignored). Each scenario produces a `.webm` file named after the test.

## Pacing

The recordings are intentionally slowed down so viewers can follow along:

| Setting | Default | Purpose |
|---------|---------|---------|
| `STEP_DELAY` | 1500ms | Playwright `slowMo` — delay between every browser action |
| `ACTION_PAUSE` | 2000ms | Extra pause after scripted steps (login, send message) |

Adjust these in `.env` to make recordings faster or slower.

## Adding New Scenarios

Create a new file in `scenarios/` following the naming convention:

```
scenarios/03-your-scenario-name.spec.ts
```

Use the shared helpers from `helpers.ts`:

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

## Available Helpers

| Helper | Purpose |
|--------|---------|
| `login(page, email, password)` | Log in and wait for chat page |
| `startNewChat(page)` | Click "New Chat" and wait for empty state |
| `sendMessage(page, text)` | Type a message human-style and wait for AI response |
| `scrollResponse(page, steps?)` | Slowly scroll through the response |
| `humanType(page, selector, text)` | Type character-by-character |
| `pause(page, ms?)` | Wait between actions |

## TTS Narration

Scenarios can include spoken voice commentary using the `synaplan-tts` service (Piper TTS). The narration is synthesized during recording, timed to each action, and merged into the final video as an MP4 with audio.

### Prerequisites

- **synaplan-tts** running (`docker compose up -d` in the `synaplan-tts/` repo)
- **ffmpeg** installed on the host (`brew install ffmpeg`)
- TTS settings in `.env` (see `.env.example`)

### How It Works

1. **During recording:** Each `narrator.say(page, "text")` call hits the TTS API to generate a WAV clip, records its timestamp, and pauses the browser for the clip's duration
2. **After recording:** The `afterAll` hook combines all clips into a single audio track (with correct timing gaps) and merges it with the silent `.webm` video using ffmpeg
3. **Output:** A narrated `.mp4` file in `videos/`

### Writing a Narrated Scenario

```typescript
import { test } from '@playwright/test'
import { login, startNewChat, sendMessage, pause } from '../helpers'
import { Narrator } from '../narrate'

const DEMO_EMAIL = process.env.DEMO_EMAIL || 'demo@synaplan.com'
const DEMO_PASSWORD = process.env.DEMO_PASSWORD || 'demo'

let narrator: Narrator
let audioTrackPath: string

test('Demo: My narrated scenario', async ({ page }) => {
  narrator = new Narrator('my-scenario')
  narrator.start()

  await narrator.say(page, 'Welcome! Let me show you this feature.')
  await login(page, DEMO_EMAIL, DEMO_PASSWORD)
  await narrator.say(page, 'We are now logged in.')

  // ... your scenario actions ...

  // Build audio track at the end (before browser closes)
  audioTrackPath = narrator.buildAudioTrack()
})

test.afterAll(async () => {
  // Merge audio into video (see 03-narrated-chat-demo.spec.ts for full example)
})
```

### Narrator API

| Method | Purpose |
|--------|---------|
| `new Narrator(name, { voice?, speed? })` | Create narrator with optional voice/speed override |
| `narrator.start()` | Begin timing (call at scenario start) |
| `narrator.say(page, text, extraPauseMs?)` | Synthesize + pause browser for the clip duration |
| `narrator.buildAudioTrack()` | Combine all clips into a single timed WAV file |
| `narrator.mergeIntoVideo(videoPath, outputPath?)` | One-shot: build audio + merge with video |

### Manual Merge

If automatic merging fails, use the shell script:

```bash
./merge-narration.sh <video.webm> <narration.wav> [output.mp4]
```

### TTS Service Reference

The `synaplan-tts` service runs Piper TTS with these voices:

| Language | Voice Key | Speaker |
|----------|-----------|---------|
| English (US) | `en_US-lessac-medium` | lessac |
| German | `de_DE-thorsten-medium` | thorsten |
| Spanish | `es_ES-davefx-medium` | davefx |
| Turkish | `tr_TR-dfki-medium` | dfki |
| Russian | `ru_RU-irina-medium` | irina |
| Persian | `fa_IR-reza_ibrahim-medium` | reza_ibrahim |

**API endpoints** (default `http://127.0.0.1:10200`):

```bash
# Health check
curl http://127.0.0.1:10200/health

# List voices
curl http://127.0.0.1:10200/api/voices

# Synthesize (WAV)
curl -X POST http://127.0.0.1:10200/api/tts \
  -H "Content-Type: application/json" \
  -d '{"text":"Hello world","voice":"en_US-lessac-medium"}' \
  -o output.wav

# Synthesize with language auto-select
curl "http://127.0.0.1:10200/api/tts?text=Hallo+Welt&language=de" -o output.wav

# Streaming (Opus/WebM)
curl -X POST http://127.0.0.1:10200/api/tts \
  -d '{"text":"Long text here","stream":true}' -o output.webm
```

**Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `text` | (required) | Text to speak (max 5000 chars) |
| `voice` | `en_US-lessac-medium` | Exact voice key |
| `language` | — | Auto-select voice by language code |
| `length_scale` | `1.0` | Speed: <1.0 = faster, >1.0 = slower |
| `volume` | `1.0` | Volume multiplier (0.0–5.0) |
| `stream` | `false` | Return Opus/WebM stream instead of WAV |

## Security

- **Never commit `.env`** — it is gitignored
- **Only use dedicated demo accounts** — never record with real user credentials
- The `.env.example` ships with placeholder values only
