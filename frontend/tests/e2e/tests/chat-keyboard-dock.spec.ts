/**
 * Chat keyboard dock — the composer meets the native keyboard, no empty band.
 *
 * In the app, tapping the composer docks the chat to the keyboard: the shell
 * writes the keyboard's overlap into `--keyboard-inset-height` and the chat
 * page rises with it. Some Android WebViews also shrink above the keyboard.
 * Rising by the full overlap on top of that pushed the whole chat up and left
 * an empty band above the keyboard (synaplan-apps#29, back with the dock).
 *
 * The browser stands in for the shell: a SynaplanChatKeyboard bridge, the
 * overlap on <html>, and a viewport resize for the WebView shrink. The
 * assertion is where a user looks: the composer's bottom edge against the
 * keyboard's top edge.
 */
import { test, expect, type Page } from '../test-setup'
import { openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

const WIDTH = 412
const FULL_HEIGHT = 915
const KEYBOARD = 320
const KEYBOARD_TOP = FULL_HEIGHT - KEYBOARD

test.use({ viewport: { width: WIDTH, height: FULL_HEIGHT } })

async function composerBottom(page: Page): Promise<number> {
  const box = await page.locator(selectors.chat.composer).boundingBox()
  return box ? Math.round(box.y + box.height) : -1
}

async function dockComposer(page: Page): Promise<void> {
  await page.locator(selectors.chat.textInput).click()
  await expect
    .poll(() =>
      page.evaluate(() => document.documentElement.classList.contains('synaplan-keyboard-dock'))
    )
    .toBe(true)
  expect(await composerBottom(page)).toBe(FULL_HEIGHT)
}

/** The shell's show path: keyboard-open class, then the overlap on every frame. */
async function shellReportsKeyboard(page: Page, px: number): Promise<void> {
  await page.evaluate((overlap) => {
    const root = document.documentElement
    root.classList.add('synaplan-keyboard-open')
    root.style.setProperty('--keyboard-inset-height', `${overlap}px`)
  }, px)
}

test.describe('Chat keyboard dock', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      const bridge = {
        setDocked: (enabled: boolean) => {
          if (enabled) document.documentElement.classList.add('synaplan-keyboard-dock')
        },
      }
      Object.assign(window, { SynaplanChatKeyboard: bridge })
    })
    await openApp(page)
  })

  test('@ci keyboard over a full-height WebView lifts the chat by its height', async ({ page }) => {
    await dockComposer(page)

    await shellReportsKeyboard(page, KEYBOARD)

    await expect.poll(() => composerBottom(page)).toBe(KEYBOARD_TOP)
  })

  test('@ci WebView that shrinks above the keyboard leaves no empty band', async ({ page }) => {
    await dockComposer(page)

    await shellReportsKeyboard(page, KEYBOARD)
    await page.setViewportSize({ width: WIDTH, height: KEYBOARD_TOP })

    // The WebView now ends where the keyboard starts. Rising again by the
    // overlap would put the composer KEYBOARD pixels above it.
    await expect.poll(() => composerBottom(page)).toBe(KEYBOARD_TOP)
  })
})
