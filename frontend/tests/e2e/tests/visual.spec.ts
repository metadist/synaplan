/**
 * Layer 3 of the UI guard — visual snapshots, HARD-CAPPED (≤ 10 shots; see
 * planning doc §5.2). CI-only: baselines are generated on the ubuntu runner
 * via the "Update visual baselines" workflow dispatch and committed via PR —
 * never from a laptop (font rendering differs, local baselines go red).
 *
 * Runs ONLY in the `chromium-visual` project (grep @visual); the regular
 * desktop/mobile projects grep-invert it. A snapshot diff is reviewed like
 * code — never blind-approved.
 */
import { test, expect, type Page } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const NAV = selectors.nav

const SNAPSHOT_OPTS = {
  animations: 'disabled' as const,
  maxDiffPixelRatio: 0.01,
}

async function ensureAdvancedMode(page: Page) {
  await page.evaluate(() => localStorage.setItem('app_mode', 'advanced'))
  await page.reload()
  await expect(page.locator(selectors.chat.textInput)).toBeVisible({
    timeout: TIMEOUTS.STANDARD,
  })
}

test.describe('@visual UI guard — capped snapshots', () => {
  test('sidebar rail — light and dark', async ({ page, credentials }) => {
    await login(page, credentials)
    await ensureAdvancedMode(page)
    const rail = page.locator(NAV.sidebar)
    await expect(rail).toBeVisible({ timeout: TIMEOUTS.SHORT })

    // Mask the avatar button — initials derive from the per-worker email.
    const mask = [page.locator(selectors.userMenu.button)]

    await expect(rail).toHaveScreenshot('rail-light.png', { ...SNAPSHOT_OPTS, mask })

    await page.emulateMedia({ colorScheme: 'dark' })
    await expect(rail).toHaveScreenshot('rail-dark.png', { ...SNAPSHOT_OPTS, mask })
    await page.emulateMedia({ colorScheme: 'light' })
  })

  test('chat-input action row', async ({ page, credentials }) => {
    await login(page, credentials)
    await ensureAdvancedMode(page)
    const row = page.locator('[data-testid="section-chat-secondary-actions"]')
    await expect(row).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await expect(row).toHaveScreenshot('chat-input-action-row.png', SNAPSHOT_OPTS)
  })

  test('files page header (upload form card)', async ({ page, credentials }) => {
    await login(page, credentials)
    await page.goto('/files')
    const uploadForm = page.locator('[data-testid="section-upload-form"]')
    await expect(uploadForm).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(uploadForm).toHaveScreenshot('files-upload-form.png', SNAPSHOT_OPTS)
  })

  test('settings flyout', async ({ page, credentials }) => {
    await login(page, credentials)
    await ensureAdvancedMode(page)
    await page.locator(NAV.sidebarV2Settings).click()
    const flyout = page.locator(NAV.navDropdown)
    await expect(flyout).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await expect(flyout).toHaveScreenshot('settings-flyout.png', SNAPSHOT_OPTS)
  })
})
