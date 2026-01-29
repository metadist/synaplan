/**
 * Test Credentials
 *
 * Centralized login credentials for E2E tests.
 * Priority: Function parameters > ENV variables > defaults
 */

export const CREDENTIALS = {
  DEFAULT_USER: process.env.AUTH_USER || 'admin@synaplan.com',
  DEFAULT_PASSWORD: process.env.AUTH_PASS || 'admin123',

  /**
   * Get credentials with optional overrides
   */
  getCredentials(overrides?: { user?: string; pass?: string }) {
    return {
      user: overrides?.user ?? this.DEFAULT_USER,
      pass: overrides?.pass ?? this.DEFAULT_PASSWORD,
    }
  },
} as const
