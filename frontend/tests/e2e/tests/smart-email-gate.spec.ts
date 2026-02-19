/**
 * PR-CI gate: Smart-Email flow (Inbound Webhook → AI Test-Provider → SMTP → MailHog).
 * Uses Playwright request API only; no browser. Backend: WebhookController::email (POST /api/v1/webhooks/email).
 */

import { test, expect } from '../test-setup'
import {
  clearMailHog,
  fetchMessages,
  getPlainTextBody,
  toMatches,
} from '../helpers/email'
import { getApiUrl, isTestStack } from '../config/config'

const WEBHOOK_PATH = '/api/v1/webhooks/email'
const MAILHOG_POLL_TIMEOUT_MS = 3000
const MAILHOG_POLL_INTERVAL_MS = 200

/** Recipient for inbound "from"; reply is sent To this address. */
const TEST_FROM = 'user@example.com'
/** To address (smart email); must match backend parsing (e.g. smart+keyword@synaplan.net or smart@synaplan.net). */
const TEST_TO = 'smart@synaplan.net'
/** Deterministic reply substring from TestProvider (backend/src/AI/Provider/TestProvider.php). */
const TEST_PROVIDER_REPLY_MARKER = 'TestProvider response'

function webhookUrl(): string {
  return `${getApiUrl()}${WEBHOOK_PATH}`
}

test.describe('Smart-Email gate @gate', () => {
  test.beforeEach(async ({ request }) => {
    await clearMailHog(request)
  })

  test('Inbound → Reply sent: webhook 2xx, exactly one reply in MailHog with TestProvider text', async ({
    request,
  }) => {
    test.skip(
      !isTestStack(),
      'Smart-email gate requires test stack (E2E_STACK=test, MailHog, TestProvider)'
    )
    await test.step('Arrange: verify 0 messages', async () => {
      const messages = await fetchMessages(request)
      expect(messages.length, 'MailHog should be empty before trigger').toBe(0)
    })

    const subject = `Gate test ${Date.now()}`
    const body = 'What is machine learning?'

    await test.step('Act: trigger inbound via POST /api/v1/webhooks/email', async () => {
      const res = await request.post(webhookUrl(), {
        data: {
          from: TEST_FROM,
          to: TEST_TO,
          subject,
          body,
          message_id: `gate-${Date.now()}`,
        },
      })
      expect(res.status(), 'Webhook must return 2xx').toBeGreaterThanOrEqual(200)
      expect(res.status()).toBeLessThan(300)
      const json = await res.json()
      expect(json.success, 'Response must indicate success').toBe(true)
    })

    await test.step('Assert: bounded retry until exactly one mail to user@example.com with TestProvider reply', async () => {
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

      expect(matching.length, 'Exactly one reply mail to user@example.com').toBe(1)
      expect(
        matching[0].body,
        'Reply body must contain deterministic TestProvider response text'
      ).toContain(TEST_PROVIDER_REPLY_MARKER)
    })
  })

  })
