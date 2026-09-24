import { test, expect } from '../test-setup'
import { getAuthHeaders } from '../helpers/auth'
import { isAgentsEnabled } from '../helpers/features'
import { selectors } from '../helpers/selectors'
import { getApiUrl, TIMEOUTS } from '../config/config'

const CHAT = selectors.chat

test.describe('@ci Assistants first chat message', () => {
  test('the first and second messages keep the assistant from Start chat', async ({
    page,
    request,
    credentials,
  }) => {
    test.skip(!(await isAgentsEnabled(request, credentials)), 'AGENTS.ENABLED is off')

    const headers = await getAuthHeaders(request, credentials)
    const created = await request.post(`${getApiUrl()}/api/v1/agents`, {
      headers,
      data: { name: `Pin first message ${Date.now()}` },
    })
    expect(created.ok()).toBeTruthy()
    const agentId = ((await created.json()) as { agent: { id: number } }).agent.id

    const published = await request.post(`${getApiUrl()}/api/v1/agents/${agentId}/publish`, {
      headers,
      data: { changelog: 'e2e' },
    })
    expect(published.ok()).toBeTruthy()

    const seen: string[] = []
    await page.route('**/api/v1/messages/stream', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.continue()
        return
      }
      const body = route.request().postDataJSON() as { agentId?: string | number }
      seen.push(body.agentId == null ? '' : String(body.agentId))
      await route.fulfill({
        status: 200,
        contentType: 'text/event-stream',
        body: 'data: {"status":"complete"}\n\n',
      })
    })

    try {
      await page.goto(`/?agentId=${agentId}`)
      await expect(page.locator(CHAT.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('[data-testid="banner-pinned-assistant"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })

      await page.locator(CHAT.textInput).fill('Who are you?')
      await page.locator(CHAT.sendBtn).click()
      await expect.poll(() => seen.length, { timeout: TIMEOUTS.STANDARD }).toBe(1)
      expect(seen[0]).toBe(String(agentId))
      await expect(page.locator('[data-testid="banner-pinned-assistant"]')).toBeVisible()

      await page.locator(CHAT.textInput).fill('And now?')
      await page.locator(CHAT.sendBtn).click()
      await expect.poll(() => seen.length, { timeout: TIMEOUTS.STANDARD }).toBe(2)
      expect(seen[1]).toBe(String(agentId))
      await expect(page.locator('[data-testid="banner-pinned-assistant"]')).toBeVisible()
    } finally {
      await request.delete(`${getApiUrl()}/api/v1/agents/${agentId}`, { headers })
    }
  })
})
