import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

/**
 * A multi-node (DAG) answer survives navigating away mid-stream and back — no
 * manual refresh.
 *
 * The user sends a request that expands into a multi-node plan, and navigates
 * away to another page (Files) WHILE the task cards are still streaming. The
 * client SSE is only detached, not cancelled (ChatView `handleNavigateAway` /
 * `finishStreamingTurnLocally`), so the backend run keeps executing. When the
 * user returns to the SAME chat via the History modal, `ChatView` remounts and
 * restores the turn from the persisted plan (`BMESSAGE_TASKS` / `inProgressTurn`)
 * plus `resumeActiveRunIfAny()` — the completed answer and task cards are shown,
 * all client-side, with no page reload.
 *
 * Deterministic without a real AI provider: the whole test stack runs on
 * TestProvider (pinned in global-setup). A "summarize … and translate …" prompt
 * (> 280 chars, so it bypasses the classifier fast-path and reaches the planner)
 * expands into a summarize → translate → compose_reply DAG with task cards.
 *
 * Why DAG and not single-node: the multi-node restore path is DB-backed
 * (`BMESSAGE_TASKS`), so it survives a client detach deterministically. A plain
 * single-node turn only buffers its answer in the (Redis-backed) resumable-run
 * store and is re-attachable only while the run is still active — with the fast
 * stub the turn finishes before the navigation round-trip completes, so a
 * single-node variant would be racy. (The E2E test stack also ships without
 * Redis, which disables the resumable-run buffer entirely.)
 *
 * What makes this robust rather than timing-dependent:
 *  - We leave only once the task plan is visible, so the backend turn is
 *    registered and genuinely mid-stream.
 *  - We assert `message-done` is ABSENT right before leaving: this pins the
 *    "navigated away mid-stream" precondition deterministically.
 *  - On return we assert the terminal `message-done` + non-empty assembled
 *    answer: the turn that was still running when we left has completed and is
 *    restored on remount.
 *  - A `page.on('load')` counter must stay 0: SPA router navigation never fires
 *    'load', so a non-zero count would mean a real refresh happened.
 *
 * The unique marker at the START of the message becomes the chat's
 * `firstMessagePreview` (first 30 chars), which is what the History-modal row
 * shows — so the row is targetable via `filter({ hasText: marker })` regardless
 * of list order.
 */

const NAV = selectors.nav

// Reused from multitask.spec.ts: long enough (> 280 chars) to skip the
// classifier fast-path, and matches TestProvider's summarize+translate branch.
const DAG_PROMPT_BODY =
  'Please summarize the following note for me and then translate that summary into German ' +
  'so I can share it with my colleagues in Berlin. The note is about our quarterly planning ' +
  'meeting where we discussed the product roadmap, the budget, the hiring plans, and the ' +
  'marketing strategy for the next two quarters.'

test.describe('@ci Chat streaming survives navigating away and back', () => {
  test('multi-node (DAG) answer completes after leaving and returning mid-stream', async ({
    page,
  }) => {
    const chat = new ChatHelper(page)
    const marker = `nav-dag-${Date.now()}`
    const prompt = `${marker} ${DAG_PROMPT_BODY}`

    await test.step('Arrange: open the app and start a new chat', async () => {
      await openApp(page)
      await chat.startNewChat()
      // Sanity: long enough to bypass the fast-path so the planner (DAG) runs.
      expect(prompt.length).toBeGreaterThan(280)
    })

    // "no refresh" guard: count full page loads from here on. Vue Router
    // navigation is client-side and never fires 'load'; only a real reload
    // would. The initial openApp() load already happened before we attach.
    let fullLoads = 0
    page.on('load', () => {
      fullLoads += 1
    })

    let previousCount = 0
    await test.step('Act: send a DAG request and leave while task cards stream', async () => {
      previousCount = await chat.sendMessage(prompt)

      const bubble = chat.conversationBubbles().nth(previousCount)
      await bubble.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

      // The multi-node plan being visible means the planner ran and the nodes
      // are executing on the backend — we are genuinely mid-stream.
      await bubble
        .locator(selectors.multitask.plan)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

      // Precondition: leaving MID-stream (the DAG turn has not finished yet).
      await expect(bubble.locator(selectors.chat.messageDone)).toHaveCount(0)

      // Navigate away to Files, then return to this chat via the History modal.
      // Pure client-side navigation (router.push) — no reload.
      await page.locator(NAV.sidebarV2Files).click()
      await expect(page.locator(selectors.files.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

      await page.locator(NAV.sidebarV2ChatNav).click()
      await expect(page.locator(NAV.modalChatManager)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page
        .locator(NAV.chatManagerListRows)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      const row = page.locator(NAV.chatV2Row).filter({ hasText: marker })
      await row.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await row.click()

      await expect(page.locator(selectors.pages.chat)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: task cards + assembled answer render, done, no refresh', async () => {
      const bubble = chat.conversationBubbles().nth(previousCount)
      await bubble.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      // The task-plan bubble is restored on return (persisted / re-attached).
      await bubble
        .locator(selectors.multitask.plan)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
      await bubble
        .locator(selectors.chat.messageDone)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })

      // compose_reply output lands in the message body below the task plan.
      const answerText = bubble.locator(selectors.multitask.answerTextOutsidePlan)
      await answerText.first().waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      expect((await answerText.first().innerText()).trim().length).toBeGreaterThan(0)

      expect(fullLoads, 'no full page reload should occur during navigate-away-and-back').toBe(0)
    })
  })
})
