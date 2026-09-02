# POSTPONED: #984 — Memories: persist in MariaDB as source of truth (data loss + GDPR)

**Status:** Not implemented in the 2026-07-02 prio:1 sprint. Documented here for a
dedicated follow-up.

## Why postponed

This is a sizeable feature, not a bug fix, and it explicitly crosses two AGENTS.md
"Ask First Before" boundaries:

- **Changing database schema** (must go through Doctrine Migrations).
- The change touches production data lifecycle (a one-time Qdrant→SQL backfill) that
  needs a human to run/verify against real data.

It also spans several tightly-coupled services and cannot be safely validated by the
CI gate alone (lint / phpstan / unit tests) — it needs an integration environment with
a live Qdrant + MariaDB and a manual re-vectorize dry-run. Shipping it blind would risk
the very data loss it aims to prevent.

## Scope (for the follow-up implementer)

Current state: memories live ONLY in Qdrant. Key files:

- `backend/src/Service/UserMemoryService.php` (~967 lines) — CRUD, all Qdrant-backed.
  Constructor comment literally says "All memories stored in Qdrant microservice (no MariaDB)".
- `backend/src/Service/MemoryExtractionService.php` — async chat extraction → Qdrant.
- `backend/src/Service/Embedding/EmbeddingReindexService.php` — `reindexMemories()` scrolls
  Qdrant, re-embeds, upserts (the failure mode from the issue: a mid-flight failure after
  `recreateMemoriesCollection()` empties the collection = permanent loss).
- `backend/src/Service/VectorSearch/QdrantClientDirect.php` — vector index client.

### Acceptance criteria (from the issue)

1. New `user_memories` Doctrine entity + migration: `id, user_id, category, key, value,
   source (chat_extraction|manual|api), namespace, active, created_at, updated_at`.
   - Follow `docs/MIGRATIONS.md`; generate with `make -C backend migrate-diff`.
   - Mirror the existing RAG pattern: content in SQL (`BRAG`), embeddings only in Qdrant.
2. `UserMemoryService::createMemory()` / `updateMemory()` — write SQL first, THEN upsert
   the embedding in Qdrant (so a Qdrant failure never loses the authoritative row).
3. `UserMemoryService::deleteMemory()` — delete from BOTH SQL and Qdrant.
4. `EmbeddingReindexService::reindexMemories()` — read from SQL (not Qdrant scroll),
   re-embed, upsert. Makes re-vectorize fully recoverable.
5. `MemoryExtractionService` — store extracted memories in SQL before Qdrant.
6. GDPR erasure: `DELETE FROM user_memories WHERE user_id = ?` + purge matching Qdrant points.
7. GDPR export: admin action / endpoint dumps all memories for a user as JSON.
8. One-time migration command: scroll the current Qdrant collection → INSERT into SQL,
   making Qdrant a derived index. (Idempotent; safe to re-run.)
9. Verify re-vectorize no longer loses data: if Qdrant upserts fail, SQL still holds the
   data and a retry succeeds.

### Suggested implementation order

1. Entity + migration + repository (schema first, no behaviour change yet).
2. Dual-write in create/update/delete (SQL authoritative, Qdrant derived).
3. One-time backfill command (Qdrant → SQL) with a `--dry-run`.
4. Flip `reindexMemories()` and `MemoryExtractionService` to read/write SQL.
5. GDPR erase + export.
6. Tests: unit for the service dual-write/delete, integration for reindex recoverability.

### Risks / notes

- Data-loss-sensitive: the backfill and the reindex flip must be verified against real
  data before rollout. Keep the "memories pinned to their own embedding model" invariant
  (see `MemoryEmbeddingModelResolver` / PR #985) so a VECTORIZE dimension switch still
  doesn't corrupt the collection.
- Coordinate the entity/migration with a maintainer (schema = ask-first).
- related: #959 (dimension mismatch on model switch).
