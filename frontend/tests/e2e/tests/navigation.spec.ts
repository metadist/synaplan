import { test, expect, type Page } from '../test-setup'
import { login, openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

const NAV = selectors.nav
const SET = selectors.settings
const USR = selectors.userMenu

/** Wait until the signed-in Work + Manage rail is painted. */
async function ensureNavReady(page: Page) {
  await expect(page.locator(NAV.sidebarV2Manage)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

/** Avatar menu → Preferences → /settings page. */
async function openPreferences(page: Page) {
  await page.locator(USR.button).click()
  await expect(page.locator(USR.dropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await page.locator(USR.dropdown).locator(USR.preferencesBtn).click()
  await expect(page.locator(SET.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

/** Open a rail flyout (Manage / Operate) and wait for it. */
async function openFlyout(page: Page, railItemSelector: string) {
  await page.locator(railItemSelector).click()
  const flyout = page.locator(NAV.navDropdown)
  await expect(flyout).toBeVisible({ timeout: TIMEOUTS.SHORT })
  return flyout
}

/** Open Manage, then a named group (channels, assistants, …) in the second flyout. */
async function openManageGroup(page: Page, groupKey: string) {
  await openFlyout(page, NAV.sidebarV2Manage)
  await page.locator(NAV.flyoutGroup(groupKey)).click()
  const sub = page.locator(NAV.navSubDropdown)
  await expect(sub).toBeVisible({ timeout: TIMEOUTS.SHORT })
  return sub
}

/**
 * Same rule as `isAiAccountsEnabled()`: Higgsfield counts as on when the
 * module key is missing; Anthropic BYO follows GET /messages-gateway.
 */
async function isAiAccountsNavEnabled(page: Page): Promise<boolean> {
  const runtime = (await page.request.get('/api/v1/config/runtime').then((r) => r.json())) as {
    modules?: { higgsfield?: { configured?: boolean } }
  }
  const higgsfield = runtime.modules?.higgsfield?.configured
  const higgsfieldOn = typeof higgsfield === 'boolean' ? higgsfield : true
  const gatewayRes = await page.request.get('/api/v1/messages-gateway')
  if (!gatewayRes.ok()) {
    return higgsfieldOn
  }
  const gateway = (await gatewayRes.json()) as { enabled?: boolean }
  return higgsfieldOn || gateway.enabled === true
}

test.describe('Navigation: Sidebar basics (non-admin)', () => {
  test('@ci Sidebar shows Work + Manage (no leftover Channels / AI Setup pair)', async ({
    page,
  }) => {
    await test.step('Arrange: login', async () => {
      await openApp(page)
    })

    await test.step('Assert: everyday rail is New / History / Sources / Manage', async () => {
      await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2NewChat)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2ChatNav)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2Files)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2Manage)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator('[data-testid="btn-sidebar-v2-nav-channels"]')).toHaveCount(0)
      await expect(page.locator('[data-testid="btn-sidebar-v2-nav-ai-setup"]')).toHaveCount(0)
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
  test('@ci Manage flyout opens with group entries, not a flat dump', async ({ page }) => {
    await test.step('Arrange: login and wait for nav', async () => {
      await openApp(page)
      await ensureNavReady(page)
    })

    await test.step('Act+Assert: first flyout lists groups only', async () => {
      const flyout = await openFlyout(page, NAV.sidebarV2Manage)
      await expect(flyout.locator(NAV.flyoutGroup('assistants'))).toBeVisible()
      await expect(flyout.locator(NAV.flyoutGroup('automations'))).toBeVisible()
      await expect(flyout.locator(NAV.flyoutGroup('channels'))).toBeVisible()
      await expect(flyout.locator(NAV.flyoutGroup('connections'))).toBeVisible()
      await expect(flyout.locator(NAV.flyoutGroup('developer'))).toBeVisible()
      await expect(flyout.locator(NAV.flyoutGroup('api'))).toHaveCount(0)
      await expect(flyout.locator(NAV.flyoutGroup('tools'))).toHaveCount(0)
      await expect(flyout.locator(NAV.flyoutLinkInbound)).toHaveCount(0)
      await expect(flyout.locator(NAV.flyoutLinkChatWidget)).toHaveCount(0)
    })

    await test.step('Act+Assert: Channels submenu shows inbound, widgets, live support', async () => {
      await page.locator(NAV.flyoutGroup('channels')).click()
      const sub = page.locator(NAV.navSubDropdown)
      await expect(sub).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(sub.locator(NAV.flyoutLinkInbound)).toBeVisible()
      await expect(sub.locator(NAV.flyoutLinkChatWidget)).toBeVisible()
      await expect(sub.locator(NAV.flyoutLinkLiveSupport)).toBeVisible()
    })

    await test.step('Act+Assert: Developer & devices submenu shows API docs', async () => {
      await page.locator(NAV.flyoutGroup('developer')).click()
      const sub = page.locator(NAV.navSubDropdown)
      await expect(sub.locator(NAV.flyoutLinkApiDocs)).toBeVisible()
    })

    await test.step('Act+Assert: Assistants submenu has no Summarizer page', async () => {
      await page.locator(NAV.flyoutGroup('assistants')).click()
      const sub = page.locator(NAV.navSubDropdown)
      await expect(sub.locator('[data-testid="link-sidebar-v2-doc-summary"]')).toHaveCount(0)
    })
  })

  // Connected apps is always in the Connections group (D5 ungate). Saved
  // Tasks stays behind features.savedTasks (SAVEDTASKS.ENABLED) under
  // Automations. The test stack seeds that flag ON, so both must render.
  test('@ci Saved Tasks and Connections appear in Manage when enabled', async ({ page }) => {
    await test.step('Arrange: login and wait for nav', async () => {
      await openApp(page)
      await ensureNavReady(page)
    })

    await test.step('Assert: Connections lives under the Connections group', async () => {
      const connections = await openManageGroup(page, 'connections')
      await expect(connections.locator(NAV.flyoutLinkConnections)).toBeVisible()
      await expect(connections.locator(NAV.flyoutLinkApiDocs)).toHaveCount(0)
    })

    await test.step('Assert: Saved Tasks lives under Automations and navigates', async () => {
      await page.locator(NAV.flyoutGroup('automations')).click()
      const automations = page.locator(NAV.navSubDropdown)
      await expect(automations.locator(NAV.flyoutLinkSavedTasks)).toBeVisible()
      await automations.locator(NAV.flyoutLinkSavedTasks).click()
      await expect(page).toHaveURL(/\/channels\/tasks/, { timeout: TIMEOUTS.STANDARD })
    })
  })

  test('@ci Manage flyout includes models, instructions and email handler', async ({ page }) => {
    await test.step('Arrange: login', async () => {
      await openApp(page)
      await ensureNavReady(page)
    })

    await test.step('Act+Assert: Assistants submenu shows models and instructions', async () => {
      const assistants = await openManageGroup(page, 'assistants')
      await expect(assistants.locator(NAV.flyoutLinkAiModels)).toBeVisible()
      await expect(assistants.locator(NAV.flyoutLinkTaskPrompts)).toBeVisible()
      // U11: hide Your AI accounts when Higgsfield and the gateway are both off
      // (the default CI image). Require the link only when a provider is on.
      if (await isAiAccountsNavEnabled(page)) {
        await expect(assistants.locator(NAV.flyoutLinkAiAccounts)).toBeVisible()
      } else {
        await expect(assistants.locator(NAV.flyoutLinkAiAccounts)).toHaveCount(0)
      }
    })

    await test.step('Act+Assert: Channels submenu shows email handler', async () => {
      await page.locator(NAV.flyoutGroup('channels')).click()
      await expect(
        page.locator(NAV.navSubDropdown).locator(NAV.flyoutLinkMailHandler)
      ).toBeVisible()
    })
  })

  test('@ci Manage flyout navigates to Chat Widget page', async ({ page }) => {
    await test.step('Arrange: login, open Channels submenu', async () => {
      await openApp(page)
      await ensureNavReady(page)
      await openManageGroup(page, 'channels')
    })

    await test.step('Act: click Chat Widget link', async () => {
      await page.locator(NAV.navSubDropdown).locator(NAV.flyoutLinkChatWidget).click()
    })

    await test.step('Assert: Widgets page visible', async () => {
      await expect(page.locator(selectors.widgets.page)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })

  test('@ci Manage flyout navigates to Live support', async ({ page }) => {
    await test.step('Arrange: login, open Channels submenu', async () => {
      await openApp(page)
      await ensureNavReady(page)
      await openManageGroup(page, 'channels')
    })

    await test.step('Act: click Live support', async () => {
      await page.locator(NAV.navSubDropdown).locator(NAV.flyoutLinkLiveSupport).click()
    })

    await test.step('Assert: live support URL resolves', async () => {
      await expect(page).toHaveURL(/\/channels\/widgets\/live-support/, {
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })

  test('@ci Manage flyout navigates to AI Models page', async ({ page }) => {
    await test.step('Arrange: login, open Assistants submenu', async () => {
      await openApp(page)
      await ensureNavReady(page)
      await openManageGroup(page, 'assistants')
    })

    await test.step('Act: click AI Models link', async () => {
      await page.locator(NAV.navSubDropdown).locator(NAV.flyoutLinkAiModels).click()
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
