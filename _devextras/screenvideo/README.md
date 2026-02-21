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

## Security

- **Never commit `.env`** — it is gitignored
- **Only use dedicated demo accounts** — never record with real user credentials
- The `.env.example` ships with placeholder values only
