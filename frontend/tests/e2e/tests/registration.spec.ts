import { test, expect } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { deleteUser } from '../helpers/auth'

test('@ci @auth @smoke registration flow with email verification id=006', async ({
  page,
  request,
}) => {
  const uniqueSuffix = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
  const testEmail = `test+${uniqueSuffix}@test.com`
  const testPassword = 'Test1234'
  const mailhogUrl = process.env.MAILHOG_URL || 'http://localhost:8025'
  const baseUrl = process.env.BASE_URL || 'http://localhost:5173'
  const testStartTime = Date.now()
  const decodeQuotedPrintable = (input: string) =>
    input
      .replace(/=\r?\n/g, '')
      .replace(/=([0-9A-Fa-f]{2})/g, (_, hex: string) => String.fromCharCode(parseInt(hex, 16)))

  try {
    await page.goto('/login')
    await expect(page.locator(selectors.login.email)).toBeVisible({ timeout: 10_000 })
    const cookieButton = page.locator('[data-testid="btn-cookie-accept"]')
    if (await cookieButton.count()) {
      await cookieButton.click()
    }

    await page.locator(selectors.login.signUpLink).click()
    await expect(page).toHaveURL(/\/register/)
    await expect(page.locator(selectors.register.fullName)).toBeVisible({ timeout: 10_000 })

    await page.locator(selectors.register.fullName).fill('test')
    await page.locator(selectors.register.email).fill(testEmail)
    await page.locator(selectors.register.password).fill(testPassword)
    await page.locator(selectors.register.confirmPassword).fill(testPassword)

    await page.locator(selectors.register.submit).click()

    await expect(page.locator(selectors.register.successSection)).toBeVisible({ timeout: 10_000 })
    await expect(page.locator(selectors.register.successSection)).toContainText(
      /registration.*success/i
    )

    await page.locator(selectors.register.backToLoginBtn).click()
    await expect(page).toHaveURL(/\/login/)

    let verificationEmail: any = null
    await expect
      .poll(
        async () => {
          const mailhogResponse = await request.get(`${mailhogUrl}/api/v2/messages`)
          if (!mailhogResponse.ok()) {
            throw new Error(`MailHog API returned ${mailhogResponse.status()}`)
          }

          const messages = await mailhogResponse.json()
          verificationEmail = messages.items?.find((msg: any) => {
            const toHeader = msg.Content?.Headers?.To || []
            const toList = Array.isArray(toHeader) ? toHeader : [toHeader]
            const toMatches = toList.some((to: string) => to.includes(testEmail))

            const subjectHeader = msg.Content?.Headers?.Subject || []
            const subjectList = Array.isArray(subjectHeader) ? subjectHeader : [subjectHeader]
            const subjectMatches = subjectList.some((subj: string) =>
              subj.toLowerCase().includes('verif')
            )

            const body = msg.Content?.Body || ''
            const partBodies = Array.isArray(msg.Content?.Parts)
              ? msg.Content.Parts.map((part: any) => part.Body || '').join(' ')
              : ''
            const contentLower = `${body} ${partBodies}`.toLowerCase()
            const contentMatches =
              contentLower.includes('verify') || contentLower.includes('verification')

            const createdValue =
              msg.Created ?? msg.created ?? msg.CreatedAt ?? msg.createdAt ?? msg.CreatedUTC
            let createdMs =
              typeof createdValue === 'number' ? createdValue : Date.parse(createdValue)
            if (typeof createdValue === 'number' && createdValue < 1_000_000_000_000) {
              createdMs = createdValue * 1000
            }
            const isRecent = Number.isFinite(createdMs) ? createdMs >= testStartTime - 1000 : true

            return toMatches && isRecent && (subjectMatches || contentMatches)
          })

          return verificationEmail ?? null
        },
        { timeout: 60_000, intervals: [500] }
      )
      .not.toBeNull()

    const emailBody = verificationEmail.Content.Body || ''
    const emailParts = Array.isArray(verificationEmail.Content?.Parts)
      ? verificationEmail.Content.Parts.map((part: any) => part.Body).filter(Boolean)
      : []
    const emailHtml = emailParts.join('\n')
    const fullBody = emailHtml || emailBody
    const decodedBody = decodeQuotedPrintable(fullBody)

    let linkMatch = decodedBody.match(/href=["']([^"']*\/verify-email-callback\?token=[^"']*)["']/i)
    if (!linkMatch) {
      linkMatch = decodedBody.match(
        /(https?:\/\/[^\s<>"]+\/verify-email-callback\?token=[^\s<>"]+)/i
      )
    }
    if (!linkMatch) {
      const tokenMatch = decodedBody.match(/token=([a-zA-Z0-9_-]+)/i)
      if (tokenMatch) {
        linkMatch = [`/verify-email-callback?token=${tokenMatch[1]}`]
      }
    }

    if (!linkMatch) {
      throw new Error('Could not extract verification link from email')
    }
    let verificationLink = linkMatch[1] || linkMatch[0]

    // Normalize the link to use baseUrl (replace any absolute URL with baseUrl)
    // This handles cases where the backend uses a different FRONTEND_URL than the test expects
    if (verificationLink.startsWith('http://') || verificationLink.startsWith('https://')) {
      // Extract the path and query from the absolute URL
      const url = new URL(verificationLink)
      verificationLink = url.pathname + url.search
    }
    // Ensure it starts with /
    if (!verificationLink.startsWith('/')) {
      verificationLink = '/' + verificationLink
    }
    const normalizedVerificationLink = new URL(verificationLink, baseUrl).toString()

    await page.goto(normalizedVerificationLink)

    await expect(page.locator(selectors.verifyEmail.successState)).toBeVisible({ timeout: 10_000 })
    await expect(page.locator(selectors.verifyEmail.successState)).toContainText(/email.*verified/i)

    await page.locator(selectors.verifyEmail.goToLoginLink).click()
    await expect(page).toHaveURL(/\/login/)

    await page.locator(selectors.login.email).fill(testEmail)
    await page.locator(selectors.login.password).fill(testPassword)
    await page.locator(selectors.login.submit).click()

    await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
    await expect(page.locator(selectors.chat.textInput)).toBeEnabled()
  } finally {
    await deleteUser(request, testEmail)
  }
})
