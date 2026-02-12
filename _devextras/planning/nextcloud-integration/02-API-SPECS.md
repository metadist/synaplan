# Step 2: Synaplan API Specifications (Verified)

All endpoints verified against the actual Synaplan codebase (2025-02-11). Authentication uses `X-API-Key` header throughout.

## Authentication

| Method | Header/Param | Example |
|--------|-------------|---------|
| **Primary** | `X-API-Key: sk_...` | `X-API-Key: sk_abc123def456` |
| **Fallback** | `?api_key=sk_...` | `/api/health?api_key=sk_abc123` |

**Important**: Do **NOT** use `Authorization: Bearer`. Synaplan uses `X-API-Key` exclusively.

The API key belongs to a Synaplan user. All actions are attributed to that user.

---

## 1. Health Check

| | |
|---|---|
| **Endpoint** | `GET /api/health` |
| **Auth** | Optional (works without key) |
| **Purpose** | Connection test, provider availability check |

**Response:**
```json
{
  "status": "ok",
  "providers": {
    "openai": true,
    "ollama": true,
    "anthropic": false,
    "groq": true,
    "gemini": true
  },
  "whisper": true
}
```

**Nextcloud Usage**: "Test Connection" button in admin settings.

---

## 2. Summarization

| | |
|---|---|
| **Endpoint** | `POST /api/v1/summary/generate` |
| **Auth** | `X-API-Key` (required) |
| **Purpose** | Generate document summaries |

**Request:**
```json
{
  "text": "Full document text content...",
  "summaryType": "bullet-points",
  "length": "medium",
  "outputLanguage": "en",
  "focusAreas": []
}
```

| Field | Type | Required | Values |
|-------|------|----------|--------|
| `text` | string | Yes | Document content |
| `summaryType` | string | Yes | `abstractive`, `extractive`, `bullet-points` |
| `length` | string | Yes | `short`, `medium`, `long`, `custom` |
| `outputLanguage` | string | No | `en`, `de`, `fr`, `es`, `it` (default: `en`) |
| `focusAreas` | array | No | Focus topics |

**Response:**
```json
{
  "success": true,
  "summary": "• Key point 1\n• Key point 2\n• Key point 3",
  "metadata": {
    "model": "gpt-4o-mini",
    "provider": "openai",
    "originalLength": 15000,
    "summaryLength": 450,
    "processingTime": 2.3
  }
}
```

**Nextcloud Usage**: "Summarize with Synaplan" context menu action.

---

## 3. Translation (via Summary Endpoint)

| | |
|---|---|
| **Endpoint** | `POST /api/v1/summary/generate` |
| **Auth** | `X-API-Key` (required) |
| **Purpose** | Translate documents by setting `outputLanguage` |

**Request (Translation Mode):**
```json
{
  "text": "The quarterly report shows strong growth...",
  "summaryType": "abstractive",
  "length": "long",
  "outputLanguage": "de"
}
```

**Strategy**: Setting `summaryType: "abstractive"` + `length: "long"` + different `outputLanguage` produces a translation-like output. For the MVP, this is sufficient.

**Future**: A dedicated `POST /api/v1/translate` endpoint may be added for higher-quality translations.

---

## 4. Create Chat Session

| | |
|---|---|
| **Endpoint** | `POST /api/v1/chats` |
| **Auth** | `X-API-Key` (required) |
| **Purpose** | Create a new chat session |

**Request:**
```json
{
  "title": "Nextcloud: report.pdf"
}
```

**Response:**
```json
{
  "id": 42,
  "title": "Nextcloud: report.pdf",
  "created_at": "2025-02-11T10:00:00Z"
}
```

**Nextcloud Usage**: Create a chat before streaming messages.

---

## 5. Chat Streaming (SSE)

| | |
|---|---|
| **Endpoint** | `GET /api/v1/messages/stream` |
| **Auth** | `X-API-Key` (required) |
| **Purpose** | Stream AI chat responses via Server-Sent Events |

**Query Parameters:**

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `message` | string | Yes | User's message |
| `chatId` | int | Yes | Chat session ID |
| `fileIds` | string | No | Comma-separated file IDs for RAG context |
| `webSearch` | int | No | `1` to enable web search |
| `modelId` | int | No | Override default model |
| `reasoning` | int | No | `1` for reasoning models |
| `voiceReply` | int | No | `1` for voice output |

**SSE Event Format:**
```
event: message
data: {"status":"token","content":"Hello"}

event: message
data: {"status":"token","content":" world"}

event: message
data: {"status":"memories_loaded","metadata":{"memories":[...]}}

event: message
data: {"status":"complete","content":"Hello world, how can I help?"}
```

**Event Types:**

| Status | Purpose |
|--------|---------|
| `token` | Streaming text chunk |
| `memories_loaded` | Memories used in response |
| `feedback_loaded` | Feedback examples used |
| `complete` | Stream finished |
| `error` | Error occurred |

**Nextcloud Usage**: Document Chat sidebar and Research Chat page.

---

## 6. Non-Streaming Chat

| | |
|---|---|
| **Endpoint** | `POST /api/v1/messages/send` |
| **Auth** | `X-API-Key` (required) |
| **Purpose** | Send message and get complete response (no streaming) |

**Useful for**: Simple one-shot queries where SSE streaming is overkill.

---

## 7. File Upload

| | |
|---|---|
| **Endpoint** | `POST /api/v1/files/upload` |
| **Auth** | `X-API-Key` (required) |
| **Purpose** | Upload file with automatic text extraction and vectorization |
| **Content-Type** | `multipart/form-data` |

**Form Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `files[]` | file | Yes | One or more files |
| `group_key` | string | No | Group identifier (e.g., `nextcloud`) |
| `process_level` | string | No | `extract`, `vectorize`, `full` (default: `full`) |

**Response:**
```json
{
  "success": true,
  "files": [
    {
      "id": 123,
      "filename": "report.pdf",
      "size": 245000,
      "mime_type": "application/pdf",
      "extracted_text_length": 15000,
      "vectorized": true
    }
  ]
}
```

**Nextcloud Usage**: Upload Nextcloud files to Synaplan for RAG-based Document Chat.

---

## 8. File Content

| | |
|---|---|
| **Endpoint** | `GET /api/v1/files/{id}/content` |
| **Auth** | `X-API-Key` (required) |
| **Purpose** | Get file metadata and extracted text |

**Response:**
```json
{
  "id": 123,
  "filename": "report.pdf",
  "mime_type": "application/pdf",
  "extracted_text": "Full extracted text content...",
  "size": 245000,
  "created_at": "2025-02-11T10:00:00Z"
}
```

---

## 9. RAG Search

| | |
|---|---|
| **Endpoint** | `POST /api/v1/rag/search` |
| **Auth** | `X-API-Key` (required) |
| **Purpose** | Semantic search over vectorized documents |

**Request:**
```json
{
  "query": "What were the Q4 results?",
  "limit": 5,
  "min_score": 0.7,
  "group_key": "nextcloud"
}
```

**Response:**
```json
{
  "results": [
    {
      "chunk_id": 456,
      "text": "Q4 revenue increased by 15%...",
      "score": 0.92,
      "file_id": 123,
      "filename": "report.pdf"
    }
  ]
}
```

---

## Deep Linking ("Open in Synaplan")

| Action | URL Pattern |
|--------|-------------|
| Dashboard | `{synaplan_url}/` |
| Specific Chat | `{synaplan_url}/chat/{chatId}` |
| New Chat | `{synaplan_url}/chat/new?source=nextcloud&filename={name}` |

---

## Content Extraction Strategy

The Nextcloud app needs to extract text from files before sending to Synaplan:

| Strategy | When to Use | How |
|----------|-------------|-----|
| **Direct Read** | `.txt`, `.md` files | Read file content directly from Nextcloud storage |
| **Synaplan Upload** | `.pdf`, `.docx`, `.pptx`, etc. | Upload file to Synaplan, which uses Tika for extraction |
| **Inline Text** | Small files (<50KB extracted) | Send text directly in API requests |
| **File Reference** | Large files (>50KB) | Upload first, then reference `fileIds` in chat |

---

## API Gaps & Action Items

| Priority | Task | Status |
|----------|------|--------|
| **P0** | Verify `X-API-Key` auth works with `/messages/stream` | Verified ✅ |
| **P0** | Verify `/api/v1/summary/generate` works end-to-end | Verified ✅ |
| **P0** | Health endpoint accessible | Verified ✅ |
| **P1** | Deep link handling in Synaplan frontend (`source`, `filename`) | TODO |
| **P1** | Add `context` param to StreamController for inline text RAG | TODO (use `fileIds` for now) |
| **P2** | Dedicated `POST /api/v1/translate` endpoint | Future |
| **P2** | Webhook for async operations (long document processing) | Future |

---

## CORS Note

Since Nextcloud's PHP backend proxies all API requests to Synaplan, browser CORS is **not an issue**. The browser only talks to Nextcloud; Synaplan is called server-side.

However, for SSE streaming (Document Chat, Research Chat), we have two options:
1. **Proxy SSE**: Nextcloud backend acts as SSE proxy (more complex but cleaner)
2. **Direct SSE**: Browser connects directly to Synaplan for streaming (requires CORS config)

**Recommended**: Option 1 (Proxy SSE) for security — the API key stays server-side.
