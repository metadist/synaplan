import { test as base } from '@playwright/test'
import { cleanupUserData } from './helpers/auth'
import { CREDENTIALS } from './config/credentials'

// Extend base test with afterEach hook for automatic cleanup
export const test = base.extend({
  // Set window.__PLAYWRIGHT__ to mark test environment (prevents widget in index.html from loading)
  page: async ({ page }, use) => {
    await page.addInitScript(() => {
      ;(window as any).__PLAYWRIGHT__ = true
    })
    await use(page)
  },
})

// Note: Large viewport (1920x1080) is configured in playwright.config.ts
// This ensures all elements are visible and prevents viewport-related issues

test.afterEach(async ({ request }, testInfo) => {
  const testUserEmail = CREDENTIALS.DEFAULT_USER
  try {
    await cleanupUserData(request, testUserEmail)
  } catch (error) {
    console.warn(`Failed to cleanup user data after test ${testInfo.title}:`, error)
  }
})

export { expect, type Locator, type Page } from '@playwright/test'
