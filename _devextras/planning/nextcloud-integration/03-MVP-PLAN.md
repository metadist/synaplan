# Step 3: MVP Implementation Plan

## Overview

Six phases, each delivering a working vertical slice. Each phase ends with a verifiable outcome.

**Target**: Nextcloud 34, PHP 8.2+, Vue 3, `@nextcloud/vue` components.

---

## Phase 1: App Skeleton & Configuration

**Goal**: App installs cleanly, admin settings page saves and persists Synaplan credentials.

**Duration**: ~2 hours

### Tasks

1. **App Manifest** (`appinfo/info.xml`)
   - App ID: `synaplan_integration`
   - Namespace: `SynaplanIntegration`
   - Min NC version: 30, Max NC version: 34
   - Category: `integration`
   - Licence: AGPL

2. **Application Class** (`lib/AppInfo/Application.php`)
   - Extends `OCP\AppFramework\App`
   - Implements `IBootstrap`
   - Registers settings, event listeners
   - Constant `APP_ID = 'synaplan_integration'`

3. **Admin Settings Page**
   - `lib/Settings/SynaplanAdmin.php` implements `OCP\Settings\ISettings`
   - Template renders Vue app
   - Fields:
     - `synaplan_url` (text, default: `http://localhost:8000`)
     - `synaplan_api_key` (password field)
   - "Test Connection" button → calls `/api/health`
   - "Open Synaplan" link → opens `synaplan_url` in new tab
   - Saved via `OCP\IConfig` (`setAppValue` / `getAppValue`)

4. **Settings API Controller** (`lib/Controller/SettingsController.php`)
   - `GET /settings` — Return current settings
   - `PUT /settings` — Save settings
   - `POST /settings/test` — Test Synaplan connection

5. **Synaplan API Client** (`lib/Service/SynaplanClient.php`)
   - Pure PHP HTTP client (uses `OCP\Http\Client\IClientService`)
   - Methods: `healthCheck()`, `summarize()`, `createChat()`, `sendMessage()`, `uploadFile()`
   - All methods use `X-API-Key` header
   - Proper error handling and timeouts

6. **Frontend** (`src/settings.js` + `src/components/AdminSettings.vue`)
   - Vue 3 component with `@nextcloud/vue` inputs
   - Save/load settings via OCS API
   - Test connection with loading state

### Verify

- [ ] `occ app:enable synaplan_integration` succeeds
- [ ] Admin → Settings → "Synaplan Integration" section visible
- [ ] URL and API key save and persist across page reloads
- [ ] "Test Connection" shows success/failure
- [ ] "Open Synaplan" opens in new tab
- [ ] No PHP errors in Nextcloud log

### Key Files

```
synaplan_integration/
├── appinfo/
│   ├── info.xml
│   └── routes.php
├── lib/
│   ├── AppInfo/Application.php
│   ├── Controller/SettingsController.php
│   ├── Service/SynaplanClient.php
│   └── Settings/SynaplanAdmin.php
├── src/
│   ├── settings.js
│   └── components/AdminSettings.vue
├── templates/
│   └── settings/admin.php
├── img/
│   └── app.svg
├── package.json
└── webpack.config.js
```

---

## Phase 2: Summarization

**Goal**: Right-click file → "Summarize with Synaplan" → See result in modal.

**Duration**: ~3 hours

### Prerequisites

- Phase 1 complete (settings configured, API client working)

### UX Flow

1. User right-clicks a supported file (txt, md, pdf, docx)
2. Clicks "Summarize with Synaplan" in context menu
3. Modal opens with loading spinner + "Analyzing {filename}..."
4. Summary appears with options to change type/length
5. "Copy" and "Close" buttons

### Tasks

1. **File Action Registration**
   - In `Application::boot()` or via event listener
   - Register file action for supported MIME types
   - NC34: Use `\OCA\Files\Event\LoadAdditionalScriptsEvent` to inject frontend JS
   - Frontend: `OCA.Files` file action API or `@nextcloud/files` registerFileAction

2. **Backend: Summarize Endpoint** (`lib/Controller/SynaplanController.php`)
   - `POST /api/v1/summarize`
   - Accepts `fileId`, `summaryType`, `length`, `outputLanguage`
   - Reads file from Nextcloud storage (`OCP\Files\IRootFolder`)
   - For text files: reads content directly
   - For binary files (PDF, DOCX): uploads to Synaplan first, reads extracted text
   - Calls Synaplan `/api/v1/summary/generate`
   - Returns summary JSON

3. **File Content Extraction** (`lib/Service/FileContentService.php`)
   - Gets file node from Nextcloud storage
   - Reads content for text-based files
   - For binary files: uploads to Synaplan, returns extracted text
   - Caches extracted text to avoid re-processing
   - Size limit check (configurable, default 10MB)

4. **Frontend: Summary Modal** (`src/components/SummaryModal.vue`)
   - Uses `@nextcloud/vue` `NcModal` component
   - Summary type selector (bullet-points, abstractive, extractive)
   - Length selector (short, medium, long)
   - Result display with markdown rendering
   - Copy to clipboard button
   - Loading state with progress indication
   - Error state with retry button

5. **Frontend: File Action Script** (`src/files-actions.js`)
   - Registers context menu items
   - Opens modal on click
   - Passes file ID and metadata to modal

### Verify

- [ ] Context menu shows "Summarize with Synaplan" on .txt files
- [ ] Context menu shows on .pdf, .docx files
- [ ] Modal opens with loading state
- [ ] Summary displays correctly for text files
- [ ] Summary displays correctly for PDF files
- [ ] Copy button works
- [ ] Error message shows when API is down
- [ ] No menu item on unsupported files (.zip, .jpg)

---

## Phase 3: Translation

**Goal**: Right-click file → "Translate with Synaplan" → Pick language → See result.

**Duration**: ~2 hours

### Prerequisites

- Phase 2 complete (file content extraction and modal pattern established)

### UX Flow

1. User right-clicks a supported file
2. Clicks "Translate with Synaplan"
3. Modal opens with language dropdown (EN, DE, FR, ES, IT)
4. User selects target language
5. Translation appears
6. Download as .txt or Copy options

### Tasks

1. **File Action**: Register "Translate" menu item (same pattern as Summarize)

2. **Backend: Translate Endpoint** (`lib/Controller/SynaplanController.php`)
   - `POST /api/v1/translate`
   - Accepts `fileId`, `targetLanguage`
   - Reuses `FileContentService` for text extraction
   - Calls Synaplan summary API with `summaryType: "abstractive"`, `length: "long"`, `outputLanguage: $targetLang`

3. **Frontend: Translate Modal** (`src/components/TranslateModal.vue`)
   - Reuses modal layout from SummaryModal
   - Language dropdown with flag icons
   - Result display with copy and download options
   - Detects source language (show in header)

### Verify

- [ ] Language selection dropdown works
- [ ] Translation displays correctly
- [ ] Download as .txt creates proper file
- [ ] Copy button works

---

## Phase 4: Document Chat (RAG)

**Goal**: File sidebar tab with chat interface for asking questions about the selected file.

**Duration**: ~6 hours

### Prerequisites

- Phase 1-3 complete
- SSE proxy mechanism implemented

### UX Flow

1. User selects a file in Files app
2. Opens sidebar → "Synaplan" tab
3. Sees: File name context indicator + chat interface
4. Types question → Streaming response appears
5. "Open in Synaplan" button for full RAG experience

### Tasks

1. **Sidebar Tab Registration**
   - Register via `LoadAdditionalScriptsEvent`
   - Frontend: Use `OCA.Files.Sidebar.registerTab()` or NC34 equivalent
   - Tab icon: Synaplan logo
   - Tab label: "Synaplan AI"

2. **Backend: Chat Endpoints** (`lib/Controller/ChatController.php`)
   - `POST /api/v1/chat/start` — Creates chat session, uploads file to Synaplan
   - `GET /api/v1/chat/stream` — SSE proxy to Synaplan's message stream
   - `GET /api/v1/chat/{chatId}/messages` — Chat history

3. **SSE Proxy Service** (`lib/Service/SseProxyService.php`)
   - Proxies SSE from Synaplan to Nextcloud client
   - Maintains `X-API-Key` server-side (never exposed to browser)
   - Handles connection timeouts and reconnection
   - Passes through `token`, `complete`, `error` events

4. **File Upload to Synaplan** (in `SynaplanClient`)
   - Uploads Nextcloud file to Synaplan via `/api/v1/files/upload`
   - Stores mapping: NC file ID → Synaplan file ID (in NC preferences or app config)
   - Avoids re-uploading same file (check by hash)

5. **Frontend: Chat Sidebar** (`src/components/ChatSidebar.vue`)
   - Message list with user/AI distinction
   - Streaming text display (append tokens as they arrive)
   - Markdown rendering for AI responses
   - Input field with Enter-to-send
   - File context indicator ("Chatting about: report.pdf")
   - "Open in Synaplan" link
   - Loading/typing indicator during streaming

6. **Frontend: SSE Client** (`src/services/sseClient.js`)
   - EventSource connection to Nextcloud's SSE proxy endpoint
   - Handles `token`, `complete`, `error` events
   - Auto-reconnect on connection drop

### Verify

- [ ] Sidebar tab appears when file is selected
- [ ] Chat starts with file context
- [ ] Streaming responses display token-by-token
- [ ] Markdown renders correctly (code blocks, lists, etc.)
- [ ] "Open in Synaplan" links to web UI with context
- [ ] Chat history persists when switching away and back
- [ ] Error handling when API is down

---

## Phase 5: Research Chat

**Goal**: General AI chat accessible from top navigation or app launcher.

**Duration**: ~4 hours

### Prerequisites

- Phase 4 complete (chat and SSE patterns established)

### UX Flow

1. User clicks "Synaplan" in app launcher or top navigation
2. Opens full-page chat interface
3. No file context — general knowledge + web search
4. Web search toggle available
5. "Open in Synaplan" button

### Tasks

1. **Navigation Entry** (`appinfo/info.xml`)
   - `<navigation>` element with route to Research Chat page
   - Icon: Synaplan logo SVG

2. **Page Controller** (`lib/Controller/PageController.php`)
   - `GET /` — Renders Research Chat template
   - Uses `#[FrontpageRoute]` attribute
   - Injects initial state (settings, user info)

3. **Frontend: Research Chat Page** (`src/views/ResearchChat.vue`)
   - Full-page chat interface
   - Chat list sidebar (previous conversations)
   - New chat button
   - Web search toggle
   - Model selector (optional, if Synaplan exposes models)
   - Reuses chat components from Phase 4

4. **Backend: Research Chat API** (reuses `ChatController`)
   - Same endpoints as Document Chat
   - Without file context
   - With optional `webSearch=1` parameter

### Verify

- [ ] "Synaplan" appears in app launcher
- [ ] Full-page chat loads correctly
- [ ] Chat works without file context
- [ ] Web search toggle works
- [ ] New conversations can be created
- [ ] Previous conversations are listed

---

## Phase 6: Polish & Release

**Goal**: Production-ready quality, error handling, translations, documentation.

**Duration**: ~4 hours

### Tasks

1. **Error Handling**
   - Graceful failures for all API calls
   - Retry buttons on transient errors
   - Clear error messages ("Synaplan is not reachable — check settings")
   - Logging to Nextcloud log (`\OCP\ILogger`)

2. **Loading States**
   - Skeleton loaders for chat messages
   - Spinners for API calls
   - Progress indicators for file uploads

3. **Translations (l10n)**
   - English (`l10n/en.json`)
   - German (`l10n/de.json`)
   - Use `\OCP\IL10N` in PHP, `@nextcloud/l10n` in JS

4. **Security**
   - CSRF protection on all endpoints
   - Rate limiting on API calls
   - API key stored encrypted in NC config
   - No API key exposure to browser

5. **Documentation**
   - User-facing README in app folder
   - Admin guide (setup, configuration)
   - Screenshots for App Store
   - CHANGELOG.md

6. **Testing**
   - PHPUnit tests for `SynaplanClient`
   - PHPUnit tests for controllers
   - Jest/Vitest tests for Vue components
   - Integration test script

7. **"Open in Synaplan" Links**
   - Every panel has escape hatch to full Synaplan UI
   - Deep links include context (filename, chat ID)

### UX Checklist

- [ ] Every action has visual feedback
- [ ] Errors are actionable ("Try again", "Check settings")
- [ ] Heavy operations don't block the UI
- [ ] Keyboard shortcuts work (Enter to send in chat)
- [ ] Dark theme compatibility
- [ ] Mobile-responsive layout

---

## Timeline Summary

| Phase | Feature | Effort | Dependencies |
|-------|---------|--------|--------------|
| 1 | Skeleton & Settings | ~2h | None |
| 2 | Summarization | ~3h | Phase 1 |
| 3 | Translation | ~2h | Phase 2 |
| 4 | Document Chat (RAG) | ~6h | Phase 1 |
| 5 | Research Chat | ~4h | Phase 4 |
| 6 | Polish & Release | ~4h | All |
| | **Total** | **~21h** | |

Phases 2-3 (Summarize/Translate) and Phase 4 (Document Chat) can be developed in parallel after Phase 1.
