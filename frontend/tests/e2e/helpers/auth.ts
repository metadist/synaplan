import type { Page, APIRequestContext } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS, getApiUrl } from '../config/config'
import { CREDENTIALS } from '../config/credentials'

/** Base URL for API requests (backend port). Use this for all /api/* calls. */
function apiBaseUrl(): string {
  return getApiUrl()
}

const KEYCLOAK_URL = process.env.KEYCLOAK_URL || 'http://host.docker.internal:8080'
const KEYCLOAK_REALM = 'synaplan'
const KEYCLOAK_TOKEN_ENDPOINT = `${KEYCLOAK_URL}/realms/${KEYCLOAK_REALM}/protocol/openid-connect/token`

/**
 * Get an OIDC access token via direct access grant (resource owner password).
 * Used for API-only tests that need an OIDC token without browser interaction.
 */
export async function getOidcAccessToken(options: {
  clientId: string
  user?: string
  pass?: string
}): Promise<string> {
  const user = options.user ?? process.env.OIDC_USER ?? 'testuser'
  const pass = options.pass ?? process.env.OIDC_PASS ?? 'testpass123'

  const response = await fetch(KEYCLOAK_TOKEN_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'password',
      client_id: options.clientId,
      username: user,
      password: pass,
    }),
  })

  if (!response.ok) {
    const body = await response.text()
    throw new Error(`Failed to get OIDC access token: ${response.status} ${body}`)
  }

  const data = await response.json()
  return data.access_token
}

/**
 * Exchange a user's OIDC token for a Synaplan-scoped token via RFC 8693 token exchange.
 * Uses the synaplan-opencloud confidential client.
 */
export async function exchangeTokenForSynaplan(subjectToken: string): Promise<string> {
  const clientId = process.env.EXCHANGE_CLIENT_ID || 'synaplan-opencloud'
  const clientSecret = process.env.EXCHANGE_CLIENT_SECRET || 'synaplan-opencloud-secret'
  const audience = process.env.TARGET_AUDIENCE || 'synaplan-app'

  const response = await fetch(KEYCLOAK_TOKEN_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'urn:ietf:params:oauth:grant-type:token-exchange',
      subject_token: subjectToken,
      subject_token_type: 'urn:ietf:params:oauth:token-type:access_token',
      audience,
      client_id: clientId,
      client_secret: clientSecret,
    }),
  })

  if (!response.ok) {
    const body = await response.text()
    throw new Error(`Token exchange failed: ${response.status} ${body}`)
  }

  const data = await response.json()
  return data.access_token
}

interface AdminUserSummary {
  id: number
  email: string | null
  emailVerified: boolean
}

interface AdminUsersResponse {
  users?: AdminUserSummary[]
}

export async function login(page: Page, credentials?: { user: string; pass: string }) {
  if (process.env.AUTH_METHOD === 'oidc') {
    return loginViaOidcButton(page, credentials)
  }

  const creds = CREDENTIALS.getCredentials(credentials)

  await page.goto('/login')

  await page.fill(selectors.login.email, creds.user)
  await page.fill(selectors.login.password, creds.pass)
  await page.click(selectors.login.submit)

  try {
    await page.waitForSelector(selectors.chat.textInput, { timeout: TIMEOUTS.STANDARD })
  } catch {
    throw new Error(`Login failed: current URL is ${page.url()}`)
  }
}

export async function loginViaOidcButton(page: Page, credentials?: { user: string; pass: string }) {
  const user = credentials?.user ?? process.env.OIDC_USER ?? 'testuser@synaplan.com'
  const pass = credentials?.pass ?? process.env.OIDC_PASS ?? 'testpass123'

  await page.goto('/login')
  await page.click(selectors.oidc.keycloakButton)

  // Keycloak login form
  await page.fill(selectors.oidc.keycloakUsername, user)
  await page.fill(selectors.oidc.keycloakPassword, pass)
  await page.click(selectors.oidc.keycloakSubmit)

  // Wait for redirect back + auth
  await page.waitForSelector(selectors.chat.textInput, { timeout: 15_000 })
}

export async function loginViaOidcRedirect(
  page: Page,
  credentials?: { user: string; pass: string }
) {
  const user = credentials?.user ?? process.env.OIDC_USER ?? 'testuser@synaplan.com'
  const pass = credentials?.pass ?? process.env.OIDC_PASS ?? 'testpass123'

  await page.goto('/login')

  // Should auto-redirect to Keycloak - wait for Keycloak login form
  await page.waitForSelector(selectors.oidc.keycloakSubmit, { timeout: 15_000 })

  await page.fill(selectors.oidc.keycloakUsername, user)
  await page.fill(selectors.oidc.keycloakPassword, pass)
  await page.click(selectors.oidc.keycloakSubmit)

  await page.waitForSelector(selectors.chat.textInput, { timeout: 15_000 })
}

/**
 * Login via API and return cookie header string for authenticated requests
 */
export async function loginViaApi(
  request: APIRequestContext,
  credentials?: { user?: string; pass?: string }
): Promise<string> {
  const creds = CREDENTIALS.getCredentials(credentials)

  const response = await request.post(`${apiBaseUrl()}/api/v1/auth/login`, {
    data: {
      email: creds.user,
      password: creds.pass,
    },
  })

  if (!response.ok()) {
    throw new Error(`Login failed with status ${response.status()}`)
  }

  const setCookieHeaders = response.headers()['set-cookie'] || []
  const cookieHeaders = Array.isArray(setCookieHeaders) ? setCookieHeaders : [setCookieHeaders]
  const cookies = cookieHeaders
    .map((header) => {
      const match = header.match(/^([^=]+)=([^;]+)/)
      return match ? `${match[1]}=${match[2]}` : null
    })
    .filter((cookie): cookie is string => cookie !== null)

  return cookies.join('; ')
}

/**
 * Get headers with auth cookie for API requests (e.g. in API-only tests).
 * Calls loginViaApi and returns { Cookie: '...' } for use with request.get/post etc.
 */
export async function getAuthHeaders(
  request: APIRequestContext,
  credentials?: { user?: string; pass?: string }
): Promise<{ Cookie: string }> {
  const cookie = await loginViaApi(request, credentials)
  return { Cookie: cookie }
}

/**
 * Delete user by email via admin API (uses admin credentials for the request)
 */
export async function deleteUser(
  request: APIRequestContext,
  userEmail: string,
  adminCredentials?: { user: string; pass: string }
): Promise<boolean> {
  try {
    const creds = adminCredentials ?? CREDENTIALS.getAdminCredentials()
    const cookieHeader = await loginViaApi(request, creds)
    const usersResponse = await request.get(
      `${apiBaseUrl()}/api/v1/admin/users?search=${encodeURIComponent(userEmail)}`,
      {
        headers: {
          Cookie: cookieHeader,
        },
      }
    )

    if (!usersResponse.ok()) {
      console.warn(`Failed to fetch users: ${usersResponse.status()}`)
      return false
    }

    const usersData: AdminUsersResponse = await usersResponse.json()
    const targetUser = usersData.users?.find((u) => u.email === userEmail)

    if (!targetUser) {
      console.log(`User ${userEmail} not found - may already be deleted`)
      return false
    }

    const deleteResponse = await request.delete(
      `${apiBaseUrl()}/api/v1/admin/users/${targetUser.id}`,
      {
        headers: {
          Cookie: cookieHeader,
        },
      }
    )

    if (deleteResponse.status() === 200) {
      console.log(`User ${userEmail} successfully deleted`)
      return true
    } else {
      console.warn(`Failed to delete user: ${deleteResponse.status()}`)
      return false
    }
  } catch (error) {
    console.warn(`Error deleting user ${userEmail}:`, error)
    return false
  }
}
