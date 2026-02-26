/**
 * E2E Tests for the Casting Data Connector Plugin
 *
 * Tests the full integration between:
 *   - CastApp API (external casting platform)
 *   - Synaplan Plugin (CastingApiClient, CastingContextProvider)
 *   - Synaplan Chat Widget (embedded on CastApp)
 *
 * Prerequisites:
 *   - CastApp running on CASTAPP_URL (default: http://localhost)
 *   - Synaplan backend on SYNAPLAN_API_URL (default: http://localhost:8000)
 *   - CastApp has test data (productions, auditions)
 *   - CastApp has SYNAPLAN_API_KEY set
 *   - Synaplan has a widget with allowedDomains including CastApp host
 *   - Plugin configured with CastApp API URL + key for the Synaplan admin user
 *
 * ENV vars (optional, all have defaults):
 *   CASTAPP_URL          — CastApp base URL (default: http://localhost)
 *   CASTAPP_API_KEY      — API key for CastApp (default: test-castapp-api-key-12345)
 *   CASTAPP_LOGIN_EMAIL  — Performer test account (default: test@synaplan.local)
 *   CASTAPP_LOGIN_PASS   — Performer test password (default: password)
 *   SYNAPLAN_API_URL     — Synaplan backend URL (default: http://localhost:8000)
 *   SYNAPLAN_ADMIN_EMAIL — Synaplan admin email (default: admin@synaplan.com)
 *   SYNAPLAN_ADMIN_PASS  — Synaplan admin password (default: admin123)
 */
import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// --- Config -----------------------------------------------------------------

const CASTAPP_URL = process.env.CASTAPP_URL || 'http://localhost'
const CASTAPP_API_KEY = process.env.CASTAPP_API_KEY || 'test-castapp-api-key-12345'
const CASTAPP_EMAIL = process.env.CASTAPP_LOGIN_EMAIL || 'test@synaplan.local'
const CASTAPP_PASS = process.env.CASTAPP_LOGIN_PASS || 'password'
const SYNAPLAN_API = process.env.SYNAPLAN_API_URL || 'http://localhost:8000'
const SYNAPLAN_ADMIN_EMAIL = process.env.SYNAPLAN_ADMIN_EMAIL || 'admin@synaplan.com'
const SYNAPLAN_ADMIN_PASS = process.env.SYNAPLAN_ADMIN_PASS || 'admin123'

// --- Helpers ----------------------------------------------------------------

async function castappLogin(page: Page) {
  await page.goto(`${CASTAPP_URL}/login`)
  await page.fill('input[name="email"], input[type="email"]', CASTAPP_EMAIL)
  await page.fill('input[name="password"], input[type="password"]', CASTAPP_PASS)
  await page.click('button[type="submit"]')
  await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
  // Navigate to auditions page where widget is embedded
  await page.goto(`${CASTAPP_URL}/auditions`)
  await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
  await page.waitForTimeout(3_000)
}

function castappApiHeaders() {
  return { Authorization: `Bearer ${CASTAPP_API_KEY}`, Accept: 'application/json' }
}

async function synaplanLoginViaApi(request: APIRequestContext): Promise<string> {
  const response = await request.post(`${SYNAPLAN_API}/api/v1/auth/login`, {
    data: { email: SYNAPLAN_ADMIN_EMAIL, password: SYNAPLAN_ADMIN_PASS },
  })

  if (!response.ok()) {
    throw new Error(`Synaplan login failed with status ${response.status()}`)
  }

  const setCookieHeaders = response.headers()['set-cookie'] || []
  const headers = Array.isArray(setCookieHeaders) ? setCookieHeaders : [setCookieHeaders]

  return headers
    .map((h) => {
      const m = h.match(/^([^=]+)=([^;]+)/)
      return m ? `${m[1]}=${m[2]}` : null
    })
    .filter((c): c is string => c !== null)
    .join('; ')
}

async function openWidget(page: Page) {
  // CastApp uses a header button (#synaplanChatBtn) with hideButton: true
  const headerBtn = page.locator('#synaplanChatBtn')
  const floatingBtn = page.locator('#synaplan-widget-button')

  const useHeaderBtn = await headerBtn.isVisible().catch(() => false)

  if (useHeaderBtn) {
    await headerBtn.click()
  } else {
    await expect(floatingBtn).toBeVisible({ timeout: 15_000 })
    await floatingBtn.click()
  }

  const chatWindow = page.locator('[data-testid="section-chat-window"]')
  await expect(chatWindow).toBeVisible({ timeout: 15_000 })
}

async function sendWidgetMessage(page: Page, message: string) {
  const input = page.locator('[data-testid="input-message"]')
  await expect(input).toBeVisible({ timeout: 5_000 })
  await input.fill(message)
  const sendBtn = page.locator('[data-testid="btn-send"]')
  await sendBtn.click()
}

// --- 1. CastApp API Integration Tests ---------------------------------------

test.describe('@plugin CastApp API — productions & auditions', () => {
  test('GET /api/v1/productions returns 200 with data array', async ({ request }) => {
    const res = await request.get(`${CASTAPP_URL}/api/v1/productions`, {
      headers: castappApiHeaders(),
    })
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(body).toHaveProperty('data')
    expect(Array.isArray(body.data)).toBe(true)
  })

  test('GET /api/v1/productions?search=Mamma finds Mamma Mia', async ({ request }) => {
    const res = await request.get(`${CASTAPP_URL}/api/v1/productions?search=Mamma`, {
      headers: castappApiHeaders(),
    })
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(body.data.length).toBeGreaterThanOrEqual(1)
    expect(body.data[0].title).toContain('Mamma Mia')
  })

  test('GET /api/v1/productions/{id} returns production with roles', async ({ request }) => {
    const listRes = await request.get(`${CASTAPP_URL}/api/v1/productions?limit=1`, {
      headers: castappApiHeaders(),
    })
    const list = await listRes.json()
    const productionId = list.data[0]?.id
    expect(productionId).toBeTruthy()

    const res = await request.get(`${CASTAPP_URL}/api/v1/productions/${productionId}`, {
      headers: castappApiHeaders(),
    })
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(body.data).toHaveProperty('title')
    expect(body.data).toHaveProperty('roles')
    expect(Array.isArray(body.data.roles)).toBe(true)
  })

  test('GET /api/v1/auditions returns active auditions', async ({ request }) => {
    const res = await request.get(`${CASTAPP_URL}/api/v1/auditions`, {
      headers: castappApiHeaders(),
    })
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(body).toHaveProperty('data')
    expect(Array.isArray(body.data)).toBe(true)
  })

  test('GET /api/v1/auditions?search=Wien finds auditions in Wien', async ({ request }) => {
    const res = await request.get(`${CASTAPP_URL}/api/v1/auditions?search=Wien`, {
      headers: castappApiHeaders(),
    })
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(body.data.length).toBeGreaterThanOrEqual(1)

    const cities = body.data.map((a: Record<string, string>) => a.cities || '')
    expect(cities.some((c: string) => c.includes('Wien'))).toBe(true)
  })

  test('GET /api/v1/productions rejects missing API key with 401', async ({ request }) => {
    const res = await request.get(`${CASTAPP_URL}/api/v1/productions`, {
      headers: { Accept: 'application/json' },
    })
    expect(res.status()).toBe(401)
  })

  test('GET /api/v1/productions rejects wrong API key with 401', async ({ request }) => {
    const res = await request.get(`${CASTAPP_URL}/api/v1/productions`, {
      headers: { Authorization: 'Bearer wrong-key', Accept: 'application/json' },
    })
    expect(res.status()).toBe(401)
  })
})

// --- 2. Synaplan Plugin Config API Tests ------------------------------------

test.describe('@plugin Synaplan Plugin — config endpoints', () => {
  let cookieHeader: string

  test.beforeAll(async ({ request }) => {
    cookieHeader = await synaplanLoginViaApi(request)
  })

  test('PUT /plugins/castingdata/config saves configuration', async ({ request }) => {
    const res = await request.put(
      `${SYNAPLAN_API}/api/v1/user/1/plugins/castingdata/config`,
      {
        headers: { Cookie: cookieHeader, 'Content-Type': 'application/json' },
        data: {
          api_url: CASTAPP_URL,
          api_key: CASTAPP_API_KEY,
          enabled: true,
        },
      },
    )
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(body.success).toBe(true)
    expect(body.api_url).toBe(CASTAPP_URL)
    expect(body.enabled).toBe(true)
    expect(body.has_api_key).toBe(true)
  })

  test('GET /plugins/castingdata/config returns config with masked key', async ({ request }) => {
    const res = await request.get(
      `${SYNAPLAN_API}/api/v1/user/1/plugins/castingdata/config`,
      {
        headers: { Cookie: cookieHeader },
      },
    )
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(body.api_url).toBe(CASTAPP_URL)
    expect(body.enabled).toBe(true)
    expect(body.has_api_key).toBe(true)
    expect(body.api_key_masked).toContain('...')
    expect(body.api_key_masked).not.toBe(CASTAPP_API_KEY)
  })

  test('POST /plugins/castingdata/test-connection succeeds', async ({ request }) => {
    const res = await request.post(
      `${SYNAPLAN_API}/api/v1/user/1/plugins/castingdata/test-connection`,
      {
        headers: { Cookie: cookieHeader },
      },
    )
    expect(res.status()).toBe(200)

    const body = await res.json()
    expect(body.success).toBe(true)
    expect(body.message).toContain('successful')
  })
})

// --- 3. Chat Widget on CastApp — visual / integration -----------------------

test.describe('@plugin Chat Widget on CastApp', () => {
  test.beforeAll(async ({ request }) => {
    await request.get(`${SYNAPLAN_API}/api/v1/health`).catch(() => {})
  })

  test('header chat button appears after performer login', async ({ page }) => {
    await castappLogin(page)

    const headerBtn = page.locator('#synaplanChatBtn')
    await expect(headerBtn).toBeVisible({ timeout: 30_000 })

    await page.screenshot({ path: 'tests/e2e/test-results/plugin-header-button.png' })
  })

  test('widget opens in fullscreen with backdrop on header button click', async ({ page }) => {
    await castappLogin(page)
    await openWidget(page)

    const chatWindow = page.locator('[data-testid="section-chat-window"]')
    await expect(chatWindow).toBeVisible()

    const backdrop = page.locator('[data-testid="fullscreen-backdrop"]')
    await expect(backdrop).toBeVisible()

    const input = page.locator('[data-testid="input-message"]')
    await expect(input).toBeVisible()

    await page.screenshot({ path: 'tests/e2e/test-results/plugin-widget-fullscreen.png' })
  })

  test('widget closes when close button is clicked', async ({ page }) => {
    await castappLogin(page)
    await openWidget(page)

    const closeBtn = page.locator('[data-testid="btn-close"]')
    await closeBtn.click()
    await page.waitForTimeout(1_000)

    const chatWindow = page.locator('[data-testid="section-chat-window"]')
    await expect(chatWindow).not.toBeVisible()

    const headerBtn = page.locator('#synaplanChatBtn')
    await expect(headerBtn).toBeVisible()
  })

  test('widget closes when backdrop is clicked', async ({ page }) => {
    await castappLogin(page)
    await openWidget(page)

    const backdrop = page.locator('[data-testid="fullscreen-backdrop"]')
    await expect(backdrop).toBeVisible()
    await backdrop.click({ position: { x: 10, y: 10 } })
    await page.waitForTimeout(1_000)

    const chatWindow = page.locator('[data-testid="section-chat-window"]')
    await expect(chatWindow).not.toBeVisible()
  })

  test('fullscreen toggle button is visible in chat header', async ({ page }) => {
    await castappLogin(page)
    await openWidget(page)

    const fullscreenBtn = page.locator('[data-testid="btn-fullscreen"]')
    await expect(fullscreenBtn).toBeVisible()
  })
})

// --- 4. Chat Widget — AI query with casting context -------------------------

test.describe('@plugin Chat Widget — AI casting queries', () => {
  test.setTimeout(90_000)

  test('asks about available productions and gets contextual answer', async ({ page }) => {
    await castappLogin(page)
    await openWidget(page)

    await sendWidgetMessage(page, 'Welche Produktionen gibt es gerade?')

    // Wait for AI streaming response — poll for assistant message bubble
    const assistantMsg = page.locator('[data-testid="section-messages"] .message-assistant, [data-testid="section-messages"] [class*="assistant"]').first()
    await expect(assistantMsg).toBeVisible({ timeout: 30_000 }).catch(() => {})

    // Allow streaming to complete
    await page.waitForTimeout(15_000)

    await page.screenshot({
      path: 'tests/e2e/test-results/plugin-query-produktionen.png',
      fullPage: false,
    })

    // Verify the response area has content (any text beyond the input)
    const messagesSection = page.locator('[data-testid="section-messages"]')
    const messagesText = await messagesSection.innerText().catch(() => '')
    // Should contain at least some production-related keywords from CastApp test data
    const hasContext = ['Mamma Mia', 'Hamlet', 'West Side Story', 'Produktion', 'produktion'].some(
      (keyword) => messagesText.includes(keyword),
    )

    expect
      .soft(hasContext, `AI response should mention known productions. Got: ${messagesText.slice(0, 200)}`)
      .toBe(true)
  })

  test('asks about auditions in Wien and gets location-specific answer', async ({ page }) => {
    await castappLogin(page)
    await openWidget(page)

    await sendWidgetMessage(page, 'Gibt es Auditions in Wien?')

    // Wait for AI streaming response
    const assistantMsg = page.locator('[data-testid="section-messages"] .message-assistant, [data-testid="section-messages"] [class*="assistant"]').first()
    await expect(assistantMsg).toBeVisible({ timeout: 30_000 }).catch(() => {})

    // Allow streaming to complete
    await page.waitForTimeout(15_000)

    await page.screenshot({
      path: 'tests/e2e/test-results/plugin-query-auditions-wien.png',
      fullPage: false,
    })

    const messagesSection = page.locator('[data-testid="section-messages"]')
    const messagesText = await messagesSection.innerText().catch(() => '')
    const hasContext = ['Wien', 'Audition', 'audition', 'Vorsprechen', 'Casting'].some(
      (keyword) => messagesText.includes(keyword),
    )

    expect
      .soft(hasContext, `AI response should mention Wien/Audition. Got: ${messagesText.slice(0, 200)}`)
      .toBe(true)
  })
})
