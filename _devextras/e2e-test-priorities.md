# E2E Test Prioritäten & API Tests Bewertung

## 📊 Aktueller Status

### ✅ Bereits vorhandene E2E Tests:
1. **Auth Flow** (`auth.spec.ts`)
   - ✅ Login
   - ✅ Logout mit Session-Clear

2. **Registration Flow** (`registration.spec.ts`)
   - ✅ Vollständiger Flow mit Email-Verification
   - ✅ MailHog Integration

3. **Chat Flow** (`chat.spec.ts`)
   - ✅ Standard Model Response
   - ✅ All Models Response (mit "Again" Feature)

4. **RAG/Search Flow** (`rag-search.spec.ts`)
   - ✅ File Upload → Vectorization → Semantic Search

---

## 🎯 PRIORITÄT 1: Kritische User Flows (SOFORT)

### 1. Widget Embedding Test ⚠️ **KRITISCH**
**Warum kritisch:**
- Widget ist ein Kern-Feature für externe Integrationen
- Cross-Origin Funktionalität muss funktionieren
- Öffentliche API ohne Auth - Sicherheitsrisiko wenn kaputt

**Was testen:**
- Widget Script lädt korrekt
- Widget kann auf externer Seite eingebettet werden
- Widget kann Nachrichten senden (POST `/api/v1/widget/message`)
- Widget erhält Antworten (Streaming)
- Widget Session Management funktioniert
- CORS Headers sind korrekt gesetzt

**Test-Datei:** `frontend/tests/e2e/tests/widget.spec.ts`

**Komplexität:** Mittel-Hoch (benötigt separate Test-HTML-Seite)

---

### 2. Chat Management Flow
**Warum wichtig:**
- Core Feature - User erstellen/löschen/teilen Chats
- Wird häufig verwendet

**Was testen:**
- Neuen Chat erstellen
- Chat-Liste anzeigen
- Chat löschen
- Chat teilen (Share-Funktion)
- Geteilten Chat öffnen (öffentlicher Link)

**Test-Datei:** `frontend/tests/e2e/tests/chat-management.spec.ts`

**Komplexität:** Niedrig-Mittel

---

### 3. File Upload & Processing (isoliert)
**Warum wichtig:**
- Aktuell nur in RAG-Test integriert
- Sollte isoliert getestet werden
- File Processing ist komplex (Extraction, Vectorization)

**Was testen:**
- File Upload (verschiedene Formate: PDF, TXT, DOCX)
- File Processing Status (Uploaded → Extracted → Vectorized)
- File Liste anzeigen
- File löschen
- File Download/Serve

**Test-Datei:** `frontend/tests/e2e/tests/files.spec.ts`

**Komplexität:** Mittel (benötigt verschiedene File-Typen)

---

## 📋 PRIORITÄT 2: Wichtige Features (Nächste Iteration)

### 4. Profile & Settings
**Was testen:**
- Profile-Daten anzeigen
- Profile-Daten ändern
- Password ändern
- Email ändern (mit Verification)

**Test-Datei:** `frontend/tests/e2e/tests/profile.spec.ts`

**Komplexität:** Niedrig

---

### 5. API Keys Management
**Was testen:**
- API Key erstellen
- API Key Liste anzeigen
- API Key löschen
- API Key Scopes setzen

**Test-Datei:** `frontend/tests/e2e/tests/api-keys.spec.ts`

**Komplexität:** Niedrig-Mittel

---

### 6. Widget Management (Admin)
**Was testen:**
- Widget erstellen
- Widget konfigurieren (Farben, etc.)
- Widget Liste anzeigen
- Widget aktivieren/deaktivieren
- Widget Embed-Code kopieren

**Test-Datei:** `frontend/tests/e2e/tests/widget-management.spec.ts`

**Komplexität:** Mittel

---

## 🔮 PRIORITÄT 3: Nice-to-Have (Später)

### 7. Subscription/Plans Flow
**Warum später:**
- Benötigt Stripe Test-Keys
- Nicht kritisch für Core-Funktionalität

**Was testen:**
- Plans anzeigen
- Plan auswählen
- Checkout Flow (mit Stripe Test-Modus)

**Komplexität:** Hoch (Stripe Integration)

---

### 8. Admin Features
**Was testen:**
- User Management (nur für Admin)
- Model Configuration
- Usage Statistics

**Komplexität:** Mittel-Hoch

---

## 🤔 API Tests als Gate - BEWERTUNG

### ✅ **PRO: API Tests als Gate**

**Vorteile:**
1. **Schneller als E2E Tests**
   - Keine Browser-Overhead
   - Direkte HTTP-Calls
   - Parallele Ausführung möglich

2. **Bessere Coverage**
   - Testet alle Endpoints systematisch
   - Testet Edge Cases (z.B. ungültige Requests)
   - Testet Response Schemas (OpenAPI Compliance)

3. **Frühe Fehlererkennung**
   - API-Breaking-Changes werden sofort erkannt
   - Schema-Änderungen werden validiert
   - Backend-Logik-Fehler werden gefunden

4. **Unabhängig von Frontend**
   - Frontend-Bugs blockieren nicht API-Tests
   - API kann getestet werden ohne Frontend-Build

5. **Bereits vorhandene Infrastruktur**
   - Backend hat bereits PHPUnit Tests für Controller
   - Können erweitert werden

---

### ❌ **CONTRA: API Tests als Gate**

**Nachteile:**
1. **Doppelte Tests**
   - E2E Tests testen APIs bereits indirekt
   - Kann zu Redundanz führen

2. **Weniger realistisch**
   - Testet nicht die echte User-Experience
   - Frontend-Integration wird nicht getestet

3. **Mehr Wartungsaufwand**
   - Zwei Test-Suites zu pflegen
   - API-Tests müssen bei Schema-Änderungen aktualisiert werden

---

## 💡 **EMPFEHLUNG: Hybrid-Ansatz**

### ✅ **JA, API Tests als Gate - ABER:**

**1. API Contract Tests (Priorität HOCH)**
- **Was:** OpenAPI Schema Validation
- **Warum:** Stellt sicher dass API-Spec korrekt ist
- **Wie:** 
  - Validierung der generierten OpenAPI Spec
  - Response Schema Validation (z.B. mit `ajv` oder `zod`)
  - Request/Response Contract Tests

**2. API Integration Tests (Priorität MITTEL)**
- **Was:** Kritische API-Endpoints direkt testen
- **Warum:** Schneller als E2E, testet Backend-Logik
- **Wie:**
  - Backend PHPUnit Tests erweitern
  - Testet kritische Flows:
    - Auth Flow (Login, Register, Token Refresh)
    - Message Send/Receive
    - File Upload/Processing
    - Widget Public API
    - Rate Limiting

**3. E2E Tests für User Flows (Priorität HOCH)**
- **Was:** Vollständige User-Flows über Browser
- **Warum:** Testet Frontend + Backend Integration
- **Wie:**
  - Widget Embedding (kritisch!)
  - Chat Management
  - File Upload (UI-Flow)

---

## 📝 **Konkrete Implementierungs-Empfehlung**

### Phase 1: API Contract Tests (SOFORT)
```yaml
# In CI hinzufügen:
- name: Validate OpenAPI Spec
  run: swagger-parser validate openapi-spec.json

- name: API Contract Tests
  run: php bin/phpunit tests/Contract/
  # Neue Test-Klasse: ApiContractTest.php
  # Testet dass Responses dem OpenAPI Schema entsprechen
```

### Phase 2: Kritische API Integration Tests (Diese Woche)
- Erweitere bestehende PHPUnit Controller Tests
- Fokus auf:
  - Widget Public API (`WidgetPublicController`)
  - Message API (`MessageController`)
  - File API (`FileController`)
  - Auth API (`AuthController`)

### Phase 3: E2E Tests für kritische Flows (Diese Woche)
1. Widget Embedding Test (PRIORITÄT 1)
2. Chat Management Test (PRIORITÄT 1)
3. File Upload Test (PRIORITÄT 1)

---

## 🎯 **Zusammenfassung**

### ✅ **JA zu API Tests als Gate, ABER:**

1. **API Contract Tests** (Schema Validation) - **SOFORT**
   - Validierung der OpenAPI Spec
   - Response Schema Validation
   - Schnell, wichtig, wenig Wartung

2. **API Integration Tests** (Kritische Endpoints) - **Diese Woche**
   - Erweitere bestehende PHPUnit Tests
   - Fokus auf kritische APIs
   - Schneller als E2E, gute Coverage

3. **E2E Tests** (User Flows) - **Diese Woche**
   - Widget Embedding (KRITISCH!)
   - Chat Management
   - File Upload

**Warum dieser Ansatz?**
- ✅ API Contract Tests: Schnell, wichtig, wenig Wartung
- ✅ API Integration Tests: Schneller als E2E, gute Backend-Coverage
- ✅ E2E Tests: Testet Frontend + Backend Integration, realistische User-Flows

**Ergebnis:** Beste Balance zwischen Geschwindigkeit, Coverage und Realismus.

---

## 📚 **Nächste Schritte**

1. ✅ OpenAPI Schema Validation in CI hinzufügen
2. ✅ API Contract Tests erstellen (`tests/Contract/ApiContractTest.php`)
3. ✅ Widget Embedding E2E Test erstellen
4. ✅ Chat Management E2E Test erstellen
5. ✅ File Upload E2E Test erstellen (isoliert)
6. ✅ Kritische API Integration Tests erweitern
