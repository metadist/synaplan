# File Storage Migration Plan

**Date:** 2026-01-05
**Status:** PLANNING
**Goal:** Ensure files are stored ONLY on disk (NFS mount at `upload/`) and never as encoded content in the database

---

## 📋 Filename Convention

All AI-generated media files will use this naming format:

```
{YYYYMM}_{messageId}_{provider}.{ext}
```

**Examples:**
- `202601_1234567_google.png` - Google-generated image
- `202601_8901234_openai.mp4` - OpenAI-generated video
- `202512_5678901_anthropic.jpg` - Anthropic-generated image

**Benefits:**
- Sortable by date (YYYYMM prefix)
- Traceable to specific message (messageId)
- Identifies source AI provider
- No collisions (unique message IDs)

---

## ⚠️ CRITICAL ISSUE FOUND

### Base64 Data URLs Being Stored in Database

When AI image generation **download fails**, the base64 data URL from the AI provider is stored directly in `BMESSAGES.BFILEPATH`:

**Code Path:**
1. `MediaGenerationHandler.php:298-299` - Gets image URL from AI provider (can be `data:image/png;base64,...`)
2. `MediaGenerationHandler.php:310` - Attempts to download image to local storage
3. `MediaGenerationHandler.php:327` - **If download fails, uses original URL as fallback!**
4. `MediaGenerationHandler.php:354` - Returns `displayUrl` in metadata
5. `StreamController.php:667` - Gets `$filePath` from `$response['metadata']['file']['path']`
6. `StreamController.php:698` - **Stores in `$outgoingMessage->setFilePath($filePath)`**

**Result:** `BMESSAGES.BFILEPATH` (type: `text`) can contain huge base64-encoded images!

```php
// MediaGenerationHandler.php:325-327
// PROBLEM: If download fails, $mediaUrl is a base64 data URL from AI provider!
$displayUrl = $localPath ? "/api/v1/files/uploads/{$localPath}" : $mediaUrl;
```

### AI Provider Return Values (All Base64)

| Provider | Method | Returns |
|----------|--------|---------|
| `GoogleProvider.php:320` | Image generation | `data:image/png;base64,...` |
| `GoogleProvider.php:375` | Imagen API | `data:image/png;base64,...` |
| `GoogleProvider.php:511` | Video generation | `data:video/mp4;base64,...` |
| `GoogleProvider.php:594` | Image edit | `data:image/png;base64,...` |
| `OpenAIProvider.php:463` | Image generation | `data:image/png;base64,...` |

---

## Executive Summary

This document plans the migration to ensure:
1. **Files are stored on filesystem** at `upload/` (NFS-mounted storage)
2. **BFILEPATH columns** contain only relative file paths
3. **No base64/encoded file content** in database columns
4. **Vectorization is preserved** (text extraction for RAG is OK)

---

## Current State Analysis

### ✅ What's Already Working

The codebase **already stores files on disk**, not in the database:

1. **FileStorageService.php** stores files to `var/uploads/`
2. **UserUploadPathBuilder.php** creates user-based directory structure
3. **BFILES.BFILEPATH** is `varchar(255)` storing relative paths
4. **File entities** store metadata + path, not file content

### Current Path Structure (Correct - No Change Needed)

```
{last2}/{prev3}/{paddedUserId}/{year}/{month}/{filename}
Example: 13/000/00013/2025/01/image_1735123456.png
```

### Database Tables Involved

| Table | Column | Type | Current Usage | Issue |
|-------|--------|------|---------------|-------|
| `BFILES` | `BFILEPATH` | varchar(255) | Relative path | ✅ Correct |
| `BFILES` | `BFILETEXT` | longtext | Extracted text for RAG | ✅ Correct |
| `BMESSAGES` | `BFILEPATH` | text | Relative path OR base64 | ❌ **May contain base64** |
| `BMESSAGES` | `BFILETEXT` | longtext | Extracted text for RAG | ✅ Correct |

---

## Code Locations Requiring Fixes

### 1. MediaGenerationHandler.php - CRITICAL FIX NEEDED

**File:** `backend/src/Service/Message/Handler/MediaGenerationHandler.php`

**Current Problem (Lines 325-327):**
```php
// PROBLEM: Falls back to base64 URL if download fails
$displayUrl = $localPath ? "/api/v1/files/uploads/{$localPath}" : $mediaUrl;
```

**Required Fix:**
1. If download fails, decode base64 and save to disk anyway
2. Never return a base64 data URL as the file path
3. Use `UserUploadPathBuilder` for consistent path structure

**Proposed Fix:**
```php
// Handle base64 data URL or external URL
if (str_starts_with($mediaUrl, 'data:')) {
    // ALWAYS decode base64 and save to disk - NEVER store data URLs in DB
    $localPath = $this->saveDataUrlAsFile($mediaUrl, $message->getId(), $provider);
    if (!$localPath) {
        throw new \Exception("Failed to save generated media to disk");
    }
} elseif (!$localPath) {
    // External URL download failed - try one more time with cURL
    $localPath = $this->downloadImage($mediaUrl);
    if (!$localPath) {
        throw new \Exception("Failed to download media from: {$mediaUrl}");
    }
}

$displayUrl = "/api/v1/files/uploads/{$localPath}";
```

**New Method - `saveDataUrlAsFile()`:**

Creates files from data URLs with naming convention: `{YYYYMM}_{messageId}_{provider}.{ext}`

```php
/**
 * Save a data URL (base64 encoded) as a file on disk.
 *
 * Filename format: {YYYYMM}_{messageId}_{provider}.{ext}
 * Example: 202601_1234567_google.mp4
 *
 * @param string $dataUrl  The data URL (e.g., data:image/png;base64,...)
 * @param int    $messageId The message ID for filename
 * @param string $provider  The AI provider name (google, openai, etc.)
 * @return string|null Relative file path or null on failure
 */
private function saveDataUrlAsFile(string $dataUrl, int $messageId, string $provider): ?string
{
    // Parse data URL: data:image/png;base64,XXXX
    if (!preg_match('#^data:([^;]+);base64,(.+)$#', $dataUrl, $matches)) {
        $this->logger->error('MediaGenerationHandler: Invalid data URL format');
        return null;
    }

    $mimeType = $matches[1];
    $base64Data = $matches[2];
    $fileContent = base64_decode($base64Data, true);

    if (false === $fileContent || empty($fileContent)) {
        $this->logger->error('MediaGenerationHandler: Failed to decode base64 data');
        return null;
    }

    // Determine extension from MIME type
    $extension = match ($mimeType) {
        'image/png' => 'png',
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'audio/mpeg', 'audio/mp3' => 'mp3',
        'audio/wav' => 'wav',
        default => 'bin',
    };

    // Generate filename: YYYYMM_messageId_provider.ext
    // Example: 202601_1234567_google.png
    $yearMonth = date('Ym');
    $sanitizedProvider = preg_replace('/[^a-z0-9]/', '', strtolower($provider));
    $filename = sprintf('%s_%d_%s.%s', $yearMonth, $messageId, $sanitizedProvider, $extension);

    // Build path: {uploadDir}/{filename}
    // Files go directly in upload dir (flat structure for generated media)
    $absolutePath = $this->uploadDir.'/'.$filename;

    // Save file
    $bytesWritten = file_put_contents($absolutePath, $fileContent);

    if (false === $bytesWritten) {
        $this->logger->error('MediaGenerationHandler: Failed to write file', [
            'path' => $absolutePath,
        ]);
        return null;
    }

    $this->logger->info('MediaGenerationHandler: Saved data URL as file', [
        'filename' => $filename,
        'mime_type' => $mimeType,
        'size' => $bytesWritten,
    ]);

    return $filename;  // Return just the filename (relative to uploadDir)
}
```

---

### 2. MediaGenerationHandler - `downloadImage()` Fix

**Current Problem (Lines 446-448):**
```php
// Uses uniqid() - no meaningful filename
$filename = 'generated_'.uniqid().'.'.$extension;
$localPath = $this->uploadDir.'/'.$filename;
```

**Required Fix:** Update `downloadImage()` to accept message ID and provider, use consistent naming:

```php
/**
 * Download image from URL to local storage.
 *
 * @param string $url       The image URL to download
 * @param int    $messageId The message ID for filename
 * @param string $provider  The AI provider name
 * @return string|null Relative filename or null on failure
 */
private function downloadImage(string $url, int $messageId, string $provider): ?string
{
    try {
        // ... existing download logic ...

        // NEW: Use consistent filename format
        $yearMonth = date('Ym');
        $sanitizedProvider = preg_replace('/[^a-z0-9]/', '', strtolower($provider));
        $filename = sprintf('%s_%d_%s.%s', $yearMonth, $messageId, $sanitizedProvider, $extension);
        $localPath = $this->uploadDir.'/'.$filename;

        // Save to disk
        $bytesWritten = file_put_contents($localPath, $imageContent);
        // ...

        return $filename;
    } catch (\Exception $e) {
        // ...
        return null;
    }
}
```

**Update all calls:**
```php
// Before:
$localPath = $this->downloadImage($mediaUrl);

// After:
$localPath = $this->downloadImage($mediaUrl, $message->getId(), $provider);
```

---

### 3. StreamController.php - Validation

**File:** `backend/src/Controller/StreamController.php:698`

```php
$outgoingMessage->setFilePath($filePath);
```

**Add Validation:**
```php
// Ensure filePath is not a data URL
if (str_starts_with($filePath, 'data:')) {
    $this->logger->error('StreamController: Refusing to save data URL in BFILEPATH', [
        'message_id' => $outgoingMessage->getId(),
    ]);
    $filePath = ''; // Clear instead of saving blob
}
$outgoingMessage->setFilePath($filePath);
```

---

### 4. Files Already Correct (No Changes Needed)

These use `UserUploadPathBuilder` and `FileStorageService` correctly:

| File | Function |
|------|----------|
| `FileStorageService.php` | User file uploads |
| `FileController.php` | Upload endpoint |
| `WidgetPublicController.php` | Widget uploads |
| `StreamController.php:1117` | AI-generated office files |
| `ChatHandler.php:947` | AI-generated files |
| `WhatsAppService.php:797` | WhatsApp media |

---

## Data Migration & Cleanup

### SQL Query to Find Base64 Data in Database

```sql
-- Find BMESSAGES entries with base64 encoded images
SELECT BID, BUSERID, BFILE, LEFT(BFILEPATH, 100) as path_preview, LENGTH(BFILEPATH) as path_length
FROM BMESSAGES
WHERE BFILEPATH LIKE 'data:%'
   OR BFILEPATH LIKE '/api/v1/files/uploads/generated_%'
LIMIT 100;

-- Count affected rows
SELECT COUNT(*) as affected_count
FROM BMESSAGES
WHERE BFILEPATH LIKE 'data:%';
```

### Migration Script for Existing Data

```php
<?php
// bin/console app:fix-base64-filepaths

namespace App\Command;

use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:fix-base64-filepaths',
    description: 'Extract base64 data URLs from BMESSAGES.BFILEPATH and save as files'
)]
class FixBase64FilePathsCommand extends Command
{
    public function __construct(
        private MessageRepository $msgRepo,
        private EntityManagerInterface $em,
        private string $uploadDir,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->getOption('dry-run');

        // Find messages with base64 data URLs in BFILEPATH
        $messages = $this->msgRepo->createQueryBuilder('m')
            ->where('m.filePath LIKE :pattern')
            ->setParameter('pattern', 'data:%')
            ->getQuery()
            ->getResult();

        $output->writeln(sprintf('Found %d messages with data URLs', count($messages)));

        $fixed = 0;
        $errors = 0;

        foreach ($messages as $message) {
            $dataUrl = $message->getFilePath();

            // Parse data URL: data:image/png;base64,XXXX
            if (!preg_match('#^data:([^;]+);base64,(.+)$#', $dataUrl, $matches)) {
                $output->writeln(sprintf('  [SKIP] Message %d: Invalid data URL format', $message->getId()));
                $errors++;
                continue;
            }

            $mimeType = $matches[1];
            $base64Data = $matches[2];
            $fileContent = base64_decode($base64Data, true);

            if (false === $fileContent || empty($fileContent)) {
                $output->writeln(sprintf('  [ERROR] Message %d: Failed to decode base64', $message->getId()));
                $errors++;
                continue;
            }

            // Determine extension and provider
            $extension = $this->getExtensionFromMime($mimeType);
            $provider = $this->guessProviderFromMessage($message);

            // Generate filename: YYYYMM_messageId_provider.ext
            // Use original message timestamp for YYYYMM
            $yearMonth = date('Ym', $message->getUnixTimestamp());
            $filename = sprintf('%s_%d_%s.%s', $yearMonth, $message->getId(), $provider, $extension);
            $absolutePath = $this->uploadDir.'/'.$filename;

            $output->writeln(sprintf('  Message %d: %s (%d bytes)',
                $message->getId(),
                $filename,
                strlen($fileContent)
            ));

            if ($dryRun) {
                $fixed++;
                continue;
            }

            // Save file
            if (file_put_contents($absolutePath, $fileContent)) {
                $message->setFilePath($filename);
                $fixed++;

                $this->logger->info('Migrated base64 to file', [
                    'message_id' => $message->getId(),
                    'filename' => $filename,
                    'size' => strlen($fileContent),
                ]);
            } else {
                $output->writeln(sprintf('  [ERROR] Message %d: Failed to write file', $message->getId()));
                $errors++;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $output->writeln('');
        $output->writeln(sprintf('Fixed: %d, Errors: %d%s', $fixed, $errors, $dryRun ? ' (dry-run)' : ''));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function getExtensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            default => 'bin',
        };
    }

    /**
     * Try to guess the AI provider from message metadata.
     */
    private function guessProviderFromMessage($message): string
    {
        // Check message metadata for provider info
        $provider = $message->getMeta('ai_chat_provider');
        if ($provider) {
            return preg_replace('/[^a-z0-9]/', '', strtolower($provider));
        }

        // Fallback based on file type or other heuristics
        return 'unknown';
    }
}
```

**Usage:**
```bash
# Preview what would be done
bin/console app:fix-base64-filepaths --dry-run

# Actually fix the data
bin/console app:fix-base64-filepaths
```

---

## Files Summary

### Must Fix (Code Changes)
| File | Issue | Fix |
|------|-------|-----|
| `MediaGenerationHandler.php:325-327` | Falls back to base64 URL | Decode and save locally |
| `MediaGenerationHandler.php:446-448` | No user path structure | Use `UserUploadPathBuilder` |

### Should Add (Validation)
| File | Location | Add |
|------|----------|-----|
| `StreamController.php:698` | Before `setFilePath()` | Reject data URLs |

### Verify/Test (Already Correct)
| File | Notes |
|------|-------|
| `FileStorageService.php` | Uses path builder ✅ |
| `StreamController.php:1117` | Uses path builder ✅ |
| `ChatHandler.php:947` | Uses path builder ✅ |
| `WhatsAppService.php:797` | Uses path builder ✅ |

### Tests
| File | Notes |
|------|-------|
| Add test for `MediaGenerationHandler` | Ensure base64 fallback saves to disk |
| Add test for `StreamController` | Ensure data URL validation |

---

## Dependency Injection Addition

`MediaGenerationHandler` needs `UserUploadPathBuilder` injected:

**Update `config/services.yaml`:**
```yaml
App\Service\Message\Handler\MediaGenerationHandler:
    arguments:
        $uploadDir: '%kernel.project_dir%/var/uploads'
        # Add this:
        $userUploadPathBuilder: '@App\Service\File\UserUploadPathBuilder'
    tags:
        - { name: 'app.message.handler' }
```

---

## Verification Queries (Post-Fix)

```sql
-- Should return 0 rows after fix
SELECT COUNT(*) FROM BMESSAGES WHERE BFILEPATH LIKE 'data:%';

-- All paths should be proper file paths
SELECT BID, BFILEPATH
FROM BMESSAGES
WHERE BFILE = 1
  AND BFILEPATH != ''
  AND BFILEPATH NOT REGEXP '^[0-9]{2}/[0-9]{3}/[0-9]{5}/[0-9]{4}/[0-9]{2}/'
LIMIT 50;
```

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Base64 URLs in production DB | **High** (confirmed in code) | High (DB bloat) | Run migration script |
| Download failures cause data loss | Medium | Medium | Always decode base64 as fallback |
| Path inconsistency | Low | Low | Add validation |

---

## Timeline Estimate

| Phase | Duration | Notes |
|-------|----------|-------|
| Phase 1: Fix `MediaGenerationHandler` | 2-3 hours | Main code fix |
| Phase 2: Add validation | 1 hour | StreamController check |
| Phase 3: Migration script | 2-3 hours | Extract & save existing base64 |
| Phase 4: Verification | 1-2 hours | SQL queries + testing |
| **Total** | **6-9 hours** | ~1 day |

---

## Questions Resolved

1. **Path format:** Current format `13/000/00013/2025/01/file.png` is **correct - no change needed**

2. **The real issue:** Base64 data URLs being stored in `BMESSAGES.BFILEPATH` when image download fails

3. **Root cause:** `MediaGenerationHandler.php:327` uses original AI provider URL as fallback
