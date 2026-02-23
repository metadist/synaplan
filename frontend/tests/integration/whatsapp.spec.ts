/**
 * Smoke: WhatsApp Meta Cloud API (inbound webhook → outbound to stub).
 * Request-only. Stub: Docker service whatsapp-stub:3999; runner uses localhost:3999.
 */

import type { APIRequestContext } from '@playwright/test'
import { test, expect } from '../e2e/test-setup'
import { getApiUrl, isTestStack } from '../e2e/config/config'
import {
  getStubBaseUrl,
  getStubRequests,
  resetStub,
  simulateStubFail,
  countPostMessages,
  getPostMessageBodies,
  assertStubContract,
  awaitOutboundExactlyOnce,
  assertNoSecondOutboundWithinWindow,
  makeRunId,
} from './helpers/whatsapp-stub'
import {
  metaPayloadText,
  metaPayloadImage,
  metaPayloadAudio,
  PHONE_NUMBER_ID,
} from './helpers/whatsapp-payload'

const WEBHOOK_PATH = '/api/v1/webhooks/whatsapp'
const EXPECTED_PATH_MESSAGES = `/v21.0/${PHONE_NUMBER_ID}/messages`
const TEST_FROM = '4915112345678'

function webhookUrl(): string {
  return `${getApiUrl()}${WEBHOOK_PATH}`
}

async function postWebhookAndAssert2xx(request: APIRequestContext, payload: object): Promise<void> {
  const res = await request.post(webhookUrl(), { data: payload })
  expect(res.status()).toBeGreaterThanOrEqual(200)
  expect(res.status()).toBeLessThan(300)
  const json = await res.json()
  expect(json.success).toBe(true)
}

test.describe('WhatsApp smoke @smoke', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeEach(async ({ request }, testInfo) => {
    test.skip(!isTestStack(), 'WhatsApp smoke requires test stack (E2E_STACK=test, stub at whatsapp-stub:3999)')
    await resetStub(request, getStubBaseUrl(), makeRunId(testInfo.testId))
  })

  test('Text: webhook 2xx, exactly one outbound text, contract, idempotency', async ({
    request,
  }, testInfo) => {
    const baseUrl = getStubBaseUrl()
    const runId = makeRunId(testInfo.testId)
    const messageId = `smoke-text-${Date.now()}-${Math.random().toString(36).slice(2)}`
    const payload = metaPayloadText(messageId, TEST_FROM, 'ping')

    await test.step('Act: POST webhook', async () => {
      await postWebhookAndAssert2xx(request, payload)
    })

    await test.step('Assert: exactly one outbound + stability window', async () => {
      const reqs = await awaitOutboundExactlyOnce(request, baseUrl, { runId })
      assertStubContract(reqs, {
        expectedPathExact: EXPECTED_PATH_MESSAGES,
        expectedBearerToken: 'stub',
        expectedContentType: 'application/json',
      })
      const bodies = getPostMessageBodies(reqs)
      expect(bodies.length).toBe(1)
      expect(bodies[0].to).toBe(TEST_FROM)
      expect(bodies[0].type).toBe('text')
      const textBody = (bodies[0].text as { body?: string })?.body
      expect(textBody).toBeDefined()
      expect(String(textBody).length).toBeGreaterThan(0)
    })

    await test.step('Idempotency: same message id again → no second outbound', async () => {
      await postWebhookAndAssert2xx(request, payload)
      await assertNoSecondOutboundWithinWindow(request, baseUrl, { runId })
    })
  })

  test('Negative: stub 500 → backend reports failed, exactly one attempt', async ({
    request,
  }, testInfo) => {
    const baseUrl = getStubBaseUrl()
    const runId = makeRunId(testInfo.testId)
    await resetStub(request, baseUrl, runId)
    await simulateStubFail(request, baseUrl, 1)

    const messageId = `smoke-neg-${Date.now()}-${Math.random().toString(36).slice(2)}`
    const payload = metaPayloadText(messageId, TEST_FROM, 'ping')

    await test.step('Act: POST webhook (stub 500 on outbound)', async () => {
      const res = await request.post(webhookUrl(), { data: payload })
      expect(res.status()).toBeGreaterThanOrEqual(200)
      expect(res.status()).toBeLessThan(300)
      const json = await res.json()
      expect(json.success).toBe(true)
      expect(json.responses).toBeDefined()
      expect(json.responses!.length).toBe(1)
      expect(json.responses![0].success).toBe(false)
      expect(json.responses![0].error).toBeDefined()
      expect(String(json.responses![0].error).length).toBeGreaterThan(0)
    })

    await test.step('Assert: stub received exactly one send POST', async () => {
      const reqs = await getStubRequests(request, baseUrl, runId)
      expect(countPostMessages(reqs)).toBe(1)
    })
  })

  test('Image: webhook 2xx, exactly one outbound message (text or image)', async ({
    request,
  }, testInfo) => {
    const baseUrl = getStubBaseUrl()
    const runId = makeRunId(testInfo.testId)
    const messageId = `smoke-image-${Date.now()}-${Math.random().toString(36).slice(2)}`
    const mediaId = `img-${Date.now()}`
    const payload = metaPayloadImage(messageId, TEST_FROM, mediaId)

    await test.step('Act: POST webhook with image payload', async () => {
      await postWebhookAndAssert2xx(request, payload)
    })

    await test.step('Assert: exactly one outbound message', async () => {
      const reqs = await awaitOutboundExactlyOnce(request, baseUrl, { runId })
      assertStubContract(reqs, {
        expectedPathExact: EXPECTED_PATH_MESSAGES,
        expectedBearerToken: 'stub',
        expectedContentType: 'application/json',
      })
      const bodies = getPostMessageBodies(reqs)
      expect(bodies.length).toBe(1)
      expect(bodies[0].to).toBe(TEST_FROM)
      const msgType = bodies[0].type as string
      expect(['text', 'image']).toContain(msgType)
      if (msgType === 'text') {
        const textBody = (bodies[0].text as { body?: string })?.body
        expect(textBody).toBeDefined()
        expect(String(textBody).length).toBeGreaterThan(0)
      } else {
        expect(bodies[0].image).toBeDefined()
      }
    })
  })

  test('Audio: webhook 2xx, exactly one outbound message (text; stub audio is invalid so no TTS)', async ({
    request,
  }, testInfo) => {
    const baseUrl = getStubBaseUrl()
    const runId = makeRunId(testInfo.testId)
    const messageId = `smoke-audio-${Date.now()}-${Math.random().toString(36).slice(2)}`
    const mediaId = `audio-${Date.now()}`
    const payload = metaPayloadAudio(messageId, TEST_FROM, mediaId)

    await test.step('Act: POST webhook with audio payload', async () => {
      await postWebhookAndAssert2xx(request, payload)
    })

    await test.step('Assert: exactly one outbound (text, e.g. transcription error)', async () => {
      const reqs = await awaitOutboundExactlyOnce(request, baseUrl, { runId })
      assertStubContract(reqs, {
        expectedPathExact: EXPECTED_PATH_MESSAGES,
        expectedBearerToken: 'stub',
        expectedContentType: 'application/json',
      })
      const bodies = getPostMessageBodies(reqs)
      expect(bodies.length).toBe(1)
      expect(bodies[0].to).toBe(TEST_FROM)
      expect(bodies[0].type).toBe('text')
      const textBody = (bodies[0].text as { body?: string })?.body
      expect(textBody).toBeDefined()
      expect(String(textBody).length).toBeGreaterThan(0)
    })
  })
})
