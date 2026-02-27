/**
 * Smoke: Smart-Email flow (Inbound Webhook → AI Test-Provider → SMTP → MailHog).
 * Request-only. Backend: POST /api/v1/webhooks/email.
 */

import { test, expect } from '../e2e/test-setup'
import { clearMailHog, fetchMessages, getPlainTextBody, toMatches } from './helpers/email'
import { getApiUrl, isTestStack } from '../e2e/config/config'
import { INTEGRATION } from '../e2e/config/integration-data'

const WEBHOOK_PATH = '/api/v1/webhooks/email'
const MAILHOG_POLL_TIMEOUT_MS = 3000
const MAILHOG_POLL_INTERVAL_MS = 200

const { TEST_FROM, TEST_TO, TEST_PROVIDER_REPLY_MARKER } = INTEGRATION.EMAIL

function webhookUrl(): string {
  return `${getApiUrl()}${WEBHOOK_PATH}`
}

test.describe('@ci Smart-Email smoke @smoke', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeEach(async ({ request }) => {
    if (!isTestStack()) return
    await clearMailHog(request)
  })

  test('Inbound → Reply sent: webhook 2xx, exactly one reply in MailHog with TestProvider text', async ({
    request,
  }) => {
    await test.step('Arrange: verify 0 messages', async () => {
      const messages = await fetchMessages(request)
      expect(messages.length, 'MailHog should be empty before trigger').toBe(0)
    })

    const subject = `Smoke ${Date.now()}`
    const body = 'What is machine learning?'

    await test.step('Act: POST /api/v1/webhooks/email', async () => {
      const res = await request.post(webhookUrl(), {
        data: {
          from: TEST_FROM,
          to: TEST_TO,
          subject,
          body,
          message_id: `smoke-${Date.now()}`,
        },
      })
      expect(res.status()).toBeGreaterThanOrEqual(200)
      expect(res.status()).toBeLessThan(300)
      const json = await res.json()
      expect(json.success).toBe(true)
      expect(json.message_id).toBeDefined()
      expect(Number.isInteger(json.message_id)).toBe(true)
      expect(json.chat_id).toBeDefined()
      expect(Number.isInteger(json.chat_id)).toBe(true)
    })

    await test.step('Assert: bounded retry until exactly one mail with TestProvider reply', async () => {
      let matching: Array<{ body: string }> = []
      await expect
        .poll(
          async () => {
            const messages = await fetchMessages(request)
            matching = messages
              .filter((m) => toMatches(m, TEST_FROM))
              .map((m) => ({ body: getPlainTextBody(m) }))
            return matching.length >= 1 ? matching : null
          },
          {
            timeout: MAILHOG_POLL_TIMEOUT_MS,
            intervals: [MAILHOG_POLL_INTERVAL_MS, MAILHOG_POLL_INTERVAL_MS],
          }
        )
        .not.toBeNull()

      expect(matching.length).toBe(1)
      expect(matching[0].body).toContain(TEST_PROVIDER_REPLY_MARKER)
    })
  })

  test('Invalid payload → 400, error body, no reply in MailHog', async ({ request }) => {
    await test.step('Arrange: empty MailHog', async () => {
      const messages = await fetchMessages(request)
      expect(messages.length).toBe(0)
    })

    await test.step('Act: POST with missing required body', async () => {
      const res = await request.post(webhookUrl(), {
        data: {
          from: TEST_FROM,
          to: TEST_TO,
          subject: 'No body',
          // body missing
        },
      })
      expect(res.status()).toBe(400)
      const json = await res.json()
      expect(json.success).toBe(false)
      expect(json.error).toBeDefined()
      expect(String(json.error)).toContain('from')
      expect(String(json.error)).toContain('to')
      expect(String(json.error)).toContain('body')
    })

    await test.step('Assert: no reply sent to sender', async () => {
      const messages = await fetchMessages(request)
      const toSender = messages.filter((m) => toMatches(m, TEST_FROM))
      expect(toSender.length, 'Invalid request must not trigger reply').toBe(0)
    })
  })
})
