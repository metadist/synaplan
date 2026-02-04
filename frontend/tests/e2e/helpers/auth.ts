import type { Page, APIRequestContext } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { URLS, TIMEOUTS } from '../config/config'
import { CREDENTIALS } from '../config/credentials'

interface AdminUserSummary {
  id: number
  email: string | null
  emailVerified: boolean
}

interface AdminUsersResponse {
  users?: AdminUserSummary[]
}

export async function login(page: Page, credentials?: { user: string; pass: string }) {
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

/**
 * Login via API and return cookie header string for authenticated requests
 */
export async function loginViaApi(
  request: APIRequestContext,
  credentials?: { user?: string; pass?: string }
): Promise<string> {
  const creds = CREDENTIALS.getCredentials(credentials)

  const response = await request.post(`${URLS.BASE_URL}/api/v1/auth/login`, {
    data: {
      email: creds.user,
      password: creds.pass,
    },
  })

  if (!response.ok()) {
    throw new Error(`Login failed with status ${response.status()}`)
  }

  // Extract cookies from response headers
  // Set-Cookie headers can be an array or single string
  const setCookieHeaders = response.headers()['set-cookie'] || []
  const cookieHeaders = Array.isArray(setCookieHeaders) ? setCookieHeaders : [setCookieHeaders]

  // Parse cookies: extract name=value pairs from Set-Cookie headers
  // Format: "name=value; Path=/; HttpOnly; SameSite=Lax"
  const cookies = cookieHeaders
    .map((header) => {
      const match = header.match(/^([^=]+)=([^;]+)/)
      return match ? `${match[1]}=${match[2]}` : null
    })
    .filter((cookie): cookie is string => cookie !== null)

  return cookies.join('; ')
}

/**
 * Delete user by email via admin API
 */
export async function deleteUser(request: APIRequestContext, userEmail: string): Promise<boolean> {
  try {
    // Login as admin via API and get cookie header
    const cookieHeader = await loginViaApi(request)

    // Find user by email via admin API
    const usersResponse = await request.get(
      `${URLS.BASE_URL}/api/v1/admin/users?search=${encodeURIComponent(userEmail)}`,
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

    // Delete user via admin API
    const deleteResponse = await request.delete(`${URLS.BASE_URL}/api/v1/admin/users/${targetUser.id}`, {
      headers: {
        Cookie: cookieHeader,
      },
    })

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
 * Cleanup user data but keep user account (for idempotent tests)
 */
export async function cleanupUserData(
  request: APIRequestContext,
  userEmail: string
): Promise<boolean> {
  try {
    // Login as admin via API and get cookie header
    const cookieHeader = await loginViaApi(request)

    // Find user by email via admin API
    const usersResponse = await request.get(
      `${URLS.BASE_URL}/api/v1/admin/users?search=${encodeURIComponent(userEmail)}`,
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

    // Cleanup user data via admin API
    const cleanupResponse = await request.post(
      `${URLS.BASE_URL}/api/v1/admin/users/${targetUser.id}/cleanup`,
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
