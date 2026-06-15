import { test, expect, type Page } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

const NAV = selectors.nav
const SET = selectors.settings
const USR = selectors.userMenu
const DLG = selectors.dialog

/**
 * Mode is persisted in localStorage (`app_mode`) by the appMode store.
 * Setting it directly + reloading is the fastest, most robust way to
 * arrange the desired mode for tests that don't specifically exercise
 * the Preferences UI itself (those tests live below and click through
 * the Preferences page).
 */
async function ensureEasyMode(page: Page) {
  await page.evaluate(() => localStorage.setItem('app_mode', 'easy'))
  await page.reload()
  await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

async function ensureAdvancedMode(page: Page) {
  await page.evaluate(() => localStorage.setItem('app_mode', 'advanced'))
  await page.reload()
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

test.describe('Navigation: Sidebar basics (non-admin, easy mode)', () => {
  test('@ci Easy mode shows Channels and AI Setup locked (Q6), Files and History stay usable', async ({
    page,
    credentials,
  }) => {
    await test.step('Arrange: login and switch to easy mode', async () => {
      await login(page, credentials)
      await ensureEasyMode(page)
    })

    await test.step('Assert: primary items visible', async () => {
      await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2ChatNav)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2Files)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })

    await test.step('Assert: Channels + AI Setup are visible, not hidden (Q6)', async () => {
      await expect(page.locator(NAV.sidebarV2Channels)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2AiSetup)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })

    await test.step('Act+Assert: tapping a locked item offers the Advanced-mode switch', async () => {
      await page.locator(NAV.sidebarV2Channels).click()
      await expect(page.locator(DLG.confirmBtn)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      // Dismiss: stays in easy mode, no flyout opens
      await page.locator(DLG.cancelBtn).click()
      await expect(page.locator(NAV.navDropdown)).not.toBeVisible()
      expect(await page.evaluate(() => localStorage.getItem('app_mode'))).toBe('easy')
    })
  })

  test('@ci Locked Channels item switches to Advanced mode on confirm (Q6)', async ({
    page,
    credentials,
  }) => {
    await test.step('Arrange: login and switch to easy mode', async () => {
      await login(page, credentials)
      await ensureEasyMode(page)
    })

    await test.step('Act: tap Channels, confirm the switch', async () => {
      await page.locator(NAV.sidebarV2Channels).click()
      await expect(page.locator(DLG.confirmBtn)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.locator(DLG.confirmBtn).click()
    })

    await test.step('Assert: now in advanced mode and the Channels flyout opened', async () => {
      await expect(page.locator(NAV.navDropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect
        .poll(() => page.evaluate(() => localStorage.getItem('app_mode')), {
          timeout: TIMEOUTS.SHORT,
        })
        .toBe('advanced')
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

  test('@ci History button opens chat manager modal', async ({ page, credentials }) => {
    await test.step('Arrange: login', async () => {
      await login(page, credentials)
    })

    await test.step('Act: click History nav button', async () => {
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

  test('@ci Rail items carry always-visible labels (§4.1 #3)', async ({ page, credentials }) => {
    await test.step('Arrange: login', async () => {
      await login(page, credentials)
    })

    await test.step('Assert: every rail nav button shows a non-empty label node', async () => {
      const navButtons = page.locator('[data-testid^="btn-sidebar-v2-nav-"]')
      const count = await navButtons.count()
      expect(count).toBeGreaterThanOrEqual(2)
      for (let i = 0; i < count; i++) {
        const label = navButtons.nth(i).locator(NAV.railLabel)
        await expect(label).toBeVisible()
        expect((await label.textContent())?.trim()).not.toBe('')
      }
    })
  })
})

test.describe('Navigation: Advanced mode (non-admin)', () => {
  test('@ci Mode toggle (Preferences) switches between easy and advanced', async ({
    page,
    credentials,
  }) => {
    await test.step('Arrange: login and switch to easy mode', async () => {
      await login(page, credentials)
      await ensureEasyMode(page)
    })

    await test.step('Act: open Preferences and pick Advanced', async () => {
      await openPreferences(page)
      await page.locator(SET.btnModeAdvanced).click()
    })

    await test.step('Assert: app mode persisted after going back', async () => {
      await page.goto('/')
      await expect(page.locator(NAV.sidebarV2Channels)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      expect(await page.evaluate(() => localStorage.getItem('app_mode'))).toBe('advanced')
    })
  })

  test('@ci Channels flyout opens with child links', async ({ page, credentials }) => {
    await test.step('Arrange: login and ensure advanced mode', async () => {
      await login(page, credentials)
      await ensureAdvancedMode(page)
    })

    await test.step('Act+Assert: Channels flyout shows its links', async () => {
      const flyout = await openFlyout(page, NAV.sidebarV2Channels)
      await expect(flyout.locator(NAV.flyoutLinkInbound)).toBeVisible()
      await expect(flyout.locator(NAV.flyoutLinkChatWidget)).toBeVisible()
      await expect(flyout.locator(NAV.flyoutLinkMailHandler)).toBeVisible()
      await expect(flyout.locator(NAV.flyoutLinkApiDocs)).toBeVisible()
    })
  })

  test('@ci AI Setup flyout opens with child links', async ({ page, credentials }) => {
    await test.step('Arrange: login and ensure advanced mode', async () => {
      await login(page, credentials)
      await ensureAdvancedMode(page)
    })

    await test.step('Act+Assert: AI Setup flyout shows its links', async () => {
      const flyout = await openFlyout(page, NAV.sidebarV2AiSetup)
      await expect(flyout.locator(NAV.flyoutLinkAiModels)).toBeVisible()
      await expect(flyout.locator(NAV.flyoutLinkTaskPrompts)).toBeVisible()
    })
  })

  test('@ci Channels flyout navigates to Chat Widget page', async ({ page, credentials }) => {
    await test.step('Arrange: login, ensure advanced, open flyout', async () => {
      await login(page, credentials)
      await ensureAdvancedMode(page)
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

  test('@ci AI Setup flyout navigates to AI Models page', async ({ page, credentials }) => {
    await test.step('Arrange: login, ensure advanced, open flyout', async () => {
      await login(page, credentials)
      await ensureAdvancedMode(page)
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
  test('@ci User menu shows Profile, Statistics, Preferences and Logout', async ({
    page,
    credentials,
  }) => {
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
      await expect(dropdown.locator(USR.preferencesBtn)).toBeVisible()
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

test.describe('Navigation: Preferences page controls', () => {
  test('@ci Language switch (Preferences) changes active language', async ({
    page,
    credentials,
  }) => {
    await test.step('Arrange: login and open Preferences', async () => {
      await login(page, credentials)
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
