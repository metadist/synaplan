import { test } from '@playwright/test'
import { login, startNewChat, sendMessage, scrollResponse, pause } from '../helpers'

/*
 * Scenario: New Chat — Ask a Knowledge Question
 *
 * Demonstrates:
 *   1. Logging into Synaplan
 *   2. Starting a new chat conversation
 *   3. Asking a general knowledge question
 *   4. Viewing the AI response
 */

const DEMO_EMAIL = process.env.DEMO_EMAIL || 'demo@synaplan.com'
const DEMO_PASSWORD = process.env.DEMO_PASSWORD || 'demo'

test('Demo: Ask a knowledge question in a new chat', async ({ page }) => {
  await login(page, DEMO_EMAIL, DEMO_PASSWORD)

  await startNewChat(page)

  await sendMessage(page, 'How long is the Great Wall of China?')

  await scrollResponse(page)

  await pause(page, 2000)
})
