import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const PROMPTS = selectors.taskPrompts
const TASKS = selectors.savedTasks
const CHAT = selectors.chat

/**
 * Saved Task roundtrip — the one flow that unit/integration tests can't cover:
 * the cross-page wiring from turning a prompt into a task, seeing it on the
 * Saved Tasks page, running it, and landing in the task's chat with a real
 * reply. The runner itself (pipeline, run recording, scheduling) is covered by
 * backend integration tests, so we only assert the UI seams here.
 *
 * Deterministic because globalSetup pins CHAT to the TestProvider, and the
 * /run endpoint executes the pipeline synchronously before returning.
 *
 * "Save as task" is only offered on a CUSTOM prompt, so we create one first
 * (same modal flow as task-prompts.spec.ts). The worker user is disposable, so
 * no explicit cleanup — teardown cascades the prompt, task, chat and messages.
 */
test.describe('@ci Saved Task roundtrip', () => {
  test('create task from a prompt, run it, and land in its chat', async ({ page }) => {
    const topic = `e2e-task-${Date.now()}`
    const taskName = `E2E Task ${topic}`
    const card = page.locator(TASKS.card).filter({ hasText: taskName })

    await test.step('Arrange: create a custom prompt', async () => {
      await openApp(page)
      await page.goto('/ai/instructions')
      await expect(page.locator(PROMPTS.overview)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

      await page.locator(PROMPTS.btnCreate).click()
      await page.locator(PROMPTS.createModal).waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await page.locator(PROMPTS.inputNewTopic).fill(topic)
      await page.locator(PROMPTS.inputNewName).fill(taskName)
      await page
        .locator(PROMPTS.inputNewContent)
        .fill('You are an E2E saved task. Reply with a short confirmation sentence.')
      await page.locator(PROMPTS.btnConfirmCreate).click()
      await page
        .locator(PROMPTS.createModal)
        .waitFor({ state: 'hidden', timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(PROMPTS.promptDetails)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: save the prompt as a task', async () => {
      await page.locator(PROMPTS.btnSaveAsTask).click()
      // The editor renders the task inline once it exists — the create seam worked.
      await expect(page.locator(TASKS.card)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: the task is listed on the Saved Tasks page', async () => {
      await page.goto('/channels/tasks')
      await expect(page.locator(TASKS.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(card).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: run the task now', async () => {
      await card.locator(TASKS.runNow).click()
      // Run now executes synchronously, then routes to the task's chat.
      await expect(page).toHaveURL(/[?&]chat=\d+/, { timeout: TIMEOUTS.LONG })
    })

    await test.step('Assert: the task chat shows a completed reply (no error)', async () => {
      // The run is synchronous, so the reply is a fully-committed historical
      // message on load — same bubble scoping as ChatHelper.waitForAnswer.
      const bubble = new ChatHelper(page).conversationBubbles().first()
      await bubble.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

      const outcome = await Promise.race([
        bubble
          .locator(CHAT.messageDone)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
          .then(() => 'done' as const),
        bubble
          .locator(CHAT.messageTopicError)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
          .then(() => 'error' as const),
      ])
      expect(outcome).toBe('done')
    })

    await test.step('Assert: the run is recorded on the task', async () => {
      await page.goto('/channels/tasks')
      await expect(card).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      // "Show results" only renders once the task has a chat → it has run.
      await expect(card.locator(TASKS.showResults)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

      await card.locator(TASKS.viewRuns).click()
      await expect(card.locator(TASKS.runsList)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })
  })
})
