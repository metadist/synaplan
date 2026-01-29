# System Configuration Admin Page - Integration Plan

**Created:** 2026-01-29
**Status:** Planning Phase
**Priority:** High (Security-Critical Feature)

---

## ⚠️ SECURITY WARNING — READ BEFORE IMPLEMENTING ⚠️

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                             │
│   🔒 THIS IS A SECURITY-CRITICAL FEATURE                                   │
│                                                                             │
│   This page allows editing of .env files containing:                        │
│   • API keys (OpenAI, Anthropic, Stripe, etc.)                             │
│   • Database credentials                                                    │
│   • OAuth secrets                                                           │
│   • Service passwords                                                       │
│                                                                             │
│   RULES:                                                                    │
│   1. ONLY ADMIN users may access this page — enforce everywhere            │
│   2. NEVER log sensitive values — always mask with ****                    │
│   3. NEVER return raw secrets in API responses — always mask               │
│   4. ALWAYS create backup before writing                                   │
│   5. ALWAYS validate input before writing                                  │
│                                                                             │
│   If you're unsure about security: STOP and ASK.                           │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current State Analysis](#2-current-state-analysis)
3. [Proposed Tab Structure](#3-proposed-tab-structure)
4. [.env.example Restructure](#4-envexample-restructure)
5. [Security Architecture](#5-security-architecture)
6. [Backend API Design](#6-backend-api-design)
7. [Frontend Implementation](#7-frontend-implementation)
8. [Translation Keys](#8-translation-keys)
9. [Implementation Phases](#9-implementation-phases)
10. [Open Questions](#10-open-questions)
11. [Risk Assessment](#11-risk-assessment)
12. [Definition of Done](#12-definition-of-done)
13. [Restart Notification UX](#13-user-experience-restart-notification)
14. [Related Documentation](#14-related-documentation)
15. [Vibe Coding Guide (AI Instructions)](#15-vibe-coding-guide-ai-agent-instructions)

---

## 1. Executive Summary

This document outlines the plan to create a new "System Config" page under the Administration menu, allowing admins to edit `.env` configuration through a secure, tabbed web interface. This is a security-critical feature that requires careful design to prevent unauthorized access, credential exposure, and system misconfiguration.

---

## 2. Current State Analysis

### 2.1 Existing Admin Structure

**Sidebar Navigation (Admin Section):**
- Dashboard (`/admin`) - Overview, Users, Prompts, Usage tabs
- Feature Status (`/admin/features`) - Feature flag management

**Proposed Addition:**
- System Config (`/admin/config`) - Environment configuration management

### 2.2 Current `.env.example` Organization

The existing `backend/.env.example` already has section separators but needs restructuring for better UX:

| Current Section | Line Count | Notes |
|-----------------|------------|-------|
| Development Settings | 8 | Core app settings |
| Google reCAPTCHA | 5 | Optional security |
| Email / Mailer | 5 | Single DSN |
| Lock Component | 6 | Internal |
| WhatsApp Business API | 4 | Channel integration |
| External AI API Keys | 5 | Multiple providers |
| Ollama | 4 | Local AI |
| NVIDIA Triton | 5 | Self-hosted LLMs |
| OIDC Authentication | 5 | External auth |
| Token Authentication | 4 | JWT settings |
| Social Login - Google | 7 | OAuth |
| Gmail IMAP | 10 | Smart email handler |
| Social Login - GitHub | 5 | OAuth |
| Frontend/Synaplan URLs | 11 | URLs |
| Brave Search | 10 | Web search |
| Stripe Payment | 12 | Billing |
| Database | 10 | MySQL connection |
| Tika Service | 10 | Doc extraction |
| PDF Rasterizer | 5 | OCR |
| Whisper Service | 6 | Audio transcription |
| Qdrant Vector DB | 9 | Vector search |

---

## 3. Proposed Tab Structure

The System Config page will have **6 main tabs**, organized by functional area:

### Tab 1: AI Services (`ai-services`)
**Purpose:** Configure AI providers and API keys

**Sections:**
1. **Local AI (Ollama)**
   - `OLLAMA_BASE_URL` - Ollama server URL

2. **Cloud AI Providers**
   - `OPENAI_API_KEY` - OpenAI API key
   - `ANTHROPIC_API_KEY` - Anthropic (Claude) API key
   - `GROQ_API_KEY` - Groq API key
   - `GOOGLE_GEMINI_API_KEY` - Google Gemini API key

3. **Self-Hosted AI (Optional)**
   - `TRITON_SERVER_URL` - NVIDIA Triton gRPC endpoint

4. **Text-to-Speech (Optional)**
   - `ELEVENLABS_API_KEY` - ElevenLabs TTS API key

**UI Features:**
- Show/hide toggle for API keys (masked by default)
- "Test Connection" button for each provider
- Provider status indicator (configured/not configured)
- Link to provider documentation

---

### Tab 2: Email Configuration (`email`)
**Purpose:** Configure outbound email for notifications and signups

**Sections:**
1. **Primary Mailer (Signup/Notifications)**
   - `MAILER_DSN` - SMTP connection string
   - `APP_SENDER_EMAIL` - From email address
   - `APP_SENDER_NAME` - From name

**UI Features:**
- "Send Test Email" button
- DSN format helper/examples (SMTP, SES, Mailgun)

---

### Tab 3: Social & Authentication (`auth`)
**Purpose:** Configure external authentication providers

**Sections:**
1. **Google OAuth 2.0**
   - `GOOGLE_CLIENT_ID`
   - `GOOGLE_CLIENT_SECRET`
   - `GOOGLE_CLOUD_PROJECT_ID` (optional)
   - Callback URL display (readonly)

2. **GitHub OAuth 2.0**
   - `GITHUB_CLIENT_ID`
   - `GITHUB_CLIENT_SECRET`
   - Callback URL display (readonly)

3. **OIDC (Enterprise SSO)**
   - `OIDC_DISCOVERY_URL`
   - `OIDC_CLIENT_ID`
   - `OIDC_CLIENT_SECRET`

4. **Security**
   - `RECAPTCHA_ENABLED` - Toggle
   - `RECAPTCHA_SITE_KEY`
   - `RECAPTCHA_SECRET_KEY`
   - `RECAPTCHA_MIN_SCORE` - Slider (0.0-1.0)

**UI Features:**
- OAuth callback URL auto-generated from SYNAPLAN_URL
- "Test OAuth Flow" button (opens popup)
- Provider enable/disable toggles

---

### Tab 4: Inbound Channels (`channels`)
**Purpose:** Configure external channels for interacting with the AI platform

**Sections:**
1. **WhatsApp Business API**
   - `WHATSAPP_ENABLED` - Toggle
   - `WHATSAPP_ACCESS_TOKEN`
   - `WHATSAPP_WEBHOOK_VERIFY_TOKEN`

2. **Smart Mail (Gmail IMAP)**
   - `GMAIL_USERNAME` - Gmail address for smart email
   - `GMAIL_PASSWORD` - Gmail App Password (16-char)
   - `GMAIL_OAUTH_TOKEN` - OAuth token (JSON, advanced)
   - Documentation link for App Password setup

**UI Features:**
- WhatsApp webhook URL display
- "Test IMAP Connection" button for Gmail
- Visual guide for Gmail App Password generation
- Warning about 2FA requirement for Gmail

---

### Tab 5: Document Processing (`processing`)
**Purpose:** Configure file processing and content augmentation services

**Sections:**
1. **Apache Tika (Text Extraction)**
   - `TIKA_BASE_URL`
   - `TIKA_HTTP_USER` (optional)
   - `TIKA_HTTP_PASS` (optional)
   - `TIKA_TIMEOUT_MS` - Number input
   - `TIKA_RETRIES` - Number input
   - `TIKA_MIN_LENGTH` - Number input
   - `TIKA_MIN_ENTROPY` - Number input (0.0-5.0)

2. **PDF Rasterizer (OCR)**
   - `RASTERIZE_DPI` - Slider (72-300)
   - `RASTERIZE_PAGE_CAP` - Number input
   - `RASTERIZE_TIMEOUT_MS` - Number input

3. **Whisper (Audio Transcription)**
   - `WHISPER_ENABLED` - Toggle
   - `WHISPER_DEFAULT_MODEL` - Dropdown (tiny/base/small/medium/large)
   - `WHISPER_BINARY` - Path input (advanced)
   - `WHISPER_MODELS_PATH` - Path input (advanced)
   - `FFMPEG_BINARY` - Path input (advanced)

4. **Web Search (Brave)**
   - `BRAVE_SEARCH_ENABLED` - Toggle
   - `BRAVE_SEARCH_API_KEY`
   - `BRAVE_SEARCH_COUNT` - Number input (1-20)
   - `BRAVE_SEARCH_COUNTRY` - Dropdown (ISO 3166-1 alpha-2)
   - `BRAVE_SEARCH_SEARCH_LANG` - Dropdown (ISO 639-1)

**UI Features:**
- "Test Tika Connection" button
- "Test Whisper" button (upload small audio)
- "Test Search" button for Brave
- Advanced settings collapse

---

### Tab 6: Vector Database (`vector-db`)
**Purpose:** Configure Qdrant vector search service

**Sections:**
1. **Qdrant Service Connection**
   - `QDRANT_SERVICE_URL` - URL input
   - `QDRANT_SERVICE_API_KEY` - Password input

2. **Qdrant Direct (Optional/Advanced)**
   - `QDRANT_URL` - URL input (from synaplan-memories)
   - `QDRANT_API_KEY` - Password input

**UI Features:**
- "Test Connection" button
- Collection health status display
- Document count indicator

---

## 4. `.env.example` Restructure

The `.env.example` file should be reorganized to match the tab structure. Each section should have:

```bash
# ==============================================================================
# SECTION_NAME
# ==============================================================================
# Brief description of what this section configures
# Documentation link if applicable
# ==============================================================================

# Subsection: Setting Group
SETTING_KEY=default_value  # Inline description
```

### Proposed Section Order:

```
1. APPLICATION CORE
   - APP_ENV, APP_SECRET, APP_URL, LOG_FORMAT
   - FRONTEND_URL, SYNAPLAN_URL
   - TOKEN_SECRET
   - LOCK_DSN

2. AI SERVICES
   - Ollama
   - OpenAI
   - Anthropic
   - Groq
   - Google Gemini
   - NVIDIA Triton
   - ElevenLabs

3. EMAIL CONFIGURATION
   - Primary Mailer (MAILER_DSN, APP_SENDER_*)

4. AUTHENTICATION & SECURITY
   - reCAPTCHA
   - Google OAuth
   - GitHub OAuth
   - OIDC

5. INBOUND CHANNELS
   - WhatsApp
   - Smart Mail (Gmail IMAP)

6. DOCUMENT PROCESSING
   - Tika
   - PDF Rasterizer
   - Whisper
   - Brave Web Search

7. VECTOR DATABASE
   - Qdrant Service

8. PAYMENTS & BILLING (not editable via web UI)
   - Stripe (stays in .env, manual edit only for security)

9. DATABASE (not editable via web UI)
   - MySQL credentials (manual edit only for security)
   - Doctrine URLs
```

---

## 5. Security Architecture

### 5.1 Access Control

| Requirement | Implementation |
|-------------|----------------|
| Admin-only access | Route guard + API middleware checking `isAdmin` |
| CSRF protection | Symfony CSRF token on all form submissions |
| Rate limiting | Max 10 config changes per minute |
| Audit logging | Log all config changes with user ID, timestamp, old/new values (masked) |

### 5.2 Sensitive Field Handling

**High Sensitivity (Never logged, masked in UI):**
- `*_API_KEY`
- `*_SECRET`
- `*_PASSWORD`
- `*_TOKEN`
- `DATABASE_*_URL`

**Medium Sensitivity (Logged masked, shown masked):**
- OAuth client IDs
- Webhook URLs

**Low Sensitivity (Logged, shown):**
- URLs (non-auth)
- Numeric settings
- Boolean toggles

### 5.3 File Write Safety

1. **Backup Creation:** Before any write, create `.env.backup.{timestamp}`
2. **Syntax Validation:** Parse new values before writing
3. **Atomic Write:** Write to `.env.tmp`, then `rename()` (atomic on POSIX)
4. **Rollback Capability:** Keep last 5 backups, admin can restore via UI
5. **Docker Awareness:** Warn that changes require container restart

### 5.4 Dangerous Operations

**Require Confirmation Dialog:**
- Changing database URLs
- Changing AI provider keys
- Disabling security features (reCAPTCHA, OAuth)

**Require Additional Verification:**
- Clearing all API keys → Confirm with password
- Changing APP_SECRET → Confirm with password + warning about session invalidation

---

## 6. Backend API Design

### 6.1 New Endpoints

```
GET  /api/v1/admin/config/schema
     Returns: Field definitions, types, sections, validation rules

GET  /api/v1/admin/config/values
     Returns: Current values (sensitive fields masked with "••••••••")

PUT  /api/v1/admin/config/values
     Body: { section: string, key: string, value: string }
     Returns: { success: boolean, requiresRestart: boolean }

POST /api/v1/admin/config/test/{service}
     Tests: ollama, openai, anthropic, groq, gemini, tika, qdrant, mailer, gmail
     Returns: { success: boolean, message: string, details?: object }

GET  /api/v1/admin/config/backups
     Returns: List of backup files with timestamps

POST /api/v1/admin/config/restore/{backupId}
     Restores a backup
```

### 6.2 Backend Service

```php
final readonly class SystemConfigService
{
    public function __construct(
        private string $envFilePath,
        private string $backupDir,
        private LoggerInterface $logger,
        private EntityManagerInterface $em, // For audit log
    ) {}

    public function getSchema(): array;
    public function getValues(): array;
    public function setValue(string $key, string $value): void;
    public function testConnection(string $service): TestResult;
    public function createBackup(): string;
    public function restoreBackup(string $backupId): void;
}
```

---

## 7. Frontend Implementation

### 7.1 New Components

```
frontend/src/
├── views/
│   └── AdminConfigView.vue          # Main config page
├── components/admin/
│   ├── ConfigSection.vue            # Reusable section container
│   ├── ConfigField.vue              # Individual field (text/password/select/toggle)
│   ├── ConfigTabAI.vue              # AI Services tab content
│   ├── ConfigTabEmail.vue           # Email tab content
│   ├── ConfigTabAuth.vue            # Auth tab content
│   ├── ConfigTabChannels.vue        # Channels tab content
│   ├── ConfigTabProcessing.vue      # Processing tab content
│   ├── ConfigTabVectorDB.vue        # Vector DB tab content
│   └── ConfigBackupManager.vue      # Backup restore UI
└── services/api/
    └── adminConfigApi.ts            # API client
```

### 7.2 Router Changes

```typescript
// Add to router/index.ts
{
  path: '/admin/config',
  name: 'admin-config',
  component: () => import('@/views/AdminConfigView.vue'),
  meta: { requiresAuth: true, requiresAdmin: true }
}
```

### 7.3 Sidebar Changes

```typescript
// Add to adminChildren array in Sidebar.vue
{ path: '/admin/config', label: t('nav.adminSystemConfig') }
```

---

## 8. Translation Keys

```json
{
  "nav": {
    "adminSystemConfig": "System Config"
  },
  "admin": {
    "config": {
      "title": "System Configuration",
      "description": "Manage environment variables and service connections",
      "tabs": {
        "ai": "AI Services",
        "email": "Email",
        "auth": "Authentication",
        "channels": "Inbound Channels",
        "processing": "Processing",
        "vectorDb": "Vector DB"
      },
      "sections": {
        "ollama": "Local AI (Ollama)",
        "cloudAI": "Cloud AI Providers",
        "selfHostedAI": "Self-Hosted AI",
        "tts": "Text-to-Speech",
        "primaryMailer": "Primary Mailer",
        "whatsapp": "WhatsApp Business",
        "smartMail": "Smart Mail (Gmail IMAP)",
        "webSearch": "Web Search"
      },
      "actions": {
        "testConnection": "Test Connection",
        "sendTestEmail": "Send Test Email",
        "save": "Save Changes",
        "restore": "Restore Backup"
      },
      "warnings": {
        "restartRequired": "Changes require container restart to take effect",
        "sensitiveChange": "This change affects system security. Are you sure?",
        "databaseChange": "Changing database URL may cause data loss. Proceed with caution."
      }
    }
  }
}
```

---

## 9. Implementation Phases

### Phase 1: Foundation (Backend)
1. Create `SystemConfigService` with read-only operations
2. Create schema definition for all config fields
3. Implement API endpoints (GET only initially)
4. Add audit logging infrastructure

### Phase 2: Frontend (Read-Only)
1. Create `AdminConfigView.vue` with tabs
2. Implement all tab components
3. Add route and sidebar integration
4. Add i18n translations (en + de)

### Phase 3: Write Operations
1. Implement `setValue()` with backup/validation
2. Add PUT endpoint
3. Add confirmation dialogs for dangerous operations
4. Implement change detection and save button

### Phase 4: Testing Features
1. Implement `testConnection()` for each service
2. Add POST test endpoints
3. Add status indicators to UI

### Phase 5: Backup/Restore
1. Implement backup management
2. Add backup list API
3. Add restore UI with confirmation

### Phase 6: Polish
1. Comprehensive testing
2. Documentation update
3. Security audit

---

## 10. Open Questions

1. **Restart Mechanism:** Should we offer a "Restart Backend" button, or just show instructions?
   - *Recommendation:* Show warning with manual restart instructions (safer)

2. **Multi-Instance:** How to handle config sync in multi-container deployments?
   - *Recommendation:* Phase 1 targets single-instance; document limitation

3. **Secrets Manager Integration:** Should we support external secrets (Vault, AWS Secrets)?
   - *Recommendation:* Out of scope for v1; design for future extensibility

4. **Field Validation:** How strict should validation be?
   - *Recommendation:* Validate format, warn on potential issues, allow override

5. **History/Diff View:** Should we show change history with diffs?
   - *Recommendation:* Phase 2 feature; store in DB, show last 50 changes

---

## 11. Risk Assessment

### Critical Risks (MUST mitigate)

| Risk | Impact | Mitigation |
|------|--------|------------|
| **Credential exposure in logs** | API keys stolen, accounts compromised | ALWAYS mask sensitive values in logs |
| **Unauthorized access** | Non-admin edits system config, steals credentials | Admin check on EVERY route + endpoint |
| **API returns raw secrets** | Credentials visible in browser devtools | ALWAYS return masked values (`••••••••`) |

### High Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Malformed config breaks app | System downtime | Syntax validation + automatic backup |
| Database URL change causes data loss | Permanent data loss | Double confirmation dialog + warning |

### Medium Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Container restart confusion | User frustrated, changes "don't work" | Prominent restart banner with copy command |
| Backup storage fills disk | System issues over time | Keep only last 5 backups, auto-cleanup |

---

## 12. Definition of Done

### Functionality
- [ ] Backend API endpoints implemented and tested
- [ ] Frontend UI complete with all 6 tabs
- [ ] All fields have appropriate input types and validation
- [ ] Backup/restore functionality working
- [ ] "Test Connection" buttons work for all services
- [ ] Restart notification banner displays after save

### Security (REQUIRED — No exceptions)
- [ ] Admin-only access enforced on ALL routes (frontend + backend)
- [ ] Sensitive fields masked in UI (show `••••••••`)
- [ ] Sensitive fields masked in API responses
- [ ] Sensitive fields NEVER appear in logs
- [ ] CSRF protection on all write operations
- [ ] Rate limiting on all endpoints
- [ ] Audit logging for all changes (with masked values)
- [ ] Unauthorized access tests pass (401/403 returned)
- [ ] Manual security review completed

### Quality
- [ ] `make lint` passes
- [ ] `make test` passes
- [ ] Translations complete (en + de)
- [ ] Documentation updated
- [ ] E2E tests for critical paths

---

## 13. User Experience: Restart Notification

After any `.env` change is saved, display a **prominent, persistent notification banner** at the top of the page:

### Notification Design

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ ⚠️  Configuration Updated — Restart Required                            [X] │
│                                                                             │
│ Your changes have been saved to the .env file. For the changes to take     │
│ effect, you need to restart your Docker containers.                        │
│                                                                             │
│ Run this command in your terminal:                                         │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ docker compose restart backend                                    [📋] │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                             │
│ [Copy Command]                              [I've Restarted — Dismiss]     │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Notification Behavior

1. **Persistence:** Banner stays visible until explicitly dismissed
2. **Page Navigation:** Banner persists across tab switches within the config page
3. **Copy Button:** One-click copy of the restart command
4. **Dismiss Options:**
   - "I've Restarted — Dismiss" (primary action)
   - X button (with "Are you sure? Changes won't take effect until restart" confirmation)
5. **Color:** Warning yellow/amber background (`bg-yellow-50 dark:bg-yellow-900/20`)
6. **Icon:** Warning triangle icon

### Translation Keys for Notification

```json
{
  "admin": {
    "config": {
      "restartBanner": {
        "title": "Configuration Updated — Restart Required",
        "message": "Your changes have been saved to the .env file. For the changes to take effect, you need to restart your Docker containers.",
        "commandLabel": "Run this command in your terminal:",
        "command": "docker compose restart backend",
        "copyCommand": "Copy Command",
        "dismiss": "I've Restarted — Dismiss",
        "dismissConfirm": "Are you sure? Changes won't take effect until you restart the containers."
      }
    }
  }
}
```

---

## 14. Related Documentation

- [CONFIGURATION.md](/wwwroot/synaplan/docs/CONFIGURATION.md) - User-facing config docs
- [EMAIL.md](/wwwroot/synaplan/docs/EMAIL.md) - Email setup guide
- [RAG.md](/wwwroot/synaplan/docs/RAG.md) - Vector DB setup
- [WHATSAPP.md](/wwwroot/synaplan/docs/WHATSAPP.md) - WhatsApp integration

---

## 15. Vibe Coding Guide (AI Agent Instructions)

This section provides step-by-step instructions for AI coding assistants implementing this feature.

### ⚠️ CRITICAL SECURITY RULES ⚠️

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  🔒 SECURITY IS NON-NEGOTIABLE                                              │
│                                                                             │
│  This feature handles sensitive credentials (API keys, passwords, secrets). │
│  ANY security shortcut or oversight can lead to credential theft,           │
│  unauthorized access, or complete system compromise.                        │
│                                                                             │
│  When in doubt: ASK. Do not assume. Do not skip security checks.           │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Security Checklist (MUST verify before any PR)

- [ ] **Admin-only access enforced** on ALL routes and API endpoints
- [ ] **No sensitive values in logs** — verify with `grep -r "API_KEY\|SECRET\|PASSWORD" var/log/`
- [ ] **No sensitive values in API responses** — all secrets return `"••••••••"`
- [ ] **CSRF protection** on all state-changing operations
- [ ] **Audit log** records who changed what and when (with masked values)
- [ ] **Backup created** before every write operation
- [ ] **Input validation** prevents injection attacks
- [ ] **Rate limiting** prevents brute force

### Step-by-Step Integration Instructions

#### Step 1: Backend — Create Config Schema (Read-Only)

**File:** `backend/src/Service/SystemConfigService.php`

```php
// Key points:
// 1. Define schema with field types, sections, and sensitivity levels
// 2. NEVER expose raw values for sensitive fields
// 3. Use getenv() to read current values
// 4. Group fields by tab/section matching the UI structure
```

**Security checks:**
- [ ] Schema defines `sensitive: true` for all API keys, secrets, passwords
- [ ] Sensitive fields have `masked: true` in schema response

#### Step 2: Backend — Create Admin Controller

**File:** `backend/src/Controller/Admin/SystemConfigController.php`

```php
// Key points:
// 1. #[IsGranted('ROLE_ADMIN')] on EVERY method
// 2. CSRF token validation on PUT/POST
// 3. Rate limiting attribute
// 4. Audit logging on every change
```

**Security checks:**
- [ ] `#[IsGranted('ROLE_ADMIN')]` present on class level
- [ ] Each endpoint additionally validates `$this->isGranted('ROLE_ADMIN')`
- [ ] No endpoint returns raw sensitive values

#### Step 3: Backend — Write Operations with Safety

**File:** `backend/src/Service/SystemConfigService.php` (extend)

```php
// Key points:
// 1. createBackup() BEFORE any write
// 2. Validate syntax before write
// 3. Write to .env.tmp, then atomic rename
// 4. Log change to audit table (masked values)
```

**Security checks:**
- [ ] Backup created with timestamp before write
- [ ] Old value logged as `****` not actual value
- [ ] New value logged as `****` for sensitive fields

#### Step 4: Frontend — Create Route with Guard

**File:** `frontend/src/router/index.ts`

```typescript
// Key points:
// 1. meta: { requiresAuth: true, requiresAdmin: true }
// 2. Navigation guard checks authStore.isAdmin
// 3. Redirect to /admin if not admin
```

**Security checks:**
- [ ] Route has `requiresAdmin: true` meta
- [ ] Navigation guard actually checks `authStore.isAdmin`
- [ ] Non-admin users see 403 or redirect, never the page

#### Step 5: Frontend — Create API Client

**File:** `frontend/src/services/api/adminConfigApi.ts`

```typescript
// Key points:
// 1. All requests include auth token
// 2. Response types match schema (with masked values)
// 3. Error handling for 401/403
```

**Security checks:**
- [ ] TypeScript types mark sensitive fields as `string` (always masked)
- [ ] No localStorage/sessionStorage of raw credentials

#### Step 6: Frontend — Create Config View

**File:** `frontend/src/views/AdminConfigView.vue`

```typescript
// Key points:
// 1. Check authStore.isAdmin in setup, redirect if false
// 2. Show masked values (•••) for sensitive fields
// 3. Password input type for sensitive fields
// 4. Show/hide toggle (reveal for 5 seconds, then auto-hide)
```

**Security checks:**
- [ ] `onMounted` checks `authStore.isAdmin`, redirects if false
- [ ] Sensitive fields use `<input type="password">`
- [ ] Show/hide auto-reverts to hidden after timeout
- [ ] No sensitive values stored in component state after unmount

#### Step 7: Frontend — Restart Banner Component

**File:** `frontend/src/components/admin/ConfigRestartBanner.vue`

```vue
// Key points:
// 1. Prominent yellow/amber warning style
// 2. Copy command button
// 3. Persist until dismissed
// 4. Confirm before dismiss
```

#### Step 8: Add Sidebar Navigation

**File:** `frontend/src/components/Sidebar.vue`

```typescript
// In adminChildren array, add:
{ path: '/admin/config', label: t('nav.adminSystemConfig') }
```

**Security checks:**
- [ ] Menu item only visible when `authStore.isAdmin` is true
- [ ] Direct URL access to `/admin/config` by non-admin redirects

#### Step 9: Translations

**Files:**
- `frontend/src/i18n/locales/en.json`
- `frontend/src/i18n/locales/de.json`

Add all keys from Section 8 (Translation Keys) and Section 13 (Restart Banner).

#### Step 10: Testing

**Backend tests:**
```bash
# Test admin-only access
make -C backend test -- --filter SystemConfig

# Verify no credentials in logs
grep -r "sk-\|pk_\|whsec_" var/log/  # Should return nothing
```

**Frontend tests:**
```bash
# Run component tests
make -C frontend test

# Manual test: Login as non-admin, navigate to /admin/config
# Expected: Redirect to /admin or 403 page
```

### Common Mistakes to Avoid

| Mistake | Why It's Bad | Correct Approach |
|---------|--------------|------------------|
| Logging raw API keys | Keys visible in log files | Always mask: `$key ? '****' : 'not set'` |
| Returning raw secrets in API | Exposed to browser dev tools | Return `"••••••••"` for sensitive fields |
| Only checking admin in UI | API still accessible | Check on BOTH frontend AND backend |
| Storing secrets in Vue state | Persists in memory/devtools | Clear on unmount, use computed getters |
| Skipping CSRF on "read" endpoints | CSRF needed for state changes | Add CSRF to PUT/POST/DELETE |
| No rate limiting | Brute force possible | Add `#[RateLimit]` attribute |

### Testing Security (Required Before PR)

```bash
# 1. Test unauthorized access (should all return 401/403)
curl -X GET http://localhost:8000/api/v1/admin/config/values
curl -X GET http://localhost:8000/api/v1/admin/config/schema
curl -X PUT http://localhost:8000/api/v1/admin/config/values -d '{}'

# 2. Test with non-admin user token (should return 403)
curl -X GET http://localhost:8000/api/v1/admin/config/values \
  -H "Authorization: Bearer $NON_ADMIN_TOKEN"

# 3. Test with admin token (should work, but mask sensitive values)
curl -X GET http://localhost:8000/api/v1/admin/config/values \
  -H "Authorization: Bearer $ADMIN_TOKEN" | grep -v "••••••••"
# ^ This should return EMPTY (all sensitive values masked)

# 4. Check logs for leaked secrets
grep -rE "(sk-|pk_|whsec_|API_KEY=)" var/log/
# ^ This should return NOTHING
```

### PR Checklist

Before submitting PR, verify:

- [ ] All security checks from each step are complete
- [ ] `make lint` passes
- [ ] `make test` passes
- [ ] Manual testing of unauthorized access completed
- [ ] No sensitive values in git diff (check for accidental .env commits)
- [ ] Documentation updated if needed
- [ ] Translations complete (en + de)
