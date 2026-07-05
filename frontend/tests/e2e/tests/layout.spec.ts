/**
 * Layout UI guard — deterministic geometry contracts for the navigation/UX
 * redesign (see _devextras/planning/20260611-navigation-ia-cleanup.md §5).
 *
 * Layer 1: layout contracts (no horizontal overflow, accessible nav labels,
 *          touch targets, reachability, no menu collision).
 * Layer 2: axe-core a11y/contrast scans — REPORT-ONLY in phase 0.5 (staged
 *          rollout per §5.2: findings are triaged and fixed in phases 1–2,
 *          then the scan flips to blocking per surface).
 *
 * Runs in the desktop `chromium` project (via @ci) AND the `chromium-mobile`
 * project (via @layout). Visual snapshots (Layer 3) live in visual.spec.ts.
 */
import AxeBuilder from '@axe-core/playwright'
import { test, expect, type Page } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const NAV = selectors.nav
const CHAT = selectors.chat

/**
 * Touch-target floor in px — §4.3 #1/#2: interactive nav targets are 44 px
 * minimum since phase 6 (bottom tab bar).
 */
const MIN_TARGET_PX = 44

/**
 * Tailwind `md` — since phase 6 the rail exists only at >= md; below it the
 * bottom tab bar (MobileNav) is the primary navigation.
 */
const MOBILE_MAX_WIDTH = 768

function isMobileViewport(page: Page): boolean {
  const size = page.viewportSize()
  return size !== null && size.width < MOBILE_MAX_WIDTH
}

/** App-mode switch, same mechanism navigation.spec.ts uses. */
async function ensureAdvancedMode(page: Page) {
  await page.evaluate(() => localStorage.setItem('app_mode', 'advanced'))
  await page.reload()
  await expect(page.locator(CHAT.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

/** The #1 mobile breakage: horizontal page overflow. */
async function expectNoHorizontalOverflow(page: Page, surface: string) {
  const overflow = await page.evaluate(() => {
    const el = document.documentElement
    return { scrollWidth: el.scrollWidth, clientWidth: el.clientWidth }
  })
  expect(
    overflow.scrollWidth,
    `${surface}: horizontal overflow (scrollWidth ${overflow.scrollWidth} > clientWidth ${overflow.clientWidth})`
  ).toBeLessThanOrEqual(overflow.clientWidth + 1)
}

/**
 * Bounding box once it is stable across two consecutive frames — never
 * measure mid enter-transition (toBeVisible resolves on the FIRST frame,
 * before Vue's transition has even registered with getAnimations()).
 */
async function stableBoundingBox(page: Page, selector: string) {
  const locator = page.locator(selector)
  let prev = await locator.boundingBox()
  for (let i = 0; i < 20; i++) {
    await page.waitForTimeout(100)
    const cur = await locator.boundingBox()
    if (
      prev &&
      cur &&
      Math.abs(cur.x - prev.x) < 0.5 &&
      Math.abs(cur.y - prev.y) < 0.5 &&
      Math.abs(cur.width - prev.width) < 0.5 &&
      Math.abs(cur.height - prev.height) < 0.5
    ) {
      return cur
    }
    prev = cur
  }
  return prev
}

/** Element must sit fully inside the viewport. */
async function expectInsideViewport(page: Page, selector: string, label: string) {
  const box = await stableBoundingBox(page, selector)
  expect(box, `${label}: not rendered`).not.toBeNull()
  const viewport = page.viewportSize()
  expect(viewport).not.toBeNull()
  if (!box || !viewport) return
  expect(box.x, `${label}: clipped left`).toBeGreaterThanOrEqual(0)
  expect(box.y, `${label}: clipped top`).toBeGreaterThanOrEqual(0)
  expect(box.x + box.width, `${label}: clipped right`).toBeLessThanOrEqual(viewport.width + 1)
  expect(box.y + box.height, `${label}: clipped bottom`).toBeLessThanOrEqual(viewport.height + 1)
}

/**
 * Layer 2 — axe scan, report-only (phase 0.5). Findings are attached to the
 * test report and annotated, but NEVER fail the run. Flip to expect() per
 * surface once it is clean (target: blocking by end of phase 2).
 */
async function axeReportOnly(page: Page, surface: string, colorScheme: 'light' | 'dark') {
  await page.emulateMedia({ colorScheme })
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  const severe = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
  await test.info().attach(`axe-${surface}-${colorScheme}.json`, {
    body: JSON.stringify(severe, null, 2),
    contentType: 'application/json',
  })
  test.info().annotations.push({
    type: 'axe-report-only',
    description: `${surface} (${colorScheme}): ${severe.length} serious/critical violation(s)`,
  })
  await page.emulateMedia({ colorScheme: 'light' })
}

test.describe('@ci @layout UI guard — chat surface', () => {
  test('chat page has no overflow and input is reachable', async ({ page, credentials }) => {
    await login(page, credentials)
    await expectNoHorizontalOverflow(page, 'chat')

    await expectInsideViewport(page, CHAT.textInput, 'chat input')
    await expectInsideViewport(page, CHAT.sendBtn, 'send button')
  })

  test('primary nav controls carry visible labels and meet target size', async ({
    page,
    credentials,
  }) => {
    await login(page, credentials)
    await ensureAdvancedMode(page)

    if (isMobileViewport(page)) {
      // Phase 6 (§4.3 #2): on mobile the bottom tab bar IS the primary nav.
      await expect(page.locator(NAV.mobileBar)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      const tabs = page.locator('[data-testid^="btn-mobile-nav-"]')
      const count = await tabs.count()
      expect(count, 'bottom bar renders New/History/Files/More').toBe(4)

      for (let i = 0; i < count; i++) {
        const tab = tabs.nth(i)
        const testid = await tab.getAttribute('data-testid')

        const label = tab.locator(NAV.railLabel)
        await expect(label, `${testid}: tab label node missing`).toBeVisible()
        expect(((await label.textContent()) ?? '').trim() !== '', `${testid}: label empty`).toBe(
          true
        )

        const box = await tab.boundingBox()
        expect(box, `${testid}: not rendered`).not.toBeNull()
        if (box) {
          expect(box.width, `${testid}: tap target too narrow`).toBeGreaterThanOrEqual(
            MIN_TARGET_PX
          )
          expect(box.height, `${testid}: tap target too short`).toBeGreaterThanOrEqual(
            MIN_TARGET_PX
          )
        }
      }
      return
    }

    await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    const navButtons = page.locator('[data-testid^="btn-sidebar-v2-nav-"]')
    const count = await navButtons.count()
    expect(count, 'rail renders nav items').toBeGreaterThanOrEqual(2)

    for (let i = 0; i < count; i++) {
      const btn = navButtons.nth(i)
      const testid = await btn.getAttribute('data-testid')

      // Phase 2 contract (§4.1 #3): every rail control carries an
      // ALWAYS-VISIBLE label node — tooltips are additive, never the only
      // affordance.
      const label = btn.locator(NAV.railLabel)
      await expect(label, `${testid}: rail label node missing`).toBeVisible()
      expect(
        ((await label.textContent()) ?? '').trim() !== '',
        `${testid}: rail label is empty`
      ).toBe(true)
    }
  })

  test('More sheet opens with accordion sections and 44px rows (mobile)', async ({
    page,
    credentials,
  }) => {
    test.skip(!isMobileViewport(page), 'bottom tab bar exists only below md')

    await login(page, credentials)
    await ensureAdvancedMode(page)

    await page.locator(NAV.mobileMore).click()
    const sheet = page.locator(NAV.mobileMoreSheet)
    await expect(sheet).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await expectNoHorizontalOverflow(page, 'more sheet')
    await expectInsideViewport(page, NAV.mobileMoreSheet, 'more sheet')

    // §4.4: the sheet carries the remaining sections + the account block.
    const sections = sheet.locator('[data-testid^="btn-mobile-more-"]')
    expect(await sections.count(), 'more sheet renders section rows').toBeGreaterThanOrEqual(2)
    await expect(sheet.locator('[data-testid="section-mobile-more-account"]')).toBeVisible()

    // Accordion: tapping Channels expands its children inline (§4.3 #3).
    await sheet.locator('[data-testid="btn-mobile-more-channels"]').click()
    await expect(sheet.locator('[data-testid="link-mobile-more-inbound"]')).toBeVisible({
      timeout: TIMEOUTS.SHORT,
    })

    // Touch targets: every visible row in the sheet is >= 44 px tall.
    const rows = sheet.locator(
      '[data-testid^="btn-mobile-more-"], [data-testid^="link-mobile-more-"]'
    )
    const rowCount = await rows.count()
    for (let i = 0; i < rowCount; i++) {
      const row = rows.nth(i)
      if (!(await row.isVisible())) continue
      const testid = await row.getAttribute('data-testid')
      const box = await row.boundingBox()
      expect(box, `${testid}: not rendered`).not.toBeNull()
      if (box) {
        expect(box.height, `${testid}: row too short`).toBeGreaterThanOrEqual(MIN_TARGET_PX)
      }
    }

    // Account rows navigate (regression: dead Subscription tap). Preferences
    // exists for every user level, and shares handleNavigate with the
    // Subscription/Upgrade rows.
    const preferencesRow = sheet.locator('[data-testid="btn-mobile-more-preferences"]')
    await preferencesRow.scrollIntoViewIfNeeded()
    await preferencesRow.tap()
    await expect(page).toHaveURL(/\/settings/, { timeout: TIMEOUTS.STANDARD })
    await expect(sheet).toBeHidden({ timeout: TIMEOUTS.SHORT })
  })

  test('chat-input "+" menu does not navigate (menu collision guard)', async ({
    page,
    credentials,
  }) => {
    await login(page, credentials)
    await ensureAdvancedMode(page)

    // The per-message controls now live inside the "+" menu.
    await page.locator(CHAT.plusToggle).click()
    const panel = page.locator(CHAT.plusPanel)
    await expect(panel).toBeVisible({ timeout: TIMEOUTS.SHORT })

    // Contract: the menu picks context / toggles behaviour but never navigates —
    // so the panel itself must contain no link elements (navigation lives inside
    // the sub-pickers as clearly marked link rows).
    await expect(panel.locator('a, [role="link"]')).toHaveCount(0)

    // The standalone manage-folders navigation pill must not come back.
    await expect(panel.locator('[data-testid="btn-manage-knowledge-groups"]')).toHaveCount(0)
  })

  test('history sheet (chat list) opens within the viewport', async ({ page, credentials }) => {
    await login(page, credentials)

    // Same sheet, two entry points: bottom tab on mobile, rail on desktop.
    if (isMobileViewport(page)) {
      await expect(page.locator(NAV.mobileHistory)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.locator(NAV.mobileHistory).click()
    } else {
      await expect(page.locator(NAV.sidebar)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.locator(NAV.sidebarV2ChatNav).click()
    }
    await expect(page.locator(NAV.modalChatManager)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await expectNoHorizontalOverflow(page, 'history sheet')
    await expectInsideViewport(page, NAV.modalChatManager, 'history sheet')
  })

  test('chat survives a 320px ultra-narrow viewport', async ({ page, credentials }) => {
    await page.setViewportSize({ width: 320, height: 800 })
    await login(page, credentials)
    await expectNoHorizontalOverflow(page, 'chat @320px')
    await expectInsideViewport(page, CHAT.sendBtn, 'send button @320px')
  })
})

test.describe('@ci @layout UI guard — key pages', () => {
  test('files page has no overflow and tappable primary action', async ({ page, credentials }) => {
    await login(page, credentials)
    await page.goto('/files')
    await expect(page.locator(selectors.files.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expectNoHorizontalOverflow(page, 'files')

    if (isMobileViewport(page)) {
      const uploadBtn = page.locator(selectors.files.uploadButton)
      await uploadBtn.scrollIntoViewIfNeeded()
      const box = await uploadBtn.boundingBox()
      expect(box, 'files upload button: not rendered').not.toBeNull()
      if (box) {
        expect(box.height, 'files upload button: tap target too short').toBeGreaterThanOrEqual(
          MIN_TARGET_PX
        )
      }
    }
  })

  test('channels page (inbound config) has no overflow', async ({ page, credentials }) => {
    await login(page, credentials)
    await page.goto('/channels')
    await expect(page.locator('[data-testid="page-config-inbound"]')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expectNoHorizontalOverflow(page, 'channels (inbound)')
  })

  test('AI models page header stays inside the card (B1 regression)', async ({
    page,
    credentials,
  }) => {
    await login(page, credentials)
    await page.goto('/ai/models')
    await expect(page.locator(selectors.models.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expectNoHorizontalOverflow(page, 'ai models')

    // §2.8 B1: the "reset defaults" header button used to overflow the card
    // on ~390px viewports. It must render fully inside the viewport.
    const resetBtn = page.locator('[data-testid="btn-reset-defaults"]')
    await expect(resetBtn).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await resetBtn.scrollIntoViewIfNeeded()
    await expectInsideViewport(page, '[data-testid="btn-reset-defaults"]', 'reset-defaults button')
  })

  test('login page has no overflow and reachable submit', async ({ page }) => {
    await page.goto('/login')
    await expect(page.locator(selectors.login.submit)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expectNoHorizontalOverflow(page, 'login')
    await expectInsideViewport(page, selectors.login.submit, 'login submit')
  })
})

test.describe('@ci @layout UI guard — axe scans (report-only, phase 0.5)', () => {
  test('login page — light and dark', async ({ page }) => {
    await page.goto('/login')
    await expect(page.locator(selectors.login.submit)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await axeReportOnly(page, 'login', 'light')
    await axeReportOnly(page, 'login', 'dark')
  })

  test('chat and files — light and dark', async ({ page, credentials }) => {
    await login(page, credentials)
    await axeReportOnly(page, 'chat', 'light')
    await axeReportOnly(page, 'chat', 'dark')

    await page.goto('/files')
    await expect(page.locator(selectors.files.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await axeReportOnly(page, 'files', 'light')
    await axeReportOnly(page, 'files', 'dark')
  })

  test('AI models — light and dark', async ({ page, credentials }) => {
    await login(page, credentials)
    await page.goto('/ai/models')
    await expect(page.locator(selectors.models.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await axeReportOnly(page, 'ai-models', 'light')
    await axeReportOnly(page, 'ai-models', 'dark')
  })
})
