/**
 * API integration test: Inbound Email Handler CRUD.
 * Request-only (no browser) — validates REST lifecycle, auth guards, and input validation.
 */

import { test, expect, LOGGED_OUT } from '../test-setup'
import { getAuthHeaders } from '../helpers/auth'
import { getApiUrl } from '../config/config'

// The request fixture must not inherit worker auth cookies — this suite
// asserts 401 for unauthenticated calls and logs in explicitly where needed.
test.use(LOGGED_OUT)

const API_PATH = '/api/v1/inbound-email-handlers'

function apiUrl(path = ''): string {
  return `${getApiUrl()}${API_PATH}${path}`
}

const HANDLER_PAYLOAD = {
  name: 'E2E Smoke Handler',
  mailServer: 'imap.e2e-test.invalid',
  port: 993,
  protocol: 'IMAP',
  security: 'SSL/TLS',
  username: 'e2e@test.invalid',
  password: 'e2e-test-password',
  checkInterval: 60,
  deleteAfter: false,
  smtpServer: 'smtp.e2e-test.invalid',
  smtpPort: 587,
  smtpSecurity: 'STARTTLS',
  smtpUsername: 'e2e@test.invalid',
  smtpPassword: 'e2e-smtp-password',
  emailFilterMode: 'new',
  departments: [
    {
      name: 'Support',
      email: 'support@e2e-test.invalid',
      rules: 'Technical support and help',
      isDefault: true,
    },
    {
      name: 'Sales',
      email: 'sales@e2e-test.invalid',
      rules: 'Sales inquiries and pricing',
      isDefault: false,
    },
  ],
} as const

test.describe('@ci @api Inbound-Email-Handler API', () => {
  test('full CRUD lifecycle: create → get → list → update → delete', async ({ request }) => {
    const auth = await getAuthHeaders(request)
    let handlerId: number

    await test.step('Create handler', async () => {
      const res = await request.post(apiUrl(), {
        headers: auth,
        data: HANDLER_PAYLOAD,
      })
      expect(res.status()).toBe(201)

      const json = await res.json()
      expect(json.success).toBe(true)
      expect(json.handler.name).toBe(HANDLER_PAYLOAD.name)
      expect(json.handler.status).toBe('active')
      expect(json.handler.departments).toHaveLength(2)
      expect(json.handler.password).toBe('••••••••')

      handlerId = json.handler.id
    })

    await test.step('Get handler by ID', async () => {
      const res = await request.get(apiUrl(`/${handlerId}`), { headers: auth })
      expect(res.status()).toBe(200)

      const json = await res.json()
      expect(json.success).toBe(true)
      expect(json.handler.id).toBe(handlerId)
      expect(json.handler.mailServer).toBe(HANDLER_PAYLOAD.mailServer)
      expect(json.handler.smtpConfig).toBeDefined()
      expect(json.handler.smtpConfig.password).toBe('••••••••')
    })

    await test.step('List handlers includes created one', async () => {
      const res = await request.get(apiUrl(), { headers: auth })
      expect(res.status()).toBe(200)

      const json = await res.json()
      expect(json.success).toBe(true)
      const found = json.handlers.find((h: { id: number }) => h.id === handlerId)
      expect(found).toBeDefined()
      expect(found.name).toBe(HANDLER_PAYLOAD.name)
    })

    await test.step('Update handler name and interval', async () => {
      const res = await request.put(apiUrl(`/${handlerId}`), {
        headers: auth,
        data: { name: 'Updated E2E Handler', checkInterval: 120 },
      })
      expect(res.status()).toBe(200)

      const json = await res.json()
      expect(json.success).toBe(true)
      expect(json.handler.name).toBe('Updated E2E Handler')
      expect(json.handler.checkInterval).toBe(120)
    })

    await test.step('Delete handler', async () => {
      const res = await request.delete(apiUrl(`/${handlerId}`), {
        headers: auth,
      })
      expect(res.status()).toBe(200)

      const json = await res.json()
      expect(json.success).toBe(true)
    })

    await test.step('Deleted handler returns 404', async () => {
      const res = await request.get(apiUrl(`/${handlerId}`), {
        headers: auth,
      })
      expect(res.status()).toBe(404)
    })
  })

  test('unauthenticated requests return 401', async ({ request }) => {
    await test.step('List without auth', async () => {
      const res = await request.get(apiUrl())
      expect(res.status()).toBe(401)
    })

    await test.step('Create without auth', async () => {
      const res = await request.post(apiUrl(), { data: HANDLER_PAYLOAD })
      expect(res.status()).toBe(401)
    })
  })

  test('create with missing fields returns 400', async ({ request }) => {
    const auth = await getAuthHeaders(request)

    const res = await request.post(apiUrl(), {
      headers: auth,
      data: { name: 'Incomplete' },
    })

    expect(res.status()).toBe(400)
    const json = await res.json()
    expect(json.success).toBe(false)
    expect(json.error).toBeDefined()
  })

  test('test-connection preview validates input', async ({ request }) => {
    const auth = await getAuthHeaders(request)

    await test.step('Missing fields returns 400', async () => {
      const res = await request.post(apiUrl('/test-connection'), {
        headers: auth,
        data: { mailServer: 'imap.test.invalid' },
      })
      expect(res.status()).toBe(400)
    })

    await test.step('Invalid server returns connection failure', async () => {
      const res = await request.post(apiUrl('/test-connection'), {
        headers: auth,
        data: {
          mailServer: 'nonexistent.invalid',
          port: 993,
          protocol: 'IMAP',
          security: 'SSL/TLS',
          username: 'user@test.invalid',
          password: 'password',
        },
      })
      expect(res.status()).toBe(200)

      const json = await res.json()
      expect(json.success).toBe(false)
      expect(json.message).toBeDefined()
      expect(json.message.length).toBeGreaterThan(0)
    })
  })
})
