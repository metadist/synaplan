# Step 3: MVP Implementation Plan

## Phase 1: Skeleton & Configuration
**Goal:** App installs, settings page works.

### Tasks
1. **Manifest:** `appinfo/info.xml` (ID: `synaplan_integration`).
2. **Admin Settings Page:**
   - Fields: `synaplan_url`, `synaplan_api_key`.
   - "Test Connection" button.
   - "Open Synaplan" link (opens `synaplan_url` in new tab).
3. **Backend Service:** `lib/Service/SynaplanClient.php` with `checkConnection()`.

### Verify
- [ ] App enables without errors.
- [ ] Settings save and persist.
- [ ] "Open Synaplan" link works.

---

## Phase 2: Summarization
**Goal:** Right-click file → "Summarize" → See result.

### UX Flow
1. User right-clicks a file (txt, pdf, docx, md).
2. Clicks "Summarize with Synaplan".
3. Modal opens with loading spinner.
4. Summary appears. Copy button available.

### Tasks
1. **File Action:** Register context menu item.
2. **Controller:** `ApiController->summarize($fileId)`.
3. **Modal Component:** `SummaryModal.vue`.

### Verify
- [ ] Menu item appears on supported file types.
- [ ] Summary displays correctly.
- [ ] Errors show user-friendly message.

---

## Phase 3: Translation
**Goal:** Right-click file → "Translate" → Pick language → See result.

### UX Flow
1. User right-clicks a file.
2. Clicks "Translate with Synaplan".
3. Modal opens with language dropdown (EN, DE, FR, ES, IT).
4. User selects target language.
5. Translation appears. Download/Copy options.

### Tasks
1. **File Action:** Register "Translate" menu item.
2. **Controller:** `ApiController->translate($fileId, $targetLang)`.
3. **Modal Component:** `TranslateModal.vue` (reuse Summary modal layout).

### Verify
- [ ] Language selection works.
- [ ] Translation displays correctly.

---

## Phase 4: Document Chat (RAG)
**Goal:** Sidebar tab to ask questions about the selected file.

### UX Flow
1. User selects a file in Files app.
2. Opens sidebar → "Synaplan" tab.
3. Sees: File name context indicator + chat interface.
4. Types question → Streaming response appears.
5. "Open in Synaplan" button for full RAG experience.

### Tasks
1. **Sidebar Tab:** Register in Files app sidebar.
2. **Chat UI:** `DocumentChat.vue` (message list, input, markdown).
3. **Controller:** `ChatController->documentChat($fileId, $message)`.
4. **Streaming:** Proxy SSE from Synaplan.

### Verify
- [ ] Sidebar shows current file context.
- [ ] Chat streams responses.
- [ ] "Open in Synaplan" links to web UI with context.

---

## Phase 5: Research Chat
**Goal:** General AI chat accessible from top navigation.

### UX Flow
1. User clicks "Synaplan" in top nav or app launcher.
2. Opens full-page chat interface.
3. No file context — general knowledge + web search.
4. "Open in Synaplan" button for full experience.

### Tasks
1. **Navigation Entry:** Register app in top bar.
2. **Full Page:** `pages/research.vue`.
3. **Controller:** `ChatController->researchChat($message)`.
4. **Chat History:** Store in Nextcloud user preferences or Synaplan.

### Verify
- [ ] Navigation entry visible.
- [ ] Chat works without file context.
- [ ] Web search toggle available.

---

## Phase 6: Polish & Release
1. **Error Handling:** Graceful failures, retry buttons.
2. **Loading States:** Skeletons, spinners, progress indicators.
3. **Translations:** `l10n` for EN/DE.
4. **Documentation:** User-facing README in app folder.
5. **"Open in Synaplan" Links:** Ensure all panels have escape hatch.

### UX Checklist
- [ ] Every action has visual feedback.
- [ ] Errors are actionable ("Try again", "Check settings").
- [ ] Heavy operations don't block the UI.
- [ ] Users can always escape to full Synaplan UI.
