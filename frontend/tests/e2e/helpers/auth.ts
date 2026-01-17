import type { Page, APIRequestContext } from '@playwright/test'
import { selectors } from '../helpers/selectors'

interface AdminUserSummary {
  id: number
  email: string | null
  emailVerified: boolean
}

interface AdminUsersResponse {
  users?: AdminUserSummary[]
}

export async function login(page: Page, credentials?: { user: string; pass: string }) {
  const user = credentials?.user ?? process.env.AUTH_USER ?? 'admin@synaplan.com'
  const pass = credentials?.pass ?? process.env.AUTH_PASS ?? 'admin123'

  await page.goto('/login')

  await page.fill(selectors.login.email, user)
  await page.fill(selectors.login.password, pass)
  await page.click(selectors.login.submit)

  try {
    await page.waitForSelector(selectors.chat.textInput, { timeout: 10_000 })
  } catch {
    throw new Error(`Login fehlgeschlagen. Aktuelle URL: ${page.url()}`)
  }
}

/**
 * Login via API and return cookie header string for authenticated requests
 */
export async function loginViaApi(
  request: APIRequestContext,
  credentials?: { user: string; pass: string }
): Promise<string> {
  const baseUrl = process.env.BASE_URL || 'http://localhost:5173'
  const user = credentials?.user ?? process.env.AUTH_USER ?? 'admin@synaplan.com'
  const pass = credentials?.pass ?? process.env.AUTH_PASS ?? 'admin123'

  const response = await request.post(`${baseUrl}/api/v1/auth/login`, {
    data: {
      email: user,
      password: pass,
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

export async function getUserByEmail(
  request: APIRequestContext,
  userEmail: string
): Promise<AdminUserSummary | null> {
  const baseUrl = process.env.BASE_URL || 'http://localhost:5173'

  // Login as admin via API and get cookie header
  const cookieHeader = await loginViaApi(request)

  const usersResponse = await request.get(
    `${baseUrl}/api/v1/admin/users?search=${encodeURIComponent(userEmail)}`,
    {
      headers: {
        Cookie: cookieHeader,
      },
    }
  )

  if (!usersResponse.ok()) {
    throw new Error(`Failed to fetch users: ${usersResponse.status()}`)
  }

  const usersData: AdminUsersResponse = await usersResponse.json()
  const targetUser = usersData.users?.find((u) => u.email === userEmail)

  return targetUser ?? null
}

/**
 * Delete user by email via admin API
 */
export async function deleteUser(request: APIRequestContext, userEmail: string): Promise<boolean> {
  const baseUrl = process.env.BASE_URL || 'http://localhost:5173'

  try {
    // Login as admin via API and get cookie header
    const cookieHeader = await loginViaApi(request)

    // Find user by email via admin API
    const usersResponse = await request.get(
      `${baseUrl}/api/v1/admin/users?search=${encodeURIComponent(userEmail)}`,
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
    const deleteResponse = await request.delete(`${baseUrl}/api/v1/admin/users/${targetUser.id}`, {
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
