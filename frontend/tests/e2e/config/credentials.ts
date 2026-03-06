/**
 * Admin credentials for E2E test infrastructure (setup/teardown API calls).
 *
 * Worker test users are created dynamically per run (see test-setup.ts).
 * Admin is the only fixture user — seeded by backend UserFixtures.
 */

const ADMIN_USER = process.env.AUTH_USER || 'admin@synaplan.com'
const ADMIN_PASS = process.env.AUTH_PASS || 'admin123'

export const CREDENTIALS = {
  DEFAULT_USER: ADMIN_USER,
  DEFAULT_PASSWORD: ADMIN_PASS,

  getAdminCredentials() {
    return { user: ADMIN_USER, pass: ADMIN_PASS }
  },

  getCredentials(overrides?: { user?: string; pass?: string }) {
    return {
      user: overrides?.user ?? ADMIN_USER,
      pass: overrides?.pass ?? ADMIN_PASS,
    }
  },
} as const
