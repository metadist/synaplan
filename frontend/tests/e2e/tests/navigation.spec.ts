import { test, expect } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

const NAV = selectors.nav
const HDR = selectors.header
const USR = selectors.userMenu

async function ensureEasyMode(page: import('@playwright/test').Page) {
  const toggle = page.locator(HDR.modeToggle)
  if ((await toggle.getAttribute('data-mode')) !== 'easy') {
    await toggle.click()
  }
  await expect(toggle).toHaveAttribute('data-mode', 'easy', { timeout: TIMEOUTS.SHORT })
}

async function ensureAdvancedMode(page: import('@playwright/test').Page) {
  const toggle = page.locator(HDR.modeToggle)
  if ((await toggle.getAttribute('data-mode')) !== 'advanced') {
    await toggle.click()
  }
  await expect(toggle).toHaveAttribute('data-mode', 'advanced', { timeout: TIMEOUTS.SHORT })
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
  test('@ci Mode toggle switches between easy and advanced', async ({ page, credentials }) => {
    await test.step('Arrange: login and switch to easy mode', async () => {
      await login(page, credentials)
      await ensureEasyMode(page)
    })

    await test.step('Act: toggle to advanced mode', async () => {
      await page.locator(HDR.modeToggle).click()
    })

    await test.step('Assert: toggle shows advanced state and Settings appears', async () => {
      await expect(page.locator(HDR.modeToggle)).toHaveAttribute('data-mode', 'advanced', {
        timeout: TIMEOUTS.SHORT,
      })
      await expect(page.locator(NAV.sidebarV2Settings)).toBeVisible({ timeout: TIMEOUTS.SHORT })
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
      await expect(flyout.locator('a[href="/tools/chat-widget"]')).toBeVisible()
      await expect(flyout.locator('a[href="/config/ai-models"]')).toBeVisible()
      await expect(flyout.locator('a[href="/config/task-prompts"]')).toBeVisible()
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
      await page.locator(NAV.navDropdown).locator('a[href="/tools/chat-widget"]').click()
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
      await page.locator(NAV.navDropdown).locator('a[href="/config/ai-models"]').click()
    })

    await test.step('Assert: AI Models page visible', async () => {
      await expect(page.locator(selectors.models.page)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})

test.describe('Navigation: Admin sidebar', () => {
  test('@ci Admin sees Admin button in sidebar', async ({ page, credentials }) => {
    void credentials

    await test.step('Arrange: login as admin', async () => {
      await login(page, CREDENTIALS.getAdminCredentials())
    })

    await test.step('Assert: Admin nav button visible', async () => {
      await expect(page.locator(NAV.sidebarV2Admin)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })
  })

  test('@ci Admin flyout navigates to admin dashboard', async ({ page, credentials }) => {
    void credentials

    await test.step('Arrange: login as admin', async () => {
      await login(page, CREDENTIALS.getAdminCredentials())
    })

    await test.step('Act: open Admin flyout and click Dashboard', async () => {
      await page.locator(NAV.sidebarV2Admin).click()
      const flyout = page.locator(NAV.navDropdown)
      await expect(flyout).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await flyout.locator('a[href="/admin"]').click()
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

    await test.step('Assert: chat page visible instead of admin', async () => {
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
      const dropdown = page.locator(NAV.userDropdown)
      await expect(dropdown).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(dropdown.locator('[data-testid="btn-sidebar-v2-profile"]')).toBeVisible()
      await expect(dropdown.locator('[data-testid="btn-sidebar-v2-statistics"]')).toBeVisible()
      await expect(dropdown.locator('[data-testid="btn-sidebar-v2-logout"]')).toBeVisible()
    })
  })

  test('@ci User menu navigates to Profile page', async ({ page, credentials }) => {
    await test.step('Arrange: login and open user menu', async () => {
      await login(page, credentials)
      await page.locator(USR.button).click()
      await expect(page.locator(NAV.userDropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })

    await test.step('Act: click Profile', async () => {
      await page.locator(NAV.userDropdown).locator('[data-testid="btn-sidebar-v2-profile"]').click()
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
      await expect(page.locator(NAV.userDropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })

    await test.step('Act: click Statistics', async () => {
      await page
        .locator(NAV.userDropdown)
        .locator('[data-testid="btn-sidebar-v2-statistics"]')
        .click()
    })

    await test.step('Assert: Statistics page visible', async () => {
      await expect(page.locator(selectors.pages.statistics)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})

test.describe('Navigation: Header controls', () => {
  test('@ci Language selector opens with language options', async ({ page, credentials }) => {
    await test.step('Arrange: login', async () => {
      await login(page, credentials)
    })

    await test.step('Act: open language selector', async () => {
      await page.locator(HDR.languageToggle).click()
    })

    await test.step('Assert: dropdown visible with language options', async () => {
      const menu = page.locator(HDR.languageMenu)
      await expect(menu).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(menu.getByRole('menuitem')).toHaveCount(4)
    })
  })
})
