import { test, expect, LOGGED_OUT } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { provisionUser, deleteUser, login } from '../helpers/auth'
import { TIMEOUTS } from '../config/config'

/**
 * Password-change roundtrip with a DISPOSABLE user: the worker user's
 * password must stay untouched (other tests log in with it), so this spec
 * provisions its own account and deletes it afterwards. Starting logged out
 * because the login flow itself is part of the assertion.
 */
test.use(LOGGED_OUT)

test.describe('@ci Profile Password Change', () => {
  test('user can change the password and log in with the new one', async ({ page, request }) => {
    const email = `e2e-pwchange-${Date.now()}@test.synaplan.com`
    const oldPassword = 'E2eOldPass1234!'
    const newPassword = 'E2eNewPass5678!'

    await test.step('Arrange: provision a disposable user and log in', async () => {
      await provisionUser(request, { email, password: oldPassword })
      await login(page, { user: email, pass: oldPassword })
    })

    try {
      await test.step('Act: change the password on the profile page', async () => {
        await page.goto('/profile')
        await page
          .locator(selectors.profile.inputCurrentPassword)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        await page.locator(selectors.profile.inputCurrentPassword).fill(oldPassword)
        await page.locator(selectors.profile.inputNewPassword).fill(newPassword)
        await page.locator(selectors.profile.inputConfirmPassword).fill(newPassword)
        await page.locator(selectors.unsavedBar.save).click()
      })

      await test.step('Assert: save succeeded (password fields reset, no error toast)', async () => {
        await expect(page.locator(selectors.profile.inputCurrentPassword)).toHaveValue('', {
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(page.locator(selectors.notification.error)).toHaveCount(0)
      })

      await test.step('Act: log out via the user menu', async () => {
        await page.locator(selectors.userMenu.button).click()
        await page
          .locator(selectors.userMenu.logoutBtn)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
        await page.locator(selectors.userMenu.logoutBtn).click()
        await page.waitForURL(/\/login/, { timeout: TIMEOUTS.STANDARD })
      })

      await test.step('Assert: the old password is rejected', async () => {
        await page.locator(selectors.login.email).fill(email)
        await page.locator(selectors.login.password).fill(oldPassword)
        await page.locator(selectors.login.submit).click()
        await expect(page.locator(selectors.login.errorAlert)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
      })

      await test.step('Assert: the new password logs in', async () => {
        await login(page, { user: email, pass: newPassword })
      })
    } finally {
      await deleteUser(request, email)
    }
  })
})
