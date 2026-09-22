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
      // The create button stays unmounted while the first Qdrant read runs.
      // The page allows that read 15s; STANDARD (10s) ends during it.
      await page.locator(MEM.btnCreate).waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
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
    // Cold-boot arrange plus a chat round-trip plus waiting for the extractor
    // does not fit the 60s default under shard load; without the headroom the
    // generous waits inside openApp() and waitForAnswer() cannot be reached
    // and the test dies on the wall clock, reporting no failing step.
    test.setTimeout(TIMEOUTS.EXTREME + TIMEOUTS.VERY_LONG)
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
      await expect(page.locator(MEM.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(
        page.locator(MEM.item).filter({ hasText: value }).filter({ visible: true })
      ).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    })
  })

  test('account Memories opens the page, not a dialog', async ({ page }) => {
    await openApp(page)
    await page.locator(selectors.userMenu.button).click()
    await expect(page.locator(selectors.userMenu.dropdown)).toBeVisible({
      timeout: TIMEOUTS.SHORT,
    })
    await page.locator(selectors.userMenu.memoriesBtn).click()
    await expect(page).toHaveURL(/\/memories/, { timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(MEM.page)).toBeVisible()
    await expect(page.locator('[data-testid="modal-memories-dialog"]')).toHaveCount(0)
  })

  test('highlight query marks a card and Back returns to the chat', async ({ page }) => {
    const key = `e2e_highlight_${Date.now()}`
    const chat = new ChatHelper(page)

    await openApp(page)
    await page.goto('/memories')
    // Same budget as the create test: the button is absent until the 15s read ends.
    await page.locator(MEM.btnCreate).waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
    await page.locator(MEM.btnCreate).click()
    await page.locator(MEM.formModal).waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    await page.locator(MEM.btnModeAdvanced).click()
    await page.locator(MEM.inputCategory).fill('preferences')
    await page.locator(MEM.inputKey).fill(key)
    await page.locator(MEM.inputValue).fill('highlight-me')
    await page.locator(MEM.btnSave).click()
    await page.locator(MEM.formModal).waitFor({ state: 'hidden', timeout: TIMEOUTS.STANDARD })

    const row = page.locator(MEM.item).filter({ hasText: key }).filter({ visible: true })
    const memoryId = await row.first().getAttribute('data-memory-id')
    expect(memoryId).toBeTruthy()

    await chat.startNewChat()
    await expect(page.locator(selectors.chat.textInput)).toBeVisible()

    await page.goto(`/memories?highlight=${memoryId}`)
    await expect(page.locator(MEM.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(
      page.locator(`[data-memory-id="${memoryId}"][data-memory-highlighted="true"]`).first()
    ).toBeVisible()

    await page.goBack()
    await expect(page.locator(selectors.chat.textInput)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
  })
})
