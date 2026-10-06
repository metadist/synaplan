import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { TIMEOUTS, INTERVALS, getApiUrl } from '../config/config'

const MEM = selectors.memories
const CHAT = selectors.chat

type DigestEntry = {
  id: number
  title: string
  messageId: number
  chatId: number | null
}

test.describe('@ci Long-term memory', () => {
  test('a remembered line can be opened from Memories and is not used after delete', async ({
    page,
  }) => {
    test.setTimeout(TIMEOUTS.EXTREME * 3)
    const title = `E2E rent letter ${Date.now()}`
    const chat = new ChatHelper(page)
    const createdIds: number[] = []

    const listEntries = async (): Promise<DigestEntry[]> => {
      const res = await page.request.get(
        `${getApiUrl()}/api/v1/user/message-digests/entries?page=1&limit=100`
      )
      if (!res.ok()) return []
      const data = (await res.json()) as { entries?: DigestEntry[] }
      return data.entries ?? []
    }

    const askInNewChat = async (question: string): Promise<string[]> => {
      await page.goto('/')
      await chat.startNewChat()
      const streamPromise = page.waitForResponse(
        (res) => res.url().includes('/api/v1/messages/stream') && res.request().method() === 'POST',
        { timeout: TIMEOUTS.LONG }
      )
      const previousCount = await chat.sendMessage(question)
      const stream = await streamPromise
      const body = await stream.text()
      const answer = await chat.waitForAnswer(previousCount)
      expect(answer.length).toBeGreaterThan(0)
      return digestTitles(body)
    }

    try {
      await test.step('Arrange: clear this account’s long-term memory', async () => {
        await openApp(page)
        const cleared = await page.request.delete(`${getApiUrl()}/api/v1/user/message-digests`)
        expect(cleared.ok()).toBeTruthy()
      })

      await test.step('Act: say something worth keeping in chat A', async () => {
        await chat.startNewChat()
        const previousCount = await chat.sendMessage(`Please remember: ${title}`)
        const answer = await chat.waitForAnswer(previousCount)
        expect(answer.length).toBeGreaterThan(0)
      })

      await test.step('Act: a turn in chat B digests chat A', async () => {
        await chat.startNewChat()
        const previousCount = await chat.sendMessage('Thanks, that is all for now.')
        const answer = await chat.waitForAnswer(previousCount)
        expect(answer.length).toBeGreaterThan(0)
      })

      let source: DigestEntry | undefined

      await test.step('Assert: the entry exists', async () => {
        await expect
          .poll(
            async () => {
              const entries = await listEntries()
              source = entries.find((entry) => entry.title === title)
              return source != null
            },
            { timeout: TIMEOUTS.VERY_LONG, intervals: INTERVALS.STANDARD() }
          )
          .toBe(true)
        expect((await listEntries()).filter((entry) => entry.title === title)).toHaveLength(1)
        createdIds.push(source!.id)
      })

      await test.step('Assert: a turn in a new chat loads the entry', async () => {
        expect(await askInNewChat(`What do you know about ${title}?`)).toContain(title)
      })

      await test.step('Assert: Memories shows the entry', async () => {
        await page.goto('/memories')
        await page.locator(MEM.btnViewLongTerm).click()
        await expect(page.locator(MEM.sectionLongTerm)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
        await expect(page.locator(MEM.textLongTermIntro)).toBeVisible()
        const row = page.locator(MEM.itemLongTerm).filter({ hasText: title })
        await expect(row).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
      })

      await test.step('Act: open the source message', async () => {
        const row = page.locator(MEM.itemLongTerm).filter({ hasText: title })
        await row.locator(MEM.btnLongTermOpen).click()
        const message = page.locator(
          `${CHAT.messageContainer}[data-message-id="${source!.messageId}"]`
        )
        await expect(message).toBeVisible({ timeout: TIMEOUTS.STANDARD })
        await expect(message).toHaveClass(/message-target-highlight/, {
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(message).toBeInViewport()
      })

      await test.step('Act: delete the entry', async () => {
        await page.goto('/memories')
        await page.locator(MEM.btnViewLongTerm).click()
        const row = page.locator(MEM.itemLongTerm).filter({ hasText: title })
        await row.locator(MEM.btnLongTermDelete).click()
        const confirmBtn = page.locator(selectors.dialog.confirmBtn)
        await confirmBtn.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
        await confirmBtn.click()
        await expect(
          page
            .locator(selectors.notification.success)
            .filter({ hasText: 'Deleted. The AI will no longer use this entry.' })
        ).toBeVisible({ timeout: TIMEOUTS.SHORT })
      })

      await test.step('Assert: the entry is gone and the empty state shows', async () => {
        await expect(page.locator(MEM.itemLongTerm).filter({ hasText: title })).toHaveCount(0, {
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(page.locator(MEM.stateLongTermEmpty)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
        await expect
          .poll(async () => (await listEntries()).some((entry) => entry.id === source!.id), {
            timeout: TIMEOUTS.STANDARD,
            intervals: INTERVALS.STANDARD(),
          })
          .toBe(false)
        createdIds.length = 0
      })

      await test.step('Assert: a later turn does not load the deleted entry', async () => {
        expect(await askInNewChat(`What do you know about ${title}?`)).not.toContain(title)
        expect((await listEntries()).some((entry) => entry.title === title)).toBe(false)
      })
    } finally {
      for (const id of createdIds) {
        await page.request.delete(`${getApiUrl()}/api/v1/user/message-digests/${id}`)
      }
    }
  })
})

function digestTitles(body: string): string[] {
  const titles: string[] = []
  for (const line of body.split('\n')) {
    const trimmed = line.trim()
    if (!trimmed.startsWith('data:')) continue
    const payload = trimmed.slice(5).trim()
    if (payload === '' || payload === '[DONE]') continue
    let data: { status?: string; metadata?: { digests?: { title?: string }[] } }
    try {
      data = JSON.parse(payload) as typeof data
    } catch {
      continue
    }
    if (data.status !== 'digests_loaded') continue
    for (const digest of data.metadata?.digests ?? []) {
      if (digest.title) titles.push(digest.title)
    }
  }
  return titles
}
