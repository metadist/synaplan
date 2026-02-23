import { test } from '@playwright/test'
import { login, startNewChat, sendMessage, scrollResponse, pause } from '../helpers'

/*
 * Scenario: Multi-Turn Conversation
 *
 * Demonstrates:
 *   1. Logging into Synaplan
 *   2. Starting a new chat
 *   3. Asking a question, then following up — showing context retention
 */

const DEMO_EMAIL = process.env.DEMO_EMAIL || 'demo@synaplan.com'
const DEMO_PASSWORD = process.env.DEMO_PASSWORD || 'demo'

test('Demo: Multi-turn conversation with follow-up questions', async ({ page }) => {
  await login(page, DEMO_EMAIL, DEMO_PASSWORD)

  await startNewChat(page)

  await sendMessage(page, 'What are the main benefits of renewable energy?')
  await scrollResponse(page)

  await sendMessage(page, 'Which of those is the most cost-effective today?')
  await scrollResponse(page)

  await sendMessage(page, 'Can you summarize this in 3 bullet points?')
  await scrollResponse(page, 4)

  await pause(page, 3000)
})
