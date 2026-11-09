/**
 * Native keyboard, shrink model — the page gives up exactly the keyboard.
 *
 * In the app the shell writes the keyboard's overlap into
 * `--keyboard-inset-height` on every frame and flags `synaplan-keyboard-open`.
 * The content column shrinks by that amount: the composer's bottom edge meets
 * the keyboard's top edge, the fixed header buttons and the drawer stay put,
 * and a page outside the main layout (sign-in) shrinks and scrolls the same
 * way. A WebView that already shrank above the keyboard reports 0, so nothing
 * rises twice (synaplan-apps#29).
 *
 * The browser stands in for the shell: a SynaplanChatKeyboard bridge, the
 * inset on <html>, and a viewport resize for the WebView shrink.
 */
import { test, expect, LOGGED_OUT, type Page } from '../test-setup'
import { openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

const WIDTH = 412
const FULL_HEIGHT = 915
const KEYBOARD = 320
const KEYBOARD_TOP = FULL_HEIGHT - KEYBOARD

test.use({ viewport: { width: WIDTH, height: FULL_HEIGHT } })

async function bottomOf(page: Page, selector: string): Promise<number> {
  const box = await page.locator(selector).boundingBox()
  return box ? Math.round(box.y + box.height) : -1
}

async function topOf(page: Page, selector: string): Promise<number> {
  const box = await page.locator(selector).boundingBox()
  return box ? Math.round(box.y) : -1
}

/** The shell's show path: keyboard-open class, then the inset on every frame. */
async function shellReportsKeyboard(page: Page, insetPx: number): Promise<void> {
  await page.evaluate((inset) => {
    const root = document.documentElement
    root.classList.toggle('synaplan-keyboard-open', inset > 0)
    root.style.setProperty('--keyboard-inset-height', `${inset}px`)
  }, insetPx)
}

/** The shell's frame path: the inset grows a step per animation frame. */
async function shellAnimatesKeyboard(page: Page, insetPx: number): Promise<void> {
  await page.evaluate(async (inset) => {
    const root = document.documentElement
    root.classList.add('synaplan-keyboard-open')
    for (let px = 0; px <= inset; px += 32) {
      root.style.setProperty('--keyboard-inset-height', `${Math.min(px, inset)}px`)
      await new Promise((resolve) => requestAnimationFrame(resolve))
    }
    root.style.setProperty('--keyboard-inset-height', `${inset}px`)
  }, insetPx)
}

async function installBridge(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const requests: boolean[] = []
    Object.assign(window, {
      SynaplanChatKeyboard: { setDocked: (enabled: boolean) => requests.push(enabled) },
      __keyboardFrameRequests: requests,
    })
  })
}

/** A dev stack with the AI literacy prompt on covers the composer with its card. */
async function dismissLiteracyCard(page: Page): Promise<void> {
  const remind = page.getByTestId('btn-literacy-remind')
  if (await remind.isVisible().catch(() => false)) {
    await remind.click()
    await expect(page.getByTestId('card-ai-literacy')).toBeHidden()
  }
}

test.describe('Native keyboard shrink — chat', () => {
  test.beforeEach(async ({ page }) => {
    await installBridge(page)
    await openApp(page)
    await dismissLiteracyCard(page)
  })

  test('@ci the composer meets the keyboard, header and drawer stay put', async ({ page }) => {
    const toggleTopBefore = await topOf(page, selectors.nav.mobileDrawerToggle)
    expect(await bottomOf(page, selectors.chat.composer)).toBe(FULL_HEIGHT)

    await page.locator(selectors.chat.textInput).click()
    // Focusing a field asks the shell for live keyboard frames.
    await expect
      .poll(() =>
        page.evaluate(
          () => (window as { __keyboardFrameRequests?: boolean[] }).__keyboardFrameRequests
        )
      )
      .toContain(true)

    await shellReportsKeyboard(page, KEYBOARD)

    await expect.poll(() => bottomOf(page, selectors.chat.composer)).toBe(KEYBOARD_TOP)
    expect(await topOf(page, selectors.nav.mobileDrawerToggle)).toBe(toggleTopBefore)
    // The drawer sits under the content column and keeps the full height.
    const drawer = await page.locator(selectors.nav.mobileDrawer).boundingBox()
    expect(drawer && Math.round(drawer.height)).toBe(FULL_HEIGHT)

    // Tapping the mobile drawer toggle while the composer is focused blurs the
    // input (dismisses the keyboard) and opens the drawer.
    await page.locator(selectors.chat.textInput).focus()
    await expect(page.locator(selectors.chat.textInput)).toBeFocused()
    await page.locator(selectors.nav.mobileDrawerToggle).click()
    await expect(page.locator(selectors.chat.textInput)).not.toBeFocused()
    await page.locator(selectors.nav.mobileDrawerToggle).click()

    // Keyboard gone: the composer owns the bottom edge again.
    await shellReportsKeyboard(page, 0)
    await expect.poll(() => bottomOf(page, selectors.chat.composer)).toBe(FULL_HEIGHT)
  })

  test('@ci a message sent while the keyboard is up lands above the composer', async ({ page }) => {
    const input = page.locator(selectors.chat.textInput)
    await input.click()
    await shellAnimatesKeyboard(page, KEYBOARD)
    await expect.poll(() => bottomOf(page, selectors.chat.composer)).toBe(KEYBOARD_TOP)

    const text = `keyboard pin ${Date.now()}`
    await input.fill(text)
    await input.press('Enter')
    const sent = page.getByTestId('message-container').filter({ hasText: text }).first()
    await expect(sent).toBeVisible()

    // The list follows the newest message; nothing hides under the composer.
    const area = page.locator(selectors.chat.messageArea)
    await expect
      .poll(() =>
        area.evaluate((el) => el.scrollHeight - el.clientHeight - Math.round(el.scrollTop))
      )
      .toBeLessThanOrEqual(1)
    const composerTop = await topOf(page, selectors.chat.composer)
    const box = await sent.boundingBox()
    expect(box && Math.round(box.y + box.height)).toBeLessThanOrEqual(composerTop)

    // Cold start into the same chat with the keyboard opening before the
    // messages have arrived: the list must still end up pinned to the bottom.
    await page.route('**/api/v1/chats/*/messages*', async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 1200))
      await route.continue()
    })
    await page.reload()
    await dismissLiteracyCard(page)
    await input.click()
    await shellAnimatesKeyboard(page, KEYBOARD)
    await expect(sent).toBeAttached({ timeout: 20_000 })
    await expect
      .poll(() =>
        area.evaluate((el) => el.scrollHeight - el.clientHeight - Math.round(el.scrollTop))
      )
      .toBeLessThanOrEqual(1)
  })

  test('@ci a WebView that shrank above the keyboard leaves no empty band', async ({ page }) => {
    await page.locator(selectors.chat.textInput).click()
    // The shell saw the layout shrink and publishes a 0 inset for it.
    await page.setViewportSize({ width: WIDTH, height: KEYBOARD_TOP })
    await shellReportsKeyboard(page, 0)
    await page.evaluate(() => document.documentElement.classList.add('synaplan-keyboard-open'))

    await expect.poll(() => bottomOf(page, selectors.chat.composer)).toBe(KEYBOARD_TOP)
  })
})

test.describe('Native keyboard shrink — sign-in page', () => {
  test.use(LOGGED_OUT)

  test('@ci the page shrinks by the keyboard and scrolls its form', async ({ page }) => {
    await installBridge(page)
    await page.goto('/login')
    const form = page.locator(selectors.login.password)
    await expect(form).toBeVisible()
    const pageRoot = page.getByTestId('page-login')
    expect(await bottomOf(page, '[data-testid="page-login"]')).toBe(FULL_HEIGHT)

    await form.click()
    await shellReportsKeyboard(page, KEYBOARD)

    await expect.poll(() => bottomOf(page, '[data-testid="page-login"]')).toBe(KEYBOARD_TOP)
    // The page is the scroller: the form can be brought above the keyboard.
    await form.scrollIntoViewIfNeeded()
    const passwordBottom = await bottomOf(page, selectors.login.password)
    expect(passwordBottom).toBeLessThanOrEqual(KEYBOARD_TOP)
    expect(await pageRoot.evaluate((el) => getComputedStyle(el).overflowY)).toBe('auto')
  })
})
