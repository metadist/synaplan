# OpenID Connect (OIDC) Integration

## 🔐 Overview

Synaplan supports OIDC authentication with **refresh token support** for secure, long-lived sessions. The client secret is **kept server-side only** for security.

## 📋 Configuration

### Environment Variables

Add to `backend/.env.local`:

```bash
# OIDC Discovery URL (ohne .well-known/openid-configuration)
OIDC_DISCOVERY_URL=https://auth.redzone.metadist.de/realms/metadist

# Client Credentials
OIDC_CLIENT_ID=your-client-id
OIDC_CLIENT_SECRET=your-client-secret  # NEVER expose to frontend!

# Optional
OIDC_USER=default-user
```

### Keycloak Configuration

1. **Discovery URL**: https://auth.redzone.metadist.de/realms/metadist/.well-known/openid-configuration
2. **Grant Types**: `authorization_code`, `refresh_token`
3. **Response Types**: `code`, `token`, `id_token`
4. **Scopes**: `openid`, `email`, `profile`

## 🔄 Authentication Flow

### 1. Frontend Login (Authorization Code Flow)

```typescript
// Frontend redirects to OIDC authorization endpoint
const authUrl = `${OIDC_DISCOVERY_URL}/protocol/openid-connect/auth?` +
  `client_id=${CLIENT_ID}&` +
  `redirect_uri=${REDIRECT_URI}&` +
  `response_type=code&` +
  `scope=openid email profile`

window.location.href = authUrl
```

### 2. Backend Token Exchange

After redirect with `code`, frontend sends code to backend:

```typescript
// POST /api/v1/auth/oidc-callback
{
  "code": "authorization_code_here"
}

// Backend exchanges code for tokens (using client_secret)
// Returns: access_token, refresh_token, expires_in
```

### 3. Using Access Token

```typescript
// All API requests
Authorization: Bearer {access_token}
```

### 4. Refresh Token Flow

When access token expires (check `expires_in`):

```typescript
// POST /api/v1/oidc/refresh
{
  "refresh_token": "refresh_token_here"
}

// Response:
{
  "success": true,
  "access_token": "new_access_token",
  "refresh_token": "new_refresh_token",  // May rotate
  "expires_in": 3600,
  "token_type": "Bearer"
}
```

## 🏗️ Architecture

### Components

1. **OidcTokenHandler** (`src/Security/OidcTokenHandler.php`)
   - Validates access tokens via OIDC UserInfo endpoint
   - Handles refresh token exchange (client secret server-side only)
   - Caches OIDC discovery configuration

2. **OidcUserProvider** (`src/Security/OidcUserProvider.php`)
   - Creates new users from OIDC data
   - Updates existing users with fresh OIDC data
   - Stores OIDC `sub` claim in `userDetails.oidc_sub`

3. **OidcController** (`src/Controller/OidcController.php`)
   - `/api/v1/oidc/refresh` - Refresh access tokens
   - `/api/v1/oidc/discovery` - Get OIDC config for frontend

### Security Features

✅ **Client Secret Server-Side Only**
- Never exposed to frontend
- Used only for token exchange

✅ **Refresh Token Rotation**
- New refresh token on each refresh
- Old tokens can be invalidated

✅ **Automatic User Creation**
- Users created on first OIDC login
- Email and profile data synced

✅ **Multi-Provider Support**
- JWT tokens (existing)
- API keys (existing)
- OIDC tokens (new)

## 🔧 Database Schema

### User Entity Updates

```php
// BUSERDETAILS JSON field
{
  "oidc_sub": "unique-oidc-identifier",
  "oidc_last_login": "2025-11-21 16:00:00",
  "email_verified": true,
  "firstName": "John",
  "lastName": "Doe",
  ...
}

// BTYPE field
"OIDC"  // For users authenticated via OIDC
```

## 📱 Frontend Integration

### 1. Get OIDC Discovery Config

```typescript
const config = await fetch('/api/v1/oidc/discovery').then(r => r.json())
// Returns: client_id, discovery_url, issuer
```

### 2. Implement Token Refresh

```typescript
class AuthService {
  private refreshTimeout: NodeJS.Timeout | null = null

  setTokens(accessToken: string, refreshToken: string, expiresIn: number) {
    localStorage.setItem('access_token', accessToken)
    localStorage.setItem('refresh_token', refreshToken)
    
    // Schedule refresh before expiry (e.g., 5 minutes before)
    const refreshTime = (expiresIn - 300) * 1000
    this.refreshTimeout = setTimeout(() => this.refreshAccessToken(), refreshTime)
  }

  async refreshAccessToken() {
    const refreshToken = localStorage.getItem('refresh_token')
    
    const response = await fetch('/api/v1/oidc/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken })
    })

    const data = await response.json()
    if (data.success) {
      this.setTokens(data.access_token, data.refresh_token, data.expires_in)
    } else {
      // Refresh failed, redirect to login
      this.logout()
    }
  }
}
```

### 3. Axios Interceptor for Auto-Refresh

```typescript
axios.interceptors.response.use(
  response => response,
  async error => {
    if (error.response?.status === 401) {
      // Try to refresh token
      await authService.refreshAccessToken()
      
      // Retry original request
      return axios.request(error.config)
    }
    return Promise.reject(error)
  }
)
```

## 🧪 Testing

### Manual Test

```bash
# 1. Get discovery config
curl http://localhost:8000/api/v1/oidc/discovery

# 2. Login via OIDC (browser)
# Get access_token and refresh_token

# 3. Use access token
curl -H "Authorization: Bearer {access_token}" \
  http://localhost:8000/api/v1/auth/me

# 4. Refresh token
curl -X POST http://localhost:8000/api/v1/oidc/refresh \
  -H "Content-Type: application/json" \
  -d '{"refresh_token": "your_refresh_token"}'
```

## 🔒 Security Best Practices

1. ✅ **Client Secret Protection**
   - Never in frontend code
   - Never in Git
   - Environment variable only

2. ✅ **Token Storage**
   - Access token: Memory or httpOnly cookie
   - Refresh token: httpOnly cookie (recommended)
   - Never in localStorage if possible

3. ✅ **HTTPS Only**
   - Always use HTTPS in production
   - OIDC requires secure connections

4. ✅ **Token Rotation**
   - Refresh tokens can rotate
   - Old tokens invalidated

5. ✅ **Scope Limitation**
   - Request minimal scopes needed
   - `openid email profile` is sufficient

## 📚 References

- [RFC 6749: OAuth 2.0](https://tools.ietf.org/html/rfc6749)
- [RFC 6750: Bearer Token Usage](https://tools.ietf.org/html/rfc6750)
- [OpenID Connect Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html)
- [Symfony Access Token Docs](https://symfony.com/doc/current/security/access_token.html)
- [Keycloak Documentation](https://www.keycloak.org/docs/latest/)

## 🎯 Benefits

✅ **Better Security**
- Short-lived access tokens
- Refresh tokens for long sessions
- Client secret server-side

✅ **Better UX**
- No frequent re-logins
- Seamless token refresh
- Social login support

✅ **Better Architecture**
- Stateless authentication
- Multi-provider support
- Standard OIDC compliance

