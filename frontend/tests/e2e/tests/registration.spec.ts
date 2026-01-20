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
  const decodeQuotedPrintable = (input: string) => {
    const withoutSoftBreaks = input.replace(/=\r?\n/g, '')
    return withoutSoftBreaks.replace(/=([0-9A-Fa-f]{2})/g, (_, hex: string) =>
      String.fromCharCode(parseInt(hex, 16))
    )
  }
  const acceptCookiesIfShown = async () => {
    const button = page.locator('[data-testid="btn-cookie-accept"]')
    try {
      await button.waitFor({ state: 'visible', timeout: 2000 })
      await button.click()
    } catch {
      // Cookie banner not shown in this run
    }
  }
  // Email content can be HTML, plain text, or token-only, so try each format.
  const extractVerificationLink = (decodedBody: string) => {
    const linkFromHref = decodedBody.match(
      /href=["']([^"']*\/verify-email-callback\?token=[^"']*)["']/i
    )?.[1]
    if (linkFromHref) {
      return linkFromHref
    }

    const linkFromUrl = decodedBody.match(
      /(https?:\/\/[^\s<>"]+\/verify-email-callback\?token=[^\s<>"]+)/i
    )?.[1]
    if (linkFromUrl) {
      return linkFromUrl
    }

    const token = decodedBody.match(/token=([a-zA-Z0-9_-]+)/i)?.[1]
    if (token) {
      return `/verify-email-callback?token=${token}`
    }

    return null
  }

  try {
    let verificationEmail: any = null
    await test.step('Clear MailHog inbox', async () => {
      const clearResponse = await request.delete(`${mailhogUrl}/api/v1/messages`)
      expect(clearResponse.ok()).toBeTruthy()
    })

    await test.step('Open registration page', async () => {
      await page.goto('/login')
      await page.locator(selectors.login.email).waitFor({ state: 'visible', timeout: 10_000 })
      await acceptCookiesIfShown()

      await page.locator(selectors.login.signUpLink).click()
      await expect(page).toHaveURL(/\/register/)
      await page.locator(selectors.register.fullName).waitFor({ state: 'visible', timeout: 10_000 })
    })

    await test.step('Submit registration form', async () => {
      await page.locator(selectors.register.fullName).fill('test')
      await page.locator(selectors.register.email).fill(testEmail)
      await page.locator(selectors.register.password).fill(testPassword)
      await page.locator(selectors.register.confirmPassword).fill(testPassword)
      await page.locator(selectors.register.submit).click()
    })

    await test.step('See registration success', async () => {
      await page
        .locator(selectors.register.successSection)
        .waitFor({ state: 'visible', timeout: 10_000 })
      const successText = (await page.locator(selectors.register.successSection).innerText())
        .trim()
        .toLowerCase()
      await expect(successText).toContain('registration')
      await expect(successText).toContain('success')
    })

    await test.step('Return to login screen', async () => {
      await page.locator(selectors.register.backToLoginBtn).click()
      await expect(page).toHaveURL(/\/login/)
    })

    await test.step('Wait for verification email', async () => {
      await expect
        .poll(
          async () => {
            const mailhogResponse = await request.get(`${mailhogUrl}/api/v2/messages`)
            if (!mailhogResponse.ok()) {
              return null
            }

            const messages = await mailhogResponse.json()
            const items = Array.isArray(messages.items) ? messages.items : []
            verificationEmail = items.find((msg: any) => {
              const toHeader = msg.Content?.Headers?.To || []
              const toList = Array.isArray(toHeader) ? toHeader : [toHeader]
              const toMatches = toList.some((to: string) => to.includes(testEmail))

              const body = msg.Content?.Body || ''
              const partBodies = Array.isArray(msg.Content?.Parts)
                ? msg.Content.Parts.map((part: any) => part.Body || '').join(' ')
                : ''
              const contentLower = `${body} ${partBodies}`.toLowerCase()
              const hasVerificationLink = contentLower.includes('verify-email-callback')

              return toMatches && hasVerificationLink
            })

            return verificationEmail ?? null
          },
          { timeout: 60_000, intervals: [500] }
        )
        .not.toBeNull()
    })

    await test.step('Open verification link', async () => {
      if (!verificationEmail) {
        throw new Error('Verification email not found')
      }

      const emailBody = verificationEmail.Content?.Body || ''
      const emailParts = Array.isArray(verificationEmail.Content?.Parts)
        ? verificationEmail.Content.Parts.map((part: any) => part.Body).filter(Boolean)
        : []
      const emailHtml = emailParts.join('\n')
      const fullBody = emailHtml || emailBody
      const decodedBody = decodeQuotedPrintable(fullBody)

      const verificationLink = extractVerificationLink(decodedBody)
      if (!verificationLink) {
        throw new Error('Could not extract verification link from email')
      }

      let verificationPath = verificationLink
      if (verificationPath.startsWith('http://') || verificationPath.startsWith('https://')) {
        const url = new URL(verificationPath)
        verificationPath = url.pathname + url.search
      }
      if (!verificationPath.startsWith('/')) {
        verificationPath = '/' + verificationPath
      }
      const normalizedVerificationLink = new URL(verificationPath, baseUrl).toString()

      await page.goto(normalizedVerificationLink)
      await page
        .locator(selectors.verifyEmail.successState)
        .waitFor({ state: 'visible', timeout: 10_000 })
      const verifiedText = (await page.locator(selectors.verifyEmail.successState).innerText())
        .trim()
        .toLowerCase()
      await expect(verifiedText).toContain('email')
      await expect(verifiedText).toContain('verified')
    })

    await test.step('Login with verified user', async () => {
      await page.locator(selectors.verifyEmail.goToLoginLink).click()
      await expect(page).toHaveURL(/\/login/)

      await page.locator(selectors.login.email).fill(testEmail)
      await page.locator(selectors.login.password).fill(testPassword)
      await page.locator(selectors.login.submit).click()

      await page.locator(selectors.nav.sidebar).waitFor({ state: 'visible', timeout: 10_000 })
      await page.locator(selectors.userMenu.button).waitFor({ state: 'visible', timeout: 10_000 })
      await page.locator(selectors.chat.textInput).waitFor({ state: 'visible', timeout: 10_000 })
      await expect(page.locator(selectors.chat.textInput)).toBeEnabled()
    })
  } finally {
    await deleteUser(request, testEmail)
  }
})
