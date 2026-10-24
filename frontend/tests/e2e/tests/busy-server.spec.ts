import { expect, test } from '../test-setup'
import { getApiUrl } from '../config/config'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'

/**
 * AU3: one stream worker and a one-second wait. The second slow chat stream
 * is refused with the server_busy body while a short request still answers.
 * Runs only on the busy overlay (docker-compose.busy.yml). The dev stack is
 * classic mode and does not take this path.
 */
test.describe('@busy a full stream pool answers at once', () => {
  test('the chat says busy, retries, and gives the text back', async ({ page, request }) => {
    test.setTimeout(180_000)
    const api = getApiUrl()
    const prompt = 'Summarise the attached report'

    // Hold the single stream worker with chained slow streams (~35 s).
    const login = await request.post(`${api}/api/v1/auth/login`, {
      data: { email: 'demo@synaplan.com', password: 'demo123' },
    })
    expect(login.ok(), 'demo login').toBeTruthy()
    const created = await request.post(`${api}/api/v1/chats`, {
      data: { title: 'busy hold' },
    })
    const createdBody = (await created.json()) as { chat?: { id?: number }; id?: number }
    const holdChatId = createdBody.chat?.id ?? createdBody.id
    expect(holdChatId, 'hold chat id').toBeTruthy()
    const holdUrl = `${api}/api/v1/messages/stream?chatId=${holdChatId}&message=${encodeURIComponent('Tell me a short story about a lighthouse.')}`
    let holding = true
    const hold = (async () => {
      while (holding) {
        await request.get(holdUrl, { timeout: 60_000 }).catch(() => null)
      }
    })()

    try {
      await openApp(page)
      const chat = new ChatHelper(page)
      await chat.startNewChat()
      await chat.sendMessage(prompt)

      // While the transport retries, the answer area says what is happening.
      await expect(page.getByTestId('processing-timeline')).toContainText('Synaplan is busy', {
        timeout: 15_000,
      })

      // After the retries the sentence says nothing was sent, and the text
      // is back in the composer.
      await expect(page.getByText(/Your message was not sent/)).toBeVisible({ timeout: 60_000 })
      await expect(page.getByTestId('input-chat-message')).toHaveValue(prompt)

      // Try again sends the same text once more: the composer clears and
      // the prompt is back in the conversation (no duplicate was stored).
      await page.getByTestId('btn-notification-action').click()
      await expect(page.getByTestId('input-chat-message')).toHaveValue('')
      await expect(page.getByTestId('message-container').first()).toContainText(prompt)
    } finally {
      holding = false
      await hold.catch(() => null)
      await request.delete(`${api}/api/v1/chats/${holdChatId}`)
    }
  })

  test('the second slow stream is busy and a short request still answers', async ({ request }) => {
    const api = getApiUrl()

    const login = await request.post(`${api}/api/v1/auth/login`, {
      data: { email: 'demo@synaplan.com', password: 'demo123' },
    })
    expect(login.ok(), 'demo login').toBeTruthy()

    const created = await request.post(`${api}/api/v1/chats`, {
      data: { title: 'busy server' },
    })
    expect(created.ok(), 'create chat').toBeTruthy()
    const createdBody = (await created.json()) as { chat?: { id?: number }; id?: number }
    const chatId = createdBody.chat?.id ?? createdBody.id
    expect(chatId, 'chat id').toBeTruthy()

    const streamUrl = `${api}/api/v1/messages/stream?chatId=${chatId}&message=${encodeURIComponent('Tell me a short story about a lighthouse.')}`
    const started = Date.now()
    const healthPromise = request
      .get(`${api}/api/health`, { timeout: 5_000 })
      .then((response) => ({ response, elapsedMs: Date.now() - started }))
    const [first, second, health] = await Promise.all([
      request.get(streamUrl, { timeout: 60_000 }),
      request.get(streamUrl, { timeout: 60_000 }),
      healthPromise,
    ])

    const statuses = [first.status(), second.status()].sort((a, b) => a - b)
    expect(statuses, 'one stream is admitted and the other is refused').toEqual([200, 503])
    expect(health.response.ok(), 'the api pool still answers /api/health').toBeTruthy()
    expect(health.elapsedMs, 'the short request was not held for the stream').toBeLessThan(5_000)

    const busy = first.status() === 503 ? first : second
    expect(busy.headers()['retry-after']).toBe('5')
    const body = (await busy.json()) as { error?: string; retryAfter?: number }
    expect(body).toEqual({ error: 'server_busy', retryAfter: 5 })

    await request.delete(`${api}/api/v1/chats/${chatId}`)
  })
})
