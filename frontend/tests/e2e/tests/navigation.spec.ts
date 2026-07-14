import { test, expect, type Page } from '../test-setup'
import { login, openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

const NAV = selectors.nav
const SET = selectors.settings
const USR = selectors.userMenu

/**
 * "Easy Mode" was removed in Release 4.0 — the full (advanced) navigation is
 * now the only mode. This just waits until the sidebar's advanced-only rail
 * items are present, so tests that rely on Channels/AI Setup are stable.
 */
async function ensureNavReady(page: Page) {
  await expect(page.locator(NAV.sidebarV2Channels)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

/** Avatar menu → Preferences → /settings page. */
async function openPreferences(page: Page) {
  await page.locator(USR.button).click()
  await expect(page.locator(USR.dropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await page.locator(USR.dropdown).locator(USR.preferencesBtn).click()
  await expect(page.locator(SET.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

/** Open a rail flyout (Channels / AI Setup / Admin) and wait for it. */
async function openFlyout(page: Page, railItemSelector: string) {
  await page.locator(railItemSelector).click()
  const flyout = page.locator(NAV.navDropdown)
  await expect(flyout).toBeVisible({ timeout: TIMEOUTS.SHORT })
  return flyout
}

test.describe('Navigation: Sidebar basics (non-admin)', () => {
  test('@ci Sidebar shows all primary rail items (Channels + AI Setup always present)', async ({
    page,
  }) => {
    await test.step('Arrange: login', async () => {
      await openApp(page)
    })

    await test.step('Assert: primary + advanced items all visible (no Easy Mode)', async () => {
      await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2ChatNav)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2Files)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2Channels)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2AiSetup)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })
  })

  test('@ci Files button navigates to files page', async ({ page }) => {
    await test.step('Arrange: login', async () => {
      await openApp(page)
    })

    await test.step('Act: click Files nav button', async () => {
      await page.locator(NAV.sidebarV2Files).click()
    })

    await test.step('Assert: Files page visible', async () => {
      await expect(page.locator(selectors.files.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })
  })

  test('@ci History button opens chat manager modal', async ({ page }) => {
    await test.step('Arrange: login', async () => {
      await openApp(page)
    })

    await test.step('Act: click History nav button', async () => {
      await page.locator(NAV.sidebarV2ChatNav).click()
    })

    await test.step('Assert: chat manager modal visible', async () => {
      await expect(page.locator(NAV.modalChatManager)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })
  })

  test('@ci New Chat button is visible and enabled', async ({ page }) => {
    await test.step('Arrange: login', async () => {
      await openApp(page)
    })

    await test.step('Assert: new chat button visible and enabled', async () => {
      await expect(page.locator(NAV.sidebarV2NewChat)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2NewChat)).toBeEnabled()
    })
  })

  // Rail-label visibility (§4.1 #3) is covered by the layout guard
  // ("primary nav controls carry visible labels and meet target size" in
  // layout.spec.ts), which runs the same loop on desktop AND mobile and
  // additionally asserts tap-target size.
})

test.describe('Navigation: Rail flyouts (non-admin)', () => {
  test('@ci Channels flyout opens with child links', async ({ page }) => {
    await test.step('Arrange: login and wait for nav', async () => {
      await openApp(page)
      await ensureNavReady(page)
    })

    await test.step('Act+Assert: Channels flyout shows its links', async () => {
      const flyout = await openFlyout(page, NAV.sidebarV2Channels)
      await expect(flyout.locator(NAV.flyoutLinkInbound)).toBeVisible()
      await expect(flyout.locator(NAV.flyoutLinkChatWidget)).toBeVisible()
      await expect(flyout.locator(NAV.flyoutLinkMailHandler)).toBeVisible()
      await expect(flyout.locator(NAV.flyoutLinkApiDocs)).toBeVisible()
    })
  })

  test('@ci AI Setup flyout opens with child links', async ({ page }) => {
    await test.step('Arrange: login and ensure advanced mode', async () => {
      await openApp(page)
      await ensureNavReady(page)
    })

    await test.step('Act+Assert: AI Setup flyout shows its links', async () => {
      const flyout = await openFlyout(page, NAV.sidebarV2AiSetup)
      await expect(flyout.locator(NAV.flyoutLinkAiModels)).toBeVisible()
      await expect(flyout.locator(NAV.flyoutLinkTaskPrompts)).toBeVisible()
    })
  })

  test('@ci Channels flyout navigates to Chat Widget page', async ({ page }) => {
    await test.step('Arrange: login, ensure advanced, open flyout', async () => {
      await openApp(page)
      await ensureNavReady(page)
      await openFlyout(page, NAV.sidebarV2Channels)
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

  test('@ci AI Setup flyout navigates to AI Models page', async ({ page }) => {
    await test.step('Arrange: login, ensure advanced, open flyout', async () => {
      await openApp(page)
      await ensureNavReady(page)
      await openFlyout(page, NAV.sidebarV2AiSetup)
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
      const flyout = await openFlyout(page, NAV.sidebarV2Admin)
      await flyout.locator(NAV.flyoutLinkAdminDashboard).click()
    })

    await test.step('Assert: Admin dashboard page visible', async () => {
      await expect(page.locator(selectors.pages.admin)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })

  test('@ci Non-admin does not see Admin button', async ({ page }) => {
    await test.step('Arrange: login as non-admin', async () => {
      await openApp(page)
    })

    await test.step('Assert: sidebar visible but Admin button absent', async () => {
      await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(NAV.sidebarV2Admin)).not.toBeVisible()
    })
  })

  test('@ci Non-admin is redirected away from admin page', async ({ page }) => {
    await test.step('Arrange: login as non-admin', async () => {
      await openApp(page)
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
  test('@ci User menu shows Profile, Statistics, Preferences and Logout', async ({ page }) => {
    await test.step('Arrange: login', async () => {
      await openApp(page)
    })

    await test.step('Act: open user menu', async () => {
      await page.locator(USR.button).click()
    })

    await test.step('Assert: dropdown visible with menu items', async () => {
      const dropdown = page.locator(USR.dropdown)
      await expect(dropdown).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(dropdown.locator(USR.profileBtn)).toBeVisible()
      await expect(dropdown.locator(USR.statisticsBtn)).toBeVisible()
      await expect(dropdown.locator(USR.preferencesBtn)).toBeVisible()
      await expect(dropdown.locator(USR.logoutBtn)).toBeVisible()
    })
  })

  test('@ci User menu navigates to Profile page', async ({ page }) => {
    await test.step('Arrange: login and open user menu', async () => {
      await openApp(page)
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

  test('@ci User menu navigates to Statistics page', async ({ page }) => {
    await test.step('Arrange: login and open user menu', async () => {
      await openApp(page)
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

test.describe('Navigation: Preferences page controls', () => {
  test('@ci Language switch (Preferences) changes active language', async ({ page }) => {
    await test.step('Arrange: login and open Preferences', async () => {
      await openApp(page)
      await openPreferences(page)
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
