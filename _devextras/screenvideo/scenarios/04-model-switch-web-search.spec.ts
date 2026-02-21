import { test } from '@playwright/test'
import { login, startNewChat, sendMessage, scrollResponse, pause, humanType } from '../helpers'
import { Narrator } from '../narrate'
import * as fs from 'fs'
import * as path from 'path'
import { execSync } from 'child_process'

/*
 * Scenario: Switch AI Model & Web Search
 *
 * Demonstrates:
 *   1. Logging in (fast, no narration on login)
 *   2. Navigating to AI model configuration
 *   3. Switching the chat model to GPT-5.2
 *   4. Returning to chat
 *   5. Using the web search tool to get live internet results
 */

const DEMO_EMAIL = process.env.DEMO_EMAIL || 'demo@synaplan.com'
const DEMO_PASSWORD = process.env.DEMO_PASSWORD || 'demo'
const VIDEO_DIR = path.join(__dirname, '..', 'videos')

let narrator: Narrator
let audioTrackPath: string

test('Demo: Narrated model switch and web search', async ({ page }) => {
  narrator = new Narrator('model-switch-web-search', { speed: 0.95 })
  narrator.start()

  // --- Title screen ---
  await page.goto('/login')
  await page.waitForSelector('[data-testid="page-login"]')
  await narrator.say(
    page,
    'Synaplan lets you choose from dozens of AI models and search the web in real time. Let me show you.',
  )

  // --- Fast login (no narration) ---
  await login(page, DEMO_EMAIL, DEMO_PASSWORD)
  await pause(page, 800)

  // --- Navigate to model config ---
  await narrator.say(page, 'First, let us switch the AI model. We open the settings page.', 300)

  await page.goto('/config/ai-models')
  await page.waitForSelector('[data-testid="page-config-ai-models"]')
  await page.waitForSelector('[data-testid="item-capability"]')
  await pause(page, 1000)

  await narrator.say(
    page,
    'Here we see all AI capabilities. Each one can use a different model. Let us change the chat model.',
    300,
  )

  // --- Click the Chat capability dropdown ---
  const chatRow = page.locator('[data-testid="item-capability"]').filter({ hasText: /Chat/i })
  const chatDropdown = chatRow.locator('[data-testid="btn-model-dropdown"]')
  await chatDropdown.click()
  await page.waitForSelector('[data-testid="btn-model-option"]')
  await pause(page, 800)

  await narrator.say(page, 'We select GPT 5.2 from the list of available models.', 300)

  // --- Select GPT-5.2 ---
  const gptOption = page.locator('[data-testid="btn-model-option"]').filter({ hasText: /gpt.*5/i })
  const optionCount = await gptOption.count()
  if (optionCount > 0) {
    await gptOption.first().click()
  } else {
    // Fallback: pick a visible model if GPT-5.2 isn't listed
    const allOptions = page.locator('[data-testid="btn-model-option"]')
    const count = await allOptions.count()
    if (count > 1) await allOptions.nth(1).click()
  }
  await pause(page, 1200)

  await narrator.say(page, 'The model is saved automatically. Now let us go back to the chat.', 300)

  // --- Navigate back to chat ---
  await page.click('[data-testid="btn-sidebar-v2-new-chat"]')
  await page.waitForSelector('[data-testid="page-chat"]')
  await page.waitForSelector('[data-testid="state-empty"]', { timeout: 10_000 })
  await pause(page, 800)

  // --- Enable web search via Tools dropdown ---
  await narrator.say(
    page,
    'Synaplan can search the internet in real time. We activate the web search tool.',
    300,
  )

  await page.click('[data-testid="btn-tools-toggle"]')
  await page.waitForSelector('[data-testid="dropdown-tools-panel"]')
  await pause(page, 600)
  await page.click('[data-testid="btn-tool-web-search"]')
  await pause(page, 800)

  await narrator.say(
    page,
    'Now we ask a question that requires up to date information from the internet.',
    300,
  )

  // --- Send a web search query ---
  await humanType(
    page,
    '[data-testid="input-chat-message"]',
    'What are the latest news about artificial intelligence today?',
    35,
  )
  await pause(page, 600)
  await page.click('[data-testid="btn-chat-send"]')

  // Wait for AI response with web search results
  await page.waitForSelector('[data-testid="assistant-message-bubble"]', { timeout: 90_000 })
  await page
    .waitForSelector('[data-testid="loading-typing-indicator"]', {
      state: 'detached',
      timeout: 120_000,
    })
    .catch(() => {})

  await pause(page, 2000)

  await narrator.say(
    page,
    'The AI searched the web and included live results in its answer. Let us scroll through the response.',
    300,
  )

  await scrollResponse(page, 5)

  await narrator.say(
    page,
    'That is how you switch models and use web search in Synaplan. Fast, flexible, and always up to date.',
  )

  await pause(page, 2000)

  // Build audio track before test ends
  audioTrackPath = narrator.buildAudioTrack()
})

test.afterAll(async () => {
  if (!audioTrackPath) return

  const webmFiles = findWebmFiles(VIDEO_DIR, 'model-switch-web-search')
  if (webmFiles.length === 0) {
    console.log(`\n  No video found. Audio: ${audioTrackPath}`)
    console.log(`  Merge manually: ./merge-narration.sh <video.webm> ${audioTrackPath}\n`)
    return
  }

  const outputMp4 = path.join(VIDEO_DIR, 'model-switch-web-search.mp4')
  try {
    execSync(
      [
        'ffmpeg -y',
        `-i "${webmFiles[0]}"`,
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
    console.log(`  Merge manually: ./merge-narration.sh "${webmFiles[0]}" "${audioTrackPath}"\n`)
  }
})

function findWebmFiles(dir: string, nameFragment: string): string[] {
  const results: string[] = []
  if (!fs.existsSync(dir)) return results
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const fullPath = path.join(dir, entry.name)
    if (entry.isDirectory() && entry.name.includes(nameFragment)) {
      const videoFile = path.join(fullPath, 'video.webm')
      if (fs.existsSync(videoFile)) results.push(videoFile)
    } else if (entry.isFile() && entry.name.endsWith('.webm') && entry.name.includes(nameFragment)) {
      results.push(fullPath)
    }
  }
  return results
}
