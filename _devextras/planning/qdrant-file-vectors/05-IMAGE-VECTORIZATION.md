# Image Vectorization

## Overview

Images are vectorized by first extracting text via Vision AI, then vectorizing that text as a single chunk.

---

## Current Flow

```
Image Upload
    │
    ▼
FileProcessor.extractText()
    │
    ├─► detectMimeType() → image/*
    │
    └─► extractFromImage()
        │
        └─► AiFacade.analyzeImage()
            │
            ▼
        Vision AI Response (text description)
            │
            ▼
        Return extracted text
            │
            ▼
VectorizationService.vectorizeAndStore()
    │
    └─► TextChunker.chunkify()
        │
        ▼
    Creates chunks (may be single chunk for short descriptions)
        │
        ▼
    AiFacade.embedBatch()
        │
        ▼
    VectorStorageInterface.storeChunkBatch()
```

---

## Image Types Supported

| MIME Type | Extraction Method | Notes |
|-----------|-------------------|-------|
| `image/jpeg` | Vision AI | Direct analysis |
| `image/png` | Vision AI | Direct analysis |
| `image/gif` | Vision AI | First frame only |
| `image/webp` | Vision AI | Direct analysis |
| `application/pdf` | Vision or Tika | Vision for scanned, Tika for text PDFs |

---

## Vision Model Configuration

Images use the user's **pic2text** model configuration:

```php
// ModelConfigService
$model = $this->getDefaultModel('PIC2TEXT', $userId);

// Falls back to system default if user hasn't configured
// Stored in BCONFIG: BOWNERID=userId, BGROUP='DEFAULTMODEL', BSETTING='PIC2TEXT'
```

### Supported Vision Providers

| Provider | Models | Notes |
|----------|--------|-------|
| OpenAI | `gpt-4-vision-preview`, `gpt-4o` | Best quality |
| Anthropic | `claude-3-*` | Good for documents |
| Google | `gemini-pro-vision` | Fast |
| Ollama | `llava`, `bakllava` | Local, no API cost |

---

## Single-Chunk Strategy for Images

### Problem

Vision AI returns a description of the image, which is typically short (100-500 words). The `TextChunker` with default settings (500 chars, 50 overlap) may split this into multiple chunks, losing context.

### Solution

For images, treat the entire extracted text as a **single chunk**:

```php
// Option 1: Modify VectorizationService to detect image type
public function vectorizeAndStore(
    string $text,
    int $userId,
    int $fileId,
    string $groupKey,
    int $fileType,
    bool $singleChunk = false  // NEW: Force single chunk for images
): array {
    if ($singleChunk || strlen($text) < 1000) {
        // Treat as single chunk
        $chunks = [[
            'content' => $text,
            'start_line' => 1,
            'end_line' => substr_count($text, "\n") + 1,
        ]];
    } else {
        $chunks = $this->textChunker->chunkify($text);
    }
    // ... rest of method
}

// Option 2: Call from FileController with singleChunk=true for images
if ($this->isImageFile($file->getMimeType())) {
    $this->vectorizationService->vectorizeAndStore(
        $extractedText,
        $userId,
        $file->getId(),
        $groupKey,
        $fileType,
        singleChunk: true
    );
}
```

### Recommendation

**Option 2** is cleaner - the caller knows the file type and can make the decision.

---

## Metadata Preservation

### Current Issue

When an image is vectorized, only the extracted text is stored. The original filename and any metadata from the Vision API response are lost.

### Proposed Enhancement

Store additional metadata alongside the chunk:

```php
// Enhanced DocumentPayload for Qdrant
{
    "user_id": 1,
    "file_id": 123,
    "group_key": "DEFAULT",
    "file_type": 3,  // Image type code
    "chunk_index": 0,
    "start_line": 1,
    "end_line": 1,
    "text": "A photograph of a sunset over mountains...",
    "created": 1706745600,
    // NEW metadata fields
    "source_type": "vision_extraction",
    "original_filename": "sunset.jpg",
    "mime_type": "image/jpeg",
    "extraction_model": "gpt-4o",
    "extraction_confidence": 0.95
}
```

This metadata helps with:
- Displaying source information in search results
- Filtering by source type
- Re-extraction if model improves

---

## PDF Handling

### Text-Based PDFs

1. Tika extracts text directly
2. Text is chunked normally
3. Multiple chunks stored

### Scanned PDFs (Images)

1. `FileProcessor.extractFromPdfViaVision()`:
   - Converts PDF pages to images (rasterization)
   - Sends each page to Vision AI
   - Aggregates extracted text
2. Combined text is chunked (or single-chunk if short)
3. Chunks stored with `file_type` indicating scanned PDF

```php
// FileProcessor.php - existing flow
private function extractFromPdfViaVision(string $filePath): string
{
    // Rasterize PDF pages
    $pages = $this->rasterizePdf($filePath);
    
    $extractedTexts = [];
    foreach ($pages as $pageNumber => $pageImage) {
        // Extract text via Vision
        $text = $this->aiFacade->analyzeImage(
            $pageImage,
            'Extract all text from this document page. Preserve formatting.',
            $userId
        );
        $extractedTexts[] = "--- Page $pageNumber ---\n$text";
    }
    
    return implode("\n\n", $extractedTexts);
}
```

---

## Implementation Checklist

### No Changes Required (Current Flow Works)

- [x] Image upload via FileController
- [x] Vision extraction via FileProcessor
- [x] Text vectorization via VectorizationService
- [x] Storage via new VectorStorageInterface (after refactor)
- [x] Search via VectorSearchService

### Recommended Enhancements

- [ ] Add `singleChunk` parameter to `vectorizeAndStore()`
- [ ] Add metadata fields to Qdrant DocumentPayload
- [ ] Track `source_type` (vision_extraction, tika, whisper)
- [ ] Store `original_filename` in payload

---

## Search Behavior for Images

When a user searches:

1. Query is embedded
2. Search returns chunks (including image-derived chunks)
3. Image chunks have:
   - `text`: Vision-extracted description
   - `filename`: Original image filename
   - `file_type`: Image type code

### RAG Context Example

```
User Query: "What does the sunset photo show?"

RAG Context (from image chunk):
---
Source: sunset.jpg (image)
Content: A photograph of a stunning sunset over a mountain range. 
The sky is painted in shades of orange, pink, and purple. 
Snow-capped peaks are visible in the foreground. A small lake 
reflects the colorful sky. The image appears to be taken during 
golden hour with professional composition.
---
```

---

## File Type Codes

| Code | Type | Extraction Method |
|------|------|-------------------|
| 1 | Text/Markdown | Direct read |
| 2 | PDF (text) | Tika |
| 3 | PDF (scanned) | Vision |
| 4 | Image | Vision |
| 5 | Office (DOC/DOCX) | Tika |
| 6 | Spreadsheet (XLS/XLSX) | Tika |
| 7 | Presentation (PPT/PPTX) | Tika |
| 8 | Audio | Whisper |
| 9 | Code | Direct read |
| 10 | Unknown | Tika fallback |

These codes are used for:
- Filtering search results by type
- Statistics grouping
- Re-extraction with type-appropriate method
