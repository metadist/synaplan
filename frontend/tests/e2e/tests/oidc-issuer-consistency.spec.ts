import { test, expect, type APIRequestContext } from '@playwright/test'

/**
 * OIDC Issuer Consistency E2E tests.
 *
 * Verifies that Keycloak's KC_HOSTNAME + KC_HOSTNAME_BACKCHANNEL_DYNAMIC
 * configuration produces consistent issuers across HTTP and HTTPS ports.
 * The dev stack runs Keycloak with both HTTP (8080) and HTTPS (8443) to
 * support the synaplan-opencloud dev stack, which needs HTTPS for browser
 * OIDC flows. These tests ensure the Keycloak hostname config keeps both
 * ports reporting a consistent issuer.
 *
 * Requires: docker compose --profile oidc (Keycloak with HTTPS enabled).
 * HTTPS uses the Playwright request client, which follows the suite's
 * ignoreHTTPSErrors setting, so Node's certificate checks stay on.
 */

const KC_HTTP = process.env.KEYCLOAK_URL || 'http://host.docker.internal:8080'
const KC_HTTPS = process.env.KEYCLOAK_HTTPS_URL || 'https://host.docker.internal:8443'
const REALM = 'synaplan'

async function fetchDiscovery(
  request: APIRequestContext,
  baseUrl: string
): Promise<Record<string, unknown>> {
  const resp = await request.get(`${baseUrl}/realms/${REALM}/.well-known/openid-configuration`)
  if (!resp.ok()) throw new Error(`Discovery fetch failed: ${resp.status()} from ${baseUrl}`)
  return resp.json() as Promise<Record<string, unknown>>
}

test.describe('@ci @oidc @issuer-consistency Keycloak Issuer Consistency', () => {
  test('HTTP and HTTPS discovery report the same issuer', async ({ request }) => {
    const httpDiscovery = await fetchDiscovery(request, KC_HTTP)
    const httpsDiscovery = await fetchDiscovery(request, KC_HTTPS)

    expect(httpDiscovery.issuer).toBeTruthy()
    expect(httpsDiscovery.issuer).toBeTruthy()
    expect(httpDiscovery.issuer).toBe(httpsDiscovery.issuer)
  })

  test('issuer uses HTTPS scheme', async ({ request }) => {
    const discovery = await fetchDiscovery(request, KC_HTTP)
    expect(discovery.issuer).toMatch(/^https:\/\//)
  })

  test('HTTP backchannel returns HTTP endpoints', async ({ request }) => {
    const discovery = await fetchDiscovery(request, KC_HTTP)
    expect(discovery.token_endpoint).toMatch(/^http:\/\//)
    expect(discovery.jwks_uri).toMatch(/^http:\/\//)
  })

  test('HTTPS frontend returns HTTPS endpoints', async ({ request }) => {
    const discovery = await fetchDiscovery(request, KC_HTTPS)
    expect(discovery.token_endpoint).toMatch(/^https:\/\//)
    expect(discovery.jwks_uri).toMatch(/^https:\/\//)
  })

  test('token minted via HTTP has HTTPS issuer', async ({ request }) => {
    const discovery = await fetchDiscovery(request, KC_HTTP)

    const tokenResp = await request.post(discovery.token_endpoint as string, {
      form: {
        grant_type: 'password',
        client_id: 'opencloud',
        username: 'testuser',
        password: 'testpass123',
      },
    })

    expect(tokenResp.ok()).toBe(true)
    const { access_token } = (await tokenResp.json()) as { access_token: string }

    // Decode JWT payload (no verification, just check iss claim)
    const payload = JSON.parse(atob(access_token.split('.')[1])) as { iss: string }
    expect(payload.iss).toBe(discovery.issuer)
  })
})
