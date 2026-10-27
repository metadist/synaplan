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

/**
 * #2823 J-MO-1 with the provider mocked: the SSRF guard keeps a real sign-in
 * server out of CI, so the list, create, start, consent and disconnect answers
 * are stubbed and the consent page answers with the callback redirect.
 */
test.describe('@ci MCP sign-in to your own server', () => {
  test('add, sign in, see where, disconnect, delete', async ({ page }) => {
    const id = 77
    const name = `${SERVER_PREFIX} wiki ${Date.now()}`
    const serverUrl = 'https://wiki.e2e-signin.test/mcp'
    const consentUrl = 'https://auth.e2e-signin.test/authorize?client_id=synaplan'
    let servers: Record<string, unknown>[] = []
    const row = (status: string, host: string | null) => ({
      id,
      name,
      url: serverUrl,
      auth_header: '',
      has_auth_token: false,
      auth_mode: 'oauth',
      oauth_status: status,
      oauth_sign_in_host: host,
      enabled: true,
      allow_write: false,
      created: '20261027120000',
      updated: '20261027120000',
    })

    await page.route(/\/api\/v1\/mcp-servers(\/.*)?(\?.*)?$/, async (route) => {
      const request = route.request()
      const path = new URL(request.url()).pathname
      const json = (body: unknown) => route.fulfill({ status: 200, json: body })
      if (path.endsWith('/api/v1/mcp-servers') && request.method() === 'GET') {
        return json({
          success: true,
          client_enabled: true,
          oauth_connectors_enabled: true,
          servers,
        })
      }
      if (path.endsWith('/api/v1/mcp-servers') && request.method() === 'POST') {
        servers = [row('not_connected', null)]
        return route.fulfill({ status: 201, json: { success: true, server: servers[0] } })
      }
      if (path.endsWith(`/${id}/oauth/start`)) {
        return json({ success: true, authorize_url: consentUrl })
      }
      if (path.endsWith(`/${id}/oauth/disconnect`)) {
        servers = [row('not_connected', 'auth.e2e-signin.test')]
        return json({ success: true })
      }
      if (path.endsWith(`/${id}`) && request.method() === 'DELETE') {
        servers = []
        return json({ success: true })
      }
      return route.continue()
    })
    await page.route('https://auth.e2e-signin.test/**', async (route) => {
      servers = [row('connected', 'auth.e2e-signin.test')]
      await route.fulfill({
        status: 302,
        headers: { location: new URL(`/channels/mcp?connected=${id}`, page.url()).toString() },
      })
    })

    await test.step('Arrange: open MCP Servers', async () => {
      await openApp(page)
      await page.goto('/channels/mcp')
      await expect(page.locator(selectors.mcp.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: Custom, own address, Sign in with your account', async () => {
      await page.locator(selectors.mcp.addBtn).click()
      await page.locator(selectors.mcp.inputName).fill(name)
      await page.locator(selectors.mcp.inputUrl).fill(serverUrl)
      await page.locator(selectors.mcp.authMethod('oauth')).check()
      await expect(page.locator(selectors.mcp.authToken)).toHaveCount(0)
      await expect(page.locator(selectors.mcp.saveBtn)).toHaveText('Save and sign in')
    })

    await test.step('Act: save, confirm the sign-in host, consent', async () => {
      await page.locator(selectors.mcp.saveBtn).click()
      await expect(page.locator(selectors.dialog.confirmBtn)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await page.locator(selectors.dialog.confirmBtn).click()
    })

    await test.step('Assert: back on the page, signed in, where is said', async () => {
      await expect(page.locator(selectors.mcp.oauthStatus(id))).toHaveText(/Connected/, {
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(selectors.mcp.signInHost(id))).toHaveText(
        'Signs in at auth.e2e-signin.test'
      )
      await expect(page.locator(selectors.notification.success)).toContainText(name)
      await expect(page.locator(selectors.notification.error)).toHaveCount(0)
    })

    await test.step('Act + Assert: disconnect, then delete', async () => {
      await page.locator(selectors.mcp.oauthDisconnect(id)).click()
      await page.locator(selectors.dialog.confirmBtn).click()
      await expect(page.locator(selectors.mcp.oauthStatus(id))).toHaveText(/Not connected/, {
        timeout: TIMEOUTS.STANDARD,
      })
      await page.locator(selectors.mcp.deleteServer(id)).click()
      await page.locator(selectors.dialog.confirmBtn).click()
      await expect(page.locator(selectors.mcp.serverRow(id))).toBeHidden({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})
