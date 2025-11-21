# 🔐 Keycloak OIDC Integration - Einfache Erklärung

## ✅ Was ist Keycloak?

**Keycloak** ist ein **Identity & Access Management** System (wie Google/GitHub, aber selbst-gehostet).

- **Single Sign-On (SSO)**: Ein Login für alle Apps
- **OAuth 2.0 / OpenID Connect**: Standard-Protokolle
- **User Management**: Benutzer, Rollen, Gruppen zentral verwalten
- **Multi-Tenancy**: Verschiedene "Realms" für verschiedene Organisationen

## 🔄 Wie funktioniert das mit Keycloak?

### **Dein Setup:**
- **Keycloak Server**: `https://auth.redzone.metadist.de`
- **Realm**: `metadist` (wie eine "Organisation" in Keycloak)
- **Client**: Ein "App" in Keycloak (deine Synaplan App)

### **Authentication Flow:**

```
┌─────────┐         ┌──────────┐         ┌──────────┐
│ Synaplan│         │ Keycloak │         │  User    │
│ Frontend│         │  Server  │         │ Browser  │
└────┬────┘         └────┬─────┘         └────┬─────┘
     │                   │                     │
     │ 1. "Login"        │                     │
     ├──────────────────>│                     │
     │                   │                     │
     │ 2. Redirect to    │                     │
     │    Keycloak Login │                     │
     │<──────────────────┤                     │
     │                   │                     │
     │ 3. Show Login Form│                     │
     ├───────────────────┼────────────────────>│
     │                   │                     │
     │ 4. User enters    │                     │
     │    credentials    │                     │
     │<──────────────────┼─────────────────────┤
     │                   │                     │
     │ 5. Validate       │                     │
     │    credentials    │                     │
     ├──────────────────>│                     │
     │                   │                     │
     │ 6. Authorization  │                     │
     │    Code           │                     │
     │<──────────────────┤                     │
     │                   │                     │
     │ 7. Exchange Code  │                     │
     │    for Tokens     │                     │
     │    (Client Secret)│                     │
     ├──────────────────>│                     │
     │                   │                     │
     │ 8. Access Token + │                     │
     │    Refresh Token  │                     │
     │<──────────────────┤                     │
     │                   │                     │
     │ 9. Get User Info  │                     │
     ├──────────────────>│                     │
     │                   │                     │
     │10. User Data      │                     │
     │<──────────────────┤                     │
     │                   │                     │
     │11. JWT Token      │                     │
     │   (Synaplan)      │                     │
     ├───────────────────┼────────────────────>│
```

## 📋 Environment Variables (.env.local)

**SO IST ES RICHTIG:**

```bash
# OIDC/Keycloak Configuration
OIDC_DISCOVERY_URL=https://auth.redzone.metadist.de/realms/metadist
OIDC_CLIENT_ID=deine-client-id
OIDC_CLIENT_SECRET=dein-client-secret
```

**WICHTIG:**
- ❌ **NICHT** `/.well-known/openid-configuration` anhängen!
- ✅ Nur die **Realm URL** bis `/realms/metadist`
- ✅ Der Code fügt `/.well-known/openid-configuration` automatisch hinzu

**FALSCH (alte Variable):**
```bash
OIDC_KEYLOCAL=https://auth.redzone.metadist.de/realms/metadist/.well-known/openid-configuration  ❌
OIDC_USER=...  ❌ (wird nicht gebraucht)
```

## 🔑 Was machen die Variablen?

### **OIDC_DISCOVERY_URL**
- **Was**: Base URL deines Keycloak Realms
- **Beispiel**: `https://auth.redzone.metadist.de/realms/metadist`
- **Verwendet für**: Discovery Endpoint, Token Endpoint, UserInfo Endpoint
- **Der Code fügt hinzu**: `/.well-known/openid-configuration`

### **OIDC_CLIENT_ID**
- **Was**: Die ID deines Keycloak Clients (öffentlich)
- **Beispiel**: `synaplan-app`
- **Verwendet für**: OAuth Requests identifizieren

### **OIDC_CLIENT_SECRET**
- **Was**: Das Secret deines Keycloak Clients (GEHEIM!)
- **Beispiel**: `abc123...xyz789`
- **Verwendet für**: Token Exchange (Server-to-Server)
- **WICHTIG**: ⚠️ NIE im Frontend verwenden!

## 🎯 Was passiert im Code?

### **1. Discovery Config laden**
```php
// OidcTokenHandler.php Zeile 93
$discoveryEndpoint = rtrim($this->discoveryUrl, '/') . '/.well-known/openid-configuration';
// Ergebnis: https://auth.redzone.metadist.de/realms/metadist/.well-known/openid-configuration
```

### **2. Token validieren**
```php
// OidcTokenHandler.php Zeile 41
$response = $this->httpClient->request('GET', $discovery['userinfo_endpoint'], [
    'headers' => [
        'Authorization' => 'Bearer ' . $accessToken,
    ],
]);
```

### **3. User erstellen/updaten**
```php
// OidcUserProvider.php
public function loadUserFromOidcData(array $oidcData): User
{
    // Findet oder erstellt User basierend auf 'sub' claim
    $sub = $oidcData['sub'] ?? null;
    $email = $oidcData['email'] ?? null;
    
    // Speichert in BUSERDETAILS JSON:
    // - oidc_sub
    // - oidc_email
    // - oidc_last_login
}
```

## 🧪 Keycloak Client Setup

### **1. Client erstellen in Keycloak:**

```
Client ID: synaplan-app
Client Protocol: openid-connect
Access Type: confidential
Valid Redirect URIs: http://localhost:5173/auth/callback
                     http://localhost:8000/api/v1/oidc/callback (falls direkt)
```

### **2. Credentials Tab:**
```
Client Authenticator: Client Id and Secret
Secret: [Generiert von Keycloak, kopieren!]
```

### **3. Scope Settings:**
```
✅ openid
✅ email
✅ profile
```

## 🔄 Unterschied: Google/GitHub OAuth vs. Keycloak OIDC

### **Google/GitHub OAuth (bereits implementiert):**
```
1. User klickt "Login with Google"
2. Google zeigt Consent Screen
3. Callback mit Authorization Code
4. Backend tauscht Code gegen Token
5. Backend erstellt User & JWT
6. Redirect zu Frontend
```

### **Keycloak OIDC (mit deiner Config):**
```
1. User sendet bereits existierenden Access Token
2. Backend validiert Token via Keycloak UserInfo
3. Backend erstellt/updated User
4. User ist authentifiziert
```

**ODER** (wenn du den vollen Flow willst wie Google/GitHub):

```
1. User klickt "Login with Keycloak"
2. Redirect zu Keycloak Login
3. Keycloak zeigt Login Form
4. Callback mit Authorization Code
5. Backend tauscht Code gegen Token (mit Client Secret)
6. Backend validiert Token
7. Backend erstellt User & JWT
8. Redirect zu Frontend
```

## 🚀 Was du jetzt tun musst:

### **Option A: Access Token Validation (aktuell implementiert)**

```bash
# backend/.env.local
OIDC_DISCOVERY_URL=https://auth.redzone.metadist.de/realms/metadist
OIDC_CLIENT_ID=deine-client-id
OIDC_CLIENT_SECRET=dein-client-secret
```

**Dann kann dein Frontend:**
```javascript
// User hat bereits Token von Keycloak
const response = await fetch('http://localhost:8000/api/v1/auth/me', {
  headers: {
    'Authorization': 'Bearer ' + keycloakAccessToken
  }
});
```

### **Option B: Full OAuth Flow (wie Google/GitHub)**

Brauchst du noch **OidcController** mit:
- `/api/v1/auth/keycloak/login` - Initiiert Login
- `/api/v1/auth/keycloak/callback` - Empfängt Code

**Soll ich das auch implementieren?** Dann hast du:
- "Login with Google"
- "Login with GitHub"  
- "Login with Keycloak"

Alle drei mit dem gleichen Flow! 🎉

## ✅ Checklist für deine Config:

- [ ] Keycloak Client erstellt mit Type "confidential"
- [ ] Client ID kopiert
- [ ] Client Secret kopiert
- [ ] Redirect URIs konfiguriert
- [ ] `.env.local` mit korrekten Werten:
  ```bash
  OIDC_DISCOVERY_URL=https://auth.redzone.metadist.de/realms/metadist
  OIDC_CLIENT_ID=...
  OIDC_CLIENT_SECRET=...
  ```
- [ ] ❌ NICHT `/.well-known/openid-configuration` anhängen!
- [ ] ❌ NICHT `OIDC_USER` oder `OIDC_KEYLOCAL` verwenden

## 🎯 Zusammenfassung

**Keycloak = Dein eigener "Google Login"**

- Zentrale User-Verwaltung
- Standard OIDC/OAuth 2.0
- Discovery URL: Code fügt automatisch `/.well-known/openid-configuration` hinzu
- Client Secret bleibt server-side
- Access Token wird via UserInfo Endpoint validiert
- User wird automatisch erstellt/geupdatet

**Müsste jetzt funktionieren wenn:**
1. Client richtig in Keycloak konfiguriert
2. `.env.local` richtig gesetzt (OHNE `.well-known/...`)
3. Redirect URIs passen
