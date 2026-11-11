import { test, expect, LOGGED_OUT } from '../test-setup'
import { deleteUser, loginViaOidcButton, getAuthHeaders } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { getApiUrl } from '../config/config'

// Directory provisioning is independent of the self-sign-up switch (D2).
// This file is @oidc only, so the sharded password jobs never see the flip.
// The switch is installation-wide, so it is held for this one journey and
// put back in finally, including when the journey fails.
test.use(LOGGED_OUT)

const ADA = { user: 'ada@example.org', pass: 'ada-dev-pass' }
const DEMO = { user: 'demo@synaplan.com', pass: 'demo123' }
const REGISTRATION_KEY = 'REGISTRATION_ENABLED'

type RegistrationValue = {
  value?: string
  isSet?: boolean
  envOverride?: boolean
  effectiveValue?: string
}

test.describe('@oidc SSO-only login', () => {
  test('registration off still shows company login, and a first login creates the account', async ({
    page,
    request,
  }) => {
    test.skip(
      process.env.AUTH_METHOD !== 'oidc',
      'This journey flips the installation-wide sign-up switch and needs Keycloak'
    )

    const api = getApiUrl()
    const admin = await getAuthHeaders(request, CREDENTIALS.getAdminCredentials())
    const current = await request.get(`${api}/api/v1/admin/config/values`, { headers: admin })
    expect(current.ok()).toBeTruthy()
    const values = (await current.json()) as { values?: Record<string, RegistrationValue> }
    const registration = values.values?.[REGISTRATION_KEY]
    test.skip(
      registration?.envOverride === true && registration.effectiveValue !== 'false',
      'REGISTRATION_ENABLED is pinned on by the environment, so the admin switch cannot close sign-up'
    )

    const previous = registration?.isSet ? (registration.value ?? 'true') : 'true'
    const setRegistration = (value: string) =>
      request.put(`${api}/api/v1/admin/config/values`, {
        headers: admin,
        data: { key: REGISTRATION_KEY, value },
      })

    await deleteUser(request, ADA.user)
    const closed = previous === 'false' && registration?.envOverride === true

    try {
      if (!closed) {
        expect((await setRegistration('false')).ok(), 'turn self-sign-up off').toBeTruthy()
      }

      await page.goto('/login')
      await expect(page.locator(selectors.oidc.keycloakButton)).toBeVisible()
      await expect(page.locator(selectors.login.signUpLink)).toHaveCount(0)
      await expect(page.getByTestId('login-registration-closed')).toBeVisible()
      await expect(page.locator(selectors.login.password)).toBeVisible()

      await page.goto('/register')
      const closedPage = page.getByTestId('register-closed')
      await expect(closedPage).toBeVisible()
      await closedPage.locator('button').click()
      await expect(page).toHaveURL(/\/login/)
      await expect(page.locator(selectors.oidc.keycloakButton)).toBeVisible()
      await expect(page.locator(selectors.login.signUpLink)).toHaveCount(0)

      const stranger = `closed-${Date.now()}@test.synaplan.com`
      const refused = await request.post(`${api}/api/v1/auth/register`, {
        data: { email: stranger, password: 'ClosedPass9!' },
      })
      expect(refused.status()).toBe(403)
      expect(await refused.json()).toMatchObject({ code: 'SIGNUP_CLOSED' })

      // fetch, not the Playwright request jar: a successful sign-in must not
      // replace the admin cookie the cleanup calls still need.
      const demo = await fetch(`${api}/api/v1/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: DEMO.user, password: DEMO.pass }),
      })
      expect(demo.ok, 'an existing password account still signs in').toBeTruthy()

      const before = await request.get(
        `${api}/api/v1/admin/users?search=${encodeURIComponent(ADA.user)}`,
        { headers: admin }
      )
      expect(before.ok()).toBeTruthy()
      const beforeBody = (await before.json()) as { users?: { email?: string | null }[] }
      expect(beforeBody.users?.some((user) => user.email === ADA.user)).toBeFalsy()

      await loginViaOidcButton(page, ADA)
      await expect(page.locator(selectors.chat.textInput)).toBeVisible()

      const listed = await request.get(
        `${api}/api/v1/admin/users?search=${encodeURIComponent(ADA.user)}`,
        { headers: admin }
      )
      expect(listed.ok()).toBeTruthy()
      const body = (await listed.json()) as { users?: { email?: string | null }[] }
      expect(body.users?.some((user) => user.email === ADA.user)).toBeTruthy()
    } finally {
      if (!closed) {
        await setRegistration(previous)
      }
      await deleteUser(request, ADA.user)
    }
  })
})
