import { test, expect } from '../test-setup'
import { getAuthHeaders } from '../helpers/auth'
import { isAgentsEnabled } from '../helpers/features'
import { getApiUrl, TIMEOUTS } from '../config/config'

test.describe('@ci Assistant template gallery', () => {
  test('installing the offer writer shows what is still needed', async ({
    page,
    request,
    credentials,
  }) => {
    test.skip(!(await isAgentsEnabled(request, credentials)), 'AGENTS.ENABLED is off')

    await page.goto('/ai/agents/new')
    await expect(page.getByTestId('card-template-offer-writer')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await page.getByTestId('card-template-offer-writer').click()
    await expect(page.getByTestId('section-template-detail')).toBeVisible()
    await page.getByTestId('btn-template-install').click()
    await expect(page).toHaveURL(/\/ai\/assistants\/\d+/, { timeout: TIMEOUTS.STANDARD })

    const agentId = page.url().match(/\/ai\/assistants\/(\d+)/)?.[1]
    expect(agentId).toBeTruthy()

    try {
      await expect(page.getByTestId('section-template-checklist')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.getByTestId('btn-template-choose-folder')).toBeVisible()
      await expect(page.getByTestId('btn-template-try-0')).toBeVisible()
      await expect(page.getByTestId('badge-from-template')).toBeVisible()
    } finally {
      const headers = await getAuthHeaders(request, credentials)
      const archiveRes = await request.patch(`${getApiUrl()}/api/v1/agents/${agentId}`, {
        headers,
        data: { status: 'archived' },
      })
      expect(archiveRes.ok()).toBeTruthy()
      const deleteRes = await request.delete(`${getApiUrl()}/api/v1/agents/${agentId}`, { headers })
      expect(deleteRes.status()).toBe(204)
    }
  })
})
