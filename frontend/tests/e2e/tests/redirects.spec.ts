/**
 * Transitional-redirect watchdog — §4.6 of the navigation IA cleanup,
 * Sprint A of 20260914-navigation-consolidation and the 2026-10 UX overhaul
 * (_devextras/planning/20261009-ux-overhaul).
 *
 * Every legacy path must land on its canonical successor (bookmarks, docs,
 * support articles). The redirects stay for at least 2 releases; when they
 * are removed this spec is the tripwire that forces a conscious decision.
 *
 * A moved route adds a row in the same PR that retires the old path.
 *
 * Successors that need an admin session or arm a chat tool live as dedicated
 * tests below, not in the path-equality loop.
 */
import { test, expect } from '../test-setup'
import { login, openApp } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'
import { isModuleConfigured } from '../helpers/features'

const TOPICS = '/ai/models?tab=topics'

/** old path → canonical path for a regular user */
const redirects: Array<[string, string]> = [
  ['/rag', '/files/search'],
  ['/config', '/apps'],
  ['/config/inbound', '/apps'],
  ['/config/ai-models', '/ai/models'],
  ['/config/task-prompts', TOPICS],
  ['/config/sorting-prompt', '/ai/models'],
  ['/config/api-keys', '/apps/api'],
  ['/config/api-documentation', '/apps/api/docs'],
  ['/tools', '/apps'],
  ['/tools/chat-widget', '/channels/widgets'],
  ['/tools/chat-widget/live-support', '/channels/widgets?tab=conversations'],
  ['/tools/chat-widget/42', '/channels/widgets/42'],
  ['/tools/chat-widget/42/chats', '/channels/widgets/42/chats'],
  ['/channels', '/apps'],
  ['/channels/connections', '/apps/connected'],
  ['/channels/platform-links', '/apps/connected'],
  ['/channels/api', '/apps/api'],
  ['/channels/api/docs', '/apps/api/docs'],
  ['/ai/instructions', TOPICS],
  ['/ai/task-prompts', TOPICS],
  ['/ai/routing', '/ai/models'],
]

const endsWith = (path: string): RegExp =>
  new RegExp(`${path.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}$`)

test.describe('Redirects: legacy URLs land on canonical paths (§4.6)', () => {
  test('@ci every legacy path redirects to its successor', async ({ page }) => {
    // About twenty full page boots in one test: every legacy bookmark reloads
    // the whole SPA (~4s in CI), so the loop needs ~95s end to end — past the
    // 60s default, which killed healthy runs mid-boot on whatever row was last
    // (blank page, legacy URL, "Test timeout exceeded"). A genuinely broken
    // redirect still fails fast: each row below asserts on its own STANDARD
    // budget, so only the sum gets headroom here.
    test.setTimeout(180_000)
    await openApp(page)

    for (const [oldPath, newPath] of redirects) {
      await test.step(`${oldPath} → ${newPath}`, async () => {
        // Resolve on document commit, not `load`: the app boots and immediately
        // redirects to the canonical path, which aborts a `load`-gated goto
        // (NS_BINDING_ABORTED on firefox). toHaveURL below is the real assertion.
        await page.goto(oldPath, { waitUntil: 'commit' })
        await expect(page, `${oldPath} should land on ${newPath}`).toHaveURL(endsWith(newPath), {
          timeout: TIMEOUTS.STANDARD,
        })
      })
    }
  })

  test('@ci redirect preserves the query string', async ({ page }) => {
    await openApp(page)
    // A topic bookmark keeps the topic it pointed at.
    await page.goto('/ai/task-prompts?topic=mail', { waitUntil: 'commit' })
    await expect(page).toHaveURL(/\/ai\/models\?topic=mail&tab=topics$/, {
      timeout: TIMEOUTS.STANDARD,
    })
  })

  test('@ci /ai/summarizer arms Summarize a document in chat', async ({ page }) => {
    await openApp(page)
    await page.goto('/ai/summarizer', { waitUntil: 'commit' })
    await expect(page.locator('[data-testid="summarize-options"]')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect
      .poll(
        () => {
          const url = new URL(page.url())
          return url.pathname === '/' && !url.searchParams.has('tool')
        },
        { timeout: TIMEOUTS.STANDARD }
      )
      .toBe(true)
  })

  test('@ci /tools/doc-summary arms Summarize a document in chat', async ({ page }) => {
    await openApp(page)
    await page.goto('/tools/doc-summary', { waitUntil: 'commit' })
    await expect(page.locator('[data-testid="summarize-options"]')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect
      .poll(
        () => {
          const url = new URL(page.url())
          return url.pathname === '/' && !url.searchParams.has('tool')
        },
        { timeout: TIMEOUTS.STANDARD }
      )
      .toBe(true)
  })

  test('@ci /ai/providers/higgsfield lands on its app page', async ({ page }) => {
    await openApp(page)
    test.skip(
      !(await isModuleConfigured(page.request, 'higgsfield')),
      'Higgsfield is off: the app and its page are absent'
    )
    await page.goto('/ai/providers/higgsfield', { waitUntil: 'commit' })
    await expect(page).toHaveURL(/\/apps\/higgsfield$/, {
      timeout: TIMEOUTS.STANDARD,
    })
  })

  // `/admin` is admin-only; the worker storageState is a regular user, so this
  // bookmark cannot live in the generic loop above (that user is sent home).
  test('@ci /statistics#chats lands on All chats', async ({ page }) => {
    await openApp(page)
    await page.goto('/statistics#chats', { waitUntil: 'commit' })
    await expect(page, '/statistics#chats should land on /chats').toHaveURL(/\/chats$/, {
      timeout: TIMEOUTS.STANDARD,
    })
  })

  test('@ci /admin?tab=users lands on People for an admin', async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())
    await page.goto('/admin?tab=users', { waitUntil: 'commit' })
    await expect(page, '/admin?tab=users should land on /admin/people').toHaveURL(
      /\/admin\/people$/,
      { timeout: TIMEOUTS.STANDARD }
    )
  })

  // Operate is grouped by topic: every bookmark from the old layout lands on
  // the page and tab that now shows the same thing.
  test('@ci Operate bookmarks land on their topic page', async ({ page }) => {
    test.setTimeout(90_000)
    await login(page, CREDENTIALS.getAdminCredentials())

    const operateRedirects: Array<[string, RegExp]> = [
      ['/admin/model-status', /\/admin\/setup\?tab=health$/],
      ['/admin?tab=prompts', /\/admin\/setup\?tab=prompts$/],
      ['/admin?tab=moderation', /\/admin\/people\?tab=moderation$/],
      ['/admin/setup?tab=models', /\/admin\/setup\?tab=providers$/],
      ['/admin/setup?tab=extraction', /\/admin\/setup\?tab=documents$/],
      ['/admin/setup?tab=rerank', /\/admin\/setup\?tab=search$/],
      ['/admin/setup?tab=web-search', /\/admin\/config\?tab=web_search$/],
      ['/admin/config?tab=vectordb', /\/admin\/setup\?tab=search$/],
      [
        '/admin/config?tab=processing&section=docling',
        /\/admin\/setup\?tab=documents&section=docling$/,
      ],
      [
        '/admin/config?tab=processing&section=compute',
        /\/admin\/config\?tab=tools&section=compute$/,
      ],
      ['/ai/routing', /\/admin\/setup\?tab=behavior$/],
      ['/ai/models?tab=runs', /\/admin\/setup\?tab=catalog$/],
      ['/files/vectors', /\/admin\/vectors$/],
    ]

    for (const [oldPath, expected] of operateRedirects) {
      await test.step(`${oldPath} → ${expected}`, async () => {
        await page.goto(oldPath, { waitUntil: 'commit' })
        await expect(page, `${oldPath} should land on ${expected}`).toHaveURL(expected, {
          timeout: TIMEOUTS.STANDARD,
        })
      })
    }
  })
})
