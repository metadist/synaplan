# Implementation Guide: Qdrant Vector Storage

## 🚀 Master Plan

**Goal:** Enable Qdrant as an optional vector storage backend while keeping MariaDB as default.
**Strategy:** Facade Pattern + Zero Regression + User Isolation.

---

## 🛡️ Security & Isolation Rules

1.  **User ID is Mandatory**: Every storage method MUST require `$userId`.
2.  **Always Filter**: Every query (MariaDB or Qdrant) MUST include `user_id` filter.
3.  **Group Keys**: Validate all group keys against `/^[A-Za-z0-9_:\-]+$/`.
4.  **Reserved Prefixes**: Users cannot create groups starting with `WIDGET:`, `TASKPROMPT:`, `SYSTEM:`.

---

## 📦 Phase 1: Qdrant Service (Rust)

**File:** `synaplan-memories/qdrant-service/`

1.  **Config**: Add `documents_collection_name` to `Config` struct.
2.  **Models**: Add `DocumentPayload`, `UpsertDocumentRequest`, `SearchDocumentsRequest`.
3.  **Handlers**: Implement `/documents/*` endpoints (upsert, search, delete).
    *   *Critical*: Ensure `search_documents` enforces `user_id` filter.
4.  **Routes**: Register new endpoints in `main.rs`.

---

## 🏗️ Phase 2: Backend Abstraction (PHP)

**File:** `synaplan/backend/src/Service/RAG/VectorStorage/`

1.  **Interface**: Create `VectorStorageInterface`.
2.  **DTOs**: Create `VectorChunk`, `SearchQuery`, `SearchResult`.
3.  **MariaDB Impl**: Move logic from `VectorizationService` to `MariaDBVectorStorage`.
    *   *Note*: Keep using raw SQL for `VECTOR` type inserts.
4.  **Qdrant Impl**: Implement `QdrantVectorStorage` using `QdrantClientHttp`.
5.  **Facade**: Create `VectorStorageFacade` that selects provider based on config.

---

## 🔌 Phase 3: Integration (Refactoring)

**Goal**: Replace direct DB calls with Facade calls.

1.  **Refactor `VectorizationService`**:
    *   Inject `VectorStorageFacade`.
    *   Replace `INSERT INTO BRAG` with `$this->facade->storeChunk()`.
    *   *Keep return format*: `['success' => bool, ...]` (add `'provider'` key).

2.  **Refactor `VectorSearchService`**:
    *   Inject `VectorStorageFacade`.
    *   Replace `SELECT ... FROM BRAG` with `$this->facade->search()`.
    *   *Critical*: Map `SearchResult` DTOs back to arrays to match existing return signature (prevent regression in ChatHandler).

3.  **Update Controllers**:
    *   `FileController`: Use facade for `delete` and `reVectorize`.
    *   `PromptController`: Use facade for `linkFile` and `deleteFile`.
    *   `UserDeletionService`: Use facade for `deleteAllForUser`.

---

## ⚙️ Phase 4: Configuration

1.  **Env**: Add `VECTOR_STORAGE_PROVIDER=mariadb` to `.env`.
2.  **Admin UI**: Add provider selector in System Settings.
3.  **Validation**: Ensure Qdrant URL is set if provider is Qdrant.

---

## ✅ Verification Checklist

- [ ] **Regression Test**: Chat RAG works with MariaDB (default).
- [ ] **Regression Test**: File upload works with MariaDB.
- [ ] **New Feature**: Switch to Qdrant -> Upload File -> Verify in Qdrant Dashboard.
- [ ] **Isolation**: User A cannot search User B's files.
- [ ] **Groups**: Widget files (`WIDGET:xxx`) only appear in widget chat.
- [ ] **Cleanup**: Deleting a file removes vectors from active provider.

---

## 💡 "Vibe Check" for Developers

*   **Keep it Simple**: The Facade handles the complexity. Consumers just call `store` or `search`.
*   **Strict Types**: Use DTOs internally, but map to arrays at the service boundary if needed for legacy code.
*   **No Magic**: Explicitly pass `userId` everywhere. No implicit global state.
*   **Logs**: Log the provider name (`mariadb` vs `qdrant`) in all operations for easy debugging.
