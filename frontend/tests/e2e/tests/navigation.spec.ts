import { test, expect, type Page } from '../test-setup'
import { login, openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

const NAV = selectors.nav
const SET = selectors.settings
const USR = selectors.userMenu

/** Wait until the signed-in rail (Assistants) is painted. */
async function ensureNavReady(page: Page) {
  await expect(page.locator(NAV.sidebarV2Assistants)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

/** Avatar menu → Profile, then the language page in the profile sidebar. */
async function openPreferences(page: Page) {
  await page.locator(USR.button).click()
  await expect(page.locator(USR.dropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await page.locator(USR.dropdown).locator(USR.profileBtn).click()
  await expect(page.locator(SET.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
  await page.locator('[data-testid="link-sidebar-v2-settings-appearance"]').click()
  await expect(page.locator(SET.btnLanguage('en'))).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

/** Open a rail section and wait for its context panel. */
async function openSection(page: Page, railItemSelector: string) {
  await page.locator(railItemSelector).click()
  const panel = page.locator(NAV.sidebarPanel)
  await expect(panel).toBeVisible({ timeout: TIMEOUTS.SHORT })
  return panel
}

const GROUP_RAIL: Record<string, string> = {
  assistants: NAV.sidebarV2Assistants,
  automations: NAV.sidebarV2Assistants,
  channels: NAV.sidebarV2Channels,
  connections: NAV.sidebarV2Channels,
  developer: NAV.sidebarV2Channels,
}

/** Open the rail section that owns a group and return that group's block. */
async function openManageGroup(page: Page, groupKey: string) {
  await openSection(page, GROUP_RAIL[groupKey])
  const group = page.locator(NAV.panelGroup(groupKey))
  await expect(group).toBeVisible({ timeout: TIMEOUTS.SHORT })
  return group
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
  test('@ci Sidebar shows Chats, Library, Assistants and Channels', async ({ page }) => {
    await test.step('Arrange: login', async () => {
      await openApp(page)
    })

    await test.step('Assert: everyday rail is Chats / Library / Assistants / Channels', async () => {
      await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2NewChat)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2ChatNav)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2Files)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2Assistants)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(NAV.sidebarV2Channels)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator('[data-testid="btn-sidebar-v2-nav-ai-setup"]')).toHaveCount(0)
      await expect(page.locator('[data-testid="btn-sidebar-v2-nav-manage"]')).toHaveCount(0)
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

  test('@ci Chats rail shows the chat list in the side panel', async ({ page }) => {
    await test.step('Arrange: login', async () => {
      await openApp(page)
    })

    await test.step('Act: click Chats nav button', async () => {
      await page.locator(NAV.sidebarV2ChatNav).click()
    })

    await test.step('Assert: chat list is in the side panel', async () => {
      await expect(page.locator(NAV.sidebarChats)).toBeVisible({ timeout: TIMEOUTS.SHORT })
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

test.describe('Navigation: section panels (non-admin)', () => {
  test('@ci Assistants and Channels panels keep their pages apart', async ({ page }) => {
    await test.step('Arrange: login and wait for nav', async () => {
      await openApp(page)
      await ensureNavReady(page)
    })

    await test.step('Act+Assert: Assistants lists models and automations, not channels', async () => {
      const panel = await openSection(page, NAV.sidebarV2Assistants)
      await expect(panel.locator(NAV.panelGroup('assistants'))).toBeVisible()
      await expect(panel.locator(NAV.panelGroup('automations'))).toBeVisible()
      await expect(panel.locator(NAV.flyoutLinkAiModels)).toBeVisible()
      await expect(panel.locator(NAV.flyoutLinkInbound)).toHaveCount(0)
      await expect(panel.locator(NAV.flyoutLinkChatWidget)).toHaveCount(0)
      await expect(panel.locator('[data-testid="link-sidebar-v2-doc-summary"]')).toHaveCount(0)
    })

    await test.step('Act+Assert: Channels lists inbound, widgets, live support and API docs', async () => {
      const panel = await openSection(page, NAV.sidebarV2Channels)
      await expect(panel.locator(NAV.panelGroup('channels'))).toBeVisible()
      await expect(panel.locator(NAV.panelGroup('connections'))).toBeVisible()
      await expect(panel.locator(NAV.panelGroup('developer'))).toBeVisible()
      await expect(panel.locator(NAV.flyoutLinkInbound)).toBeVisible()
      await expect(panel.locator(NAV.flyoutLinkChatWidget)).toBeVisible()
      await expect(panel.locator(NAV.flyoutLinkLiveSupport)).toBeVisible()
      await expect(panel.locator(NAV.flyoutLinkApiDocs)).toBeVisible()
      await expect(panel.locator(NAV.flyoutLinkAiModels)).toHaveCount(0)
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
      const automations = await openManageGroup(page, 'automations')
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

    await test.step('Act+Assert: Channels panel shows email handler', async () => {
      const channels = await openManageGroup(page, 'channels')
      await expect(channels.locator(NAV.flyoutLinkMailHandler)).toBeVisible()
    })
  })

  test('@ci Manage flyout navigates to Chat Widget page', async ({ page }) => {
    await test.step('Arrange: login, open Channels submenu', async () => {
      await openApp(page)
      await ensureNavReady(page)
      await openManageGroup(page, 'channels')
    })

    await test.step('Act: click Chat Widget link', async () => {
      await page.locator(NAV.flyoutLinkChatWidget).click()
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
      await page.locator(NAV.flyoutLinkLiveSupport).click()
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
      await page.locator(NAV.flyoutLinkAiModels).click()
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

    await test.step('Act: open Operate and click Overview', async () => {
      const panel = await openSection(page, NAV.sidebarV2Admin)
      await panel.locator(NAV.flyoutLinkAdminDashboard).click()
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
  test('@ci User menu shows Profile and Logout', async ({ page }) => {
    await test.step('Arrange: login', async () => {
      await openApp(page)
    })

    await test.step('Act: open user menu', async () => {
      await page.locator(USR.button).click()
    })

    await test.step('Assert: dropdown has Profile and Logout only', async () => {
      const dropdown = page.locator(USR.dropdown)
      await expect(dropdown).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(dropdown.locator(USR.profileBtn)).toBeVisible()
      await expect(dropdown.locator(USR.logoutBtn)).toBeVisible()
      await expect(dropdown.locator(USR.statisticsBtn)).toHaveCount(0)
      await expect(dropdown.locator('[role="menuitem"]')).toHaveCount(2)
    })
  })

  test('@ci User menu navigates to Profile', async ({ page }) => {
    await test.step('Arrange: login and open user menu', async () => {
      await openApp(page)
      await page.locator(USR.button).click()
      await expect(page.locator(USR.dropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })

    await test.step('Act: click Profile', async () => {
      await page.locator(USR.dropdown).locator(USR.profileBtn).click()
    })

    await test.step('Assert: settings page lists its sections in the sidebar', async () => {
      await expect(page.locator(selectors.settings.page)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(selectors.pages.profile)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator('[data-testid="link-sidebar-v2-settings-profile"]')).toBeVisible()
      await expect(
        page.locator('[data-testid="link-sidebar-v2-settings-appearance"]')
      ).toBeVisible()
      await expect(page.locator('[data-testid="link-sidebar-v2-statistics"]')).toBeVisible()
      await expect(page.locator('[data-testid="nav-settings-sections"]')).toHaveCount(0)
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

    await test.step('Assert: localStorage and visible copy follow the new language', async () => {
      await expect
        .poll(() => page.evaluate(() => localStorage.getItem('language')), {
          timeout: TIMEOUTS.SHORT,
        })
        .toBe(targetLang)
      const expectedTitle = targetLang === 'de' ? 'Sprache & Darstellung' : 'Language & appearance'
      await expect(page.locator(SET.page)).toContainText(expectedTitle)
    })
  })
})
