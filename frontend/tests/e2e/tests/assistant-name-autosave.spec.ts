import { test, expect } from '../test-setup'
import { getAuthHeaders } from '../helpers/auth'
import { isAgentsEnabled } from '../helpers/features'
import { selectors } from '../helpers/selectors'
import { getApiUrl, TIMEOUTS } from '../config/config'

const SEL = selectors.assistants

test.describe('@ci Assistants builder name autosave', () => {
  test('an empty name does not discard a greeting typed in the same save', async ({
    page,
    request,
    credentials,
  }) => {
    test.skip(!(await isAgentsEnabled(request, credentials)), 'AGENTS.ENABLED is off')

    const headers = await getAuthHeaders(request, credentials)
    const originalName = `Name autosave ${Date.now()}`
    const created = await request.post(`${getApiUrl()}/api/v1/agents`, {
      headers,
      data: { name: originalName },
    })
    expect(created.ok()).toBeTruthy()
    const agentId = ((await created.json()) as { agent: { id: number } }).agent.id
    const greeting = 'hello from probe'

    try {
      await page.goto(`/ai/assistants/${agentId}`)
      await expect(page.locator(SEL.builder)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      const name = page.locator(SEL.name)
      const greetingField = page.locator(SEL.greeting)
      await expect(name).toHaveValue(originalName, { timeout: TIMEOUTS.STANDARD })

      const patch = page.waitForResponse(
        (response) =>
          /\/api\/v1\/agents\/\d+$/.test(new URL(response.url()).pathname) &&
          response.request().method() === 'PATCH'
      )
      await greetingField.fill(greeting)
      await name.fill('')
      const response = await patch
      expect(response.status()).toBe(200)
      await expect(page.locator(SEL.nameError)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(SEL.nameError)).toContainText('Enter a name')

      page.once('dialog', (dialog) => dialog.accept())
      await page.reload()
      await expect(page.locator(SEL.builder)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(SEL.name)).toHaveValue(originalName, {
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(SEL.greeting)).toHaveValue(greeting)
    } finally {
      await request.delete(`${getApiUrl()}/api/v1/agents/${agentId}`, { headers })
    }
  })
})
