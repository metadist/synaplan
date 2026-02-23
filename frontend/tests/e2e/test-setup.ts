import { test as base } from '@playwright/test'
import { cleanupUserData } from './helpers/auth'
import { CREDENTIALS } from './config/credentials'

export const test = base.extend({
  // Mark test env so index.html does not load the widget
  page: async ({ page }, use) => {
    await page.addInitScript(() => {
      ;(window as any).__PLAYWRIGHT__ = true
    })
    await use(page)
  },
})

test.afterEach(async ({ request }, testInfo) => {
  if (testInfo.file && testInfo.file.includes('integration')) return
  const testUserEmail = CREDENTIALS.DEFAULT_USER
  try {
    await cleanupUserData(request, testUserEmail)
  } catch (error) {
    console.warn(`Failed to cleanup user data after test ${testInfo.title}:`, error)
  }
})

export { expect, type Locator, type Page } from '@playwright/test'
