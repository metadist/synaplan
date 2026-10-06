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
    files: /Bibliothek/,
    memories: /Erinnerungen/,
    assistants: /Assistenten/,
    widgets: /Chat-Widgets/,
    settings: /Einstellungen/,
    admin: /Betrieb/,
  },
  fr: {
    login: /Connexion/,
    files: /Bibliothèque/,
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
  // The account language replaces a device-only choice. ?lang= keeps this
  // page load in the requested locale without writing the account.
  const url = new URL(path, 'http://localhost')
  url.searchParams.set('lang', locale)
  await page.goto(`${url.pathname}${url.search}`)
}

test.describe('i18n namespace split', () => {
  test('@ci Login cold-load renders the locale before first paint', async ({ page }) => {
    await page.context().clearCookies()
    await openWithLocale(page, 'de', '/login')
    await expect(page).toHaveTitle(TITLES.de.login)
    await expect(page.locator('body')).not.toContainText('pageTitles.login')
  })

  test('@ci Language switch replaces visible Preferences copy', async ({ page }) => {
    await page.goto('/settings/appearance')
    await expect(page.locator(SET.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(SET.page)).toContainText('Language')
    await expect(page.locator(SET.page)).not.toContainText('bundle.title')

    await page.locator(SET.btnLanguage('de')).click()
    await expect
      .poll(() => page.evaluate(() => localStorage.getItem('language')), {
        timeout: TIMEOUTS.SHORT,
      })
      .toBe('de')
    await expect(page.locator(SET.page)).toContainText('Sprache & Darstellung')
    await expect(page.locator(SET.page)).not.toContainText('settings.title')
    await expect(page.locator(SET.page)).not.toContainText('bundle.title')

    // This click is saved on the shared account. Put English back so later
    // tests are not switched away from the locale they set up.
    await page.locator(SET.btnLanguage('en')).click()
    await expect
      .poll(() => page.evaluate(() => localStorage.getItem('language')), {
        timeout: TIMEOUTS.SHORT,
      })
      .toBe('en')
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
