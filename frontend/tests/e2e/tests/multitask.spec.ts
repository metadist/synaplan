import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { TIMEOUTS } from '../config/config'

/**
 * Multi-task routing UX: a request that expands into a multi-node plan renders
 * one task card per node, each advancing through running → done.
 *
 * The message is intentionally long (> 280 chars) so it bypasses the
 * classifier fast-path and goes through AI sorting, which is what triggers the
 * planner. In the test stack the planner runs on the deterministic TestProvider,
 * which returns a summarize → translate → compose_reply plan for a
 * "summarize … and translate …" request (two text cards, no TTS).
 *
 * Single-task turns never emit a plan event, so this never affects normal chat.
 */
const MULTITASK_PROMPT =
  'Please summarize the following note for me and then translate that summary into German ' +
  'so I can share it with my colleagues in Berlin. The note is about our quarterly planning ' +
  'meeting where we discussed the product roadmap, the budget, the hiring plans, and the ' +
  'marketing strategy for the next two quarters.'

test.describe('@ci @multitask Multi-task routing', () => {
  test('a multi-node request renders task cards that complete', async ({ page, credentials }) => {
    await login(page, credentials)
    const chat = new ChatHelper(page)

    await test.step('Arrange: start a new chat', async () => {
      await chat.startNewChat()
      // Sanity: the prompt must be long enough to bypass the fast-path.
      expect(MULTITASK_PROMPT.length).toBeGreaterThan(280)
    })

    const previousCount = await chat.conversationBubbles().count()

    await test.step('Act: send a multi-task prompt', async () => {
      await page.locator(selectors.chat.textInput).fill(MULTITASK_PROMPT)
      await page.locator(selectors.chat.sendBtn).click()
    })

    const bubble = chat.conversationBubbles().nth(previousCount)
    await bubble.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

    await test.step('Assert: the task-plan bubble with per-task cards appears', async () => {
      const plan = bubble.locator('[data-testid="task-plan"]')
      await plan.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

      // Two visible task cards (summarize + translate); compose_reply is hidden.
      await bubble
        .locator('[data-testid="task-card-n1"]')
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await bubble
        .locator('[data-testid="task-card-n2"]')
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: both task cards reach the done state', async () => {
      await expect(bubble.locator('[data-testid="task-card-n1"]')).toHaveAttribute(
        'data-state',
        'done',
        { timeout: TIMEOUTS.LONG }
      )
      await expect(bubble.locator('[data-testid="task-card-n2"]')).toHaveAttribute(
        'data-state',
        'done',
        { timeout: TIMEOUTS.LONG }
      )
    })

    await test.step('Assert: the streaming turn finished and a card has text', async () => {
      await bubble
        .locator(selectors.chat.messageDone)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

      const cardText = (await bubble.locator('[data-testid="task-card-n1"]').innerText()).trim()
      expect(cardText.length).toBeGreaterThan(0)
    })

    await test.step('Assert: the assembled answer text renders without a reload (#1057)', async () => {
      // The reply node (compose_reply) has no card — its assembled text must
      // land in the regular message body below the task plan. Before the fix
      // it was dropped while taskPlan.active and only appeared after a reload.
      const answerText = bubble.locator(
        '[data-testid="message-text"]:not([data-testid="task-plan"] [data-testid="message-text"])'
      )
      await answerText.first().waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      expect((await answerText.first().innerText()).trim().length).toBeGreaterThan(0)
    })

    await test.step('Assert: task cards are still visible after a page reload (#1070)', async () => {
      // Before the fix, the persisted row had no card data, so a reload lost the
      // task-plan bubble entirely — only the compose_reply text remained.
      await page.reload()
      const reloadedBubble = chat.conversationBubbles().nth(previousCount)
      await reloadedBubble.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      const plan = reloadedBubble.locator('[data-testid="task-plan"]')
      await plan.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      await expect(reloadedBubble.locator('[data-testid="task-card-n1"]')).toHaveAttribute(
        'data-state',
        'done',
        { timeout: TIMEOUTS.STANDARD }
      )
      await expect(reloadedBubble.locator('[data-testid="task-card-n2"]')).toHaveAttribute(
        'data-state',
        'done',
        { timeout: TIMEOUTS.STANDARD }
      )
    })
  })

  /**
   * QA feedback PR #1076: a DAG turn with a web_search node must produce
   * the Sources (N) dropdown just like a single-task web search does.
   *
   * Requires BraveSearch to be configured (BRAVE_SEARCH_API_KEY set).
   * Tagged @webSearch only (not @ci) — skip in CI unless env is configured.
   * TestProvider recognises "websearch:" prefix in the message text and
   * returns the web_search + chat plan.
   */
  test('@webSearch Sources dropdown appears after a DAG web-search turn (PR #1076)', async ({
    page,
    credentials,
  }) => {
    await login(page, credentials)
    const chat = new ChatHelper(page)

    await test.step('Arrange: start a new chat', async () => {
      await chat.startNewChat()
    })

    const previousCount = await chat.conversationBubbles().count()

    await test.step('Act: send a DAG web-search prompt', async () => {
      // The "websearch:" prefix triggers the TestProvider web_search+chat plan.
      await page
        .locator(selectors.chat.textInput)
        .fill(
          'websearch: What are the latest developments in AI agents? ' +
            'Please summarise the most recent news from this year 2026.'
        )
      await page.locator(selectors.chat.sendBtn).click()
    })

    const bubble = chat.conversationBubbles().nth(previousCount)
    await bubble.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

    await test.step('Assert: task-plan cards appear with web_search card', async () => {
      const plan = bubble.locator('[data-testid="task-plan"]')
      await plan.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
      await bubble
        .locator('[data-testid="task-card-n1"]')
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: turn completes successfully', async () => {
      await bubble
        .locator(selectors.chat.messageDone)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
    })

    await test.step('Assert: Sources dropdown is visible (QA feedback #1076)', async () => {
      await expect(bubble.locator(selectors.chat.sourcesToggle)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Assert: Sources dropdown still visible after reload', async () => {
      await page.reload()
      const reloadedBubble = chat.conversationBubbles().nth(previousCount)
      await reloadedBubble.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await expect(reloadedBubble.locator(selectors.chat.sourcesToggle)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })

  /**
   * Issue #1070 acceptance: a multi-step DAG turn with voice reply (text +
   * TTS) must show the audio player BOTH live (without a reload) and after
   * a page reload.
   *
   * Live, the `audio` SSE event is suppressed while task cards stream
   * (taskPlan.active), so the player only appears because the frontend
   * re-fetches the persisted message after `complete` and reconciles it
   * (GET /api/v1/messages/{id} — the single authoritative source).
   */
  test('TTS audio in a DAG turn is visible live and after reload (#1070)', async ({
    page,
    credentials,
  }) => {
    await login(page, credentials)
    const chat = new ChatHelper(page)

    const toggleToolsPanel = async (open: boolean) => {
      await page.locator(selectors.chat.toolsToggle).click()
      await page
        .locator(selectors.chat.toolsPanel)
        .waitFor({ state: open ? 'visible' : 'hidden', timeout: TIMEOUTS.SHORT })
    }

    await test.step('Arrange: start a new chat and enable voice reply', async () => {
      await chat.startNewChat()
      await toggleToolsPanel(true)
      await page.locator(selectors.chat.toolVoiceReply).click()
      await expect(page.locator(selectors.chat.toolsActiveBadge)).toBeVisible({
        timeout: TIMEOUTS.SHORT,
      })
      await toggleToolsPanel(false)
    })

    const previousCount = await chat.conversationBubbles().count()

    await test.step('Act: send a multi-task prompt with voice reply on', async () => {
      await page.locator(selectors.chat.textInput).fill(MULTITASK_PROMPT)
      await page.locator(selectors.chat.sendBtn).click()
    })

    const bubble = chat.conversationBubbles().nth(previousCount)
    await bubble.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

    await test.step('Assert: the DAG turn streams task cards and completes', async () => {
      await bubble
        .locator('[data-testid="task-plan"]')
        .waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
      await bubble
        .locator(selectors.chat.messageDone)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
    })

    await test.step('Assert: the audio player is visible WITHOUT a reload', async () => {
      await bubble
        .locator(selectors.chat.messageAudio)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: the audio player is still visible after a reload', async () => {
      await page.reload()
      const reloadedBubble = chat.conversationBubbles().nth(previousCount)
      await reloadedBubble.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await reloadedBubble
        .locator(selectors.chat.messageAudio)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })
  })
})
