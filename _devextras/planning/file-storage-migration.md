# File Storage Migration Plan

**Date:** 2026-01-05
**Status:** PLANNING
**Goal:** Ensure files are stored ONLY on disk (NFS mount) and never as encoded content in the database.

---

## 📋 Path & URL Convention (Consistency Check)

To ensure consistency across Local and LIVE (`https://web.synaplan.com`), we follow these canonical reference formats:

### 1. User Uploads & Attachments (`BFILES`)
*   **Storage Path:** `var/uploads/{last2}/{prev3}/{paddedUserId}/{year}/{month}/{filename}`
*   **Database (`BFILES.BFILEPATH`):** `{last2}/{prev3}/{paddedUserId}/{year}/{month}/{filename}` (e.g., `01/000/00001/2026/01/doc.pdf`)
*   **Frontend Display:** The API must return the path prefixed with `/up/` (e.g., `/up/01/000/00001/2026/01/doc.pdf`).
*   **Final URL:** `https://web.synaplan.com/up/01/000/00001/2026/01/doc.pdf`
*   **Access Control:** Handled by `FileServeController`.

### 2. AI Generated Media (`BMESSAGES`)
*   **Storage Path:** `var/uploads/{YYYYMM}_{messageId}_{provider}.{ext}`
*   **Database (`BMESSAGES.BFILEPATH`):** `/api/v1/files/uploads/{filename}` (e.g., `/api/v1/files/uploads/202601_1234567_google.mp4`)
*   **Frontend Display:** Directly from DB.
*   **Final URL:** `https://web.synaplan.com/api/v1/files/uploads/202601_1234567_google.mp4`
*   **Access Control:** Handled by `StaticUploadController`.

---

## ⚠️ CRITICAL ISSUE FOUND: Binary in `BFILETEXT`

During the consistency check, we found that AI-generated Office files currently store their **entire binary payload** into the `BFILES.BFILETEXT` column:

*   `ChatHandler.php:979`: `$file->setFileText($content);`
*   `StreamController.php:1149`: `$file->setFileText($content);`

This violates the goal of keeping binary data out of the database.

**Required Fix:**
1.  **Never** store raw `$content` in `BFILETEXT` if it's binary.
2.  After writing the file to disk, trigger the **Extraction Pipeline** (`FileProcessor`) to extract pure text from the file (e.g., via Tika for `.xlsx`).
3.  Store **only the extracted pure text** in `BFILETEXT` for RAG/searchability.

---

## ⚠️ CRITICAL ISSUE FOUND: Base64 Data URLs in `BFILEPATH`

When AI image generation **download fails**, the base64 data URL from the AI provider is currently stored directly in `BMESSAGES.BFILEPATH`.

**Root Cause:** `MediaGenerationHandler.php:327` uses the original AI provider URL (often a data URL) as a fallback.

**Result:** `BMESSAGES.BFILEPATH` (type: `text`) can contain huge base64-encoded images, bloating the database.

---

## Executive Summary

This document plans the implementation to ensure:
1. **Files are stored on filesystem** at `var/uploads/` (which will be an NFS-mounted storage).
2. **BFILETEXT column** contains ONLY pure extracted text (no binary payloads, no data URLs).
3. **BFILEPATH column** contains ONLY path references (no data URLs).
4. **Fix-on-read** automatically heals legacy data URLs when a message is accessed.

---

## Code Locations Requiring Fixes

### 1. MediaGenerationHandler.php - Fix Generation & Fallback

**File:** `backend/src/Service/Message/Handler/MediaGenerationHandler.php`

**Proposed Fix:**
1. If media is `data:`, ALWAYS use `saveDataUrlAsFile()`.
2. If media is an external URL, ALWAYS attempt `downloadImage()`.
3. Never return a base64 data URL as the file path.

```php
// Handle base64 data URL or external URL
if (str_starts_with($mediaUrl, 'data:')) {
    // ALWAYS decode base64 and save to disk - NEVER store data URLs in DB
    $localPath = $this->saveDataUrlAsFile($mediaUrl, $message->getId(), $provider);
    if (!$localPath) {
        throw new \Exception("Failed to save generated media to disk");
    }
} else {
    // Attempt download
    $localPath = $this->downloadImage($mediaUrl, $message->getId(), $provider);
    if (!$localPath) {
        throw new \Exception("Failed to download media from: {$mediaUrl}");
    }
}

// Result is always a URL reference for StaticUploadController
$displayUrl = "/api/v1/files/uploads/{$localPath}";
```

**New Method - `saveDataUrlAsFile()`:**
Creates files from data URLs with naming convention: `{YYYYMM}_{messageId}_{provider}.{ext}`

---

### 2. MediaGenerationHandler - `downloadImage()` Fix

Update `downloadImage()` to accept message ID and provider, and use the `{YYYYMM}_{messageId}_{provider}.{ext}` naming convention.

---

### 3. StreamController.php & ChatHandler.php - BFILETEXT Fix

**Required Fix:**
Instead of `setFileText($content)`, use the `FileProcessor` to extract text from the file after it has been saved to disk.

---

## Lazy Migration: Fix-On-Read Service

### New Service: `DataUrlFixer`

**File:** `backend/src/Service/File/DataUrlFixer.php`

This service will:
1. Check if `BFILEPATH` starts with `data:`.
2. If so, decode the content and save it to `var/uploads/` using the `{YYYYMM}_{messageId}_{provider}.{ext}` format.
3. Update the `BFILEPATH` in the database to `/api/v1/files/uploads/{filename}`.
4. Flush the changes to the database.

### Key Integration Points

The fixer must be called in **API responses that return chat history**, as the frontend will render `data:` URLs as-is if present.

| File | Method | Why |
|------|--------|-----|
| `ChatController.php` | `getMessages()` | Fix messages before they are returned to the UI. |
| `ChatController.php` | `getShared()` | Fix shared chat messages. |
| `MessageController.php` | `sendMessage()` | Fix non-streamed responses before they are persisted/returned. |

---

## Files Summary

### New Files
| File | Purpose |
|------|---------|
| `Service/File/DataUrlFixer.php` | Fix-on-read service for data URLs. |

### Must Fix (Code Changes)
| File | Issue | Fix |
|------|-------|-----|
| `MediaGenerationHandler.php` | Data URL fallback | Use `saveDataUrlAsFile()`. |
| `MediaGenerationHandler.php` | Naming convention | Use `{YYYYMM}_{id}_{provider}.{ext}`. |
| `ChatHandler.php` | Binary in `BFILETEXT` | Extract text instead of storing content. |
| `StreamController.php` | Binary in `BFILETEXT` | Extract text instead of storing content. |
| `ChatController.php` | URL building | Ensure `/up/` prefix for user files. |

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Base64 URLs in production DB | **High** | High | Fix-on-read + prevent new writes. |
| Download failures cause data loss | Medium | Medium | Always decode base64 as fallback. |
| Path inconsistency on LIVE | Low | High | Use canonical URL paths (`/up/...`, `/api/...`). |

---

## Timeline Estimate

| Phase | Duration | Notes |
|-------|----------|-------|
| Phase 1: `DataUrlFixer` & `MediaGenerationHandler` fixes | 3 hours | Core storage logic. |
| Phase 2: `BFILETEXT` extraction fixes | 2 hours | Prevent binary leakage. |
| Phase 3: Controller integration & URL consistency | 1 hour | History & shared chats. |
| Phase 4: Verification & Testing | 2 hours | Verify on local before LIVE. |
| **Total** | **8 hours** | 1 day |
