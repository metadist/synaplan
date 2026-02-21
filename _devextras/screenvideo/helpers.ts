import { type Page } from '@playwright/test'

const PAUSE_MS = parseInt(process.env.ACTION_PAUSE || '2000', 10)

/**
 * Pause between actions so the viewer can follow along.
 * Use shorter pauses for minor steps, longer for important moments.
 */
export async function pause(page: Page, ms: number = PAUSE_MS): Promise<void> {
  await page.waitForTimeout(ms)
}

/**
 * Type text character-by-character at a human-readable speed.
 * Much more engaging in recordings than instant fill.
 */
export async function humanType(
  page: Page,
  selector: string,
  text: string,
  delayPerChar: number = 60,
): Promise<void> {
  await page.click(selector)
  await page.locator(selector).pressSequentially(text, { delay: delayPerChar })
}

/**
 * Log in to Synaplan using the demo credentials from .env.
 * Waits for the chat page to fully load before returning.
 */
export async function login(
  page: Page,
  email: string,
  password: string,
): Promise<void> {
  await page.goto('/login')
  await page.waitForSelector('[data-testid="page-login"]')
  await pause(page, 1000)

  await humanType(page, '[data-testid="input-email"]', email)
  await pause(page, 500)

  await humanType(page, '[data-testid="input-password"]', password)
  await pause(page, 800)

  await page.click('[data-testid="btn-login"]')
  await page.waitForURL('/', { timeout: 15_000 })
  await page.waitForSelector('[data-testid="page-chat"]', { timeout: 15_000 })
  await pause(page, 1500)
}

/**
 * Start a fresh chat conversation.
 */
export async function startNewChat(page: Page): Promise<void> {
  await page.click('[data-testid="btn-sidebar-v2-new-chat"]')
  await page.waitForSelector('[data-testid="state-empty"]', { timeout: 10_000 })
  await pause(page)
}

/**
 * Send a chat message and wait for the AI to respond.
 * Returns once the assistant bubble appears and streaming finishes.
 */
export async function sendMessage(page: Page, message: string): Promise<void> {
  await humanType(page, '[data-testid="input-chat-message"]', message, 45)
  await pause(page, 1000)

  await page.click('[data-testid="btn-chat-send"]')

  await page.waitForSelector('[data-testid="assistant-message-bubble"]', {
    timeout: 60_000,
  })

  // Wait for streaming to finish (typing indicator disappears)
  await page
    .waitForSelector('[data-testid="loading-typing-indicator"]', {
      state: 'detached',
      timeout: 120_000,
    })
    .catch(() => {
      // Indicator may have already disappeared
    })

  await pause(page, 3000)
}

/**
 * Scroll slowly through the AI response so the viewer can read it.
 */
export async function scrollResponse(
  page: Page,
  scrollSteps: number = 3,
): Promise<void> {
  const messagesArea = page.locator('[data-testid="section-messages"]')
  for (let i = 0; i < scrollSteps; i++) {
    await messagesArea.evaluate((el) => {
      el.scrollBy({ top: 200, behavior: 'smooth' })
    })
    await pause(page, 1200)
  }
}
