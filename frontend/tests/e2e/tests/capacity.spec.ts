import type { Page } from '@playwright/test'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * Admin › System › Capacity (architecture update AU7, journey J-AU-2): Olga
 * reads how loaded each server is and whether one needs help.
 *
 * The first test runs against the real endpoint: the test image starts the
 * watchdog, so this stack reports at least itself, and the answering server
 * is marked. The second seeds a three-server cluster through a route mock —
 * one live, one silent, one worker — because a single-server stack cannot
 * show those states. @ci, provider-free. The worker storageState is a
 * non-admin user, so both log in as the seeded admin.
 */
test.describe('@ci Capacity', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())
  })

  test('J-AU-2 this server reports itself and is marked as the one you are connected to', async ({
    page,
  }) => {
    // page.request shares the admin session the login above created.
    const res = await page.request.get(`${getApiUrl()}/api/v1/admin/capacity/servers`)
    const servedBy = res.headers()['x-synaplan-server']
    expect(servedBy, 'every response names its server').toBeTruthy()

    await page.goto('/admin/features')
    await expect(page.locator(selectors.capacity.navLink)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await page.locator(selectors.capacity.navLink).click()
    await expect(page).toHaveURL(/\/admin\/capacity/)
    await expect(page.locator(selectors.pages.capacity)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    // The CI test stack runs no Redis (REDIS_DSN points at the container's
    // own localhost), so there the honest answer is 503 and the page says so.
    // A stack with Redis reports itself; both paths are the real product.
    if (res.status() === 503) {
      expect(await res.json()).toEqual({ success: false, error: 'capacity_unavailable' })
      await expect(page.locator(selectors.capacity.unavailable)).toContainText(
        'the shared cache does not answer',
        { timeout: TIMEOUTS.STANDARD }
      )
      await expect(page.locator(selectors.capacity.retry)).toBeVisible()
      return
    }

    expect(res.status(), 'the capacity endpoint answers for an admin').toBe(200)
    const body = (await res.json()) as { served_by: string; servers: { id: string }[] }
    expect(body.served_by).toBe(servedBy)
    expect(body.servers.map((s) => s.id)).toContain(body.served_by)

    const card = page.locator(`${selectors.capacity.server}[data-server="${body.served_by}"]`)
    await expect(card).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(card.locator(selectors.capacity.connectedHere)).toHaveText(
      'You are connected to this server.'
    )
    await expect(card.locator(selectors.capacity.reportingBadge)).toHaveText('Reporting')
    await card.locator(selectors.capacity.technical).locator('summary').click()
    await expect(card.locator(selectors.capacity.logCommand)).toContainText(
      /docker logs|kubectl logs|error output/
    )
    // Recent events come through the authenticated client, in the card.
    await card.locator(selectors.capacity.recentEvents).click()
    const eventsList = card.locator(selectors.capacity.eventsList)
    await expect(eventsList).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(
      eventsList
        .locator(`${selectors.capacity.eventsEmpty}, ${selectors.capacity.eventItem}`)
        .first()
    ).toBeVisible({ timeout: TIMEOUTS.STANDARD })
  })

  test('J-AU-2 a cluster: live, silent, a worker row, and the sizing hint', async ({ page }) => {
    await mockCluster(page)
    await page.goto('/admin/capacity')
    await expect(page.locator(selectors.pages.capacity)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    await expect(page.locator(selectors.capacity.summary)).toContainText('3 servers')
    await expect(page.locator(selectors.capacity.connectedTo)).toHaveText(
      'You are connected to web2'
    )

    const cards = page.locator(selectors.capacity.server)
    await expect(cards).toHaveCount(2)
    const web1 = page.locator(`${selectors.capacity.server}[data-server="web1"]`)
    const web2 = page.locator(`${selectors.capacity.server}[data-server="web2"]`)

    await expect(web1.locator(selectors.capacity.aiAnswersReading)).toContainText(
      '22 of 24 at once'
    )
    await expect(web1.locator(selectors.capacity.aiAnswersReading)).toContainText('peak today 22')
    await expect(web1.locator(selectors.capacity.sizingHint)).toContainText(
      'At its peak today web1 answered 22 of the 24 AI requests it can hold at once.'
    )
    await expect(web1.locator(selectors.capacity.helper)).toHaveCount(1)
    await expect(web1.locator(selectors.capacity.helper)).toContainText('web1-worker')
    await expect(web1.locator(selectors.capacity.helper)).toContainText('Background work')
    await web1.locator(selectors.capacity.technical).locator('summary').click()
    await expect(web1.locator(selectors.capacity.place)).toHaveText(
      'Docker · machine synweb100 · address 10.0.0.2'
    )
    await expect(web1.locator(selectors.capacity.logCommand)).toHaveText('docker logs 79e4f90bc8c6')

    await expect(web2.locator(selectors.capacity.connectedHere)).toBeVisible()
    await expect(web2.locator(selectors.capacity.silentBadge)).toContainText(
      'the server may be down or restarting'
    )
    await expect(web2).toHaveAttribute('data-reporting', 'no')

    // The event ring, filtered to one server, rendered from a mocked answer.
    await page.route('**/api/v1/admin/logs**', async (route) => {
      const url = new URL(route.request().url())
      expect(url.searchParams.get('host')).toBe('79e4f90bc8c6')
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          mode: 'recent',
          events: [
            {
              id: 'evt1',
              ts: Math.floor(Date.now() / 1000) - 120,
              level: 'warning',
              channel: 'app',
              event: 'ai_fallback',
              message: 'Provider answered slowly; the fallback model replied.',
              exception_class: null,
              exception_message: null,
              stack: [],
              request_id: 'req-1',
              host: '79e4f90bc8c6',
              route: 'chat_send',
              method: 'POST',
              status_code: 200,
              user_id: null,
              provider: 'anthropic',
              model: null,
              worker: null,
              duration_ms: 8120,
            },
          ],
        }),
      })
    })
    await web1.locator(selectors.capacity.recentEvents).click()
    await expect(web1.locator(selectors.capacity.eventItem)).toHaveCount(1)
    await expect(web1.locator(selectors.capacity.eventItem)).toContainText(
      'Provider answered slowly; the fallback model replied.'
    )
  })

  test('the shared cache does not answer: one sentence and Check again', async ({ page }) => {
    await page.route('**/api/v1/admin/capacity/servers', async (route) => {
      await route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, error: 'capacity_unavailable' }),
      })
    })
    await page.goto('/admin/capacity')
    await expect(page.locator(selectors.capacity.unavailable)).toContainText(
      'Server load cannot be read right now because the shared cache does not answer. The servers keep working.'
    )
    await expect(page.locator(selectors.capacity.retry)).toBeVisible()
  })
})

/**
 * Admin › Capacity › Requests, AI calls, Tools and Outbound (AU33, journeys
 * J-AU-2 and J-AU-3). The CI stack has no Redis, so the timeline reports are
 * route mocks; Allow and Remove on the Outbound tab go to the real backend
 * and change the real allow list.
 */
test.describe('@ci Capacity timeline', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())
  })

  test('J-AU-2 find the slowest request and see where its time went', async ({ page }) => {
    await mockReport(page, 'requests', requestsReport())
    await mockReport(page, 'ai-calls', aiCallsReport())
    await page.goto('/admin/capacity')
    await expect(page.locator(selectors.pages.capacity)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(selectors.capacity.sizingGuide)).toHaveAttribute(
      'href',
      /docs\/CAPACITY\.md$/
    )

    await page.locator(selectors.capacity.tab('requests')).click()
    await expect(page).toHaveURL(/[?&]tab=requests/)
    const routes = page.locator(selectors.capacity.routeRow)
    await expect(routes.first()).toHaveAttribute('data-route', 'chat_stream')
    await expect(routes.first().locator(selectors.capacity.routeP95)).toHaveText('8.4 s')

    await page.locator(selectors.capacity.requestsSort).selectOption('requests')
    await expect(routes.first()).toHaveAttribute('data-route', 'files')

    const slowest = page.locator(selectors.capacity.slowRequest).first()
    await expect(slowest).toHaveAttribute('data-request-id', 'req-slow-1')
    await expect(slowest.locator(selectors.capacity.slowTotal)).toHaveText('12 s')
    await expect(slowest.locator(selectors.capacity.slowLargest)).toHaveText('Mostly AI: 9.0 s')
    await slowest.locator('summary').click()
    const detail = slowest.locator(selectors.capacity.slowDetail)
    await expect(detail).toBeVisible()
    await expect(detail.locator(selectors.capacity.partsLegend)).toContainText('Database')
    await expect(detail).toContainText('first word after 820 ms')
    await expect(detail).toContainText('Refused by the filter')
    await expect(detail.locator(selectors.capacity.slowId)).toHaveText('req-slow-1')

    await page.locator(selectors.capacity.tab('ai')).click()
    await expect(page).toHaveURL(/[?&]tab=ai/)
    const model = page.locator(selectors.capacity.aiModelRow).first()
    await expect(model).toContainText('anthropic')
    await expect(model).toContainText('0.0420')
    await expect(page.locator(selectors.capacity.aiSourceRow).first()).toContainText('Chat')
  })

  test('J-AU-3 allow a destination the filter does not know, then remove it again', async ({
    page,
  }) => {
    const host = `capacity-e2e-${Date.now()}.example.test`
    const state = { mode: 'report' as 'report' | 'enforce', listed: false }
    await page.route('**/api/v1/admin/capacity/outbound', async (route) => {
      await route.fulfill({ json: outboundReport(host, state) })
    })
    // Allow and Remove reach the real backend; only the answer is watched.
    await page.route('**/api/v1/admin/egress/allow', async (route) => {
      const response = await route.fetch()
      const body = (await response.json()) as { success: boolean; rules: string[] }
      if (body.success) state.listed = body.rules.includes(host)
      await route.fulfill({ response, json: body })
    })

    try {
      await page.goto('/admin/capacity?tab=outbound')
      const outbound = page.locator(selectors.capacity.outbound)
      await expect(outbound).toHaveAttribute('data-mode', 'report', {
        timeout: TIMEOUTS.STANDARD,
      })
      const typed = page.locator(`${selectors.capacity.outboundRow}[data-destination="typed"]`)
      await expect(typed.locator(selectors.capacity.outboundAllow)).toHaveCount(0)

      const row = page.locator(`${selectors.capacity.outboundRow}[data-destination="${host}"]`)
      await expect(row.locator(selectors.capacity.outboundState)).toHaveText('Would be refused')
      await row.locator(selectors.capacity.outboundAllow).click()
      await expect(row).toHaveAttribute('data-state', 'listed')
      await expect(row.locator(selectors.capacity.outboundRemove)).toBeVisible()
      expect(state.listed, 'the real allow list now holds the host').toBe(true)

      // Once the filter refuses, taking a rule off asks first.
      state.mode = 'enforce'
      await page.reload()
      await expect(outbound).toHaveAttribute('data-mode', 'enforce', {
        timeout: TIMEOUTS.STANDARD,
      })
      await row.locator(selectors.capacity.outboundRemove).click()
      await page.locator(selectors.dialog.cancelBtn).click()
      await expect(row).toHaveAttribute('data-state', 'listed')
      expect(state.listed).toBe(true)

      await row.locator(selectors.capacity.outboundRemove).click()
      await page.locator(selectors.dialog.confirmBtn).click()
      await expect(row).toHaveAttribute('data-state', 'not_listed')
      await expect(row.locator(selectors.capacity.outboundState)).toHaveText('Refused')
      expect(state.listed, 'the real allow list no longer holds the host').toBe(false)

      await page.locator(selectors.capacity.outboundSetting).click()
      await expect(page.locator('[data-config-field="EGRESS_MODE"]')).toHaveAttribute(
        'data-testid',
        'config-field-highlighted',
        { timeout: TIMEOUTS.STANDARD }
      )
    } finally {
      await page.unroute('**/api/v1/admin/egress/allow')
      await page.request.delete(`${getApiUrl()}/api/v1/admin/egress/allow`, {
        data: { rule: host },
      })
    }
  })

  test('tool calls that asked for approval link to what still waits', async ({ page }) => {
    await page.route('**/api/v1/config/runtime', async (route) => {
      const response = await route.fetch()
      const body = (await response.json()) as { features?: Record<string, unknown> }
      body.features = { ...body.features, toolsApprovalsEnabled: true }
      await route.fulfill({ response, json: body })
    })
    // The table counts the window's history; the link counts this viewer's open approvals.
    await page.route('**/api/v1/approvals?*', async (route) => {
      await route.fulfill({ json: { success: true, pendingCount: 1, approvals: [] } })
    })
    await mockReport(page, 'tools', {
      ...meta(),
      tools: [
        {
          source: 'mcp',
          kind: 'write',
          calls: 5,
          done: 2,
          refused: 1,
          waiting: 2,
          failed: 0,
          p95_ms: 1400,
        },
      ],
    })
    await page.goto('/admin/capacity?tab=tools')
    const row = page.locator(selectors.capacity.toolRow).first()
    await expect(row).toContainText('MCP servers', { timeout: TIMEOUTS.STANDARD })
    await expect(row.locator(selectors.capacity.toolWaiting)).toHaveText('2')
    await expect(page.locator(selectors.capacity.approvalsLink)).toContainText('1 waiting')
    await page.locator(selectors.capacity.approvalsLink).click()
    await expect(page).toHaveURL(/\/approvals/)
  })
})

function meta(): Record<string, unknown> {
  return { success: true, served_by: 'web1', generated_at: new Date().toISOString() }
}

async function mockReport(page: Page, name: string, body: Record<string, unknown>) {
  await page.route(`**/api/v1/admin/capacity/${name}`, async (route) => {
    await route.fulfill({ json: body })
  })
}

function route(routeClass: string, requests: number, p95: number): Record<string, unknown> {
  return {
    route_class: routeClass,
    requests,
    errors: 0,
    busy: 0,
    p50_ms: Math.round(p95 / 3),
    p95_ms: p95,
    p99_ms: p95 + 500,
    wait_p95_ms: null,
  }
}

function requestsReport(): Record<string, unknown> {
  return {
    ...meta(),
    window_seconds: 3600,
    route_classes: [route('files', 240, 310), route('chat_stream', 42, 8400)],
    slowest: [
      {
        id: 'req-slow-1',
        ts: Math.floor(Date.now() / 1000) - 300,
        server: 'web1',
        route_class: 'chat_stream',
        status_class: '2xx',
        total_ms: 12_000,
        php_ms: 12_000,
        db_ms: 400,
        db_queries: 18,
        ai: [
          {
            provider: 'anthropic',
            model: 'claude',
            capability: 'chat_stream',
            source: 'chat_stream',
            ttft_ms: 820,
            duration_ms: 9000,
            tokens_in: 1200,
            tokens_out: 640,
            cost: '0.0420',
            outcome: 'ok',
            hooks_ms: 12,
          },
        ],
        tools: [],
        egress: [
          { destination: 'api.anthropic.com', status_class: '2xx', duration_ms: 9000 },
          { destination: 'unknown.example.org', status_class: 'blocked', duration_ms: 2 },
        ],
        user_id: null,
      },
    ],
  }
}

function aiCallsReport(): Record<string, unknown> {
  return {
    ...meta(),
    models: [
      {
        provider: 'anthropic',
        model: 'claude',
        capability: 'chat_stream',
        calls: 1,
        failures: 0,
        ttft_p50_ms: 820,
        ttft_p95_ms: 820,
        duration_p95_ms: 9000,
        tokens_in: 1200,
        tokens_out: 640,
        cost: '0.042',
        hooks_ms_p95: 12,
      },
    ],
    sources: [
      {
        source: 'chat_web',
        calls: 1,
        failures: 0,
        tokens_in: 1200,
        tokens_out: 640,
        cost: '0.042',
      },
    ],
  }
}

function outboundReport(
  host: string,
  state: { mode: 'report' | 'enforce'; listed: boolean }
): Record<string, unknown> {
  return {
    ...meta(),
    mode: state.mode,
    destinations: [
      {
        destination: host,
        requests: 3,
        failures: 0,
        refused: state.mode === 'enforce' && !state.listed ? 3 : 0,
        p95_ms: 180,
        state: state.listed ? 'listed' : 'not_listed',
        rule: state.listed ? host : null,
        allow_rule: state.listed ? null : host,
      },
      {
        destination: 'typed',
        requests: 1,
        failures: 0,
        refused: 0,
        p95_ms: 90,
        state: 'typed',
        rule: null,
        allow_rule: null,
      },
    ],
  }
}

function server(id: string, overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id,
    hostname: id,
    role: 'web',
    runtime: 'compose',
    version: '5.6.0',
    watchdog_version: '0.1.0',
    reporting: true,
    updated_at: new Date().toISOString(),
    last_seen_at: new Date().toISOString(),
    php_ready: true,
    admin_ok: true,
    streams: { total: 24, busy: 3 },
    api: { total: 8, busy: 1 },
    memory: { rss_bytes: 2254857830, limit_bytes: 6442450944, limited: true },
    cpu: { cores: 8, limited: true, usage_ratio: 0.14 },
    place: {
      host: '',
      address: '',
      pod: '',
      namespace: '',
      deployment: '',
      container: '',
      cpu_request: '',
      memory_limit: '',
    },
    logs: { collector: 'docker', event_ring_host: id },
    threads: { count: 32, busy: 4 },
    peak_today: { streams_busy: 3, streams_total: 24, at: new Date().toISOString() },
    ...overrides,
  }
}

async function mockCluster(page: Page): Promise<void> {
  const place = (host: string, address: string) => ({
    host,
    address,
    pod: '',
    namespace: '',
    deployment: '',
    container: '',
    cpu_request: '',
    memory_limit: '',
  })
  const threeMinutesAgo = new Date(Date.now() - 3 * 60_000).toISOString()
  await page.route('**/api/v1/admin/capacity/servers', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        served_by: 'web2',
        generated_at: new Date().toISOString(),
        servers: [
          server('web1', {
            hostname: '79e4f90bc8c6',
            place: place('synweb100', '10.0.0.2'),
            logs: { collector: 'docker', event_ring_host: '79e4f90bc8c6' },
            streams: { total: 24, busy: 22 },
            peak_today: { streams_busy: 22, streams_total: 24, at: new Date().toISOString() },
          }),
          server('web1-worker', {
            hostname: 'a1b2c3d4e5f6',
            role: 'worker',
            place: place('synweb100', '10.0.0.2'),
            streams: { total: 0, busy: 0 },
            api: { total: 0, busy: 0 },
            memory: { rss_bytes: 111726592, limit_bytes: 0, limited: false },
            cpu: { cores: 8, limited: false, usage_ratio: 0.02 },
            peak_today: { streams_busy: 0, streams_total: 0, at: null },
          }),
          server('web2', {
            reporting: false,
            updated_at: threeMinutesAgo,
            last_seen_at: threeMinutesAgo,
            place: place('synweb101', '10.0.0.7'),
            peak_today: { streams_busy: 0, streams_total: 0, at: null },
          }),
        ],
      }),
    })
  })
}
