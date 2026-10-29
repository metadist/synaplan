import type { Page } from '@playwright/test'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'

/**
 * Research the chat starts on its own. The chat stream is stubbed: the
 * sorter's verdict is a model call, and the test asserts the journey around
 * it — the reply bubble working, the report in that bubble, the steps fold,
 * Answer now, Look deeper — not the verdict.
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
const ANSWER = 'No visa for 90 days.'
const REPORT = `${ANSWER} [1]`
const THOUGHT = 'The embassy publishes the rule itself, so I read that page first.'
const WHY_PICKED = 'Official source for the entry rule.'
const FACT = 'A tourist stay of up to 90 days needs no visa.'
const QUOTE = 'visa-free for 90 days'
const SOFTENED = 'Processing takes a week.'
const CHECK_NOTE = 'Only one office names a time.'
const DRAFT_START = 'No visa for '
const DRAFT_END = '90 days.'

/** What the run's event pages say, in the order the poll reads them. */
const frames = (lines: Array<[number, string, Record<string, unknown>]>): string =>
  lines
    .map(([id, event, data]) => `id: ${id}\nevent: ${event}\ndata: ${JSON.stringify(data)}\n\n`)
    .join('')
const GATHER_FRAMES = frames([
  [1, 'plan', { points: [{ id: 'p1', title: 'Visa types' }], ceiling: 'quick' }],
  [2, 'thought', { text: THOUGHT }],
  [3, 'search', { point: 'p1', query: 'japan tourist visa', scope: 'web', results: 8 }],
  [4, 'source', { id: 's1', title: 'Embassy notice', status: 'found', why: WHY_PICKED }],
  [5, 'finding', { source: 's1', point: 'p1', fact: FACT, confidence: 'high', quote: QUOTE }],
  [6, 'source', { id: 's1', status: 'read', findings: 1 }],
])
const WRITE_FRAMES = frames([
  [7, 'writing', { section: 'Answer' }],
  [8, 'draft', { text: DRAFT_START }],
  [9, 'draft', { text: DRAFT_END }],
])
const CHECK_FRAMES = frames([
  [10, 'checking', {}],
  [11, 'verdict', { statement: SOFTENED, verdict: 'partial', note: CHECK_NOTE }],
  [12, 'check', { statements: 4, supported: 3, removed: 1, same_provider: true }],
  [13, 'complete', { outcome: { code: 'complete' } }],
])
const SOURCE = {
  title: 'Embassy notice',
  url: 'https://embassy.example/visa',
  description: 'A tourist stay is visa-free for 90 days.',
  source: 'embassy.example',
}

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
    report: status === 'complete' ? { markdown: REPORT, version: 1 } : null,
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

/**
 * The research stubs. The chat's message history is stubbed too: once the
 * run is complete the reply that announced it carries the report and the
 * run id, exactly as the backend rewrites it — the stream above never
 * reaches the real backend, so nothing else would be there to reload.
 */
async function stubResearch(page: Page, mode: 'off' | 'run' | 'answer_now') {
  // The run gathers for two polls, writes on the third and ends on the fourth
  // (about five seconds), so the working reply and the draft can be looked at
  // before the report replaces them.
  let framesSent = 0
  let ended = false
  const finished = () => ended || (mode !== 'answer_now' && framesSent >= 4)
  await page.route('**/api/v1/chats/*/messages**', async (route) => {
    if (route.request().method() !== 'GET') {
      await route.fallback()
      return
    }
    const messages = finished()
      ? [
          { id: 1, direction: 'IN', text: QUESTION, timestamp: 1_790_000_000 },
          {
            id: 2,
            direction: 'OUT',
            text: REPORT,
            timestamp: 1_790_000_001,
            provider: 'research',
            effort: 'research',
            effortNotice: null,
            researchRunId: RUN_ID,
            webSearch: { query: QUESTION, resultsCount: 1 },
            searchResults: [SOURCE],
          },
        ]
      : []
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        messages,
        pagination: { offset: 0, limit: 50, total: messages.length, hasMore: false },
      }),
    })
  })
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
    const status = finished() ? 'complete' : 'gathering'
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
      mode === 'answer_now' || framesSent < 3
        ? GATHER_FRAMES
        : framesSent === 3
          ? WRITE_FRAMES
          : CHECK_FRAMES
    await route.fulfill({ status: 200, contentType: 'text/event-stream', body })
  })
  await page.route('**/api/v1/research/*/input', async (route) => {
    const body = route.request().postDataJSON() as { type?: string }
    expect(body.type).toBe('answer_now')
    ended = true
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

  test('a question that needs several sources shows the research working in the reply, then the report, and survives a reload', async ({
    page,
  }) => {
    await stubResearch(page, 'run')
    await stubChatStream(page, 'research')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()
    await chat.sendMessage(QUESTION)

    // While the run works the reply shows the run as phases, not the fallback
    // sentence: the planning folded to a line, searching open with the thing
    // happening now, writing and checking still to come. The stub sends no
    // brief, so the run has four phases and is in the second.
    const bubble = chat.conversationBubbles().last()
    const turn = bubble.locator(selectors.research.turn)
    await expect(turn).toBeVisible()
    await expect(bubble.locator(selectors.research.working)).toContainText('Researching')
    await expect(bubble.locator(selectors.research.stepOf)).toContainText('Step 2 of 4')
    await expect(bubble.locator(selectors.research.phase('plan'))).toHaveAttribute(
      'data-state',
      'done'
    )
    await expect(
      bubble.locator(selectors.research.phase('plan')).locator(selectors.research.phaseSummary)
    ).toContainText('1 point')
    await expect(bubble.locator(selectors.research.phase('gather'))).toHaveAttribute(
      'data-state',
      'active'
    )
    // The open phase reads what the model itself said: its sentence before the
    // search, why it picked the page, and what the page said in its own words.
    const gatherOpen = bubble.locator(selectors.research.phase('gather'))
    await expect(gatherOpen).toContainText(THOUGHT)
    await expect(gatherOpen.locator(selectors.research.stepAside).first()).toContainText(WHY_PICKED)
    await expect(bubble.locator(selectors.research.activity)).toContainText(FACT)
    await expect(bubble.locator(selectors.research.activity)).toContainText(QUOTE)
    await expect(gatherOpen).toContainText('1 finding')
    await expect(bubble.locator(selectors.research.phase('write'))).toHaveAttribute(
      'data-state',
      'upcoming'
    )
    await expect(bubble).not.toContainText('about 6 minutes')
    await expect(page.locator(selectors.research.answerNow)).toBeVisible()

    // The writer's words arrive while it writes: the draft grows under the
    // phases and says the check comes next.
    const draft = bubble.locator(selectors.research.draft)
    await expect(draft).toContainText(DRAFT_START + DRAFT_END)
    await expect(bubble.locator(selectors.research.draftNote)).toContainText('Draft of the answer')

    // Once it is done, the report is the reply and one line stays below it.
    // Opening it lists the phases folded; each unfolds on a click.
    const done = chat.conversationBubbles().last().locator(selectors.research.turn)
    await expect(done).toHaveAttribute('data-status', 'complete', { timeout: 15_000 })
    await expect(done.locator(selectors.research.draft)).toHaveCount(0)
    await expect(chat.conversationBubbles().last()).toContainText(ANSWER)
    await expect(done.locator(selectors.research.summary)).toContainText('sources read')
    await expect(page.locator(selectors.research.answerNow)).toHaveCount(0)
    await done.locator(selectors.research.stepsToggle).click()
    // The stub sends no brief, so that phase is left out; the four it entered stay.
    await expect(done.locator(selectors.research.phase('understand'))).toHaveCount(0)
    await expect(done.locator(selectors.research.phase('write'))).toHaveAttribute(
      'data-state',
      'done'
    )
    const gather = done.locator(selectors.research.phase('gather'))
    await expect(gather.locator(selectors.research.phaseSummary)).toContainText('1 source read')
    await expect(gather.locator(selectors.research.steps)).toHaveCount(0)
    await gather.locator(selectors.research.phaseToggle).click()
    const steps = gather.locator(selectors.research.steps)
    await expect(steps).toContainText(THOUGHT)
    await expect(steps).toContainText('japan tourist visa')
    await expect(steps).toContainText('Embassy notice')
    const check = done.locator(selectors.research.phase('check'))
    await expect(check.locator(selectors.research.phaseSummary)).toContainText('3/4')
    await expect(check.locator(selectors.research.phaseSummary)).toContainText(
      '1 left out of the answer'
    )
    await expect(check.locator(selectors.research.phaseSummary)).toContainText('1 softened')
    await check.locator(selectors.research.phaseToggle).click()
    await expect(check.locator(selectors.research.steps)).toContainText('same provider')
    await expect(check.locator(selectors.research.steps)).toContainText('Softened')
    await expect(check.locator(selectors.research.steps)).toContainText(SOFTENED)
    await expect(check.locator(selectors.research.stepAside)).toContainText(CHECK_NOTE)

    // The citation is a numbered badge that leads to the source card, and the
    // fold replaces the "Web Search" badge.
    await page.reload()
    const reloaded = page.locator(selectors.research.turn)
    await expect(reloaded).toHaveAttribute('data-status', 'complete')
    await expect(reloaded.locator(selectors.research.summary)).toBeVisible()
    const text = page.locator(selectors.chat.messageText).last()
    await expect(text).toContainText(ANSWER)
    await expect(text.locator('a.source-ref')).toHaveText('1')
    const last = chat.conversationBubbles().last()
    await expect(last).toContainText('embassy.example')
    await expect(last).not.toContainText('Web Search')
  })

  test('Answer now is the primary action and says what it does right now', async ({ page }) => {
    await stubResearch(page, 'answer_now')
    await stubChatStream(page, 'research')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()
    await chat.sendMessage(QUESTION)

    const turn = page.locator(selectors.research.turn)
    await expect(turn).toBeVisible()
    await expect(page.locator(selectors.research.answerNowConsequence)).toContainText(
      'from what was already read'
    )
    await expect(page.locator(selectors.research.stop)).toHaveCount(0)
    await expect(page.locator(selectors.chat.sendBtn)).toHaveAttribute('aria-label', 'Stop')
    await expect(page.locator('[data-testid="btn-chat-live-voice"]')).toHaveCount(0)
    await page.locator(selectors.research.answerNow).click()

    await expect(page.locator(selectors.research.turn)).toHaveAttribute('data-status', 'complete')
    await expect(page.locator(selectors.chat.messageText).last()).toContainText(ANSWER)
  })

  test('Look deeper under a direct answer starts the research the chat skipped in a new reply', async ({
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
    await expect(page.locator(selectors.research.lookDeeperSection)).not.toContainText('minutes')
    await lookDeeper.click()

    const turn = chat.conversationBubbles().last().locator(selectors.research.turn)
    await expect(turn).toBeVisible()
    // The stubbed run ends on its fourth poll, about five seconds in.
    await expect(page.locator(selectors.research.turn)).toHaveAttribute('data-status', 'complete', {
      timeout: 15_000,
    })
    await expect(page.locator(selectors.chat.messageText).last()).toContainText(ANSWER)
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

  test('the working reply fits a 320 px width in the dark theme', async ({ page }) => {
    await stubResearch(page, 'answer_now')
    await stubChatStream(page, 'research')
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()
    await chat.sendMessage(QUESTION)

    const turn = page.locator(selectors.research.turn)
    await expect(turn).toBeVisible()
    await page.setViewportSize({ width: 320, height: 700 })
    await page.evaluate(() => document.documentElement.classList.add('dark'))
    await expect(turn).toBeVisible()
    await expect(page.locator(selectors.research.answerNow)).toBeVisible()
    const box = await turn.boundingBox()
    expect(box).not.toBeNull()
    expect(box!.x).toBeGreaterThanOrEqual(0)
    expect(box!.x + box!.width).toBeLessThanOrEqual(321)
    await expect(page.locator('html')).toHaveClass(/design-v2/)
    await expect(page.locator('html')).toHaveClass(/dark/)
  })

  test('fine-tuning under /ai/models is collapsed by default and offers reading and fact-checking overrides', async ({
    page,
  }) => {
    await openApp(page)
    await page.goto('/ai/models')

    const section = page.locator('[data-testid="section-routing-planner-model"]')
    await expect(section).toBeVisible()
    const toggle = section.locator('[data-testid="btn-toggle-agent-models"]')
    await expect(toggle).toHaveAttribute('aria-expanded', 'false')
    await expect(section.locator('[data-testid="badge-agent-models-summary"]')).toContainText(
      'All automatic'
    )

    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-expanded', 'true')
    await expect(section.locator('[data-testid="select-planner-model"]')).toBeVisible()
    await expect(section.locator('[data-testid="select-research-reading-model"]')).toBeVisible()
    await expect(section.locator('[data-testid="select-research-checking-model"]')).toBeVisible()
    await expect(section.locator('[data-testid="text-research-lead-note"]')).toContainText(
      'always use the model selected in your chat'
    )
  })
})
