# 🎯 Dynamic Social Login - Feature Complete!

## ✅ Was wurde implementiert?

### **Backend: AuthProvidersController**

Neuer API-Endpoint der prüft welche OAuth-Provider konfiguriert sind:

```
GET /api/v1/auth/providers
```

**Response:**
```json
{
  "providers": [
    {
      "id": "google",
      "name": "Google",
      "enabled": true,
      "icon": "google"
    },
    {
      "id": "github",
      "name": "GitHub",
      "enabled": true,
      "icon": "github"
    },
    {
      "id": "keycloak",
      "name": "Keycloak",
      "enabled": true,
      "icon": "key"
    }
  ]
}
```

### **Logik:**

Ein Provider ist **enabled** wenn:
- ✅ Client ID gesetzt ist
- ✅ Client ID nicht der Platzhalter ist (`your-...-client-id`)
- ✅ Bei Keycloak: Discovery URL ebenfalls gesetzt

### **Frontend: LoginView.vue**

1. **Lädt verfügbare Provider** beim Mount
2. **Zeigt nur aktivierte Buttons** an
3. **Dynamisches Grid**: Passt sich der Anzahl an (1-3 Buttons)
4. **Icons**: Google, GitHub, Keycloak (Schlüssel-Icon)

## 🔄 Wie es funktioniert

### **Schritt 1: Backend prüft ENV**

```php
// AuthProvidersController.php
$providers = [
    [
        'id' => 'google',
        'enabled' => !empty($this->googleClientId) && 
                     $this->googleClientId !== 'your-google-client-id'
    ],
    [
        'id' => 'github',
        'enabled' => !empty($this->githubClientId) && 
                     $this->githubClientId !== 'your-github-client-id'
    ],
    [
        'id' => 'keycloak',
        'enabled' => !empty($this->oidcClientId) && 
                     !empty($this->oidcDiscoveryUrl) &&
                     $this->oidcClientId !== 'your-oidc-client-id'
    ]
];

return array_filter($providers, fn($p) => $p['enabled']);
```

### **Schritt 2: Frontend lädt Provider**

```typescript
// LoginView.vue
const loadSocialProviders = async () => {
  const response = await fetch(`${API_BASE_URL}/api/v1/auth/providers`)
  const data = await response.json()
  socialProviders.value = data.providers || []
}
```

### **Schritt 3: Dynamische Button-Anzeige**

```vue
<div v-if="socialProviders.length > 0" 
     :style="`grid-template-columns: repeat(${socialProviders.length}, 1fr)`">
  <button v-for="provider in socialProviders" :key="provider.id">
    <!-- Icon basierend auf provider.id -->
    <svg v-if="provider.id === 'google'">...</svg>
    <svg v-else-if="provider.id === 'github'">...</svg>
    <svg v-else-if="provider.id === 'keycloak'">...</svg>
  </button>
</div>

<p v-if="socialProviders.length === 0">
  {{ $t('auth.noSocialProviders') }}
</p>
```

## 📋 Beispiele

### **Nur Google konfiguriert:**

```bash
# .env.local
GOOGLE_CLIENT_ID=abc123...
GOOGLE_CLIENT_SECRET=xyz789...
GITHUB_CLIENT_ID=
OIDC_CLIENT_ID=
```

**Ergebnis:** Nur 1 Button für Google

### **Google + GitHub konfiguriert:**

```bash
GOOGLE_CLIENT_ID=abc123...
GOOGLE_CLIENT_SECRET=xyz789...
GITHUB_CLIENT_ID=def456...
GITHUB_CLIENT_SECRET=uvw012...
OIDC_CLIENT_ID=
```

**Ergebnis:** 2 Buttons (Google, GitHub) nebeneinander

### **Alle 3 konfiguriert:**

```bash
GOOGLE_CLIENT_ID=abc123...
GOOGLE_CLIENT_SECRET=xyz789...
GITHUB_CLIENT_ID=def456...
GITHUB_CLIENT_SECRET=uvw012...
OIDC_DISCOVERY_URL=https://auth.redzone.metadist.de/realms/metadist
OIDC_CLIENT_ID=synaplan-app
OIDC_CLIENT_SECRET=secret123...
```

**Ergebnis:** 3 Buttons (Google, GitHub, Keycloak) nebeneinander

### **Nichts konfiguriert:**

```bash
GOOGLE_CLIENT_ID=
GITHUB_CLIENT_ID=
OIDC_CLIENT_ID=
```

**Ergebnis:** 
- Kein Social Login Bereich
- Text: "Keine Social Login Anbieter konfiguriert"

## 🎨 UI Anpassung

Das Grid passt sich automatisch an:

- **1 Provider**: 1 Button (volle Breite)
- **2 Provider**: 2 Buttons nebeneinander
- **3 Provider**: 3 Buttons nebeneinander

```css
grid-template-columns: repeat(${socialProviders.length}, 1fr)
```

## 🔒 Security

- ✅ **ENV-Check**: Nur wenn wirklich konfiguriert
- ✅ **Placeholder-Filter**: `your-...-client-id` wird ignoriert
- ✅ **Client Secrets**: Bleiben server-side, nie an Frontend
- ✅ **Public Endpoint**: `/api/v1/auth/providers` ist öffentlich (kein Auth nötig)

## 🧪 Testing

### **1. Teste mit keiner Config:**

```bash
# .env.local - alle leer oder Platzhalter
GOOGLE_CLIENT_ID=your-google-client-id
```

**Erwartung:** "Keine Social Login Anbieter konfiguriert"

### **2. Teste mit Google:**

```bash
GOOGLE_CLIENT_ID=echte-client-id
GOOGLE_CLIENT_SECRET=echtes-secret
```

**Erwartung:** Nur Google Button sichtbar

### **3. Teste API direkt:**

```bash
curl http://localhost:8000/api/v1/auth/providers
```

**Erwartung:**
```json
{
  "providers": [
    {"id": "google", "name": "Google", "enabled": true, "icon": "google"}
  ]
}
```

## 📚 Files Changed

### Backend:
- ✅ `src/Controller/AuthProvidersController.php` (NEU)
- ✅ `config/services.yaml` (AuthProvidersController config)
- ✅ `config/packages/security.yaml` (public route)

### Frontend:
- ✅ `src/views/LoginView.vue` (dynamic provider loading)
- ✅ `src/i18n/en.json` (noSocialProviders)
- ✅ `src/i18n/de.json` (noSocialProviders)

## 🎉 Benefits

1. **Automatisch**: Keine manuelle Frontend-Config nötig
2. **Self-Service**: Admin setzt nur ENV-Variablen
3. **Flexibel**: 0 bis 3 Provider möglich
4. **Clean**: Keine leeren Buttons oder Fehler
5. **User-Friendly**: Nur relevante Optionen sichtbar

## ✅ Ready to Use!

Jetzt einfach die gewünschten OAuth-Provider in `.env.local` konfigurieren und die Buttons erscheinen automatisch! 🚀
