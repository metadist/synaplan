import { test, expect } from '@playwright/test'
import { getOidcAccessToken, exchangeTokenForSynaplan } from '../helpers/auth'
import { getApiUrl } from '../config/config'

/**
 * OIDC Token Exchange E2E tests.
 *
 * Tests the full token exchange flow used by synaplan-opencloud:
 * 1. Get a user token from Keycloak (direct access grant)
 * 2. Exchange it via the confidential client for a Synaplan-scoped token
 * 3. Call Synaplan API with the exchanged token
 * 4. Verify the user is authenticated and auto-provisioned
 *
 * Requires: docker compose --profile oidc (Keycloak running + provisioned)
 */

function apiBaseUrl(): string {
  return getApiUrl()
}

test.describe('@ci @oidc @token-exchange OIDC Token Exchange', () => {
  test('should get a user token from Keycloak via direct access grant', async () => {
    const token = await getOidcAccessToken('opencloud')
    expect(token).toBeTruthy()
    expect(token.split('.').length).toBe(3) // JWT format
  })

  test('should exchange user token for Synaplan-scoped token', async () => {
    const userToken = await getOidcAccessToken('opencloud')
    const exchangedToken = await exchangeTokenForSynaplan(userToken)

    expect(exchangedToken).toBeTruthy()
    expect(exchangedToken.split('.').length).toBe(3)
    expect(exchangedToken).not.toBe(userToken) // Different token
  })

  test('should authenticate to Synaplan API with exchanged token', async () => {
    const userToken = await getOidcAccessToken('opencloud')
    const synaplanToken = await exchangeTokenForSynaplan(userToken)

    const response = await fetch(`${apiBaseUrl()}/api/v1/auth/me`, {
      headers: {
        Authorization: `Bearer ${synaplanToken}`,
      },
    })

    expect(response.status).toBe(200)

    const data = await response.json()
    // Response shape: { success: true, user: { id, email, ... } }
    expect(data.success).toBe(true)
    expect(data.user.email).toBeTruthy()
  })

  test('should auto-provision user on first access via exchanged token', async () => {
    const userToken = await getOidcAccessToken('opencloud')
    const synaplanToken = await exchangeTokenForSynaplan(userToken)

    const response = await fetch(`${apiBaseUrl()}/api/v1/auth/me`, {
      headers: {
        Authorization: `Bearer ${synaplanToken}`,
      },
    })

    expect(response.status).toBe(200)

    const data = await response.json()
    expect(data.user.email).toContain('testuser')
    // User should be provisioned with OIDC details
    expect(data.user.id).toBeGreaterThan(0)
  })

  test('should reject invalid bearer token', async () => {
    const response = await fetch(`${apiBaseUrl()}/api/v1/auth/me`, {
      headers: {
        Authorization: 'Bearer invalid.jwt.token',
      },
    })

    expect(response.status).toBe(401)
  })
})
