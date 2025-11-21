# 🚀 Social Login Quickstart

## ✅ Was wurde implementiert?

### Backend
1. **GoogleAuthController.php** - Google OAuth 2.0 Flow
2. **GitHubAuthController.php** - GitHub OAuth 2.0 Flow
3. **Security Config** - Public endpoints für OAuth Callbacks
4. **Services Config** - Client ID/Secret Injection

### Frontend
1. **SocialLogin.vue** - Social Login Buttons (Google & GitHub)
2. **OAuthCallback.vue** - OAuth Callback Handler
3. **i18n** - Übersetzungen (DE/EN)

### Dokumentation
1. **SOCIAL_LOGIN_SETUP.md** - Detaillierte Setup-Anleitung
2. **OIDC_SETUP.md** - OIDC/Refresh Token Dokumentation

## 📋 Setup in 5 Schritten

### 1️⃣ Google OAuth App erstellen

```
1. Gehe zu https://console.cloud.google.com/
2. Erstelle ein neues Projekt
3. APIs & Services → Credentials → Create OAuth Client ID
4. Web Application auswählen
5. Authorized redirect URI: http://localhost:8000/api/v1/auth/google/callback
6. Client ID & Secret kopieren
```

### 2️⃣ GitHub OAuth App erstellen

```
1. Gehe zu https://github.com/settings/developers
2. New OAuth App
3. Homepage URL: http://localhost:8000
4. Authorization callback URL: http://localhost:8000/api/v1/auth/github/callback
5. Client ID & Secret kopieren
```

### 3️⃣ Environment Variables setzen

**backend/.env.local:**
```bash
# Google OAuth
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret

# GitHub OAuth
GITHUB_CLIENT_ID=your-github-client-id
GITHUB_CLIENT_SECRET=your-github-client-secret

# App URL (für Callbacks)
APP_URL=http://localhost:8000

# Frontend URL (für Redirect nach Login)
FRONTEND_URL=http://localhost:5173
```

### 4️⃣ Backend Controller anpassen (Optional)

Wenn du nach dem Login zum Frontend redirecten willst (empfohlen):

**GoogleAuthController.php & GitHubAuthController.php:**
```php
// In callback() Methode, ersetze das finale return mit:

$frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:5173';
$callbackUrl = $frontendUrl . '/auth/callback?' . http_build_query([
    'token' => $jwtToken,
    'provider' => 'google', // oder 'github'
]);

return $this->redirect($callbackUrl);
```

### 5️⃣ Frontend Integration

**Login Page (src/views/LoginView.vue):**
```vue
<template>
  <div class="login-page">
    <h1>Login</h1>
    
    <!-- Regular Login Form -->
    <form @submit.prevent="login">
      <!-- ... existing form ... -->
    </form>
    
    <!-- Social Login Buttons -->
    <SocialLogin />
  </div>
</template>

<script setup>
import SocialLogin from '@/components/auth/SocialLogin.vue'
</script>
```

**Router (src/router/index.ts):**
```typescript
{
  path: '/auth/callback',
  name: 'oauth-callback',
  component: () => import('@/components/auth/OAuthCallback.vue')
}
```

## 🧪 Testing

### Manual Test

```bash
# 1. Backend starten
cd backend && symfony server:start

# 2. Frontend starten
cd frontend && npm run dev

# 3. Browser öffnen
http://localhost:5173/login

# 4. Auf "Login with Google" klicken

# 5. Mit Google Account autorisieren

# 6. Wirst zu http://localhost:5173/auth/callback weitergeleitet

# 7. Dann zu http://localhost:5173/chat
```

### Test URLs (Direct Backend)

```bash
# Google Login initiieren
http://localhost:8000/api/v1/auth/google/login

# GitHub Login initiieren
http://localhost:8000/api/v1/auth/github/login
```

## 🔄 Authentication Flow

```
User                    Backend                 Google/GitHub
  │                        │                          │
  │  1. Click "Login"      │                          │
  ├───────────────────────>│                          │
  │                        │                          │
  │  2. Redirect to OAuth  │                          │
  │<───────────────────────│                          │
  │                        │                          │
  │  3. Authorize          │                          │
  ├──────────────────────────────────────────────────>│
  │                        │                          │
  │  4. Redirect with code │                          │
  │<──────────────────────────────────────────────────│
  │                        │                          │
  │  5. Send code          │                          │
  ├───────────────────────>│                          │
  │                        │  6. Exchange code        │
  │                        ├─────────────────────────>│
  │                        │  7. Access Token         │
  │                        │<─────────────────────────│
  │                        │  8. Get User Info        │
  │                        ├─────────────────────────>│
  │                        │  9. User Data            │
  │                        │<─────────────────────────│
  │  10. JWT Token + User  │                          │
  │<───────────────────────│                          │
```

## 🎯 Features

✅ **One-Click Login** - Kein Passwort nötig
✅ **Auto User Creation** - User wird automatisch erstellt
✅ **Account Linking** - Vorhandene Accounts werden verknüpft (gleiche E-Mail)
✅ **CSRF Protection** - State Parameter Validierung
✅ **Refresh Tokens** - Google unterstützt lange Sessions
✅ **Secure** - Client Secret bleibt server-side

## 🔒 Security

- Client Secret ist **nur** im Backend (.env.local)
- State Parameter verhindert CSRF-Angriffe
- Tokens werden verschlüsselt in DB gespeichert
- HTTPS erforderlich für Production

## 📚 Weitere Dokumentation

- **backend/SOCIAL_LOGIN_SETUP.md** - Ausführliche Anleitung
- **backend/OIDC_SETUP.md** - OIDC & Refresh Token Setup

## 🎨 UI Customization

Die Social Login Buttons können in `frontend/src/components/auth/SocialLogin.vue` angepasst werden:
- Farben
- Icons
- Text
- Styling
- Dark Mode

## 🚀 Production Deployment

1. Update Redirect URIs in Google/GitHub Console
2. Setze Production URLs in .env:
   ```bash
   APP_URL=https://api.yourdomain.com
   FRONTEND_URL=https://yourdomain.com
   ```
3. Aktiviere HTTPS
4. Update Redirect in Controllers (falls custom)

## ✅ Completed!

Social Login ist jetzt einsatzbereit. Einfach Client IDs/Secrets einfügen und testen! 🎉
