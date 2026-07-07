import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login, loginViaApi } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'
import { PROMPTS } from '../config/test-data'

test.describe('@ci @smoke Admin impersonation + chat', () => {
  test('admin can impersonate a user and chat without errors', async ({
    page,
    request,
    credentials,
  }) => {
    const chat = new ChatHelper(page)
    const adminCreds = CREDENTIALS.getAdminCredentials()
    let targetUserId: number

    await test.step('Arrange: look up the worker user ID via admin API', async () => {
      const adminCookie = await loginViaApi(request, adminCreds)
      const usersRes = await request.get(
        `${getApiUrl()}/api/v1/admin/users?search=${encodeURIComponent(credentials.user)}`,
        { headers: { Cookie: adminCookie } }
      )
      expect(usersRes.ok()).toBeTruthy()
      const body = (await usersRes.json()) as { users?: { id: number; email: string }[] }
      const target = body.users?.find((u) => u.email === credentials.user)
      expect(target, `Worker user ${credentials.user} must exist in admin user list`).toBeTruthy()
      targetUserId = target!.id
    })

    await test.step('Arrange: log in as admin and navigate to admin users tab', async () => {
      await login(page, adminCreds)

      await page.locator(selectors.nav.sidebarV2Admin).click()
      const dropdown = page.locator(selectors.nav.navDropdown)
      await expect(dropdown).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await dropdown.locator(selectors.nav.flyoutLinkAdminDashboard).click()

      await page.locator(selectors.pages.admin).waitFor({
        state: 'visible',
        timeout: TIMEOUTS.STANDARD,
      })

      await page.locator(selectors.admin.tabUsers).click()
      await page.locator(selectors.admin.sectionUsers).waitFor({
        state: 'visible',
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Act: search for the worker user and impersonate', async () => {
      await page.locator(selectors.admin.userSearch).fill(credentials.user)

      await page
        .locator(selectors.admin.impersonateUser(targetUserId))
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      await page.locator(selectors.admin.impersonateUser(targetUserId)).click()

      await page
        .locator(selectors.dialog.confirmBtn)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await page.locator(selectors.dialog.confirmBtn).click()
    })

    await test.step('Assert: impersonation banner is visible', async () => {
      await page
        .locator(selectors.impersonation.banner)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      const targetText = await page.locator(selectors.impersonation.bannerTarget).textContent()
      expect(targetText).toContain(credentials.user)
    })

    await test.step('Act: send a message and receive TestProvider response', async () => {
      await chat.startNewChat()
      const previousCount = await chat.sendMessage(PROMPTS.CHAT_SMOKE)
      const aiText = await chat.waitForAnswer(previousCount)
      expect(aiText.length).toBeGreaterThan(0)
    })

    await test.step('Act: exit impersonation', async () => {
      await page.locator(selectors.impersonation.exitBtn).click()

      await page
        .locator(selectors.impersonation.banner)
        .waitFor({ state: 'hidden', timeout: TIMEOUTS.LONG })
    })

    await test.step('Assert: admin session restored — admin page visible', async () => {
      await page.locator(selectors.pages.admin).waitFor({
        state: 'visible',
        timeout: TIMEOUTS.LONG,
      })
    })
  })
})
