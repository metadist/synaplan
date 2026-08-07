/**
 * Captures the App Store gallery screenshots.
 *
 * Not part of the E2E suite — a one-off tool, run by hand against a stack that
 * holds presentable content: a knowledge folder with real documents, a widget
 * with real sessions, and a chat model that is not the test provider.
 *
 *   SCREENSHOT_WIDGET_ID=wdg_… node tests/e2e/capture-store-screenshots.js
 *
 * Umbrel wants screenshots in the submission pull request and hosts the final
 * gallery assets itself, so the output is written outside the repository.
 */
import { chromium } from '@playwright/test'
import { mkdir } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const BASE_URL = process.env.SCREENSHOT_BASE_URL ?? 'http://localhost:5173'
const EMAIL = process.env.SCREENSHOT_EMAIL ?? 'demo@synaplan.com'
const PASSWORD = process.env.SCREENSHOT_PASSWORD ?? 'demo123'
const OUT_DIR = process.env.SCREENSHOT_OUT_DIR ?? join(tmpdir(), 'synaplan-store-screenshots')

// 16:10 at 2x. Wide enough that the sidebar and the main pane both read at
// gallery size, which a 16:9 crop of the same page does not manage.
const VIEWPORT = { width: 1600, height: 1000 }

// Asked through the composer rather than seeded through the API: the chat view
// opens on whichever conversation was last active, so driving the UI is the
// only way to be sure the shot shows this exchange and not an older one.
const CHAT_TURNS = [
  'How many days per week can I work from home, and what equipment do I get?',
  'What safety training do I need before my first warehouse shift?',
]

// Grounding the conversation in the uploaded documents is the point of the
// first shot, and the model only sees them once a knowledge folder is picked.
const KNOWLEDGE_FOLDER = process.env.SCREENSHOT_KNOWLEDGE_FOLDER ?? 'company-handbook'

// Widget ids are generated per install, so the conversation shot needs the id
// of a widget that actually holds sessions on the stack being captured.
const WIDGET_ID = process.env.SCREENSHOT_WIDGET_ID ?? ''

const SHOTS = [
  {
    name: '1-chat',
    path: '/',
    settle: 2000,
    prepare: async (page) => {
      await page.click('[data-testid="btn-sidebar-v2-new-chat"]')
      await sleep(1000)

      // On a desktop viewport the plus panel renders the knowledge picker
      // directly; the compact chip that opens it is a mobile-only affordance.
      await page.click('[data-testid="btn-chat-plus"]')
      await sleep(500)
      await page.click('[data-testid="btn-knowledge-folder"]')
      await sleep(500)
      await page
        .locator(`[data-testid="opt-knowledge-folder"]:has-text("${KNOWLEDGE_FOLDER}")`)
        .first()
        .click()
      await sleep(1000)

      for (const turn of CHAT_TURNS) {
        await page.fill('[data-testid="input-chat-message"]', turn)
        await page.press('[data-testid="input-chat-message"]', 'Enter')

        // The send button doubles as the stop button while a response streams,
        // which makes its label the most direct signal that a turn is finished.
        const sendButton = page.locator('[data-testid="btn-chat-send"]')
        await sendButton.and(page.locator('[aria-label="Stop"]')).waitFor({ timeout: 60_000 })
        await sendButton
          .and(page.locator('[aria-label="Stop"]'))
          .waitFor({ state: 'hidden', timeout: 180_000 })
        await sleep(1500)
      }

      // Scroll the exchange into view from the top so the first question is
      // part of the frame instead of only the tail of the last answer.
      await page.locator('[data-testid="section-messages"]').evaluate((el) => {
        el.scrollTop = 0
      })
    },
  },
  {
    name: '2-document-search',
    path: '/files/search',
    settle: 1500,
    // A search view with an empty result list says nothing about the feature,
    // so the query is actually run before the shot is taken.
    prepare: async (page) => {
      await page.fill(
        '[data-testid="input-query"]',
        'What safety training do warehouse staff need?'
      )
      await page.click('[data-testid="btn-search"]')
      await page.waitForSelector('[data-testid="section-results"]', { timeout: 30_000 })

      // The success toast covers the top-right corner; let it expire so the
      // frame shows the result list rather than a transient notification.
      await sleep(6000)
    },
  },
  { name: '3-chat-widgets', path: '/channels/widgets', settle: 2500 },
  {
    name: '4-widget-conversations',
    path: `/channels/widgets/${WIDGET_ID}/chats`,
    settle: 3500,
    prepare: async (page) => {
      // Collapse the AI summary panel — it starts open on an empty analysis and
      // would take a third of the frame to say "nothing here yet".
      await page.getByRole('button', { name: 'Summary', exact: true }).click()
      await sleep(500)

      // Open a conversation so the centre pane shows the actual exchange
      // instead of the "select a conversation" placeholder.
      await page.locator('[data-testid="item-widget-session"]').last().click()
      await sleep(2500)
    },
  },
  { name: '5-memories', path: '/memories', settle: 2000 },
]

// The sidebar version pill reads "dev" from a source build. A packaged install
// shows a real version there, so leaving the dev value in a store gallery would
// misrepresent what a user gets.
const HIDE_DEV_ONLY_CHROME = `
  [data-testid="section-sidebar-v2-version"] { display: none !important; }
`

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

const login = async (page) => {
  await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' })

  // The dev stack can be configured to sign in automatically; only fill the
  // form when it actually rendered.
  const emailField = page.locator('input[type="email"], input[name="email"]').first()
  if (await emailField.isVisible().catch(() => false)) {
    await emailField.fill(EMAIL)
    await page.locator('input[type="password"]').first().fill(PASSWORD)
    await page.locator('button[type="submit"]').first().click()
  }

  await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30_000 })
  await page.waitForLoadState('networkidle').catch(() => {})
}

const main = async () => {
  await mkdir(OUT_DIR, { recursive: true })

  const browser = await chromium.launch()
  const context = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: 2,
    colorScheme: 'light',
  })
  const page = await context.newPage()

  await login(page)

  for (const shot of SHOTS) {
    if ('' === WIDGET_ID && shot.path.includes('/channels/widgets/')) {
      console.log(`${shot.name} -> skipped, set SCREENSHOT_WIDGET_ID to capture it`)
      continue
    }

    await page.goto(`${BASE_URL}${shot.path}`, { waitUntil: 'networkidle' })
    await page.addStyleTag({ content: HIDE_DEV_ONLY_CHROME })
    await sleep(shot.settle)

    if (shot.prepare) {
      await shot.prepare(page)
      await sleep(1500)
    }

    const file = join(OUT_DIR, `${shot.name}.png`)
    await page.screenshot({ path: file })
    console.log(`${shot.name} -> ${file}`)
  }

  await browser.close()
  console.log(`\nAll screenshots in ${OUT_DIR}`)
}

await main()
