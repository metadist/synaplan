import { test, expect } from '@playwright/test'
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

const KEYCLOAK_URL = process.env.KEYCLOAK_URL || 'http://host.docker.internal:8080'
const KEYCLOAK_REALM = 'synaplan'
const KEYCLOAK_TOKEN_ENDPOINT = `${KEYCLOAK_URL}/realms/${KEYCLOAK_REALM}/protocol/openid-connect/token`

// OpenCloud public client (the user authenticates with this)
const OC_CLIENT_ID = process.env.OC_CLIENT_ID || 'opencloud'

// Confidential client for token exchange
const EXCHANGE_CLIENT_ID = process.env.EXCHANGE_CLIENT_ID || 'synaplan-opencloud'
const EXCHANGE_CLIENT_SECRET = process.env.EXCHANGE_CLIENT_SECRET || 'synaplan-opencloud-secret'

// Target audience (Synaplan's OIDC client)
const TARGET_AUDIENCE = process.env.TARGET_AUDIENCE || 'synaplan-app'

// Keycloak test user
const KC_USER = process.env.OIDC_USER || 'testuser'
const KC_PASS = process.env.OIDC_PASS || 'testpass123'

function apiBaseUrl(): string {
  return getApiUrl()
}

async function getUserToken(): Promise<string> {
  const response = await fetch(KEYCLOAK_TOKEN_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'password',
      client_id: OC_CLIENT_ID,
      username: KC_USER,
      password: KC_PASS,
    }),
  })

  if (!response.ok) {
    const body = await response.text()
    throw new Error(`Failed to get user token: ${response.status} ${body}`)
  }

  const data = await response.json()
  return data.access_token
}

async function exchangeToken(subjectToken: string): Promise<string> {
  const response = await fetch(KEYCLOAK_TOKEN_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'urn:ietf:params:oauth:grant-type:token-exchange',
      subject_token: subjectToken,
      subject_token_type: 'urn:ietf:params:oauth:token-type:access_token',
      audience: TARGET_AUDIENCE,
      client_id: EXCHANGE_CLIENT_ID,
      client_secret: EXCHANGE_CLIENT_SECRET,
    }),
  })

  if (!response.ok) {
    const body = await response.text()
    throw new Error(`Token exchange failed: ${response.status} ${body}`)
  }

  const data = await response.json()
  return data.access_token
}

test.describe('@ci @oidc @token-exchange OIDC Token Exchange', () => {
  test('should get a user token from Keycloak via direct access grant', async () => {
    const token = await getUserToken()
    expect(token).toBeTruthy()
    expect(token.split('.').length).toBe(3) // JWT format
  })

  test('should exchange user token for Synaplan-scoped token', async () => {
    const userToken = await getUserToken()
    const exchangedToken = await exchangeToken(userToken)

    expect(exchangedToken).toBeTruthy()
    expect(exchangedToken.split('.').length).toBe(3)
    expect(exchangedToken).not.toBe(userToken) // Different token
  })

  test('should authenticate to Synaplan API with exchanged token', async () => {
    const userToken = await getUserToken()
    const synaplanToken = await exchangeToken(userToken)

    // Call /api/v1/auth/me with the exchanged token
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
    const userToken = await getUserToken()
    const synaplanToken = await exchangeToken(userToken)

    // First call auto-provisions the user
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
