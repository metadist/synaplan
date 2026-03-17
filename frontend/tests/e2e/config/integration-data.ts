/**
 * Integration test data – single place for request-only (API/webhook) tests.
 * Used by API smoke tests (email, WhatsApp).
 */

export const INTEGRATION = {
  WHATSAPP: {
    /** Sender phone number (E.164-like, no +). */
    TEST_FROM: '4915112345678',
  },
  EMAIL: {
    /** Sender address in webhook payload. */
    TEST_FROM: 'user@example.com',
    /** Recipient (smart inbox). */
    TEST_TO: 'smart@synaplan.net',
  },
} as const
