import { test, expect } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { deleteUser } from '../helpers/auth'

test('@auth @smoke registration flow with email verification id=006', async ({ page, request }) => {
  const testEmail = 'test@test.com'
  const testPassword = 'Test1234'
  const mailhogUrl = process.env.MAILHOG_URL || 'http://localhost:8025'
  const decodeQuotedPrintable = (input: string) =>
    input
      .replace(/=\r?\n/g, '')
      .replace(/=([0-9A-Fa-f]{2})/g, (_, hex: string) =>
        String.fromCharCode(parseInt(hex, 16))
      )

  await page.goto('/login')
  await expect(page.locator(selectors.login.email)).toBeVisible({ timeout: 10_000 })
  await page.locator('[data-testid="btn-cookie-accept"]').click()

  await page.locator(selectors.login.signUpLink).click()
  await expect(page).toHaveURL(/\/register/)
  await expect(page.locator(selectors.register.fullName)).toBeVisible({ timeout: 10_000 })

  await page.locator(selectors.register.fullName).fill('test')
  await page.locator(selectors.register.email).fill(testEmail)
  await page.locator(selectors.register.password).fill(testPassword)
  await page.locator(selectors.register.confirmPassword).fill(testPassword)

  await page.locator(selectors.register.submit).click()

  await expect(page.locator(selectors.register.successSection)).toBeVisible({ timeout: 10_000 })
  await expect(page.locator(selectors.register.successSection)).toContainText(/registration.*success/i)

  await page.locator(selectors.register.backToLoginBtn).click()
  await expect(page).toHaveURL(/\/login/)

  let verificationEmail: any = null
  await expect.poll(
    async () => {
      const mailhogResponse = await request.get(`${mailhogUrl}/api/v2/messages`)
      if (!mailhogResponse.ok()) {
        throw new Error(`MailHog API returned ${mailhogResponse.status()}`)
      }

      const messages = await mailhogResponse.json()
      verificationEmail = messages.items?.find(
        (msg: any) =>
          msg.Content?.Headers?.To?.some((to: string) => to.includes(testEmail)) &&
          (msg.Content?.Headers?.Subject?.some((subj: string) =>
            subj.toLowerCase().includes('verif')
          ) ||
            msg.Content?.Body?.includes('verify') ||
            msg.Content?.Body?.includes('verification'))
      )

      return verificationEmail ?? null
    },
    { timeout: 10_000, intervals: [1000] }
  ).not.toBeNull()

  const emailBody = verificationEmail.Content.Body || ''
  const emailHtml = verificationEmail.Content?.Parts?.[0]?.Body || emailBody
  const fullBody = emailHtml || emailBody
  const decodedBody = decodeQuotedPrintable(fullBody)

  let linkMatch = decodedBody.match(
    /href=["']([^"']*\/verify-email-callback\?token=[^"']*)["']/i
  )
  if (!linkMatch) {
    linkMatch = decodedBody.match(
      /(https?:\/\/[^\s<>"]+\/verify-email-callback\?token=[^\s<>"]+)/i
    )
  }
  if (!linkMatch) {
    const tokenMatch = decodedBody.match(/token=([a-zA-Z0-9_-]+)/i)
    if (tokenMatch) {
      const baseUrl = process.env.BASE_URL || 'http://localhost:5173'
      linkMatch = [`${baseUrl}/verify-email-callback?token=${tokenMatch[1]}`]
    }
  }

  if (!linkMatch) {
    throw new Error('Could not extract verification link from email')
  }
  const verificationLink = linkMatch[1] || linkMatch[0]

  await page.goto(verificationLink)

  await expect(page.locator(selectors.verifyEmail.successState)).toBeVisible({ timeout: 10_000 })
  await expect(page.locator(selectors.verifyEmail.successState)).toContainText(/email.*verified/i)

  await page.locator(selectors.verifyEmail.goToLoginLink).click()
  await expect(page).toHaveURL(/\/login/)

  await page.locator(selectors.login.email).fill(testEmail)
  await page.locator(selectors.login.password).fill(testPassword)
  await page.locator(selectors.login.submit).click()

  await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
  await expect(page.locator(selectors.chat.textInput)).toBeEnabled()

  await deleteUser(request, testEmail)
})
