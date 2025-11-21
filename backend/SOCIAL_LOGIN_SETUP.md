# Social Login Setup (Google & GitHub)

## 🎯 Overview

Synaplan supports **OAuth 2.0 Social Login** with Google and GitHub, providing a seamless authentication experience for users.

## 📋 Google OAuth Setup

### 1. Create Google OAuth Credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Navigate to **APIs & Services** → **Credentials**
4. Click **Create Credentials** → **OAuth client ID**
5. Select **Web application**
6. Configure:
   - **Name**: Synaplan
   - **Authorized JavaScript origins**:
     - `http://localhost:8000`
     - `https://yourdomain.com` (production)
   - **Authorized redirect URIs**:
     - `http://localhost:8000/api/v1/auth/google/callback`
     - `https://yourdomain.com/api/v1/auth/google/callback` (production)
7. Click **Create**
8. Copy the **Client ID** and **Client Secret**

### 2. Configure Environment Variables

Add to `backend/.env.local`:

```bash
# Google OAuth 2.0
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
```

### 3. Test Google Login

```bash
# Navigate to login URL (browser)
http://localhost:8000/api/v1/auth/google/login

# This will:
# 1. Redirect to Google consent screen
# 2. User authorizes the app
# 3. Google redirects back with authorization code
# 4. Backend exchanges code for access/refresh tokens
# 5. Backend creates/updates user and returns JWT token
```

## 📋 GitHub OAuth Setup

### 1. Create GitHub OAuth App

1. Go to [GitHub Settings](https://github.com/settings/developers)
2. Click **New OAuth App**
3. Configure:
   - **Application name**: Synaplan
   - **Homepage URL**: `http://localhost:8000` (or your domain)
   - **Authorization callback URL**: `http://localhost:8000/api/v1/auth/github/callback`
4. Click **Register application**
5. Copy the **Client ID**
6. Click **Generate a new client secret** and copy it

### 2. Configure Environment Variables

Add to `backend/.env.local`:

```bash
# GitHub OAuth 2.0
GITHUB_CLIENT_ID=your-github-client-id
GITHUB_CLIENT_SECRET=your-github-client-secret
```

### 3. Test GitHub Login

```bash
# Navigate to login URL (browser)
http://localhost:8000/api/v1/auth/github/login

# This will:
# 1. Redirect to GitHub authorization page
# 2. User authorizes the app
# 3. GitHub redirects back with authorization code
# 4. Backend exchanges code for access token
# 5. Backend creates/updates user and returns JWT token
```

## 🔄 Authentication Flow

### Google Login Flow

```
┌────────┐                ┌────────┐                ┌────────┐
│ User   │                │ Google │                │Backend │
└───┬────┘                └───┬────┘                └───┬────┘
    │                         │                         │
    │ 1. Click "Login"        │                         │
    ├────────────────────────────────────────────────>  │
    │                         │                         │
    │ 2. Redirect to Google   │                         │
    │ <───────────────────────────────────────────────  │
    │                         │                         │
    │ 3. User authorizes      │                         │
    ├──────────────────────>  │                         │
    │                         │                         │
    │ 4. Redirect with code   │                         │
    │ <──────────────────────  │                         │
    │                         │                         │
    │ 5. Send code            │                         │
    ├────────────────────────────────────────────────>  │
    │                         │                         │
    │                         │ 6. Exchange code        │
    │                         │ <──────────────────────  │
    │                         │                         │
    │                         │ 7. Access/Refresh Token │
    │                         │ ───────────────────────> │
    │                         │                         │
    │                         │ 8. Fetch user info      │
    │                         │ <──────────────────────  │
    │                         │                         │
    │                         │ 9. User data            │
    │                         │ ───────────────────────> │
    │                         │                         │
    │ 10. JWT Token + User    │                         │
    │ <───────────────────────────────────────────────  │
```

### GitHub Login Flow

Same flow as Google, but with GitHub's endpoints.

## 📦 API Endpoints

### Google Login

#### Initiate Login
```
GET /api/v1/auth/google/login
```
Redirects to Google OAuth consent screen.

#### Handle Callback
```
GET /api/v1/auth/google/callback?code=xxx&state=xxx
```

**Response:**
```json
{
  "success": true,
  "access_token": "eyJ0eXAi...",
  "google_access_token": "ya29...",
  "google_refresh_token": "1//0g...",
  "expires_in": 3600,
  "user": {
    "id": 123,
    "email": "user@gmail.com",
    "name": "John Doe",
    "provider": "google"
  }
}
```

### GitHub Login

#### Initiate Login
```
GET /api/v1/auth/github/login
```
Redirects to GitHub OAuth authorization.

#### Handle Callback
```
GET /api/v1/auth/github/callback?code=xxx&state=xxx
```

**Response:**
```json
{
  "success": true,
  "access_token": "eyJ0eXAi...",
  "github_access_token": "gho_...",
  "user": {
    "id": 123,
    "email": "user@github.com",
    "name": "John Doe",
    "username": "johndoe",
    "provider": "github"
  }
}
```

## 🗄️ Database Schema

### User Data Stored

**Google Login:**
```json
{
  "google_id": "123456789",
  "google_email": "user@gmail.com",
  "google_verified_email": true,
  "google_refresh_token": "1//0g...",
  "google_last_login": "2025-11-21 16:00:00",
  "google_picture": "https://lh3.googleusercontent.com/..."
}
```

**GitHub Login:**
```json
{
  "github_id": 12345678,
  "github_login": "johndoe",
  "github_email": "user@github.com",
  "github_access_token": "gho_...",
  "github_last_login": "2025-11-21 16:00:00",
  "github_avatar": "https://avatars.githubusercontent.com/...",
  "github_bio": "Full-stack developer",
  "github_company": "Acme Inc",
  "github_location": "Berlin, Germany"
}
```

## 🎨 Frontend Integration

### Vue.js Example

```vue
<template>
  <div class="social-login">
    <button @click="loginWithGoogle" class="btn-google">
      <img src="/google-icon.svg" alt="Google" />
      Login with Google
    </button>
    
    <button @click="loginWithGitHub" class="btn-github">
      <img src="/github-icon.svg" alt="GitHub" />
      Login with GitHub
    </button>
  </div>
</template>

<script setup lang="ts">
const loginWithGoogle = () => {
  // Redirect to Google OAuth login
  window.location.href = 'http://localhost:8000/api/v1/auth/google/login'
}

const loginWithGitHub = () => {
  // Redirect to GitHub OAuth login
  window.location.href = 'http://localhost:8000/api/v1/auth/github/login'
}
</script>
```

### Handle Callback in Frontend

After successful OAuth, the backend returns JSON with `access_token`. You can:

1. **Option A: Direct JSON Response** (current implementation)
   - Backend returns JSON with JWT token
   - Frontend receives it via browser redirect
   - Parse and store token

2. **Option B: Redirect with Token** (recommended)
   - Backend redirects to frontend with token in URL/fragment
   - Example: `http://localhost:5173/auth/callback?token=xxx`
   - Frontend extracts token from URL and stores it

**Recommended Frontend Callback Handler:**

```typescript
// router.ts
{
  path: '/auth/callback',
  component: AuthCallback
}

// AuthCallback.vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '@/stores/auth'

const router = useRouter()
const auth = useAuth()

onMounted(async () => {
  const urlParams = new URLSearchParams(window.location.search)
  const token = urlParams.get('token')
  const provider = urlParams.get('provider')
  
  if (token) {
    // Store token
    auth.setToken(token)
    
    // Fetch user info
    await auth.fetchUser()
    
    // Redirect to dashboard
    router.push('/chat')
  } else {
    // Error handling
    router.push('/login?error=oauth_failed')
  }
})
</script>
```

## 🔧 Modify Backend to Redirect to Frontend

Update controllers to redirect instead of returning JSON:

```php
// In GoogleAuthController::callback() and GitHubAuthController::callback()
// Replace final return with:

$frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:5173';
$callbackUrl = $frontendUrl . '/auth/callback?' . http_build_query([
    'token' => $jwtToken,
    'provider' => 'google', // or 'github'
    'email' => $user->getEmail()
]);

return $this->redirect($callbackUrl);
```

Add to `.env.local`:
```bash
FRONTEND_URL=http://localhost:5173
```

## 🔒 Security Features

### CSRF Protection
✅ State parameter validation prevents CSRF attacks

### Token Security
✅ Client secrets are **server-side only**
✅ Access tokens are short-lived
✅ Refresh tokens stored securely in database

### User Matching
✅ Matches existing users by email
✅ Creates new users if no match found
✅ Updates user data on each login

### Scopes
✅ Minimal scopes requested:
- **Google**: `openid email profile`
- **GitHub**: `user:email read:user`

## 🧪 Testing

### Manual Test - Google

```bash
# 1. Start backend
cd backend && symfony server:start

# 2. Open browser
http://localhost:8000/api/v1/auth/google/login

# 3. Authorize with Google account

# 4. Check response for JWT token

# 5. Test JWT token
curl -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  http://localhost:8000/api/v1/auth/me
```

### Manual Test - GitHub

```bash
# 1. Open browser
http://localhost:8000/api/v1/auth/github/login

# 2. Authorize with GitHub account

# 3. Check response for JWT token

# 4. Test JWT token
curl -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  http://localhost:8000/api/v1/auth/me
```

## 📊 User Account Linking

If a user first logs in with email/password, then later uses Google/GitHub with the **same email**, the accounts are automatically linked:

1. User registers with `user@example.com` + password
2. User clicks "Login with Google" using `user@example.com`
3. System finds existing user by email
4. Google data is added to `userDetails` (doesn't overwrite password)
5. User can now login with either method

## 🎯 Benefits

✅ **Better UX**
- One-click login
- No password to remember
- Auto-fills user data (name, email, avatar)

✅ **Better Security**
- No password storage/validation needed
- Leverages Google/GitHub's security
- OAuth 2.0 standard

✅ **User Acquisition**
- Lower barrier to entry
- Trusted login methods
- Faster registration

## 📚 References

- [Google OAuth 2.0 Docs](https://developers.google.com/identity/protocols/oauth2)
- [GitHub OAuth Apps Docs](https://docs.github.com/en/apps/oauth-apps)
- [RFC 6749: OAuth 2.0](https://tools.ietf.org/html/rfc6749)
- [Symfony HttpClient](https://symfony.com/doc/current/http_client.html)

