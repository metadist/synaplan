/** E2E config. Set env vars (e.g. BASE_URL=http://localhost:8001) to override defaults. */

export const URLS = {
  BASE_URL: process.env.BASE_URL || 'http://localhost:5173',
  MAILHOG_URL: process.env.MAILHOG_URL || 'http://localhost:8025',

  get TEST_PAGE_URL() {
    return `${this.BASE_URL}/widget-test.html`
  },
} as const

/** Backend URL: dev 8000, test stack/CI same as BASE_URL (8001). */
export function getApiUrl(): string {
  const baseUrl = URLS.BASE_URL
  if (baseUrl.includes('localhost:5173') || baseUrl.includes('127.0.0.1:5173')) {
    return 'http://localhost:8000'
  }
  return baseUrl
}

export const TIMEOUTS = {
  SHORT: 5_000,
  STANDARD: 10_000,
  LONG: 15_000,
  VERY_LONG: 30_000,
  EXTREME: 60_000,
} as const

// Poll [min, max] ms per check (randomized to reduce flakiness). Use FAST() etc. for expect.poll().
export const INTERVALS = {
  FAST: (): [number, number] => [500, 1000],
  STANDARD: (): [number, number] => [1000, 2000],
  SLOW: (): [number, number] => [2000, 3000],
} as const
