# TheHive API Integration Plan

**Date:** 2026-01-31  
**Status:** Draft (subject to change)  
**Purpose:** Phased integration of TheHive API into Synaplan  
**Documentation:** https://docs.thehive.ai/reference/api-reference-introduction

---

## Executive Summary

TheHive offers a comprehensive AI API suite with 21+ APIs across three categories: **Generate** (images, video, audio, text), **Understand** (content moderation, OCR, translation, detection), and **Search** (visual similarity, IP detection). This document outlines a phased approach starting with Image Generation, then expanding to other capabilities.

---

## Phase Overview

| Phase | Feature | Priority | Complexity | Dependencies |
|-------|---------|----------|------------|--------------|
| **1** | Image Generation (SDXL, Flux) | High | Medium | None |
| **2** | AI Content Detection | Medium | Low | None |
| **3** | Text Moderation | Medium | Low | None |
| **4** | OCR / Text Recognition | Medium | Medium | None |
| **5** | Deepfake Detection | Low | Low | Phase 2 |
| **6** | Visual Search | Low | High | Phase 1 |

---

## Phase 1: Image Generation (Start Here)

### Objective
Add TheHive as a new provider for image generation in Synaplan, offering SDXL and Flux models as alternatives to OpenAI DALL-E and Google Imagen.

### Available Models

| Model | Use Case | Speed | Quality |
|-------|----------|-------|---------|
| **Flux Schnell** | Fast prototyping | ⚡⚡⚡ | ★★★ |
| **Flux Schnell Enhanced** | Photorealistic images | ⚡⚡ | ★★★★ |
| **SDXL** | General purpose | ⚡⚡ | ★★★★ |
| **SDXL Enhanced** | High quality output | ⚡ | ★★★★★ |
| **Emoji Model** | Custom emojis (transparent BG) | ⚡⚡⚡ | ★★★ |

### API Endpoint

```
POST https://api.thehive.ai/api/v3/{vendor}/{model}
```

**Authentication:**
```http
Authorization: Bearer <API_KEY>
Content-Type: application/json
```

### Request Format

```json
{
  "input": {
    "prompt": "A futuristic city skyline at sunset",
    "negative_prompt": "blurry, low quality, text",
    "num_images": 1,
    "image_size": { "width": 1024, "height": 1024 }
  }
}
```

### Response Format

```json
{
  "id": "task_abc123",
  "status": "completed",
  "code": 200,
  "output": [
    {
      "url": "https://cdn.thehive.ai/generated/...",
      "content_moderation": {
        "safe": true,
        "categories": {}
      }
    }
  ]
}
```

### Implementation Tasks

#### Step 1.1: Create Provider Class
**File:** `backend/src/AI/Provider/TheHiveProvider.php`

```php
<?php

namespace App\AI\Provider;

use App\AI\Interface\ImageGenerationProviderInterface;

class TheHiveProvider implements ImageGenerationProviderInterface
{
    public function getName(): string { return 'thehive'; }
    public function getDisplayName(): string { return 'TheHive'; }
    public function getCapabilities(): array { return ['image_generation']; }
    
    public function generateImage(string $prompt, array $options = []): array
    {
        // Implementation
    }
    
    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new \RuntimeException('TheHive does not support image variations');
    }
    
    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        throw new \RuntimeException('TheHive does not support image editing');
    }
}
```

#### Step 1.2: Register Provider
**File:** `backend/config/services.yaml`

```yaml
App\AI\Provider\TheHiveProvider:
    arguments:
        $apiKey: '%env(THEHIVE_API_KEY)%'
    tags:
        - { name: 'app.image_generation_provider' }
```

#### Step 1.3: Add Environment Variable
**File:** `backend/.env.example`

```bash
# TheHive API (https://thehive.ai)
THEHIVE_API_KEY=
```

#### Step 1.4: Database Model Registration
Add models to `BMODELS` table for user selection:

```sql
INSERT INTO BMODELS (BPROVIDER, BMODEL, BNAME, BTAG, BACTIVE, BDEFAULT) VALUES
('thehive', 'flux-schnell', 'Flux Schnell', 'text2pic', 1, 0),
('thehive', 'flux-schnell-enhanced', 'Flux Schnell Enhanced', 'text2pic', 1, 0),
('thehive', 'sdxl', 'SDXL', 'text2pic', 1, 0),
('thehive', 'sdxl-enhanced', 'SDXL Enhanced', 'text2pic', 1, 0),
('thehive', 'emoji', 'Custom Emoji', 'text2pic', 1, 0);
```

#### Step 1.5: Options Mapping

| Synaplan Option | TheHive Parameter | Default |
|-----------------|-------------------|---------|
| `size` | `width`, `height` | 1024x1024 |
| `quality` | Model selection | flux-schnell |
| `style` | `negative_prompt` | - |
| `n` | `num_images` | 1 |

#### Step 1.6: Frontend Integration
Update model selector in `frontend/src/components/ai/ImageGenerationPanel.vue` to show TheHive models.

### Testing Checklist

- [ ] Provider loads when `THEHIVE_API_KEY` is set
- [ ] `isAvailable()` returns false when key is missing
- [ ] Basic prompt generates image successfully
- [ ] Negative prompt is properly passed
- [ ] Multiple models selectable (Flux, SDXL variants)
- [ ] Error handling for rate limits (HTTP 429)
- [ ] Error handling for moderation failures

### Estimated Effort
- Backend: 4-6 hours
- Frontend: 2-3 hours
- Testing: 2 hours

---

## Phase 2: AI Content Detection

### Objective
Detect AI-generated content in images, text, and audio uploaded to Synaplan.

### Use Cases
- Verify authenticity of user-uploaded documents
- Flag AI-generated images in knowledge bases
- Content moderation workflows

### Endpoints

| Content Type | Endpoint | Model |
|--------------|----------|-------|
| Image/Video | `/task/sync` | `ai_generated_detection` |
| Text | `/task/sync` | `ai_generated_text_detection` |
| Audio | `/task/sync` | `ai_generated_audio_detection` |

### Response Structure

```json
{
  "output": [{
    "classes": [
      {"class": "ai_generated", "score": 0.95},
      {"class": "not_ai_generated", "score": 0.05}
    ]
  }]
}
```

### Implementation Tasks

- [ ] Create `ContentDetectionService` 
- [ ] Add `/api/v1/ai/detect-generated` endpoint
- [ ] Integrate into file upload pipeline (optional check)
- [ ] Add UI indicator for AI-detected content

### Estimated Effort: 3-4 hours

---

## Phase 3: Text Moderation

### Objective
Moderate text content in chats, knowledge base entries, and user inputs.

### Categories Detected
- Hate speech
- Violence
- Sexual content
- Self-harm
- Harassment
- Spam/scam

### Integration Points

| Location | Trigger |
|----------|---------|
| Chat input | Before sending to AI |
| KB document | On upload/update |
| Widget messages | User input validation |

### Request Format

```json
{
  "model_id": "text_moderation",
  "input": {
    "text": "Content to moderate"
  }
}
```

### Implementation Tasks

- [ ] Create `TextModerationService`
- [ ] Add `BCONFIG` setting: `moderation_enabled` (default: false)
- [ ] Hook into chat message pipeline
- [ ] Add admin UI for moderation settings
- [ ] Create moderation response handling (block/warn/log)

### Estimated Effort: 4-5 hours

---

## Phase 4: OCR / Text Recognition

### Objective
Extract text from images and scanned documents in the knowledge base.

### Use Cases
- Index scanned PDFs for RAG search
- Extract text from image-based documents
- Support for multi-language documents

### Features
- **OCR**: Raw text extraction
- **OCR Moderation**: Extract + moderate in one call
- **Structured Output**: JSON with bounding boxes

### Integration Points

| Component | Use |
|-----------|-----|
| File Upload | Auto-extract text from images |
| RAG Indexing | Include OCR text in embeddings |
| Search | Full-text search on extracted content |

### Implementation Tasks

- [ ] Create `OcrService`
- [ ] Add to `FileProcessor` pipeline
- [ ] Store extracted text in `BFILES.BOCR_TEXT` (new column)
- [ ] Include in RAG chunk generation
- [ ] Admin toggle for OCR processing

### Estimated Effort: 6-8 hours

---

## Phase 5: Deepfake Detection

### Objective
Detect manipulated images/videos (face swaps, AI alterations).

### Use Cases
- Verify identity documents
- Flag suspicious profile images
- Content authenticity verification

### Response Structure

```json
{
  "output": [{
    "classes": [
      {"class": "deepfake", "score": 0.87},
      {"class": "authentic", "score": 0.13}
    ],
    "bounding_box": {...}
  }]
}
```

### Implementation Tasks

- [ ] Add deepfake check to `ContentDetectionService`
- [ ] Create `/api/v1/ai/detect-deepfake` endpoint
- [ ] Optional integration with file upload

### Estimated Effort: 2-3 hours (builds on Phase 2)

---

## Phase 6: Visual Search

### Objective
Find visually similar images across the knowledge base.

### Use Cases
- "Find similar" for uploaded images
- Duplicate detection
- Visual content organization

### Technical Approach
1. Generate visual embeddings via TheHive
2. Store in MariaDB VECTOR column
3. Query using cosine similarity

### Implementation Tasks

- [ ] Add `visual_embedding` column to `BFILES`
- [ ] Create embedding generation during upload
- [ ] Build similarity search endpoint
- [ ] Frontend "Find Similar" button

### Estimated Effort: 8-10 hours

---

## Technical Architecture

### Provider Structure

```
backend/src/AI/
├── Provider/
│   └── TheHiveProvider.php        # Main provider class
├── Service/
│   └── TheHive/
│       ├── ImageGenerationService.php
│       ├── ContentDetectionService.php
│       ├── TextModerationService.php
│       └── OcrService.php
└── Interface/
    └── ContentModerationProviderInterface.php  # New interface
```

### Environment Variables

```bash
# TheHive Configuration
THEHIVE_API_KEY=your_api_key_here
THEHIVE_BASE_URL=https://api.thehive.ai/api/v3
THEHIVE_TIMEOUT=30
```

### Database Changes

```sql
-- Phase 1: Models (no schema changes, just data)
-- Phase 2-3: No schema changes
-- Phase 4: OCR text storage
ALTER TABLE BFILES ADD COLUMN BOCR_TEXT LONGTEXT DEFAULT NULL;
ALTER TABLE BFILES ADD COLUMN BOCR_PROCESSED_AT DATETIME DEFAULT NULL;

-- Phase 6: Visual embeddings
ALTER TABLE BFILES ADD COLUMN BVISUAL_EMBEDDING VECTOR(1024) DEFAULT NULL;
```

### Rate Limiting Considerations

TheHive has tier-based rate limits. Implement:
1. Request queuing for batch operations
2. Exponential backoff on 429 responses
3. User-level rate limit tracking in `BRATELIMITS_CONFIG`

---

## Testing Strategy

### Unit Tests
- Provider instantiation with/without API key
- Request/response mapping
- Error handling

### Integration Tests
- End-to-end image generation
- Model switching
- Content detection accuracy

### Manual Testing
- Use TheHive Playground first: https://thehive.ai/models/black-forest-labs/flux-schnell
- Compare outputs with existing providers

---

## Cost Considerations

| Feature | Pricing Model | Estimated Cost |
|---------|---------------|----------------|
| Image Generation | Per image | ~$0.01-0.05/image |
| Content Detection | Per request | ~$0.001-0.01/request |
| Text Moderation | Per 1K chars | ~$0.001/1K |
| OCR | Per image | ~$0.01/image |

**Recommendation:** Start with Phase 1, monitor usage, then expand.

---

## Timeline

| Phase | Duration | Dependencies |
|-------|----------|--------------|
| Phase 1 (Image Gen) | 1-2 days | None |
| Phase 2 (AI Detection) | 0.5 day | None |
| Phase 3 (Moderation) | 1 day | None |
| Phase 4 (OCR) | 1-2 days | None |
| Phase 5 (Deepfake) | 0.5 day | Phase 2 |
| Phase 6 (Visual Search) | 2-3 days | Phase 1 |

**Total estimated time:** 6-10 days (sequential) or 4-6 days (parallelized)

---

## Open Questions

1. **API Key Management:** Per-user keys or single system key?
2. **Fallback Strategy:** Use TheHive as primary or fallback for image gen?
3. **Content Moderation:** Block, warn, or just flag?
4. **OCR Storage:** Store raw text or structured JSON with positions?

---

## Resources

- [TheHive Documentation](https://docs.thehive.ai/)
- [Image Generation API Reference](https://docs.thehive.ai/reference/image-generation-models-reference)
- [API Authentication Guide](https://docs.thehive.ai/reference/authentication)
- [Model Playground](https://thehive.ai/models)

---

## Changelog

| Date | Change |
|------|--------|
| 2026-01-31 | Initial plan created |
