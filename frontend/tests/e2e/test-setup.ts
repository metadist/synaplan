import { test as base } from '@playwright/test'
import { cleanupUserData } from './helpers/auth'
import { CREDENTIALS } from './config/credentials'
import { isTestStack } from './config/config'

export const test = base.extend<{ credentials: { user: string; pass: string } }>({
  // Credentials for the current worker (enables parallel E2E with isolated user per worker)
  // eslint-disable-next-line no-empty-pattern
  credentials: async ({}, use, testInfo) => {
    const creds = CREDENTIALS.getCredentialsForWorker(testInfo.parallelIndex)
    await use(creds)
  },

  // Mark test env so index.html does not load the widget
  page: async ({ page }, use) => {
    await page.addInitScript(() => {
      ;(window as any).__PLAYWRIGHT__ = true
    })
    await use(page)
  },
})

// Integration tests require test stack (E2E_STACK=test). Skip from one place.
// eslint-disable-next-line no-empty-pattern
test.beforeEach(({}, testInfo) => {
  if (testInfo.file?.includes('integration') && !isTestStack()) {
    test.skip(true, 'Integration tests require test stack (E2E_STACK=test)')
  }
})

// Cleanup only for E2E specs (e2e/tests/), not integration or other suites.
test.afterEach(async ({ request }, testInfo) => {
  if (!testInfo.file?.includes('e2e/tests/')) return
  const workerCreds = CREDENTIALS.getCredentialsForWorker(testInfo.parallelIndex)
  const adminCreds = CREDENTIALS.getAdminCredentials()
  try {
    await cleanupUserData(request, workerCreds.user, adminCreds)
  } catch (error) {
    console.warn(`Failed to cleanup user data after test ${testInfo.title}:`, error)
  }
})

export { expect, type Locator, type Page } from '@playwright/test'
