import { expect, test } from '@playwright/test'
import { getApiUrl } from '../config/config'

/**
 * AU3: one stream worker and a one-second wait. The second slow chat stream
 * is refused with the server_busy body while a short request still answers.
 * Runs only on the busy overlay (docker-compose.busy.yml). The dev stack is
 * classic mode and does not take this path.
 */
test.describe('@busy a full stream pool answers at once', () => {
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
    const [first, second, health] = await Promise.all([
      request.get(streamUrl, { timeout: 30_000 }),
      request.get(streamUrl, { timeout: 15_000 }),
      request.get(`${api}/api/health`, { timeout: 5_000 }),
    ])

    const statuses = [first.status(), second.status()].sort((a, b) => a - b)
    expect(statuses, 'one stream is admitted and the other is refused').toEqual([200, 503])
    expect(health.ok(), 'the api pool still answers /api/health').toBeTruthy()
    expect(Date.now() - started, 'the short request was not held for the stream').toBeLessThan(
      5_000
    )

    const busy = first.status() === 503 ? first : second
    expect(busy.headers()['retry-after']).toBe('5')
    const body = (await busy.json()) as { error?: string; retryAfter?: number }
    expect(body).toEqual({ error: 'server_busy', retryAfter: 5 })

    await request.delete(`${api}/api/v1/chats/${chatId}`)
  })
})
