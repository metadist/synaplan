import { test, expect } from '../test-setup'
import { loginViaApi } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'
import { selectors } from '../helpers/selectors'

/**
 * The Authentication page names the four ways an account can appear, and a
 * row opens the switch that decides it.
 *
 * SIGNUP_PAUSED is toggled in this browser only via page.route(): the @ci
 * chromium shards share one test stack across four workers, so mutating
 * SIGNUP_PAUSED server-side would block concurrent registration specs with
 * 503 SIGNUP_PAUSED (see registration-closed.spec.ts and docs/E2E_TESTING.md).
 */
test.describe('@ci Account doors', () => {
  test('an administrator sees who can create an account, opens the switch and pauses sign-up', async ({
    page,
    request,
  }) => {
    const admin = CREDENTIALS.getAdminCredentials()
    await loginViaApi(request, admin)
    const state = await request.storageState()
    await page.context().clearCookies()
    await page.context().addCookies(state.cookies)
    await page.addInitScript(() => {
      localStorage.setItem('sh', '1')
    })

    let pausedValue = 'false'
    await page.route('**/api/v1/admin/config/values**', async (route) => {
      if (route.request().method() === 'PUT') {
        const payload = route.request().postDataJSON() as { key?: string; value?: string }
        if (payload.key === 'SIGNUP_PAUSED') {
          pausedValue = payload.value ?? 'false'
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, requiresRestart: false }),
          })
          return
        }
      }
      if (route.request().method() === 'GET') {
        const response = await route.fetch()
        const json = (await response.json()) as {
          values?: Record<string, { value?: string; isSet?: boolean; isMasked?: boolean }>
        }
        if (json.values) {
          json.values.SIGNUP_PAUSED = {
            value: pausedValue,
            isSet: pausedValue === 'true',
            isMasked: false,
          }
        }
        await route.fulfill({ response, json })
        return
      }
      await route.continue()
    })

    await page.goto('/admin/config?tab=auth')
    const card = page.locator(selectors.admin.accountDoors)
    await expect(card).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(card.locator(selectors.admin.accountDoor('selfSignup'))).toBeVisible()
    await expect(card.locator(selectors.admin.accountDoor('directory'))).toBeVisible()
    await expect(card.locator(selectors.admin.accountDoor('operator'))).toBeVisible()
    await expect(card.locator(selectors.admin.accountDoor('channel'))).toBeVisible()
    await expect(card.locator(selectors.admin.accountDoor('channel'))).toContainText(
      'do not create an account'
    )
    await expect(card.locator(selectors.admin.accountDoorPeople)).toHaveAttribute(
      'href',
      '/admin/people'
    )

    const selfSignup = card.locator(selectors.admin.accountDoor('selfSignup'))
    const reasonBefore = await selfSignup.getAttribute('data-reason')
    const field =
      reasonBefore === 'admission'
        ? 'REGISTRATION_MODE'
        : reasonBefore === 'paused'
          ? 'SIGNUP_PAUSED'
          : 'REGISTRATION_ENABLED'

    await card.locator(selectors.admin.accountDoorSwitch('selfSignup')).click()
    await expect(page.locator('#config-section-access')).toHaveAttribute('data-open', 'true')
    await expect(page.locator(`[data-config-field="${field}"]`)).toBeVisible()

    if (reasonBefore === 'open') {
      const pauseSwitch = page.locator('#SIGNUP_PAUSED')
      await expect(pauseSwitch).toBeEnabled()
      await pauseSwitch.click()
      await expect(selfSignup).toHaveAttribute('data-reason', 'paused', {
        timeout: TIMEOUTS.STANDARD,
      })

      await card.locator(selectors.admin.accountDoorSwitch('selfSignup')).click()
      await expect(page).toHaveURL(/highlight=SIGNUP_PAUSED/)

      await pauseSwitch.click()
      await expect(selfSignup).toHaveAttribute('data-reason', 'open', {
        timeout: TIMEOUTS.STANDARD,
      })
    }
  })
})
