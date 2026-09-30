import type { Page } from '@playwright/test'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

const S = selectors.smartSearch

/**
 * Smart Search (Ctrl/Cmd+K): the palette that finds pages, runs commands and
 * switches admin settings in place (J-SR-1, J-SR-4, J-SR-6).
 *
 * The write journey uses PROGRESS_SHOW_TIMINGS: a cosmetic, database-backed
 * switch no other spec reads, so flipping it cannot disturb parallel workers.
 * It is written back to its default after every test.
 *
 * Deterministic and provider-free (@ci): pages and commands come from the
 * in-browser index, settings from the lexical backend search; neither needs
 * an embedding or chat model. Auth: the worker `storageState` is a non-admin
 * user, so the settings tests log in as the seeded admin.
 */
const SETTING_KEY = 'PROGRESS_SHOW_TIMINGS'

const openPalette = async (page: Page) => {
  await page.keyboard.press('ControlOrMeta+k')
  await expect(page.locator(S.panel)).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await expect(page.locator(S.input)).toBeFocused()
}

const search = async (page: Page, text: string) => {
  await page.locator(S.input).fill(text)
}

const settingRow = (page: Page) =>
  page.locator(S.group('setting')).locator(S.row('setting')).filter({ hasText: SETTING_KEY })

test.describe('@ci Smart Search', () => {
  test('finds a page and runs a command from the keyboard', async ({ page }) => {
    await page.goto('/')
    await expect(page.locator(S.openSidebar)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    await test.step('Enter opens the page', async () => {
      await openPalette(page)
      await search(page, 'Show all chats')
      await expect(page.locator(S.group('command'))).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.keyboard.press('Enter')
      await expect(page).toHaveURL(/\/chats/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(S.modal)).toBeHidden()
    })

    await test.step('a command has one action, so Tab opens no pane', async () => {
      await openPalette(page)
      await search(page, 'Switch to dark theme')
      await expect(page.locator(S.group('command'))).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.keyboard.press('Tab')
      // A command only runs, so there is no second action and no pane.
      await expect(page.locator(S.actionPane)).toHaveCount(0)
      await page.keyboard.press('Enter')
      await expect(page.locator('html')).toHaveClass(/\bdark\b/)
      await expect(page.locator(S.modal)).toBeHidden()
    })
  })

  test.describe('settings', () => {
    test.beforeEach(async ({ page }) => {
      await login(page, CREDENTIALS.getAdminCredentials())
    })

    test.afterEach(async ({ page }) => {
      const res = await page.request.put(`${getApiUrl()}/api/v1/admin/config/values`, {
        data: { key: SETTING_KEY, value: 'true' },
      })
      expect(res.ok(), `restore ${SETTING_KEY}`).toBeTruthy()
    })

    test('switches a setting in place, confirms first, and undoes from the toast', async ({
      page,
    }) => {
      await openPalette(page)
      await search(page, SETTING_KEY)
      const row = settingRow(page)
      await expect(row).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      const toggle = row.locator(S.settingToggle)
      await expect(toggle).toHaveAttribute('aria-checked', 'true')

      await test.step('cancel keeps the value', async () => {
        await toggle.click()
        await expect(page.locator(selectors.dialog.confirmBtn)).toBeVisible()
        await page.locator(selectors.dialog.cancelBtn).click()
        await expect(toggle).toHaveAttribute('aria-checked', 'true')
      })

      await test.step('confirm switches it, Undo restores it', async () => {
        await toggle.click()
        await page.locator(selectors.dialog.confirmBtn).click()
        await expect(toggle).toHaveAttribute('aria-checked', 'false')
        const toast = page
          .locator(selectors.toast.item)
          .filter({ has: page.locator(selectors.toast.action) })
        await expect(toast).toBeVisible()
        await toast.locator(selectors.toast.action).click()
        await expect(toggle).toHaveAttribute('aria-checked', 'true', {
          timeout: TIMEOUTS.STANDARD,
        })
      })
    })

    test('Enter in the action pane asks before it switches a setting', async ({ page }) => {
      await openPalette(page)
      await search(page, SETTING_KEY)
      const row = settingRow(page)
      await expect(row).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await row.hover()

      await page.keyboard.press('Tab')
      await expect(page.locator(S.actionPane)).toBeVisible()
      await expect(page.locator(S.action('switch'))).toBeVisible()
      await page.keyboard.press('ArrowDown')
      // The same Enter must not also press the confirmation it opens.
      await page.keyboard.press('Enter')
      await expect(page.locator(selectors.dialog.confirmBtn)).toBeVisible()
      await expect(row.locator(S.settingToggle)).toHaveAttribute('aria-checked', 'true')
      await page.locator(selectors.dialog.cancelBtn).click()
      await expect(row.locator(S.settingToggle)).toHaveAttribute('aria-checked', 'true')
    })
  })

  test('opens from the mobile drawer at 320 px in dark theme', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 })
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.goto('/')
    await page.locator(selectors.nav.mobileDrawerToggle).click()
    await page.locator(S.openMobile).click()
    const panel = page.locator(S.panel)
    await expect(panel).toBeVisible({ timeout: TIMEOUTS.SHORT })

    const box = await panel.boundingBox()
    expect(box, 'palette panel is laid out').not.toBeNull()
    expect(box!.x).toBeGreaterThanOrEqual(0)
    expect(box!.x + box!.width).toBeLessThanOrEqual(320)
    // The preview column is a desktop aid; the list keeps the whole width.
    await expect(page.locator(S.preview)).toBeHidden()

    await search(page, 'Show all chats')
    await expect(page.locator(S.group('command'))).toBeVisible({ timeout: TIMEOUTS.SHORT })
  })
})
