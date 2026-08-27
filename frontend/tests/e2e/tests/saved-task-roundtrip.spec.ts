import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const PROMPTS = selectors.taskPrompts
const TASKS = selectors.savedTasks

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
    // Arrange + synchronous /run + chat land exceeds the 60s default under
    // CI shard load. VERY_LONG covers the run; EXTREME is headroom for setup.
    test.setTimeout(TIMEOUTS.EXTREME + TIMEOUTS.VERY_LONG)
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
      // Run now executes the pipeline synchronously, then router.push('/?chat=').
      // ChatView applies the id and immediately router.replace-strips the query
      // — asserting toHaveURL(/[?&]chat=/) races a URL the app deletes on
      // purpose (CI: 5× /channels/tasks, then 27× / with the chat already
      // showing). Wait for the chat page instead.
      await expect(page.locator(selectors.pages.chat)).toBeVisible({
        timeout: TIMEOUTS.VERY_LONG,
      })
    })

    await test.step('Assert: the task chat shows a completed reply (no error)', async () => {
      // Historical message on load — ChatHelper.waitForAnswer races done vs error.
      const aiText = await new ChatHelper(page).waitForAnswer(0)
      expect(aiText.length).toBeGreaterThan(0)
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
