/**
 * E2E Test Configuration
 *
 * Centralized configuration for E2E tests.
 * Priority: ENV variables > .env.local > defaults
 */

// URLs (with env fallbacks)
export const URLS = {
  BASE_URL: process.env.BASE_URL || 'http://localhost:5173',
  MAILHOG_URL: process.env.MAILHOG_URL || 'http://localhost:8025',

  // Derived URLs
  get TEST_PAGE_URL() {
    return `${this.BASE_URL}/test-widget.html`
  },
} as const

/**
 * Get API URL for widget tests
 * In dev: frontend on 5173, backend on 8000
 * In CI: frontend and backend on same port (8001)
 */
export function getApiUrl(): string {
  const baseUrl = URLS.BASE_URL
  if (baseUrl.includes('localhost:5173') || baseUrl.includes('127.0.0.1:5173')) {
    return 'http://localhost:8000'
  }
  // In CI or other environments, backend is on the same port as frontend
  return baseUrl
}

// Timeouts (in milliseconds)
export const TIMEOUTS = {
  // Short waits (quick UI updates)
  SHORT: 5_000,

  // Standard waits (most UI elements)
  STANDARD: 10_000,

  // Long waits (async operations, network)
  LONG: 15_000,

  // Very long waits (file uploads, AI responses)
  VERY_LONG: 30_000,

  // Extremely long (complex operations)
  EXTREME: 60_000,
} as const

// Polling intervals
// Helper functions return new arrays (mutable) for Playwright's poll options
// 
// Playwright's expect.poll() intervals option accepts [min, max] milliseconds:
// - First number: Minimum wait time between checks
// - Second number: Maximum wait time between checks
// Playwright randomly picks a time between min and max for each poll cycle.
// This randomization helps avoid deterministic timing issues and makes tests less flaky.
//
// Example: FAST() returns [500, 1000] means:
// - Wait at least 500ms between checks
// - Wait at most 1000ms between checks
// - Actual wait time is randomly chosen between 500-1000ms each cycle
export const INTERVALS = {
  /**
   * Fast polling: Check every 500ms-1s (randomized)
   * Use for quick UI updates
   */
  FAST: (): [number, number] => [500, 1000],

  /**
   * Standard polling: Check every 1s-2s (randomized)
   * Use for normal operations
   */
  STANDARD: (): [number, number] => [1000, 2000],

  /**
   * Slow polling: Check every 2s-3s (randomized)
   * Use for slow operations
   */
  SLOW: (): [number, number] => [2000, 3000],
} as const
