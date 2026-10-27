import type { Page } from '@playwright/test'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'

const CAPABILITIES = {
  enabled: true,
  research_protocol: 1,
  depths: ['quick', 'standard', 'thorough'],
  web_search_available: true,
  web_search_provider: { id: 'stub', self_hosted: true, country: null },
  knowledge_available: false,
  read_documents: false,
  max_active_thorough: 1,
  limits: { standard_per_day: null, thorough_per_day: null },
}

const RUN_ID = 'run-e2e-1'

function showBody(status: string) {
  return {
    id: RUN_ID,
    research_protocol: 1,
    status,
    depth: 'standard',
    question: 'When do the ferries run?',
    parent: null,
    follow_up: null,
    can_add_to_report: false,
    stop_requested: status === 'stopped',
    write_requested: false,
    queued_since: null,
    questions: [],
    plan: { points: [{ id: 'p1', title: 'Delivery times', status: 'answered' }] },
    sources: [{ id: 's1', title: 'Harbour notice', status: 'read' }],
    evidence: [{ source: 's1', quotes: [{ text: 'The last boat is at 18:00.' }] }],
    report: status === 'complete' ? { markdown: 'Ferries end at 18:00.', version: 1 } : null,
    check: { statements: 4, supported: 3, removed: 1 },
    outcome: null,
    models: {},
    budget: { searches: [0, 1], sources_read: [0, 1], rounds: [0, 1], seconds: [0, 1] },
    cost: { estimate: 0, spent: 0, currency: 'USD' },
    created_at: '2026-10-27T12:00:00+00:00',
    updated_at: '2026-10-27T12:00:00+00:00',
  }
}

async function stubResearch(page: Page, mode: 'off' | 'run' | 'stop') {
  let framesSent = 0
  await page.route('**/api/v1/research/*', async (route) => {
    const url = route.request().url()
    if (url.includes('/events') || url.includes('/cancel') || url.endsWith('/capabilities')) {
      await route.fallback()
      return
    }
    if (route.request().method() !== 'GET') {
      await route.fallback()
      return
    }
    const status = mode === 'stop' ? 'gathering' : framesSent > 1 ? 'complete' : 'gathering'
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(showBody(status)),
    })
  })
  await page.route('**/api/v1/research', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback()
      return
    }
    const body = route.request().postDataJSON() as { chat_id?: number; question?: string }
    expect(body.chat_id).toEqual(expect.any(Number))
    expect(body.question).toBe('When do the ferries run?')
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({
        id: RUN_ID,
        status: 'briefing',
        research_protocol: 1,
        events: [],
      }),
    })
  })
  await page.route('**/api/v1/research/*/events**', async (route) => {
    framesSent += 1
    const body =
      mode === 'stop' || framesSent === 1
        ? 'id: 1\nevent: plan\ndata: {"points":[{"id":"p1","title":"Delivery times"}]}\n\nid: 2\nevent: source\ndata: {"id":"s1","status":"read"}\n\n'
        : 'id: 3\nevent: check\ndata: {"statements":4,"supported":3,"removed":1}\n\nid: 4\nevent: complete\ndata: {}\n\n'
    await route.fulfill({ status: 200, contentType: 'text/event-stream', body })
  })
  await page.route('**/api/v1/research/*/cancel', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(showBody('stopped')),
    })
  })
  await page.route('**/api/v1/research/capabilities**', async (route) => {
    if (mode === 'off') {
      await route.fulfill({
        status: 404,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'not_found', message: 'Research is off.' }),
      })
      return
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(CAPABILITIES),
    })
  })
}

async function openResearch(page: Page) {
  await page.locator(selectors.chat.plusToggle).click()
  await page.locator(selectors.chat.toolsToggle).click()
  await page.locator(selectors.research.menu).click()
}

test.describe('@ci @research Research in the chat', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('language', 'en'))
  })

  test('the menu entry is absent when research is off', async ({ page }) => {
    await stubResearch(page, 'off')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()

    await page.locator(selectors.chat.plusToggle).click()
    await page.locator(selectors.chat.toolsToggle).click()

    await expect(page.locator(selectors.research.menu)).toHaveCount(0)
  })

  test('a stubbed run shows progress, the fact-check, and survives a reload', async ({ page }) => {
    await stubResearch(page, 'run')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()
    await openResearch(page)

    await expect(page.locator(selectors.research.continues)).toBeVisible()
    await page.locator(selectors.research.question).fill('When do the ferries run?')
    await page.locator(selectors.research.start).click()

    const card = page.locator(selectors.research.card)
    await expect(card).toBeVisible()
    await expect(page.locator(selectors.research.factCheck)).toContainText('3 of 4')
    await expect(page.locator(selectors.research.find)).toBeVisible()
    await expect(card).toContainText('The last boat is at 18:00.')

    await page.reload()
    await expect(page.locator(selectors.research.factCheck)).toBeVisible()
    await expect(page.locator(selectors.research.find)).toBeVisible()
  })

  test('stop keeps the card and says what did not continue', async ({ page }) => {
    await stubResearch(page, 'stop')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()
    await openResearch(page)
    await page.locator(selectors.research.question).fill('When do the ferries run?')
    await page.locator(selectors.research.start).click()
    await page.locator(selectors.research.stop).click()

    await expect(page.locator(selectors.research.card)).toHaveAttribute('data-status', 'stopped')
    await expect(page.locator(selectors.research.card)).toContainText(
      'Nothing further was searched'
    )
  })

  test('the card fits a 320 px width in the dark theme', async ({ page }) => {
    await stubResearch(page, 'run')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()
    await openResearch(page)
    await page.locator(selectors.research.question).fill('When do the ferries run?')
    await page.locator(selectors.research.start).click()

    const card = page.locator(selectors.research.card)
    await expect(card).toBeVisible()
    await page.setViewportSize({ width: 320, height: 700 })
    await page.evaluate(() => document.documentElement.classList.add('dark'))
    await expect(card).toBeVisible()
    const box = await card.boundingBox()
    expect(box).not.toBeNull()
    expect(box!.x).toBeGreaterThanOrEqual(0)
    expect(box!.x + box!.width).toBeLessThanOrEqual(321)
    await expect(page.locator('html')).toHaveClass(/design-v2/)
    await expect(page.locator('html')).toHaveClass(/dark/)
  })
})
