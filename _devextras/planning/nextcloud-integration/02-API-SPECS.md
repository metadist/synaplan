# Step 2: Synaplan API Requirements

Synaplan must expose **generic** endpoints that work with `X-API-Key` auth and don't require Synaplan-specific session state.

## Authentication
- **Header:** `X-API-Key: sk_...`
- **User Mapping:** API Key belongs to a Synaplan user. All actions attributed to that user.

---

## Required Endpoints

### 1. Summarization
| | |
|---|---|
| **Endpoint** | `POST /api/v1/summary/generate` |
| **Status** | ✅ Existing |
| **Auth** | `X-API-Key` |

**Payload:**
```json
{
  "text": "Document content...",
  "summaryType": "bullet-points",
  "length": "medium",
  "outputLanguage": "en"
}
```

**Response:**
```json
{
  "success": true,
  "summary": "• Point 1\n• Point 2..."
}
```

---

### 2. Translation
| | |
|---|---|
| **Endpoint** | `POST /api/v1/summary/generate` (reuse) |
| **Status** | ✅ Existing (via outputLanguage param) |
| **Auth** | `X-API-Key` |

**Payload:**
```json
{
  "text": "Document content...",
  "summaryType": "abstractive",
  "length": "long",
  "outputLanguage": "de"
}
```

**Note:** Setting `length: "long"` and `summaryType: "abstractive"` produces a translation-like output. For true translation, we may add a dedicated endpoint later.

---

### 3. Document Chat (RAG)
| | |
|---|---|
| **Endpoint** | `GET /api/v1/messages/stream` |
| **Status** | ⚠️ Verify `X-API-Key` works |
| **Auth** | `X-API-Key` |

**Query Params:**
- `message` - User's question
- `chatId` - Chat session ID
- `context` - (NEW) Inline document text for RAG

**MVP Strategy:**
- **Small files (<50KB):** Send text in `context` param.
- **Large files:** Upload to Synaplan first, reference by file ID.

**TODO:** Add `context` param support to StreamController.

---

### 4. Research Chat (Standard)
| | |
|---|---|
| **Endpoint** | `GET /api/v1/messages/stream` |
| **Status** | ⚠️ Verify `X-API-Key` works |
| **Auth** | `X-API-Key` |

Same as Document Chat, but without file context. Web search toggle via `webSearch=1`.

---

### 5. Create Chat Session
| | |
|---|---|
| **Endpoint** | `POST /api/v1/chats` |
| **Status** | ✅ Existing |
| **Auth** | `X-API-Key` |

**Payload:**
```json
{
  "title": "Nextcloud: report.pdf"
}
```

---

### 6. Health Check
| | |
|---|---|
| **Endpoint** | `GET /api/v1/health` |
| **Status** | ✅ Existing |
| **Auth** | None or `X-API-Key` |

Used for "Test Connection" button in settings.

---

## Deep Linking ("Open in Synaplan")

To enable users to jump to Synaplan web UI with context:

| Action | URL Pattern |
|--------|-------------|
| Dashboard | `{synaplan_url}/` |
| Specific Chat | `{synaplan_url}/chat/{chatId}` |
| New Chat with Context | `{synaplan_url}/chat/new?source=nextcloud&filename={name}` |

**TODO:** Ensure frontend handles `source` and `filename` query params gracefully.

---

## API Gaps & Todos

| Priority | Task | Status |
|----------|------|--------|
| **P0** | Verify `/messages/stream` works with `X-API-Key` | [ ] |
| **P0** | Add `context` param to StreamController for inline RAG | [ ] |
| **P1** | Deep link handling in frontend (`source`, `filename`) | [ ] |
| **P2** | Dedicated `/api/v1/translate` endpoint (optional) | [ ] |
| **P2** | Temp file upload for large docs | [ ] |

---

## CORS Note
Since Nextcloud backend proxies all requests, browser CORS is not an issue. However, ensure Synaplan allows requests from the Nextcloud server IP if any firewall rules exist.
