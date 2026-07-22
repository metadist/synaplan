/**
 * Integration test data – single place for request-only (API/webhook) tests.
 * Used by API smoke tests (email, WhatsApp).
 */

export const INTEGRATION = {
  // WhatsApp sender numbers are generated per test (uniqueWaSender() in
  // helpers/whatsapp-payload.ts) — a fixed number exhausts the mapped
  // ANONYMOUS user's lifetime MESSAGES limit on long-lived test DBs.
  EMAIL: {
    /** Sender address in webhook payload. */
    TEST_FROM: 'user@example.com',
    /** Recipient (smart inbox). */
    TEST_TO: 'smart@synaplan.net',
  },
} as const
