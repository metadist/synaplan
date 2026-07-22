/**
 * Smoke: Smart-Email flow (Inbound Webhook → AI → SMTP → MailHog).
 * Request-only. Backend: POST /api/v1/webhooks/email.
 */

import { test, expect } from '../test-setup'
import { fetchMessages, getPlainTextBody, toMatches } from '../helpers/email'
import { getApiUrl } from '../config/config'
import { INTEGRATION } from '../config/integration-data'

const WEBHOOK_PATH = '/api/v1/webhooks/email'
// 10 s upper bound on the inbound-email round-trip to MailHog.
//
// Originally 3 s — that worked on a quiet CI but is brittle once the test
// stack has any concurrent load (other E2E specs share the same backend +
// in-memory messenger queue, and SMTP→MailHog isn't sub-second). Bumping
// to 10 s is still well within the 60 s test timeout and removes the
// flake without masking real regressions: a genuinely broken email path
// won't deliver a reply at all.
const MAILHOG_POLL_TIMEOUT_MS = 10_000
const MAILHOG_POLL_INTERVAL_MS = 200

const { TEST_TO } = INTEGRATION.EMAIL

/**
 * Unique sender per test: the webhook maps each sender address to an
 * auto-created ANONYMOUS user whose MESSAGES limit is a LIFETIME total —
 * a fixed address would hit 429 "Rate limit exceeded" after enough runs
 * against a long-lived test DB. Unique senders also make the MailHog
 * baseline trivially 0.
 */
function uniqueEmailSender(): string {
  return `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

function webhookUrl(): string {
  return `${getApiUrl()}${WEBHOOK_PATH}`
}

test.describe('@ci @smoke Smart-Email', () => {
  test.describe.configure({ mode: 'serial' })

  test('inbound webhook triggers exactly one reply in MailHog', async ({ request }) => {
    const testFrom = uniqueEmailSender()
    let baselineCount = 0
    await test.step('Arrange: count existing messages to sender', async () => {
      const messages = await fetchMessages(request)
      baselineCount = messages.filter((m) => toMatches(m, testFrom)).length
    })

    const subject = `Smoke ${Date.now()}`
    const body = 'What is machine learning?'

    await test.step('Act: POST /api/v1/webhooks/email', async () => {
      const res = await request.post(webhookUrl(), {
        data: {
          from: testFrom,
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

    await test.step('Assert: bounded retry until exactly one new reply mail', async () => {
      let matching: Array<{ body: string }> = []
      await expect
        .poll(
          async () => {
            const messages = await fetchMessages(request)
            matching = messages
              .filter((m) => toMatches(m, testFrom))
              .map((m) => ({ body: getPlainTextBody(m) }))
            return matching.length >= baselineCount + 1 ? matching : null
          },
          {
            timeout: MAILHOG_POLL_TIMEOUT_MS,
            intervals: [MAILHOG_POLL_INTERVAL_MS, MAILHOG_POLL_INTERVAL_MS],
          }
        )
        .not.toBeNull()

      expect(matching.length).toBe(baselineCount + 1)
      expect(matching[0].body.length).toBeGreaterThan(0)
    })
  })

  test('invalid payload returns 400 and sends no reply', async ({ request }) => {
    const testFrom = uniqueEmailSender()
    let baselineCount = 0
    await test.step('Arrange: count existing messages to sender', async () => {
      const messages = await fetchMessages(request)
      baselineCount = messages.filter((m) => toMatches(m, testFrom)).length
    })

    await test.step('Act: POST with missing required body', async () => {
      const res = await request.post(webhookUrl(), {
        data: {
          from: testFrom,
          to: TEST_TO,
          subject: 'No body',
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

    await test.step('Assert: no new reply sent to sender', async () => {
      const messages = await fetchMessages(request)
      const toSender = messages.filter((m) => toMatches(m, testFrom))
      expect(toSender.length, 'Invalid request must not trigger reply').toBe(baselineCount)
    })
  })
})
