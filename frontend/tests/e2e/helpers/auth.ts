import type { Page, APIRequestContext } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS, getApiUrl } from '../config/config'
import { CREDENTIALS } from '../config/credentials'

/** Base URL for API requests (backend port). Use this for all /api/* calls. */
function apiBaseUrl(): string {
  return getApiUrl()
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

/**
 * Cleanup user data but keep user account (for idempotent tests).
 * Uses admin credentials for the admin API request.
 */
export async function cleanupUserData(
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
      console.log(`User ${userEmail} not found - skipping cleanup`)
      return false
    }

    const cleanupResponse = await request.post(
      `${apiBaseUrl()}/api/v1/admin/users/${targetUser.id}/cleanup`,
      {
        headers: {
          Cookie: cookieHeader,
        },
      }
    )

    if (cleanupResponse.status() === 200) {
      return true
    } else {
      console.warn(`Failed to cleanup user data: ${cleanupResponse.status()}`)
      return false
    }
  } catch (error) {
    console.warn(`Error cleaning up user data for ${userEmail}:`, error)
    return false
  }
}
