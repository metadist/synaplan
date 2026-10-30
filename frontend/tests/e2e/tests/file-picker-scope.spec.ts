import path from 'path'
import { fileURLToPath } from 'url'
import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { FIXTURE_PATHS } from '../config/test-data'
import { TIMEOUTS } from '../config/config'

const __filename = fileURLToPath(import.meta.url)
const fixturePath = path.join(path.dirname(__filename), '..', FIXTURE_PATHS.RAG_MOST_IMPORTANT)
const fixtureName = path.basename(fixturePath)

/**
 * Attach dialog: own files first, AI-created files on a second tab.
 */
test.describe('@ci File picker scope', () => {
  test('opens on my files and can switch to AI-created', async ({ page }) => {
    await openApp(page)
    await page.locator(selectors.nav.sidebarV2ChatNav).click()
    await page.locator(selectors.chat.plusToggle).click()
    await page.locator(selectors.chat.attachBtn).click()

    const mine = page.getByTestId('tab-file-selection-mine')
    const ai = page.getByTestId('tab-file-selection-ai')
    await expect(mine).toHaveAttribute('aria-selected', 'true', { timeout: TIMEOUTS.STANDARD })

    await page.locator('[data-testid="input-file-selection-upload"]').setInputFiles(fixturePath)
    await expect(page.getByText(fixtureName).first()).toBeVisible({ timeout: TIMEOUTS.LONG })

    await ai.click()
    await expect(ai).toHaveAttribute('aria-selected', 'true')
    const back = page.getByTestId('btn-file-selection-empty-mine')
    if (await back.isVisible()) {
      await back.click()
      await expect(mine).toHaveAttribute('aria-selected', 'true')
    }
  })
})
