import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp, getAuthHeaders } from '../helpers/auth'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * MCP server config UI (`/channels/mcp`) — full CRUD as a normal (non-admin)
 * user: create a config, verify it survives a reload, delete it via the UI.
 *
 * Deterministic / @ci-safe: create + delete only persist a `BMCPSERVERS` row
 * (no live MCP connection). The "Test connection" button is the only network
 * action and is deliberately never clicked. The URL is a fixed, SSRF-safe
 * public host (same as the backend integration test); uniqueness lives in the
 * NAME so cleanup-by-prefix is exact.
 *
 * Configs are user-scoped to the worker, so afterEach cleans up via the worker
 * session (survives a mid-test failure).
 */
const SERVER_PREFIX = 'E2E MCP'
const SERVER_URL = 'https://crm.example.com/mcp'

test.describe('@ci MCP server config', () => {
  test.afterEach(async ({ request, credentials }) => {
    const auth = await getAuthHeaders(request, credentials)
    const res = await request.get(`${getApiUrl()}/api/v1/mcp-servers`, { headers: auth })
    if (!res.ok()) return
    const { servers } = (await res.json()) as { servers?: { id: number; name: string }[] }
    for (const server of servers ?? []) {
      if (typeof server.name === 'string' && server.name.startsWith(SERVER_PREFIX)) {
        await request.delete(`${getApiUrl()}/api/v1/mcp-servers/${server.id}`, { headers: auth })
      }
    }
  })

  test('create a server, verify it persists across reload, delete it via UI', async ({ page }) => {
    const serverName = `${SERVER_PREFIX} ${Date.now()}`
    let serverId: number

    await test.step('Arrange: open the MCP config page as a normal user', async () => {
      await openApp(page)
      await page.goto('/channels/mcp')
      await expect(page.locator(selectors.mcp.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: create a server via the editor', async () => {
      await page.locator(selectors.mcp.addBtn).click()
      await expect(page.locator(selectors.mcp.editor)).toBeVisible()
      await page.locator(selectors.mcp.inputName).fill(serverName)
      await page.locator(selectors.mcp.inputUrl).fill(SERVER_URL)

      const created = page.waitForResponse(
        (res) =>
          new URL(res.url()).pathname.endsWith('/api/v1/mcp-servers') &&
          res.request().method() === 'POST',
        { timeout: TIMEOUTS.STANDARD }
      )
      await page.locator(selectors.mcp.saveBtn).click()
      const res = await created
      expect(res.ok()).toBeTruthy()
      const { server } = (await res.json()) as { server: { id: number } }
      serverId = server.id
    })

    await test.step('Assert: the new server appears in the list', async () => {
      await expect(page.locator(selectors.mcp.serverRow(serverId))).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(selectors.mcp.serverRow(serverId))).toContainText(serverName)
    })

    await test.step('Assert: the server survives a full reload', async () => {
      await page.reload()
      await expect(page.locator(selectors.mcp.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.mcp.serverRow(serverId))).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Act + Assert: delete via the UI removes the row', async () => {
      await page.locator(selectors.mcp.deleteServer(serverId)).click()
      await page.locator(selectors.dialog.confirmBtn).click()
      await expect(page.locator(selectors.mcp.serverRow(serverId))).toBeHidden({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(selectors.notification.error)).toHaveCount(0)
    })
  })
})
