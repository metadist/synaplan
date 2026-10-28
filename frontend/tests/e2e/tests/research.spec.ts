import type { Page } from '@playwright/test'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'

/**
 * Research the chat starts on its own. The chat stream is stubbed: the
 * sorter's verdict is a model call, and the test asserts the journey around
 * it — the notice sentence, the card, Answer now, Look deeper — not the verdict.
 */

const CAPABILITIES = {
  enabled: true,
  automatic: true,
  auto_limits: { minutes: 6, cost_cap: 0.5 },
  research_protocol: 1,
  depths: ['quick', 'standard', 'thorough', 'auto'],
  web_search_available: true,
  web_search_provider: { id: 'stub', self_hosted: true, country: null },
  knowledge_available: false,
  read_documents: false,
  max_active_thorough: 1,
  limits: { standard_per_day: null, thorough_per_day: null },
}

const RUN_ID = 'run-e2e-1'
const QUESTION = 'Which visa do I need for Japan and how long does it take?'
const NOTICE =
  'I am looking through several sources for this — about 6 minutes. The question has several parts.'
const DIRECT_ANSWER = 'Most tourists need no visa for up to 90 days.'

function showBody(status: string, outcome: { code: string } | null = null) {
  return {
    id: RUN_ID,
    research_protocol: 1,
    status,
    depth: 'auto',
    ceiling: 'quick',
    question: QUESTION,
    parent: null,
    follow_up: null,
    can_add_to_report: false,
    stop_requested: status === 'stopped',
    write_requested: false,
    queued_since: null,
    questions: [],
    plan: { points: [{ id: 'p1', title: 'Visa types', status: 'answered' }] },
    sources: [{ id: 's1', title: 'Embassy notice', status: 'read' }],
    evidence: [{ source: 's1', quotes: [{ text: 'A tourist stay is visa-free for 90 days.' }] }],
    report: status === 'complete' ? { markdown: 'No visa for 90 days.', version: 1 } : null,
    check: { statements: 4, supported: 3, removed: 1 },
    outcome: outcome ? { ...outcome, params: {}, text: '' } : null,
    models: {},
    budget: { searches: [0, 1], sources_read: [0, 1], rounds: [0, 1], seconds: [0, 1] },
    cost: { estimate: 0, spent: 0, currency: 'USD' },
    created_at: '2026-10-28T12:00:00+00:00',
    updated_at: '2026-10-28T12:00:00+00:00',
  }
}

function sse(events: Array<Record<string, unknown>>): string {
  return events.map((event) => `data: ${JSON.stringify(event)}\n\n`).join('')
}

/** The chat stream: either the chat starts a research, or it answers directly as a lookup. */
async function stubChatStream(page: Page, mode: 'research' | 'lookup') {
  await page.route('**/api/v1/messages/stream', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback()
      return
    }
    const timestamp = Date.now() / 1000
    const events =
      mode === 'research'
        ? [
            {
              status: 'research_started',
              message: 'Research started',
              metadata: { run_id: RUN_ID, minutes: 6, why: 'several_parts' },
              timestamp,
            },
            { status: 'data', chunk: NOTICE },
            {
              status: 'complete',
              messageId: 0,
              provider: 'research',
              model: 'research',
              topic: 'CHAT',
              language: 'en',
              effort: 'research',
              effortNotice: null,
            },
          ]
        : [
            { status: 'data', chunk: DIRECT_ANSWER },
            {
              status: 'complete',
              messageId: 0,
              provider: 'test',
              model: 'test',
              topic: 'CHAT',
              language: 'en',
              effort: 'lookup',
              effortNotice: null,
            },
          ]
    await route.fulfill({ status: 200, contentType: 'text/event-stream', body: sse(events) })
  })
}

async function stubResearch(page: Page, mode: 'off' | 'run' | 'answer_now') {
  let framesSent = 0
  await page.route('**/api/v1/research/*', async (route) => {
    const url = route.request().url()
    if (
      url.includes('/events') ||
      url.includes('/cancel') ||
      url.includes('/input') ||
      url.endsWith('/capabilities')
    ) {
      await route.fallback()
      return
    }
    if (route.request().method() !== 'GET') {
      await route.fallback()
      return
    }
    const status = mode === 'answer_now' ? 'gathering' : framesSent > 1 ? 'complete' : 'gathering'
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
    const body = route.request().postDataJSON() as {
      chat_id?: number
      question?: string
      depth?: string
      hint?: string
    }
    expect(body.chat_id).toEqual(expect.any(Number))
    expect(body.question).toBe(QUESTION)
    expect(body.depth).toBe('auto')
    expect(body.hint).toBe('deeper')
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({ id: RUN_ID, status: 'briefing', research_protocol: 1, events: [] }),
    })
  })
  await page.route('**/api/v1/research/*/events**', async (route) => {
    framesSent += 1
    const body =
      mode === 'answer_now' || framesSent === 1
        ? 'id: 1\nevent: plan\ndata: {"points":[{"id":"p1","title":"Visa types"}],"ceiling":"quick"}\n\nid: 2\nevent: source\ndata: {"id":"s1","status":"read"}\n\n'
        : 'id: 3\nevent: check\ndata: {"statements":4,"supported":3,"removed":1}\n\nid: 4\nevent: complete\ndata: {}\n\n'
    await route.fulfill({ status: 200, contentType: 'text/event-stream', body })
  })
  await page.route('**/api/v1/research/*/input', async (route) => {
    const body = route.request().postDataJSON() as { type?: string }
    expect(body.type).toBe('answer_now')
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(showBody('complete', { code: 'complete' })),
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

test.describe('@ci @research Research the chat starts on its own', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('language', 'en'))
  })

  test('the Tools menu has no research entry, on or off', async ({ page }) => {
    await stubResearch(page, 'run')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()

    await page.locator(selectors.chat.plusToggle).click()
    await page.locator(selectors.chat.toolsToggle).click()

    await expect(page.locator(selectors.research.menu)).toHaveCount(0)
  })

  test('a question that needs several sources gets the notice, the card, the fact-check, and survives a reload', async ({
    page,
  }) => {
    await stubResearch(page, 'run')
    await stubChatStream(page, 'research')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()

    const previousCount = await chat.sendMessage(QUESTION)
    const answer = await chat.waitForAnswer(previousCount)
    expect(answer).toContain('about 6 minutes')

    const card = page.locator(selectors.research.card)
    await expect(card).toBeVisible()
    await expect(card).toContainText(QUESTION)
    await expect(page.locator(selectors.research.ceiling)).toContainText('a short look')
    await expect(page.locator(selectors.research.factCheck)).toContainText('3 of 4')
    await expect(page.locator(selectors.research.find)).toBeVisible()

    await page.reload()
    await expect(page.locator(selectors.research.factCheck)).toBeVisible()
    await expect(page.locator(selectors.research.find)).toBeVisible()
  })

  test('Answer now is the primary action and says what it does right now', async ({ page }) => {
    await stubResearch(page, 'answer_now')
    await stubChatStream(page, 'research')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()

    const previousCount = await chat.sendMessage(QUESTION)
    await chat.waitForAnswer(previousCount)

    const card = page.locator(selectors.research.card)
    await expect(card).toBeVisible()
    await expect(page.locator(selectors.research.answerNowConsequence)).toContainText(
      'from what was already read'
    )
    await expect(page.locator(selectors.research.stop)).toBeVisible()
    await page.locator(selectors.research.answerNow).click()

    await expect(card).toHaveAttribute('data-status', 'complete')
    await expect(page.locator(selectors.research.find)).toBeVisible()
  })

  test('Look deeper under a direct answer starts the research the chat skipped', async ({
    page,
  }) => {
    await stubResearch(page, 'run')
    await stubChatStream(page, 'lookup')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()

    const previousCount = await chat.sendMessage(QUESTION)
    const answer = await chat.waitForAnswer(previousCount)
    expect(answer.toLowerCase()).toContain(DIRECT_ANSWER.toLowerCase())

    const lookDeeper = page.locator(selectors.research.lookDeeper)
    await expect(lookDeeper).toBeVisible()
    await expect(page.locator(selectors.research.lookDeeperSection)).toContainText(
      'About 6 minutes'
    )
    await lookDeeper.click()

    const card = page.locator(selectors.research.card)
    await expect(card).toBeVisible()
    await expect(card).toContainText(QUESTION)
    await expect(page.locator(selectors.research.factCheck)).toContainText('3 of 4')
  })

  test('Look deeper is absent when automatic research is off', async ({ page }) => {
    await stubResearch(page, 'off')
    await stubChatStream(page, 'lookup')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()

    const previousCount = await chat.sendMessage(QUESTION)
    await chat.waitForAnswer(previousCount)

    await expect(page.locator(selectors.research.lookDeeper)).toHaveCount(0)
  })

  test('the card fits a 320 px width in the dark theme', async ({ page }) => {
    await stubResearch(page, 'answer_now')
    await stubChatStream(page, 'research')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()

    const previousCount = await chat.sendMessage(QUESTION)
    await chat.waitForAnswer(previousCount)

    const card = page.locator(selectors.research.card)
    await expect(card).toBeVisible()
    await page.setViewportSize({ width: 320, height: 700 })
    await page.evaluate(() => document.documentElement.classList.add('dark'))
    await expect(card).toBeVisible()
    await expect(page.locator(selectors.research.answerNow)).toBeVisible()
    const box = await card.boundingBox()
    expect(box).not.toBeNull()
    expect(box!.x).toBeGreaterThanOrEqual(0)
    expect(box!.x + box!.width).toBeLessThanOrEqual(321)
    await expect(page.locator('html')).toHaveClass(/design-v2/)
    await expect(page.locator('html')).toHaveClass(/dark/)
  })
})
