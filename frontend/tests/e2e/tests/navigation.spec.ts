import { test, expect, type Page } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

const NAV = selectors.nav
const SET = selectors.settings
const USR = selectors.userMenu

/**
 * Mode is persisted in localStorage (`app_mode`) by the appMode store.
 * Setting it directly + reloading is the fastest, most robust way to
 * arrange the desired mode for tests that don't specifically exercise
 * the Settings UI itself (those tests live below and click through
 * the Settings page).
 */
async function ensureEasyMode(page: Page) {
  await page.evaluate(() => localStorage.setItem('app_mode', 'easy'))
  await page.reload()
  await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

async function ensureAdvancedMode(page: Page) {
  await page.evaluate(() => localStorage.setItem('app_mode', 'advanced'))
  await page.reload()
  await expect(page.locator(NAV.sidebarV2Settings)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

async function openSettings(page: Page) {
  await page.locator(USR.button).click()
  await expect(page.locator(USR.dropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await page.locator(USR.dropdown).locator(USR.settingsBtn).click()
  await expect(page.locator(SET.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

test.describe('Navigation: Sidebar basics (non-admin, easy mode)', () => {
  test('@ci Easy mode hides Settings and shows Chat and Files', async ({ page, credentials }) => {
    await test.step('Arrange: login and switch to easy mode', async () => {
      await login(page, credentials)
      await ensureEasyMode(page)
    })

    await test.step('Assert: sidebar with Chat and Files visible', async () => {
      await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2ChatNav)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2Files)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })

    await test.step('Assert: Settings button not visible in easy mode', async () => {
      await expect(page.locator(NAV.sidebarV2Settings)).not.toBeVisible()
    })
  })

  test('@ci Files button navigates to files page', async ({ page, credentials }) => {
    await test.step('Arrange: login', async () => {
      await login(page, credentials)
    })

    await test.step('Act: click Files nav button', async () => {
      await page.locator(NAV.sidebarV2Files).click()
    })

    await test.step('Assert: Files page visible', async () => {
      await expect(page.locator(selectors.files.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })
  })

  test('@ci Chat button opens chat manager modal', async ({ page, credentials }) => {
    await test.step('Arrange: login', async () => {
      await login(page, credentials)
    })

    await test.step('Act: click Chat nav button', async () => {
      await page.locator(NAV.sidebarV2ChatNav).click()
    })

    await test.step('Assert: chat manager modal visible', async () => {
      await expect(page.locator(NAV.modalChatManager)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })
  })

  test('@ci New Chat button is visible and enabled', async ({ page, credentials }) => {
    await test.step('Arrange: login', async () => {
      await login(page, credentials)
    })

    await test.step('Assert: new chat button visible and enabled', async () => {
      await expect(page.locator(NAV.sidebarV2NewChat)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2NewChat)).toBeEnabled()
    })
  })
})

test.describe('Navigation: Advanced mode (non-admin)', () => {
  test('@ci Mode toggle (Settings) switches between easy and advanced', async ({
    page,
    credentials,
  }) => {
    await test.step('Arrange: login and switch to easy mode', async () => {
      await login(page, credentials)
      await ensureEasyMode(page)
    })

    await test.step('Act: open Settings and pick Advanced', async () => {
      await openSettings(page)
      await page.locator(SET.btnModeAdvanced).click()
    })

    await test.step('Assert: Settings nav becomes visible after going back', async () => {
      await page.goto('/')
      await expect(page.locator(NAV.sidebarV2Settings)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })
  })

  test('@ci Settings flyout opens with child links', async ({ page, credentials }) => {
    await test.step('Arrange: login and ensure advanced mode', async () => {
      await login(page, credentials)
      await ensureAdvancedMode(page)
    })

    await test.step('Act: click Settings', async () => {
      await page.locator(NAV.sidebarV2Settings).click()
    })

    await test.step('Assert: flyout visible with navigation links', async () => {
      const flyout = page.locator(NAV.navDropdown)
      await expect(flyout).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(flyout.locator(NAV.flyoutLinkChatWidget)).toBeVisible()
      await expect(flyout.locator(NAV.flyoutLinkAiModels)).toBeVisible()
      await expect(flyout.locator(NAV.flyoutLinkTaskPrompts)).toBeVisible()
    })
  })

  test('@ci Settings flyout navigates to Chat Widget page', async ({ page, credentials }) => {
    await test.step('Arrange: login, ensure advanced, open flyout', async () => {
      await login(page, credentials)
      await ensureAdvancedMode(page)
      await page.locator(NAV.sidebarV2Settings).click()
      await expect(page.locator(NAV.navDropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })

    await test.step('Act: click Chat Widget link', async () => {
      await page.locator(NAV.navDropdown).locator(NAV.flyoutLinkChatWidget).click()
    })

    await test.step('Assert: Widgets page visible', async () => {
      await expect(page.locator(selectors.widgets.page)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })

  test('@ci Settings flyout navigates to AI Models page', async ({ page, credentials }) => {
    await test.step('Arrange: login, ensure advanced, open flyout', async () => {
      await login(page, credentials)
      await ensureAdvancedMode(page)
      await page.locator(NAV.sidebarV2Settings).click()
      await expect(page.locator(NAV.navDropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })

    await test.step('Act: click AI Models link', async () => {
      await page.locator(NAV.navDropdown).locator(NAV.flyoutLinkAiModels).click()
    })

    await test.step('Assert: AI Models page visible', async () => {
      await expect(page.locator(selectors.models.page)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})

test.describe('Navigation: Admin sidebar', () => {
  test('@ci Admin sees Admin button in sidebar', async ({ page }) => {
    await test.step('Arrange: login as admin', async () => {
      await login(page, CREDENTIALS.getAdminCredentials())
    })

    await test.step('Assert: Admin nav button visible', async () => {
      await expect(page.locator(NAV.sidebarV2Admin)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })
  })

  test('@ci Admin flyout navigates to admin dashboard', async ({ page }) => {
    await test.step('Arrange: login as admin', async () => {
      await login(page, CREDENTIALS.getAdminCredentials())
    })

    await test.step('Act: open Admin flyout and click Dashboard', async () => {
      await page.locator(NAV.sidebarV2Admin).click()
      const flyout = page.locator(NAV.navDropdown)
      await expect(flyout).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await flyout.locator(NAV.flyoutLinkAdminDashboard).click()
    })

    await test.step('Assert: Admin dashboard page visible', async () => {
      await expect(page.locator(selectors.pages.admin)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })

  test('@ci Non-admin does not see Admin button', async ({ page, credentials }) => {
    await test.step('Arrange: login as non-admin', async () => {
      await login(page, credentials)
    })

    await test.step('Assert: sidebar visible but Admin button absent', async () => {
      await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(NAV.sidebarV2Admin)).not.toBeVisible()
    })
  })

  test('@ci Non-admin is redirected away from admin page', async ({ page, credentials }) => {
    await test.step('Arrange: login as non-admin', async () => {
      await login(page, credentials)
    })

    await test.step('Act: navigate directly to /admin', async () => {
      await page.goto('/admin')
    })

    await test.step('Assert: redirected away from admin and chat page visible', async () => {
      await expect(page).not.toHaveURL(/\/admin/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})

test.describe('Navigation: User menu', () => {
  test('@ci User menu shows Profile, Statistics and Logout', async ({ page, credentials }) => {
    await test.step('Arrange: login', async () => {
      await login(page, credentials)
    })

    await test.step('Act: open user menu', async () => {
      await page.locator(USR.button).click()
    })

    await test.step('Assert: dropdown visible with menu items', async () => {
      const dropdown = page.locator(USR.dropdown)
      await expect(dropdown).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(dropdown.locator(USR.profileBtn)).toBeVisible()
      await expect(dropdown.locator(USR.statisticsBtn)).toBeVisible()
      await expect(dropdown.locator(USR.logoutBtn)).toBeVisible()
    })
  })

  test('@ci User menu navigates to Profile page', async ({ page, credentials }) => {
    await test.step('Arrange: login and open user menu', async () => {
      await login(page, credentials)
      await page.locator(USR.button).click()
      await expect(page.locator(USR.dropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })

    await test.step('Act: click Profile', async () => {
      await page.locator(USR.dropdown).locator(USR.profileBtn).click()
    })

    await test.step('Assert: Profile page visible', async () => {
      await expect(page.locator(selectors.pages.profile)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })

  test('@ci User menu navigates to Statistics page', async ({ page, credentials }) => {
    await test.step('Arrange: login and open user menu', async () => {
      await login(page, credentials)
      await page.locator(USR.button).click()
      await expect(page.locator(USR.dropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })

    await test.step('Act: click Statistics', async () => {
      await page.locator(USR.dropdown).locator(USR.statisticsBtn).click()
    })

    await test.step('Assert: Statistics page visible', async () => {
      await expect(page.locator(selectors.pages.statistics)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})

test.describe('Navigation: Settings page controls', () => {
  test('@ci Language switch (Settings) changes active language', async ({ page, credentials }) => {
    await test.step('Arrange: login and open Settings', async () => {
      await login(page, credentials)
      await openSettings(page)
    })

    const initialLang =
      (await page.evaluate(() => localStorage.getItem('language'))) ??
      (await page.evaluate(() => document.documentElement.lang)) ??
      'en'
    const targetLang = initialLang === 'de' ? 'en' : 'de'

    await test.step('Act: pick a different language card', async () => {
      await page.locator(SET.btnLanguage(targetLang)).click()
    })

    await test.step('Assert: localStorage reflects the new language', async () => {
      await expect
        .poll(() => page.evaluate(() => localStorage.getItem('language')), {
          timeout: TIMEOUTS.SHORT,
        })
        .toBe(targetLang)
    })
  })
})
