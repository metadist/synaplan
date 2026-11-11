import { test, expect } from '../test-setup'
import { loginViaApi } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * The Authentication page names the four ways an account can appear, and a
 * row opens the switch that decides it. Turning a door off uses that switch
 * and puts it back, including when the check fails.
 */
test.describe('@ci Account doors', () => {
  test('an administrator sees who can create an account and can open the switch', async ({
    page,
    request,
  }) => {
    const admin = CREDENTIALS.getAdminCredentials()
    const cookie = await loginViaApi(request, admin)
    const api = getApiUrl()
    const current = await request.get(`${api}/api/v1/admin/config/values`, {
      headers: { Cookie: cookie },
    })
    expect(current.ok()).toBeTruthy()
    const body = (await current.json()) as {
      values?: Record<
        string,
        { value?: string; isSet?: boolean; envOverride?: boolean; effectiveValue?: string }
      >
    }
    const pause = body.values?.SIGNUP_PAUSED
    const pausePinned = pause?.envOverride === true

    const state = await request.storageState()
    await page.context().clearCookies()
    await page.context().addCookies(state.cookies)
    await page.addInitScript(() => {
      localStorage.setItem('sh', '1')
    })

    const restorePause = pause?.isSet ? (pause.value ?? 'false') : ''
    let flipped = false
    const putPause = (value: string) =>
      request.put(`${api}/api/v1/admin/config/values`, {
        headers: { Cookie: cookie },
        data: { key: 'SIGNUP_PAUSED', value },
      })

    try {
      await page.goto('/admin/config?tab=auth')
      const card = page.getByTestId('account-doors')
      await expect(card).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(card.getByTestId('account-door-selfSignup')).toBeVisible()
      await expect(card.getByTestId('account-door-directory')).toBeVisible()
      await expect(card.getByTestId('account-door-operator')).toBeVisible()
      await expect(card.getByTestId('account-door-channel')).toBeVisible()
      await expect(card.getByTestId('account-door-channel')).toContainText(
        'do not create an account'
      )
      await expect(card.getByTestId('account-door-operator-people')).toHaveAttribute(
        'href',
        '/admin/people'
      )

      const selfSignup = card.getByTestId('account-door-selfSignup')
      const reasonBefore = await selfSignup.getAttribute('data-reason')
      const field =
        reasonBefore === 'admission'
          ? 'REGISTRATION_MODE'
          : reasonBefore === 'paused'
            ? 'SIGNUP_PAUSED'
            : 'REGISTRATION_ENABLED'
      await card.getByTestId('account-door-selfSignup-switch').click()
      await expect(page.locator('#config-section-access')).toHaveAttribute('data-open', 'true')
      await expect(page.locator(`[data-config-field="${field}"]`)).toBeVisible()

      const pauseSwitch = page.locator('#SIGNUP_PAUSED')
      if (reasonBefore === 'open' && !pausePinned) {
        await expect(pauseSwitch).toBeEnabled()
        await pauseSwitch.click()
        flipped = true
        await expect(selfSignup).toHaveAttribute('data-reason', 'paused', {
          timeout: TIMEOUTS.STANDARD,
        })
        await pauseSwitch.click()
        flipped = false
        await expect(selfSignup).toHaveAttribute('data-reason', 'open', {
          timeout: TIMEOUTS.STANDARD,
        })
      } else if (pausePinned) {
        await expect(pauseSwitch).toBeDisabled()
      }
    } finally {
      if (flipped) {
        await putPause(restorePause)
      }
    }
  })
})
