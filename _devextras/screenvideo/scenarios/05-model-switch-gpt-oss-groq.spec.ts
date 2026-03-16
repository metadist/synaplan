import { test } from '@playwright/test'
import { login, pause } from '../helpers'
import { Narrator } from '../narrate'
import * as fs from 'fs'
import * as path from 'path'
import { execSync } from 'child_process'

/*
 * Scenario: Switch chat model to Groq gpt-oss-120b
 *
 * Demonstrates:
 *   1. Logging into the local Synaplan app
 *   2. Opening the AI model switcher
 *   3. Selecting Groq gpt-oss-120b for Chat
 */

const DEMO_EMAIL = process.env.DEMO_EMAIL || 'demo@synaplan.com'
const DEMO_PASSWORD = process.env.DEMO_PASSWORD || 'demo123'
const VIDEO_DIR = path.join(__dirname, '..', 'videos')
const SCENARIO_NAME = 'model-switch-gpt-oss-120b-groq'

let audioTrackPath: string

test('Demo: Narrated switch to Groq gpt-oss-120b', async ({ page }) => {
  const narrator = new Narrator(SCENARIO_NAME, { speed: 0.95 })
  narrator.start()

  await page.goto('/login')
  await page.waitForSelector('[data-testid="page-login"]')

  await narrator.say(
    page,
    'This demo shows how to log into the local Synaplan app and switch the chat model to Groq GPT O S S one hundred twenty B.',
  )

  await login(page, DEMO_EMAIL, DEMO_PASSWORD)

  await narrator.say(
    page,
    'We are now signed in. Next, we open the AI model switcher in the settings area.',
    300,
  )

  await page.goto('/config/ai-models')
  await page.waitForSelector('[data-testid="page-config-ai-models"]')
  await page.waitForSelector('[data-testid="item-capability"]')
  await pause(page, 1200)

  const chatRow = page.locator('[data-testid="item-capability"]').filter({ hasText: /Chat/i })
  const chatDropdown = chatRow.locator('[data-testid="btn-model-dropdown"]')

  await narrator.say(
    page,
    'Each capability can use a different AI model. Here we change the chat capability.',
    300,
  )

  await chatDropdown.click()
  await page.waitForSelector('[data-testid="btn-model-option"]')
  await pause(page, 800)

  await narrator.say(
    page,
    'From the Groq models, we select GPT O S S one hundred twenty B.',
    300,
  )

  const targetOption = page
    .locator('[data-testid="btn-model-option"]')
    .filter({ hasText: /gpt-oss-120b/i })
    .filter({ hasText: /groq/i })

  await targetOption.first().click()
  await pause(page, 1500)

  await chatDropdown.waitFor({ state: 'visible' })
  await page.waitForFunction(
    () => {
      const rows = Array.from(document.querySelectorAll('[data-testid="item-capability"]'))
      const chatRowEl = rows.find((row) => row.textContent?.match(/chat/i))
      const dropdown = chatRowEl?.querySelector('[data-testid="btn-model-dropdown"]')
      return dropdown?.textContent?.toLowerCase().includes('gpt-oss-120b')
    },
    undefined,
    { timeout: 10_000 },
  )

  await narrator.say(
    page,
    'The chat model is now set to Groq GPT O S S one hundred twenty B.',
  )

  await pause(page, 2000)

  audioTrackPath = narrator.buildAudioTrack()
})

test.afterAll(async () => {
  if (!audioTrackPath) return

  const videoPath = findNewestWebmFile(VIDEO_DIR)
  if (!videoPath) {
    console.log(`\n  No video found. Audio: ${audioTrackPath}`)
    console.log(`  Merge manually: ./merge-narration.sh <video.webm> ${audioTrackPath}\n`)
    return
  }

  const outputMp4 = path.join(VIDEO_DIR, `${SCENARIO_NAME}.mp4`)
  try {
    execSync(
      [
        'ffmpeg -y',
        `-i "${videoPath}"`,
        `-i "${audioTrackPath}"`,
        '-c:v libx264 -preset fast -crf 23',
        '-c:a aac -b:a 128k',
        '-map 0:v:0 -map 1:a:0',
        '-shortest',
        '-r 25',
        `"${outputMp4}"`,
      ].join(' '),
      { stdio: 'pipe' },
    )
    try { fs.unlinkSync(audioTrackPath) } catch {}
    console.log(`\n  Narrated video: ${outputMp4}\n`)
  } catch (err) {
    console.error(`\n  Merge failed: ${err}`)
    console.log(`  Merge manually: ./merge-narration.sh "${videoPath}" "${audioTrackPath}"\n`)
  }
})

function findNewestWebmFile(dir: string): string | null {
  if (!fs.existsSync(dir)) return null

  const candidates: Array<{ path: string; mtimeMs: number }> = []

  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const fullPath = path.join(dir, entry.name)
    if (entry.isDirectory()) {
      const nestedVideo = path.join(fullPath, 'video.webm')
      if (fs.existsSync(nestedVideo)) {
        candidates.push({ path: nestedVideo, mtimeMs: fs.statSync(nestedVideo).mtimeMs })
      }
    } else if (entry.isFile() && entry.name.endsWith('.webm')) {
      candidates.push({ path: fullPath, mtimeMs: fs.statSync(fullPath).mtimeMs })
    }
  }

  candidates.sort((a, b) => b.mtimeMs - a.mtimeMs)
  return candidates[0]?.path ?? null
}
