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

    const previousCount = await chat.sendMessage(MULTITASK_PROMPT)

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
  })
})
