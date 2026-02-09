import { test, expect } from '../e2e/test-setup'
import { getApiUrl } from '../e2e/config/config'
import { getAuthHeaders } from '../e2e/helpers/auth'
import { CREDENTIALS } from '../e2e/config/credentials'

const apiBase = () => getApiUrl()

test('@noci @api @smoke health endpoint returns 200 id=api-001', async ({ request }) => {
  const res = await request.get(`${apiBase()}/api/health`)
  expect(res.status()).toBe(200)
})

test('@noci @api @smoke login returns 200 and sets auth cookies id=api-002', async ({ request }) => {
  const res = await request.post(`${apiBase()}/api/v1/auth/login`, {
    data: {
      email: CREDENTIALS.DEFAULT_USER,
      password: CREDENTIALS.DEFAULT_PASSWORD,
    },
  })
  expect(res.status()).toBe(200)
  const setCookie = res.headers()['set-cookie']
  expect(setCookie).toBeDefined()
  const cookieStr = Array.isArray(setCookie) ? setCookie.join(' ') : setCookie
  expect(cookieStr).toMatch(/access_token|refresh_token|SESSION/)
})

test('@noci @api authenticated request with getAuthHeaders succeeds id=api-003', async ({ request }) => {
  const headers = await getAuthHeaders(request)
  const res = await request.get(`${apiBase()}/api/v1/chats`, { headers })
  expect(res.status()).toBe(200)
})
