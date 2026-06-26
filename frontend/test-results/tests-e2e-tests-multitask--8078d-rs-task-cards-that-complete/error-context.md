# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: tests/e2e/tests/multitask.spec.ts >> @ci @multitask Multi-task routing >> a multi-node request renders task cards that complete
- Location: tests/e2e/tests/multitask.spec.ts:26:3

# Error details

```
Error: page.goto: Protocol error (Page.navigate): Cannot navigate to invalid URL
Call log:
  - navigating to "/login", waiting until "load"

```

# Test source

```ts
  1   | import type { Page, APIRequestContext } from '@playwright/test'
  2   | import { selectors } from '../helpers/selectors'
  3   | import { TIMEOUTS, getApiUrl } from '../config/config'
  4   | import { CREDENTIALS } from '../config/credentials'
  5   |
  6   | /** Base URL for API requests (backend port). Use this for all /api/* calls. */
  7   | function apiBaseUrl(): string {
  8   |   return getApiUrl()
  9   | }
  10  |
  11  | const KEYCLOAK_URL = process.env.KEYCLOAK_URL || 'http://host.docker.internal:8080'
  12  | const KEYCLOAK_REALM = 'synaplan'
  13  | const KEYCLOAK_TOKEN_ENDPOINT = `${KEYCLOAK_URL}/realms/${KEYCLOAK_REALM}/protocol/openid-connect/token`
  14  |
  15  | /**
  16  |  * Retrieve an OIDC access token via direct access grant (resource owner password).
  17  |  * Used for API-only tests that need an OIDC token without browser interaction.
  18  |  */
  19  | export async function retrieveOidcAccessToken(options: {
  20  |   clientId: string
  21  |   username: string
  22  |   password: string
  23  |   grantType?: string
  24  | }): Promise<string> {
  25  |   const response = await fetch(KEYCLOAK_TOKEN_ENDPOINT, {
  26  |     method: 'POST',
  27  |     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  28  |     body: new URLSearchParams({
  29  |       grant_type: options.grantType ?? 'password',
  30  |       client_id: options.clientId,
  31  |       username: options.username,
  32  |       password: options.password,
  33  |     }),
  34  |   })
  35  |
  36  |   if (!response.ok) {
  37  |     const body = await response.text()
  38  |     throw new Error(`Failed to retrieve OIDC access token: ${response.status} ${body}`)
  39  |   }
  40  |
  41  |   const data = await response.json()
  42  |   return data.access_token
  43  | }
  44  |
  45  | /**
  46  |  * Exchange an OIDC token for a different-audience token via RFC 8693 token exchange.
  47  |  */
  48  | export async function exchangeOidcToken(options: {
  49  |   subjectToken: string
  50  |   clientId: string
  51  |   clientSecret: string
  52  |   audience: string
  53  |   subjectTokenType?: string
  54  |   grantType?: string
  55  | }): Promise<string> {
  56  |   const response = await fetch(KEYCLOAK_TOKEN_ENDPOINT, {
  57  |     method: 'POST',
  58  |     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  59  |     body: new URLSearchParams({
  60  |       grant_type: options.grantType ?? 'urn:ietf:params:oauth:grant-type:token-exchange',
  61  |       subject_token: options.subjectToken,
  62  |       subject_token_type:
  63  |         options.subjectTokenType ?? 'urn:ietf:params:oauth:token-type:access_token',
  64  |       audience: options.audience,
  65  |       client_id: options.clientId,
  66  |       client_secret: options.clientSecret,
  67  |     }),
  68  |   })
  69  |
  70  |   if (!response.ok) {
  71  |     const body = await response.text()
  72  |     throw new Error(`Token exchange failed: ${response.status} ${body}`)
  73  |   }
  74  |
  75  |   const data = await response.json()
  76  |   return data.access_token
  77  | }
  78  |
  79  | interface AdminUserSummary {
  80  |   id: number
  81  |   email: string | null
  82  |   emailVerified: boolean
  83  | }
  84  |
  85  | interface AdminUsersResponse {
  86  |   users?: AdminUserSummary[]
  87  | }
  88  |
  89  | export async function login(page: Page, credentials?: { user: string; pass: string }) {
  90  |   if (process.env.AUTH_METHOD === 'oidc') {
  91  |     return loginViaOidcButton(page, credentials)
  92  |   }
  93  |
  94  |   const creds = CREDENTIALS.getCredentials(credentials)
  95  |
> 96  |   await page.goto('/login')
      |              ^ Error: page.goto: Protocol error (Page.navigate): Cannot navigate to invalid URL
  97  |
  98  |   await page.fill(selectors.login.email, creds.user)
  99  |   await page.fill(selectors.login.password, creds.pass)
  100 |   await page.click(selectors.login.submit)
  101 |
  102 |   try {
  103 |     await page.waitForSelector(selectors.chat.textInput, { timeout: TIMEOUTS.STANDARD })
  104 |   } catch {
  105 |     throw new Error(`Login failed: current URL is ${page.url()}`)
  106 |   }
  107 | }
  108 |
  109 | export async function loginViaOidcButton(page: Page, credentials?: { user: string; pass: string }) {
  110 |   const user = credentials?.user ?? process.env.OIDC_USER ?? 'testuser@synaplan.com'
  111 |   const pass = credentials?.pass ?? process.env.OIDC_PASS ?? 'testpass123'
  112 |
  113 |   await page.goto('/login')
  114 |   await page.click(selectors.oidc.keycloakButton)
  115 |
  116 |   // Keycloak login form
  117 |   await page.fill(selectors.oidc.keycloakUsername, user)
  118 |   await page.fill(selectors.oidc.keycloakPassword, pass)
  119 |   await page.click(selectors.oidc.keycloakSubmit)
  120 |
  121 |   // Wait for redirect back + auth
  122 |   await page.waitForSelector(selectors.chat.textInput, { timeout: 15_000 })
  123 | }
  124 |
  125 | export async function loginViaOidcRedirect(
  126 |   page: Page,
  127 |   credentials?: { user: string; pass: string }
  128 | ) {
  129 |   const user = credentials?.user ?? process.env.OIDC_USER ?? 'testuser@synaplan.com'
  130 |   const pass = credentials?.pass ?? process.env.OIDC_PASS ?? 'testpass123'
  131 |
  132 |   await page.goto('/login')
  133 |
  134 |   // Should auto-redirect to Keycloak - wait for Keycloak login form
  135 |   await page.waitForSelector(selectors.oidc.keycloakSubmit, { timeout: 15_000 })
  136 |
  137 |   await page.fill(selectors.oidc.keycloakUsername, user)
  138 |   await page.fill(selectors.oidc.keycloakPassword, pass)
  139 |   await page.click(selectors.oidc.keycloakSubmit)
  140 |
  141 |   await page.waitForSelector(selectors.chat.textInput, { timeout: 15_000 })
  142 | }
  143 |
  144 | /**
  145 |  * Login via API and return cookie header string for authenticated requests
  146 |  */
  147 | export async function loginViaApi(
  148 |   request: APIRequestContext,
  149 |   credentials?: { user?: string; pass?: string }
  150 | ): Promise<string> {
  151 |   const creds = CREDENTIALS.getCredentials(credentials)
  152 |
  153 |   const response = await request.post(`${apiBaseUrl()}/api/v1/auth/login`, {
  154 |     data: {
  155 |       email: creds.user,
  156 |       password: creds.pass,
  157 |     },
  158 |   })
  159 |
  160 |   if (!response.ok()) {
  161 |     throw new Error(`Login failed with status ${response.status()}`)
  162 |   }
  163 |
  164 |   const setCookieHeaders = response.headers()['set-cookie'] || []
  165 |   const cookieHeaders = Array.isArray(setCookieHeaders) ? setCookieHeaders : [setCookieHeaders]
  166 |   const cookies = cookieHeaders
  167 |     .map((header) => {
  168 |       const match = header.match(/^([^=]+)=([^;]+)/)
  169 |       return match ? `${match[1]}=${match[2]}` : null
  170 |     })
  171 |     .filter((cookie): cookie is string => cookie !== null)
  172 |
  173 |   return cookies.join('; ')
  174 | }
  175 |
  176 | /**
  177 |  * Get headers with auth cookie for API requests (e.g. in API-only tests).
  178 |  * Calls loginViaApi and returns { Cookie: '...' } for use with request.get/post etc.
  179 |  */
  180 | export async function getAuthHeaders(
  181 |   request: APIRequestContext,
  182 |   credentials?: { user?: string; pass?: string }
  183 | ): Promise<{ Cookie: string }> {
  184 |   const cookie = await loginViaApi(request, credentials)
  185 |   return { Cookie: cookie }
  186 | }
  187 |
  188 | /**
  189 |  * Delete user by email via admin API (uses admin credentials for the request)
  190 |  */
  191 | export async function deleteUser(
  192 |   request: APIRequestContext,
  193 |   userEmail: string,
  194 |   adminCredentials?: { user: string; pass: string }
  195 | ): Promise<boolean> {
  196 |   try {
```
