/**
 * Follow-up journeys for the 2026-10 UX overhaul: one place per tab bar,
 * accordions that open from a link without a pill row, the "Users" wording,
 * and OLED Black surfaces that stay readable.
 *
 * Deterministic and provider-free (@ci). Colours are compared as computed
 * values so a regression to the navy dark-mode glass or to white text on the
 * light OLED accent fails here instead of only in a screenshot.
 */
import { test, expect, type Page, type Locator } from '../test-setup'
import { login, openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

const FILES = selectors.files
const MEM = selectors.memories
const MIN_TEXT_CONTRAST = 4.5

interface Paint {
  background: string
  color: string
}

async function paintOf(locator: Locator): Promise<Paint> {
  return locator.evaluate((el) => {
    const style = getComputedStyle(el)
    return { background: style.backgroundColor, color: style.color }
  })
}

/** Reads the paint once colour transitions have settled into opaque rgb values. */
async function settledPaint(locator: Locator): Promise<Paint> {
  let paint: Paint = { background: '', color: '' }
  await expect
    .poll(
      async () => {
        paint = await paintOf(locator)
        return /^rgb\(/.test(paint.background) && /^rgb\(/.test(paint.color)
      },
      { timeout: TIMEOUTS.SHORT }
    )
    .toBe(true)
  return paint
}

function channels(css: string): [number, number, number] {
  const match = css.match(/rgba?\(([^)]+)\)/)
  if (!match) throw new Error(`Expected an rgb() colour, got "${css}"`)
  const [r, g, b] = match[1].split(',').map((part) => Number.parseFloat(part))
  return [r, g, b]
}

function luminance(css: string): number {
  const [r, g, b] = channels(css).map((value) => {
    const c = value / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

/** Navy glass has a clearly bluer channel than red; OLED grey does not. */
function isNeutral(css: string): boolean {
  const [r, , b] = channels(css)
  return Math.abs(b - r) <= 8
}

async function useTheme(page: Page, theme: 'light' | 'oled'): Promise<void> {
  await page.evaluate((value) => localStorage.setItem('theme', value), theme)
  await page.reload()
  const html = page.locator('html')
  if (theme === 'oled') {
    await expect(html).toHaveClass(/theme-oled/, { timeout: TIMEOUTS.STANDARD })
  } else {
    await expect(html).not.toHaveClass(/\bdark\b/, { timeout: TIMEOUTS.STANDARD })
  }
}

test.describe('@ci UX surfaces', () => {
  test('J-UX-6 Library sections switch from the sidebar only', async ({ page }) => {
    await openApp(page)

    await test.step('Files has its header and no second tab bar in the page', async () => {
      await page.goto('/files')
      await expect(page.locator(FILES.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('[data-testid="section-files-header"]')).toBeVisible()
      await expect(page.locator('[data-testid="files-tabs"]')).toHaveCount(0)
      await expect(page.locator('[data-testid^="tab-files-"]')).toHaveCount(0)
    })

    await test.step('The sidebar opens Incoming and back to Files', async () => {
      await page.locator(FILES.linkIncoming).click()
      await expect(page).toHaveURL(/\/files\/incoming$/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.pageIncoming)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await page.locator(FILES.linkBrowse).click()
      await expect(page).toHaveURL(/\/files$/, { timeout: TIMEOUTS.STANDARD })
    })
  })

  test('J-UX-7 Apps keeps Connected as a tab in the page', async ({ page }) => {
    await openApp(page)
    await page.goto('/apps')
    await expect(page.locator(selectors.apps.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await page.locator('[data-testid="tab-apps-connected"]').click()
    await expect(page).toHaveURL(/\/apps\/connected$/, { timeout: TIMEOUTS.STANDARD })
    await page.locator('[data-testid="tab-apps-all"]').click()
    await expect(page).toHaveURL(/\/apps$/, { timeout: TIMEOUTS.STANDARD })
  })

  test('J-UX-8 Admin accordions open from a link and highlight the whole row', async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())

    await test.step('A section link opens that section, with no pill row above', async () => {
      await page.goto('/admin/setup?tab=providers&section=tts')
      await expect(page.locator('#setup-section-tts')).toHaveAttribute('data-open', 'true', {
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator('[data-testid="section-jump-nav"]')).toHaveCount(0)
    })

    await test.step('Expand all opens every section', async () => {
      await page.locator('[data-testid="btn-setup-accordion-toggle-all"]').click()
      const sections = page.locator('[data-testid="setup-models-accordion"] section[data-open]')
      await expect(sections.first()).toBeVisible()
      await expect(
        page.locator('[data-testid="setup-models-accordion"] section[data-open="false"]')
      ).toHaveCount(0)
    })

    await test.step('Hovering Edit keeps the full row highlighted', async () => {
      await page.goto('/admin/setup?tab=prompts')
      const edit = page.locator('[data-testid^="btn-edit-prompt-"]').first()
      await expect(edit).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await edit.hover()
      const row = edit.locator('xpath=ancestor::div[contains(@class,"accordion-row")][1]')
      await expect
        .poll(async () => (await paintOf(row)).background, { timeout: TIMEOUTS.SHORT })
        .not.toBe('rgba(0, 0, 0, 0)')
      await expect
        .poll(async () => (await paintOf(edit)).background, { timeout: TIMEOUTS.SHORT })
        .toBe('rgba(0, 0, 0, 0)')
    })

    await test.step('Admin calls the accounts Users', async () => {
      await page.goto('/admin/people')
      await expect(page).toHaveTitle(/^Users \|/, { timeout: TIMEOUTS.STANDARD })
    })
  })

  test('J-UX-9 OLED Black keeps menus, modals and selected controls readable', async ({ page }) => {
    await openApp(page)

    try {
      await test.step('Switch to OLED Black', async () => {
        await page.goto('/memories')
        await useTheme(page, 'oled')
      })

      await test.step('The account menu is grey, not navy, with readable text', async () => {
        await page.locator('[data-testid="btn-sidebar-v2-user"]').click()
        const menu = page.locator('[data-testid="dropdown-sidebar-v2-user"]')
        await expect(menu).toBeVisible({ timeout: TIMEOUTS.SHORT })
        const menuPaint = await paintOf(menu)
        expect(isNeutral(menuPaint.background), menuPaint.background).toBe(true)
        const item = menu.locator('a, button').first()
        expect(contrast((await paintOf(item)).color, menuPaint.background)).toBeGreaterThan(
          MIN_TEXT_CONTRAST
        )
        await page
          .locator('[data-testid="overlay-sidebar-v2-user"]')
          .click({ position: { x: 5, y: 5 } })
        await expect(menu).toHaveCount(0)
      })

      await test.step('New Memory is grey and the chosen mode can be read', async () => {
        await page.locator(MEM.btnCreate).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        await page.locator(MEM.btnCreate).click()
        const modal = page.locator(MEM.formModal)
        await expect(modal).toBeVisible({ timeout: TIMEOUTS.SHORT })
        const modalPaint = await paintOf(modal)
        expect(isNeutral(modalPaint.background), modalPaint.background).toBe(true)

        const advanced = page.locator(MEM.btnModeAdvanced)
        await advanced.click()
        await expect(advanced).toHaveClass(/mode-toggle-active/)
        const selected = await settledPaint(advanced)
        expect(contrast(selected.color, selected.background)).toBeGreaterThan(MIN_TEXT_CONTRAST)
      })

      await test.step('Light keeps the blue accent with readable text', async () => {
        await useTheme(page, 'light')
        await page.locator(MEM.btnCreate).click()
        const advanced = page.locator(MEM.btnModeAdvanced)
        await advanced.click()
        await expect(advanced).toHaveClass(/mode-toggle-active/)
        const selected = await settledPaint(advanced)
        const [r, , b] = channels(selected.background)
        expect(b, selected.background).toBeGreaterThan(r)
        expect(contrast(selected.color, selected.background)).toBeGreaterThan(MIN_TEXT_CONTRAST)
      })
    } finally {
      await page.evaluate(() => localStorage.setItem('theme', 'light'))
    }
  })
})
