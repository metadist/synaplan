# API Standardization & Enhancement Plan

## 1. Vision: The Synaplan API Suite

We aim to transform the Synaplan backend into a developer-first platform. This involves two parallel tracks:

1.  **Industry Compatibility**: Full support for the OpenAI API standard (`/v1/chat/completions`, `/v1/models`), allowing drop-in replacement for existing AI apps.
2.  **Synaplan Enhanced Capabilities**: Exposing our unique "Superpowers" (RAG, Memory, Document Processing, Summarization) via a clean, unified, and elegant API surface that goes *beyond* simple chat.

## 2. Current State & Gap Analysis

| Feature | Current Implementation | Issues / Gaps | Target API Design |
| :--- | :--- | :--- | :--- |
| **Chat / LLM** | `GET /api/v1/messages/stream` (SSE) | Non-standard method/params. | `POST /v1/chat/completions` (OpenAI Standard) |
| **Models** | `GET /api/v1/config/models` | Nested structure, internal metadata. | `GET /v1/models` (OpenAI Standard) |
| **Summarization** | `POST /api/v1/summary/generate` | Good, but isolated. | `POST /v1/tools/summarize` |
| **RAG / Knowledge** | `POST /api/v1/rag/search` | Good. | `POST /v1/knowledge/search` |
| **Memory** | `GET /api/v1/user/memories` | Good CRUD. | `GET /v1/memory` |
| **SortX (Docs)** | `/api/v1/user/{id}/plugins/sortx/...` | **Critical**: Requires `userId` in path. Deep nesting. | `POST /v1/tools/document/classify`<br>`POST /v1/tools/document/extract` |
| **Mail Handler** | `/api/v1/inbound-email-handlers` | Config-focused CRUD. | Keep as management API. |

## 3. Implementation Plan

### Phase 1: OpenAI Compatibility Layer
*Goal: Allow standard SDKs to connect.*

1.  **Controller**: Create `OpenAiCompatibilityController`.
2.  **Endpoints**:
    *   `GET /v1/models`: Flattens `ModelRepository` data.
    *   `POST /v1/chat/completions`: Adapts JSON body to `MessageProcessor`. Handles Streaming (SSE adaptation) and Non-Streaming.
    *   `POST /v1/embeddings`: (Future) Map to internal vectorizer.

### Phase 2: The "Tools" & "Knowledge" API Refactoring
*Goal: Elegant access to Synaplan superpowers.*

We will introduce **Route Aliases** and **Wrappers** to unify disparate services under a cohesive `/v1/` namespace.

#### A. Document Intelligence (SortX Refactor)
*Problem*: Current routes require `/user/{userId}/...` which is redundant for API Key auth.
*Solution*: Create `DocumentToolController` (or add routes to `SortXController`) that infers user from token.

*   `POST /v1/tools/document/classify` -> Maps to SortX `classify` logic.
*   `POST /v1/tools/document/extract` -> Maps to SortX `extractText` logic.
*   `POST /v1/tools/document/ocr` -> Maps to SortX `ocr` logic.

#### B. Summarization
*   Alias `POST /v1/tools/summarize` -> `SummaryController::generate`.

#### C. Knowledge (RAG)
*   Alias `POST /v1/knowledge/search` -> `RagController::search`.
*   Alias `POST /v1/knowledge/stats` -> `RagController::stats`.

#### D. Memory
*   Alias `GET /v1/memory` -> `UserMemoryController::getMemories`.
*   Alias `POST /v1/memory` -> `UserMemoryController::createMemory`.
*   Alias `POST /v1/memory/search` -> `UserMemoryController::searchMemories`.

### Phase 3: Testing & Validation Strategy

1.  **Unit Tests**: PHPUnit tests for the new Controllers/Adapters.
2.  **Integration Scripts**:
    *   `test_openai_chat.py`: Uses official `openai` Python lib to chat.
    *   `test_synaplan_tools.sh`: Curl script testing Summary, RAG, and SortX endpoints.
3.  **Documentation**:
    *   Ensure all new `/v1/` endpoints have detailed `#[OA\...]` attributes.
    *   Verify generation in Swagger UI (`/api/doc`).

## 4. Detailed File Structure

```
backend/
├── src/
│   ├── Controller/
│   │   ├── OpenAiCompatibilityController.php  # The Standard Layer
│   │   └── Api/                               # New Namespace for V1 Facades?
│   │       ├── DocumentToolController.php     # Wrapper for SortX/FileProcessor
│   │       └── KnowledgeController.php        # Wrapper for RAG
│   └── Service/
│       └── Api/
│           └── OpenAiAdapter.php              # Helper for format conversion
```

## 5. Immediate Next Steps

1.  **Scaffold `OpenAiCompatibilityController`** implementing `listModels` and `chatCompletions`.
2.  **Refactor SortX**: Add routes or create a wrapper to expose functionality without `userId` in path.
3.  **Create Aliases**: Add `#[Route]` attributes to existing controllers for the new `/v1/` scheme.
