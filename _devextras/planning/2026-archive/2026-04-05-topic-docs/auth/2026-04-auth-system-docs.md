# Authentication System - Technical Documentation

## Overview

Cookie-based Access Token + Refresh Token system with OIDC/Keycloak support.

- **Access Token**: 5 min TTL, HMAC-signed, stored in HttpOnly cookie
- **Refresh Token**: 7 days TTL, stored in DB + HttpOnly cookie, rotates on each use
- **OIDC Tokens**: Keycloak's own tokens stored separately, used for session validation

---

## Backend Files

### Core Token Service
**`backend/src/Service/TokenService.php`**
- `generateAccessToken(User)` → Creates HMAC-signed access token (5 min)
- `generateRefreshToken(User)` → Creates refresh token, stores in `BTOKEN` table
- `validateAccessToken(string)` → Validates signature + expiry, returns user data
- `refreshTokens(string)` → Validates refresh token, revokes old, issues new pair
- `revokeAllUserTokens(User)` → Admin can invalidate all user sessions
- `createAccessCookie()` / `createRefreshCookie()` → HttpOnly cookie helpers

### OIDC Token Service
**`backend/src/Service/OidcTokenService.php`**
- `storeOidcTokens(Response, accessToken, refreshToken, provider)` → Stores Keycloak tokens in cookies
- `refreshOidcTokens(Request)` → Calls Keycloak token endpoint with refresh_token
- `validateOidcToken(accessToken)` → Calls Keycloak UserInfo endpoint
- `getUserFromOidcToken(tokenData)` → Finds/creates user from OIDC claims
- `clearOidcCookies(Response)` → Removes OIDC cookies on logout

### Security / Authenticator
**`backend/src/Security/CookieTokenAuthenticator.php`**
- Symfony authenticator for protected routes
- Reads `access_token` from cookie or `Authorization: Bearer` header
- Validates via `TokenService::validateAccessToken()`
- Returns 401 JSON on failure

**`backend/src/Security/QueryTokenAuthenticator.php`**
- For SSE endpoints (EventSource can't send cookies)
- Reads token from `?token=` query parameter
- Used by `/api/v1/messages/stream`

### Controllers
**`backend/src/Controller/AuthController.php`**
- `POST /api/v1/auth/login` → Sets access_token + refresh_token cookies
- `POST /api/v1/auth/refresh` → **OIDC-aware**: checks for oidc_refresh_token cookie
  - If OIDC user: calls `OidcTokenService::refreshOidcTokens()` first
  - If Keycloak rejects: returns `OIDC_SESSION_EXPIRED` error
  - If local user: uses `TokenService::refreshTokens()`
- `POST /api/v1/auth/logout` → Clears all cookies, revokes refresh token
- `GET /api/v1/auth/token` → Returns short-lived token for SSE (since EventSource can't send cookies)
- `POST /api/v1/auth/revoke-all/{userId}` → Admin endpoint to logout user everywhere

**`backend/src/Controller/KeycloakAuthController.php`**
- `GET /api/v1/auth/keycloak` → Redirects to Keycloak login
- `GET /api/v1/auth/keycloak/callback` → Handles OAuth callback
  - Exchanges code for Keycloak tokens
  - Stores Keycloak tokens via `OidcTokenService::storeOidcTokens()`
  - Creates our own tokens via `TokenService::addAuthCookies()`
  - Finds/creates user, stores `oidc_sub` in `userDetails`

### Entity
**`backend/src/Entity/Token.php`**
- Stores refresh tokens in DB
- Fields: `user_id`, `token` (hashed), `type`, `expires_at`, `used_at`
- `used_at` != null means token was consumed (rotation)

### Configuration
**`backend/config/packages/security.yaml`**
```yaml
firewalls:
    api:
        stateless: true
        custom_authenticators:
            - App\Security\CookieTokenAuthenticator
```

---

## Frontend Files

### Auth Store
**`frontend/src/stores/auth.ts`**
- `user` ref → Current user (in memory only, NOT localStorage)
- `isAuthenticated` computed → `user !== null`
- `checkAuth()` → Calls `/api/v1/auth/me` to populate user from cookies
- `authReady` Promise → Resolves when initial auth check complete (prevents router race condition)
- `login()` / `logout()` → API calls, no token storage

### Auth Service
**`frontend/src/services/authService.ts`**
- `login(email, password)` → POST to `/auth/login`, cookies set by backend
- `logout()` → POST to `/auth/logout`
- `refreshToken()` → POST to `/auth/refresh`
- `getCurrentUser()` → GET `/auth/me`
- `getSseToken()` → GET `/auth/token` for EventSource connections
- `isAuthenticated()` → Checks if user in store

### HTTP Client
**`frontend/src/services/api/httpClient.ts`**
- All requests include `credentials: 'include'` (sends cookies)
- On 401: automatically calls `refreshToken()`
- If refresh returns `OIDC_SESSION_EXPIRED`: triggers logout + redirect
- No `Authorization` header needed (cookies handle it)

### Chat API (SSE Special Case)
**`frontend/src/services/api/chatApi.ts`**
- `streamMessage()` → Fetches SSE token via `getSseToken()`
- Appends `?token=xxx` to EventSource URL
- Reason: `EventSource` API cannot send cookies or headers

### Router
**`frontend/src/router/index.ts`**
- Global navigation guard `await authReady` before checking auth
- Prevents redirect-to-login flash on page refresh
- No `localStorage` token checks anymore

### OAuth Callback
**`frontend/src/components/auth/OAuthCallback.vue`**
- After Keycloak redirect: cookies already set by backend
- Calls `authStore.handleOAuthCallback()` to fetch user data
- No URL token parsing needed

---

## Cookie Summary

| Cookie | Content | TTL | Purpose |
|--------|---------|-----|---------|
| `access_token` | HMAC-signed JWT-like | 5 min | API authentication |
| `refresh_token` | Random string | 7 days | Get new access token |
| `oidc_access_token` | Keycloak's token | Variable | Stored but unused |
| `oidc_refresh_token` | Keycloak's refresh | Variable | Validate session on refresh |
| `oidc_provider` | "keycloak" | Session | Identify OIDC user |

All cookies are `HttpOnly`, `SameSite=Lax`, `Secure` (in production).

---

## Flow Diagrams

### Local User Login
```
Frontend                    Backend
   │ POST /auth/login          │
   │ {email, password}         │
   │──────────────────────────>│
   │                           │ Validate credentials
   │                           │ Generate access_token
   │                           │ Generate refresh_token (store in DB)
   │  Set-Cookie: access_token │
   │  Set-Cookie: refresh_token│
   │<──────────────────────────│
   │                           │
   │ GET /auth/me              │
   │ (cookies auto-sent)       │
   │──────────────────────────>│
   │  {id, email, level}       │
   │<──────────────────────────│
```

### Keycloak Login
```
Frontend          Backend              Keycloak
   │                  │                    │
   │ /auth/keycloak   │                    │
   │─────────────────>│                    │
   │  302 Redirect    │                    │
   │<─────────────────│                    │
   │                  │                    │
   │──────────────────────────────────────>│
   │                  │    User logs in    │
   │<──────────────────────────────────────│
   │  ?code=xxx       │                    │
   │─────────────────>│                    │
   │                  │ Exchange code      │
   │                  │───────────────────>│
   │                  │ {access, refresh}  │
   │                  │<───────────────────│
   │                  │                    │
   │                  │ Store OIDC tokens in cookies
   │                  │ Generate OUR tokens
   │                  │ Find/create user
   │  Set-Cookie: x4  │
   │<─────────────────│
```

### Token Refresh (OIDC User)
```
Frontend              Backend                 Keycloak
   │ 401 Unauthorized    │                       │
   │<────────────────────│                       │
   │                     │                       │
   │ POST /auth/refresh  │                       │
   │ (cookies sent)      │                       │
   │────────────────────>│                       │
   │                     │ Has oidc_refresh_token?
   │                     │ YES → Call Keycloak   │
   │                     │──────────────────────>│
   │                     │                       │
   │                     │ Keycloak says OK?     │
   │                     │<──────────────────────│
   │                     │                       │
   │                     │ YES: Issue new tokens │
   │  New cookies        │ NO: OIDC_SESSION_EXPIRED
   │<────────────────────│                       │
```

---

## Admin Actions

### Logout a User Everywhere
```bash
POST /api/v1/auth/revoke-all/{userId}
# Requires ROLE_ADMIN
# Revokes all refresh tokens for that user
# User will be logged out on next refresh (max 5 min)
```

### Keycloak Admin Logout
- Admin logs out user in Keycloak admin console
- Next time user's access_token expires (max 5 min)
- Backend calls Keycloak with refresh_token
- Keycloak rejects → User gets `OIDC_SESSION_EXPIRED`
- Frontend redirects to login

