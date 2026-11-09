import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { getAuthHeaders, login } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * J-AU-5. An administrator opens People → Audit → Tool calls and reads what
 * ran, without the arguments. The row is a real `memory_add` on Synaplan's
 * own tool server. A document edit and a connected-server write use the same
 * sentence helper; those two sources are covered in the unit test because
 * editing an uploaded office file is off and CI has no external tool server.
 */
test.describe('@ci Audit tool calls', () => {
  test('a tool call appears under Tool calls and hides what was sent', async ({
    page,
    request,
  }) => {
    const stamp = Date.now()
    const displayName = `Audit Ada ${stamp}`
    const email = `audit-tool-${stamp}@test.synaplan.com`
    const secret = `secret-fact-${stamp}-do-not-show`
    const api = getApiUrl()
    const admin = await getAuthHeaders(request, CREDENTIALS.getAdminCredentials())

    const created = await request.post(`${api}/api/v1/admin/users`, {
      headers: admin,
      data: {
        source: 'e2e',
        external_id: email,
        email,
        password: 'E2eTest1234!',
        level: 'PRO',
        display_name: displayName,
      },
    })
    expect(created.ok(), `provision ${created.status()}`).toBeTruthy()
    const userId = (await created.json()).user?.id
    expect(typeof userId).toBe('number')

    const minted = await request.post(`${api}/api/v1/admin/users/${userId}/api-keys`, {
      headers: admin,
      data: { name: `audit-e2e-${stamp}`, scopes: ['*'] },
    })
    expect(minted.ok(), `mint ${minted.status()}`).toBeTruthy()
    const apiKey = (await minted.json()).api_key?.key
    expect(typeof apiKey).toBe('string')

    const mcpHeaders = {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      'Mcp-Protocol-Version': '2025-11-25',
      'X-API-Key': apiKey as string,
    }
    const initialized = await request.post(`${api}/mcp`, {
      headers: mcpHeaders,
      data: {
        jsonrpc: '2.0',
        id: 1,
        method: 'initialize',
        params: {
          protocolVersion: '2025-11-25',
          capabilities: {},
          clientInfo: { name: 'audit-e2e', version: '1' },
        },
      },
    })
    expect(initialized.ok(), `initialize ${initialized.status()}`).toBeTruthy()
    const sessionId = initialized.headers()['mcp-session-id']
    expect(sessionId, 'MCP session').toBeTruthy()

    const called = await request.post(`${api}/mcp`, {
      headers: { ...mcpHeaders, 'Mcp-Session-Id': sessionId },
      data: {
        jsonrpc: '2.0',
        id: 2,
        method: 'tools/call',
        params: {
          name: 'memory_add',
          arguments: { category: 'e2e', key: `audit_${stamp}`, value: secret },
        },
      },
    })
    expect(called.ok(), `memory_add ${called.status()}`).toBeTruthy()

    const listed = await request.get(
      `${api}/api/v1/admin/audit?action=tool.executed&actor=${userId}&limit=20`,
      { headers: admin }
    )
    expect(listed.ok(), `audit ${listed.status()}`).toBeTruthy()
    const entries = (await listed.json()).entries as Array<{
      subject?: { tool?: string; arguments?: unknown; result?: unknown }
    }>
    const match = entries.find((row) => row.subject?.tool === 'memory_add')
    expect(match, 'tool.executed row for memory_add').toBeTruthy()
    expect(match?.subject).not.toHaveProperty('arguments')
    expect(match?.subject).not.toHaveProperty('result')
    expect(JSON.stringify(match)).not.toContain(secret)

    await login(page, CREDENTIALS.getAdminCredentials())
    await page.goto('/admin/people?tab=audit')
    await expect(page.locator(selectors.people.tabAudit)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await page.locator(selectors.people.auditToolCalls).click()
    const row = page.locator(selectors.people.auditRow).filter({ hasText: displayName })
    await expect(row).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(row).toContainText('memory_add')
    await expect(row).not.toContainText(secret)
  })
})
