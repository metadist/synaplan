import { request as playwrightRequest } from '@playwright/test'
import { test, expect, type Page } from '../test-setup'
import { loginViaApi } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, URLS } from '../config/config'

const SET = selectors.settings

const TITLES = {
  de: {
    login: /Anmelden/,
    files: /Quellen/,
    memories: /Erinnerungen/,
    assistants: /Assistenten/,
    widgets: /Chat-Widgets/,
    settings: /Einstellungen/,
    admin: /Betrieb/,
  },
  fr: {
    login: /Connexion/,
    files: /Sources/,
    memories: /Mémoires/,
    assistants: /Assistants/,
    widgets: /Widgets de chat/,
    settings: /Préférences/,
    admin: /Exploitation/,
  },
} as const

async function openWithLocale(page: Page, locale: 'de' | 'fr', path: string): Promise<void> {
  await page.addInitScript((lang) => {
    localStorage.setItem('language', lang)
  }, locale)
  await page.goto(path)
}

test.describe('i18n namespace split', () => {
  test('@ci Login cold-load renders the locale before first paint', async ({ page }) => {
    await page.context().clearCookies()
    await openWithLocale(page, 'de', '/login')
    await expect(page).toHaveTitle(TITLES.de.login)
    await expect(page.locator('body')).not.toContainText('pageTitles.login')
  })

  test('@ci Language switch replaces visible Preferences copy', async ({ page }) => {
    await page.goto('/settings')
    await expect(page.locator(SET.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(SET.page)).toContainText('Preferences')
    await expect(page.locator(SET.page)).toContainText('Export & import')
    await expect(page.locator(SET.page)).not.toContainText('bundle.title')

    await page.locator(SET.btnLanguage('de')).click()
    await expect
      .poll(() => page.evaluate(() => localStorage.getItem('language')), {
        timeout: TIMEOUTS.SHORT,
      })
      .toBe('de')
    await expect(page.locator(SET.page)).toContainText('Einstellungen')
    await expect(page.locator(SET.page)).toContainText('Exportieren & importieren')
    await expect(page.locator(SET.page)).not.toContainText('settings.title')
    await expect(page.locator(SET.page)).not.toContainText('bundle.title')
  })

  test('@ci Cold deep-links render complete copy in German', async ({ page }) => {
    const checks: Array<['de', string, RegExp]> = [
      ['de', '/files', TITLES.de.files],
      ['de', '/memories', TITLES.de.memories],
      ['de', '/ai/assistants', TITLES.de.assistants],
      ['de', '/channels/widgets', TITLES.de.widgets],
      ['de', '/settings', TITLES.de.settings],
    ]
    for (const [, path, title] of checks) {
      await openWithLocale(page, 'de', path)
      // goto resolves on the document load event, while the title is set in
      // router.afterEach once the locale namespaces have loaded.
      await expect(page).toHaveTitle(title, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('body')).not.toContainText('pageTitles.')
    }
  })

  test('@ci Cold deep-link into Operate uses the admin namespace', async ({ page }) => {
    // Admin-only route. Use API login so this spec does not depend on the
    // chat composer mounting (UI login waits for input-chat-message).
    const ctx = await playwrightRequest.newContext({ baseURL: URLS.BASE_URL })
    try {
      await loginViaApi(ctx, CREDENTIALS.getAdminCredentials())
      const state = await ctx.storageState()
      await page.context().clearCookies()
      await page.context().addCookies(state.cookies)
    } finally {
      await ctx.dispose()
    }
    await openWithLocale(page, 'fr', '/admin')
    await expect(page).toHaveTitle(TITLES.fr.admin)
    await expect(page.locator('body')).not.toContainText('pageTitles.admin')
  })
})
