import { test, expect } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS, getApiUrl } from '../config/config'

function apiBaseUrl(): string {
  return getApiUrl()
}

test.describe('@ci @smoke Guest Trial Chat', () => {
  test('guest user sees chat page without login', async ({ page }) => {
    await test.step('Arrange: navigate to root as unauthenticated user', async () => {
      await page.goto('/')
    })

    await test.step('Assert: chat page loads with input visible', async () => {
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Assert: guest banner is visible', async () => {
      await expect(page.locator(selectors.guest.banner)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })

  test('guest banner shows remaining messages and can be dismissed', async ({ page }) => {
    await test.step('Arrange: open guest chat', async () => {
      await page.goto('/')
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Assert: banner contains signup link', async () => {
      await expect(page.locator(selectors.guest.bannerSignup)).toBeVisible()
    })

    await test.step('Act: dismiss the banner', async () => {
      await page.locator(selectors.guest.bannerDismiss).click()
    })

    await test.step('Assert: banner is hidden after dismiss', async () => {
      await expect(page.locator(selectors.guest.banner)).not.toBeVisible()
    })
  })

  test('guest banner signup link navigates to registration', async ({ page }) => {
    await test.step('Arrange: open guest chat', async () => {
      await page.goto('/')
      await expect(page.locator(selectors.guest.banner)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Act: click signup link on banner', async () => {
      await page.locator(selectors.guest.bannerSignup).click()
    })

    await test.step('Assert: navigated to register page', async () => {
      await expect(page).toHaveURL(/\/register/)
    })
  })
})

test.describe('@ci Guest API', () => {
  test('guest session API creates and returns session', async ({ request }) => {
    await test.step('Act: create a guest session via API', async () => {
      const response = await request.post(`${apiBaseUrl()}/api/v1/guest/session`, {
        data: {},
      })
      expect(response.ok()).toBeTruthy()

      const data = await response.json()
      expect(data.sessionId).toBeTruthy()
      expect(data.remaining).toBe(5)
      expect(data.maxMessages).toBe(5)
      expect(data.limitReached).toBe(false)
    })
  })

  test('guest session API returns existing session', async ({ request }) => {
    let sessionId: string

    await test.step('Arrange: create initial session', async () => {
      const response = await request.post(`${apiBaseUrl()}/api/v1/guest/session`, {
        data: {},
      })
      const data = await response.json()
      sessionId = data.sessionId
    })

    await test.step('Assert: re-posting with same sessionId returns it', async () => {
      const response = await request.post(`${apiBaseUrl()}/api/v1/guest/session`, {
        data: { sessionId },
      })
      expect(response.ok()).toBeTruthy()

      const data = await response.json()
      expect(data.sessionId).toBe(sessionId!)
    })
  })

  test('guest session status returns 404 for unknown session', async ({ request }) => {
    await test.step('Act & Assert: GET unknown session returns 404', async () => {
      const response = await request.get(
        `${apiBaseUrl()}/api/v1/guest/session/nonexistent-session-id`
      )
      expect(response.status()).toBe(404)
    })
  })

  test('guest chat creation requires valid session', async ({ request }) => {
    await test.step('Act & Assert: POST chat without valid session returns error', async () => {
      const response = await request.post(`${apiBaseUrl()}/api/v1/guest/chat`, {
        data: { sessionId: 'invalid-session' },
      })
      expect(response.ok()).toBeFalsy()
    })
  })

  test('guest chat creation works with valid session', async ({ request }) => {
    let sessionId: string

    await test.step('Arrange: create guest session', async () => {
      const response = await request.post(`${apiBaseUrl()}/api/v1/guest/session`, {
        data: {},
      })
      const data = await response.json()
      sessionId = data.sessionId
    })

    await test.step('Act: create chat for guest session', async () => {
      const response = await request.post(`${apiBaseUrl()}/api/v1/guest/chat`, {
        data: { sessionId: sessionId! },
      })
      expect(response.ok()).toBeTruthy()

      const data = await response.json()
      expect(data.chatId).toBeTruthy()
      expect(typeof data.chatId).toBe('number')
    })
  })

  test('guest endpoints do not require authentication', async ({ request }) => {
    await test.step('Assert: session endpoint accessible without auth', async () => {
      const response = await request.post(`${apiBaseUrl()}/api/v1/guest/session`, {
        data: {},
      })
      expect(response.status()).not.toBe(401)
    })

    await test.step('Assert: status endpoint accessible without auth', async () => {
      const response = await request.get(`${apiBaseUrl()}/api/v1/guest/session/test-id`)
      expect(response.status()).not.toBe(401)
    })
  })
})
