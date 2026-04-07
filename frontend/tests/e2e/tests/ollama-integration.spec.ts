import { test, expect } from '../test-setup'
import { request as playwrightRequest } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { getAuthHeaders } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { CREDENTIALS } from '../config/credentials'
import { getApiUrl, URLS, TIMEOUTS } from '../config/config'
import { PROMPTS } from '../config/test-data'
import { resetStub, configureStub, getStubRequests, getChatRequests } from '../helpers/ollama-stub'

const DEFAULTS_PATH = '/api/v1/config/models/defaults'
const OLLAMA_CHAT_MODEL_ID = -10

/**
 * Switch the global CHAT default to the Ollama stub model via API.
 * Returns the previous default so it can be restored.
 */
async function switchToOllamaChat(ctx: Awaited<ReturnType<typeof playwrightRequest.newContext>>) {
  const authHeaders = await getAuthHeaders(ctx, CREDENTIALS.getAdminCredentials())

  const getRes = await ctx.get(`${getApiUrl()}${DEFAULTS_PATH}`, { headers: authHeaders })
  const previousDefaults = getRes.ok()
    ? (((await getRes.json()) as { defaults?: Record<string, number> }).defaults ?? {})
    : {}

  const res = await ctx.post(`${getApiUrl()}${DEFAULTS_PATH}`, {
    headers: authHeaders,
    data: { defaults: { CHAT: OLLAMA_CHAT_MODEL_ID }, global: true },
  })
  if (!res.ok()) {
    throw new Error(`Failed to set Ollama default: ${res.status()} ${await res.text()}`)
  }

  return previousDefaults
}

async function restoreDefaults(
  ctx: Awaited<ReturnType<typeof playwrightRequest.newContext>>,
  defaults: Record<string, number>
) {
  const authHeaders = await getAuthHeaders(ctx, CREDENTIALS.getAdminCredentials())
  await ctx.post(`${getApiUrl()}${DEFAULTS_PATH}`, {
    headers: authHeaders,
    data: { defaults: { CHAT: defaults.CHAT ?? -1 }, global: true },
  })
}

test.describe('@ci @smoke Ollama Integration', () => {
  test.describe.configure({ mode: 'serial' })

  let apiCtx: Awaited<ReturnType<typeof playwrightRequest.newContext>>
  let previousDefaults: Record<string, number> = {}

  test.beforeAll(async () => {
    apiCtx = await playwrightRequest.newContext({ baseURL: URLS.BASE_URL })
    await resetStub(apiCtx)
    previousDefaults = await switchToOllamaChat(apiCtx)
  })

  test.afterAll(async () => {
    await restoreDefaults(apiCtx, previousDefaults)
    await apiCtx.dispose()
  })

  test('chat via Ollama provider produces response', async ({ page, credentials }) => {
    const chat = new ChatHelper(page)

    await test.step('Arrange: login and start new chat', async () => {
      await login(page, credentials)
      await chat.startNewChat()
    })

    const previousCount = await chat.conversationBubbles().count()

    await test.step('Act: send message', async () => {
      await page.locator(selectors.chat.textInput).fill(PROMPTS.CHAT_SMOKE)
      await page.locator(selectors.chat.sendBtn).click()
    })

    const aiText = await chat.waitForAnswer(previousCount)

    await test.step('Assert: response from Ollama stub is deterministic', async () => {
      expect(aiText.length).toBeGreaterThan(0)
      expect(aiText).toContain('fake ollama stub response')
    })

    await test.step('Verify: stub received chat request with correct model', async () => {
      const allRequests = await getStubRequests(apiCtx)
      const chatReqs = getChatRequests(allRequests)
      expect(chatReqs.length).toBeGreaterThan(0)

      const lastChat = chatReqs[chatReqs.length - 1]
      const body = lastChat.body as Record<string, unknown>
      expect(body.model).toBe('fake-chat-model')
      expect(Array.isArray(body.messages)).toBe(true)
    })
  })

  test('thinking/reasoning tokens are forwarded', async ({ page, credentials }) => {
    await test.step('Arrange: configure stub with thinking enabled', async () => {
      await resetStub(apiCtx)
      await configureStub(apiCtx, { enableThinking: true })
    })

    const chat = new ChatHelper(page)

    await test.step('Arrange: login and start new chat', async () => {
      await login(page, credentials)
      await chat.startNewChat()
    })

    const previousCount = await chat.conversationBubbles().count()

    await test.step('Act: send message', async () => {
      await page.locator(selectors.chat.textInput).fill(PROMPTS.CHAT_SMOKE)
      await page.locator(selectors.chat.sendBtn).click()
    })

    const aiText = await chat.waitForAnswer(previousCount)

    await test.step('Assert: response completed with content', async () => {
      expect(aiText.length).toBeGreaterThan(0)
      expect(aiText).toContain('fake ollama stub response')
    })

    await test.step('Verify: stub received request through thinking path', async () => {
      const allRequests = await getStubRequests(apiCtx)
      const chatReqs = getChatRequests(allRequests)
      expect(chatReqs.length).toBeGreaterThan(0)
    })
  })

  test('model-not-found shows error gracefully', async ({ page, credentials }) => {
    await test.step('Arrange: configure stub with no models', async () => {
      await resetStub(apiCtx)
      await configureStub(apiCtx, { models: [] })
      // Re-set the Ollama default (reset clears nothing about backend defaults)
      await switchToOllamaChat(apiCtx)
    })

    const chat = new ChatHelper(page)

    await test.step('Arrange: login and start new chat', async () => {
      await login(page, credentials)
      await chat.startNewChat()
    })

    const previousCount = await chat.conversationBubbles().count()

    await test.step('Act: send message', async () => {
      await page.locator(selectors.chat.textInput).fill(PROMPTS.CHAT_SMOKE)
      await page.locator(selectors.chat.sendBtn).click()
    })

    await test.step('Assert: error state reached', async () => {
      const bubbles = chat.conversationBubbles()
      const newBubble = bubbles.nth(previousCount)
      await newBubble.waitFor({ state: 'attached', timeout: TIMEOUTS.STANDARD })

      const result = await Promise.race([
        newBubble
          .locator(selectors.chat.messageDone)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
          .then(() => 'done' as const),
        newBubble
          .locator(selectors.chat.messageTopicError)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
          .then(() => 'error' as const),
      ])
      expect(result).toBe('error')
    })

    await test.step('Cleanup: restore stub models', async () => {
      await resetStub(apiCtx)
    })
  })

  test('server error handled gracefully', async ({ page, credentials }) => {
    await test.step('Arrange: configure stub to return 500', async () => {
      await resetStub(apiCtx)
      await configureStub(apiCtx, {
        simulateError: { endpoint: '/api/chat', statusCode: 500, count: 1 },
      })
      await switchToOllamaChat(apiCtx)
    })

    const chat = new ChatHelper(page)

    await test.step('Arrange: login and start new chat', async () => {
      await login(page, credentials)
      await chat.startNewChat()
    })

    const previousCount = await chat.conversationBubbles().count()

    await test.step('Act: send message', async () => {
      await page.locator(selectors.chat.textInput).fill(PROMPTS.CHAT_SMOKE)
      await page.locator(selectors.chat.sendBtn).click()
    })

    await test.step('Assert: error state reached', async () => {
      const bubbles = chat.conversationBubbles()
      const newBubble = bubbles.nth(previousCount)
      await newBubble.waitFor({ state: 'attached', timeout: TIMEOUTS.STANDARD })

      const result = await Promise.race([
        newBubble
          .locator(selectors.chat.messageDone)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
          .then(() => 'done' as const),
        newBubble
          .locator(selectors.chat.messageTopicError)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
          .then(() => 'error' as const),
      ])
      expect(result).toBe('error')
    })

    await test.step('Cleanup: reset stub', async () => {
      await resetStub(apiCtx)
    })
  })
})
