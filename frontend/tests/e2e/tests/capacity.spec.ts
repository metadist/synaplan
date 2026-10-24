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
    expect(res.status(), 'the capacity endpoint answers for an admin').toBe(200)
    const body = (await res.json()) as { served_by: string; servers: { id: string }[] }
    expect(res.headers()['x-synaplan-server'], 'every response names its server').toBe(
      body.served_by
    )
    expect(body.servers.map((s) => s.id)).toContain(body.served_by)

    await page.goto('/admin/features')
    await expect(page.locator(selectors.capacity.navLink)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await page.locator(selectors.capacity.navLink).click()
    await expect(page).toHaveURL(/\/admin\/capacity/)
    await expect(page.locator(selectors.pages.capacity)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

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
    await expect(web1.locator(selectors.capacity.place)).toHaveText('Docker · machine synweb100')
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
      'Capacity cannot be read right now because the shared cache does not answer. The servers keep working.'
    )
    await expect(page.locator(selectors.capacity.retry)).toBeVisible()
  })
})

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
  const place = (host: string) => ({
    host,
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
            place: place('synweb100'),
            logs: { collector: 'docker', event_ring_host: '79e4f90bc8c6' },
            streams: { total: 24, busy: 22 },
            peak_today: { streams_busy: 22, streams_total: 24, at: new Date().toISOString() },
          }),
          server('web1-worker', {
            hostname: 'a1b2c3d4e5f6',
            role: 'worker',
            place: place('synweb100'),
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
            place: place('synweb101'),
            peak_today: { streams_busy: 0, streams_total: 0, at: null },
          }),
        ],
      }),
    })
  })
}
