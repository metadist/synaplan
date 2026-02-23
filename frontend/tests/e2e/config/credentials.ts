/**
 * Test Credentials
 *
 * Centralized login credentials for E2E tests.
 * Priority: Function parameters > ENV variables > defaults
 *
 * Multi-worker: Worker 0 uses admin; workers 1..N use e2e-worker-{1..N}@synaplan.com
 * (see backend UserFixtures). Ensures parallel tests don't share user state.
 */

const ADMIN_USER = process.env.AUTH_USER || 'admin@synaplan.com'
const ADMIN_PASS = process.env.AUTH_PASS || 'admin123'

const WORKER_USERS = [
  { user: ADMIN_USER, pass: ADMIN_PASS },
  { user: 'e2e-worker-1@synaplan.com', pass: 'e2e123' },
  { user: 'e2e-worker-2@synaplan.com', pass: 'e2e123' },
  { user: 'e2e-worker-3@synaplan.com', pass: 'e2e123' },
] as const

export const CREDENTIALS = {
  DEFAULT_USER: ADMIN_USER,
  DEFAULT_PASSWORD: ADMIN_PASS,

  /** Admin credentials (for admin API calls: cleanup, deleteUser). */
  getAdminCredentials() {
    return { user: ADMIN_USER, pass: ADMIN_PASS }
  },

  /**
   * Credentials for the current Playwright worker (testInfo.parallelIndex).
   * Use for login(page, credentials) and cleanup target. workers must be <= WORKER_USERS.length.
   */
  getCredentialsForWorker(workerIndex: number): { user: string; pass: string } {
    const idx = workerIndex % WORKER_USERS.length
    return { ...WORKER_USERS[idx] }
  },

  /**
   * Get credentials with optional overrides (for single-worker or explicit override)
   */
  getCredentials(overrides?: { user?: string; pass?: string }) {
    return {
      user: overrides?.user ?? this.DEFAULT_USER,
      pass: overrides?.pass ?? this.DEFAULT_PASSWORD,
    }
  },
} as const
