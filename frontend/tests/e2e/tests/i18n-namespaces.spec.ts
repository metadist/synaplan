import { test, expect, type Page } from '../test-setup'
import { login, openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

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
    await openApp(page)
    await page.goto('/settings')
    await expect(page.locator(SET.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(SET.page)).toContainText('Preferences')

    await page.locator(SET.btnLanguage('de')).click()
    await expect
      .poll(() => page.evaluate(() => localStorage.getItem('language')), {
        timeout: TIMEOUTS.SHORT,
      })
      .toBe('de')
    await expect(page.locator(SET.page)).toContainText('Einstellungen')
    await expect(page.locator(SET.page)).not.toContainText('settings.title')
  })

  test('@ci Cold deep-links render complete copy in German', async ({ page }) => {
    await openApp(page)
    const checks: Array<['de', string, RegExp]> = [
      ['de', '/files', TITLES.de.files],
      ['de', '/memories', TITLES.de.memories],
      ['de', '/ai/assistants', TITLES.de.assistants],
      ['de', '/channels/widgets', TITLES.de.widgets],
      ['de', '/settings', TITLES.de.settings],
    ]
    for (const [, path, title] of checks) {
      await openWithLocale(page, 'de', path)
      await expect(page).toHaveTitle(title)
      await expect(page.locator('body')).not.toContainText('pageTitles.')
    }
  })

  test('@ci Cold deep-link into Operate uses the admin namespace', async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())
    await openWithLocale(page, 'fr', '/admin')
    await expect(page).toHaveTitle(TITLES.fr.admin)
    await expect(page.locator('body')).not.toContainText('pageTitles.admin')
  })
})
