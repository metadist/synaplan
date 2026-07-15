import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { TIMEOUTS, INTERVALS, getApiUrl } from '../config/config'

const MEM = selectors.memories

/**
 * User memories live in Qdrant (qdrant_test service in the test stack).
 *
 * Manual CRUD uses the AI-free "advanced" form of the memory dialog, so no
 * model call is involved. Automatic extraction runs synchronously in the
 * test env (messenger.yaml `when@test` routes ExtractMemoriesCommand to the
 * sync transport) with a deterministic TestProvider contract: a chat message
 * containing `memorize: some_key = some value` yields exactly one `create`
 * action; everything else extracts nothing.
 */
test.describe('@ci Memories', () => {
  test('user can create a memory manually and delete it', async ({ page }) => {
    const key = `e2e_key_${Date.now()}`
    const value = `E2E memory value ${Date.now()}`

    await test.step('Arrange: open the memories page', async () => {
      await openApp(page)
      await page.goto('/memories')
      await page.locator(MEM.btnCreate).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: create a memory via the advanced form', async () => {
      await page.locator(MEM.btnCreate).click()
      await page.locator(MEM.formModal).waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await page.locator(MEM.btnModeAdvanced).click()
      await page.locator(MEM.inputCategory).fill('preferences')
      await page.locator(MEM.inputKey).fill(key)
      await page.locator(MEM.inputValue).fill(value)
      await page.locator(MEM.btnSave).click()
      await page.locator(MEM.formModal).waitFor({ state: 'hidden', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: the memory appears in the list', async () => {
      await expect(
        page.locator(MEM.item).filter({ hasText: key }).filter({ visible: true })
      ).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: the memory survives a reload', async () => {
      await page.reload()
      await expect(
        page.locator(MEM.item).filter({ hasText: key }).filter({ visible: true })
      ).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: delete the memory with confirmation', async () => {
      const row = page.locator(MEM.item).filter({ hasText: key }).filter({ visible: true })
      await row.locator(MEM.btnDelete).click()
      const confirmBtn = page.locator(selectors.dialog.confirmBtn)
      await confirmBtn.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await confirmBtn.click()
    })

    await test.step('Assert: the memory is gone', async () => {
      await expect(
        page.locator(MEM.item).filter({ hasText: key }).filter({ visible: true })
      ).toHaveCount(0, { timeout: TIMEOUTS.STANDARD })
    })
  })

  test('a chat turn with a memorizable fact creates a memory automatically', async ({ page }) => {
    const value = `teal-${Date.now()}`
    const chat = new ChatHelper(page)

    await test.step('Arrange: open app on a fresh chat', async () => {
      await openApp(page)
      await chat.startNewChat()
    })

    await test.step('Act: state a fact the extractor should pick up', async () => {
      const previousCount = await chat.sendMessage(`Please memorize: favorite_color = ${value}`)
      const answer = await chat.waitForAnswer(previousCount)
      expect(answer.length).toBeGreaterThan(0)
    })

    await test.step('Assert: the memory shows up via the API', async () => {
      // Extraction is dispatched right after the SSE stream completes;
      // poll briefly instead of assuming strict ordering.
      await expect
        .poll(
          async () => {
            const res = await page.request.get(`${getApiUrl()}/api/v1/user/memories`)
            if (!res.ok()) return false
            const data = await res.json()
            return (data.memories ?? []).some(
              (m: { key: string; value: string }) => m.key === 'favorite_color' && m.value === value
            )
          },
          { timeout: TIMEOUTS.LONG, intervals: INTERVALS.STANDARD() }
        )
        .toBe(true)
    })

    await test.step('Assert: the memory is visible on the memories page', async () => {
      await page.goto('/memories')
      await expect(
        page.locator(MEM.item).filter({ hasText: value }).filter({ visible: true })
      ).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    })
  })
})
