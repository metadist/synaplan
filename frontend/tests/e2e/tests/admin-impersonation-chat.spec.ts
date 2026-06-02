import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login, loginViaApi } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'
import { PROMPTS } from '../config/test-data'

const adminCreds = CREDENTIALS.getAdminCredentials()

test.describe('@ci @smoke Admin impersonation + chat', () => {
  test('admin can impersonate a user and chat without errors', async ({
    page,
    request,
    credentials,
  }) => {
    const chat = new ChatHelper(page)
    let targetUserId: number

    await test.step('Arrange: look up the worker user ID via admin API', async () => {
      const adminCookie = await loginViaApi(request, adminCreds)
      const usersRes = await request.get(
        `${getApiUrl()}/api/v1/admin/users?search=${encodeURIComponent(credentials.user)}`,
        { headers: { Cookie: adminCookie } }
      )
      expect(usersRes.ok()).toBeTruthy()
      const body = await usersRes.json()
      const target = body.users?.find((u: { email: string }) => u.email === credentials.user)
      expect(target, `Worker user ${credentials.user} must exist in admin user list`).toBeTruthy()
      targetUserId = target.id
    })

    await test.step('Arrange: log in as admin and navigate to admin users tab', async () => {
      await login(page, adminCreds)

      await page.locator(selectors.nav.sidebarV2Admin).click()
      await page
        .locator(selectors.nav.navDropdown)
        .locator(selectors.nav.flyoutLinkAdminDashboard)
        .click()
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

    await test.step('Act: start a new chat and send a message as impersonated user', async () => {
      await chat.startNewChat()
      const previousCount = await chat.conversationBubbles().count()

      await page.locator(selectors.chat.textInput).fill(PROMPTS.CHAT_SMOKE)
      await page.locator(selectors.chat.sendBtn).click()

      const aiText = await chat.waitForAnswer(previousCount)
      expect(
        aiText.length,
        'AI should respond with non-empty text while impersonated'
      ).toBeGreaterThan(0)
    })

    await test.step('Act: exit impersonation', async () => {
      await page.locator(selectors.impersonation.exitBtn).click()

      await page
        .locator(selectors.impersonation.banner)
        .waitFor({ state: 'hidden', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: admin session restored — admin page visible', async () => {
      await page.locator(selectors.pages.admin).waitFor({
        state: 'visible',
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})
