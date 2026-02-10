# 06 - Migration Strategy

## Overview

Safe migration path from MariaDB-only to optional Qdrant support.

**Key Principle:** Zero downtime, zero data loss, easy rollback.

---

## Migration Options

### Option A: Fresh Start (Recommended)

New uploads go to the selected provider. Existing data stays in MariaDB.

```
┌─────────────────────────────────────────────────────────────────┐
│  Timeline                                                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  [Before]  All files → MariaDB BRAG table                       │
│                                                                  │
│  [Deploy]  Add Qdrant support, keep MariaDB as default          │
│            - No changes to existing data                         │
│            - New uploads still go to MariaDB                     │
│                                                                  │
│  [Switch]  Admin enables Qdrant in settings                      │
│            - NEW uploads → Qdrant                                │
│            - EXISTING data stays in MariaDB                      │
│                                                                  │
│  [Later]   User re-uploads/re-vectorizes files as needed        │
│            - Old MariaDB vectors become unused                   │
│            - Can be cleaned up later                             │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Pros:**
- Zero risk
- No migration script needed
- Users control when their data moves
- Easy rollback (just switch provider back)

**Cons:**
- Dual storage during transition
- Users must re-upload for Qdrant benefits

---

### Option B: Background Migration

Migrate existing data from MariaDB to Qdrant in background.

```
┌─────────────────────────────────────────────────────────────────┐
│  Migration Flow                                                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. Enable Qdrant (new uploads go to Qdrant)                    │
│                                                                  │
│  2. Start background migration:                                  │
│     - Read chunks from BRAG in batches                          │
│     - Re-embed each chunk (vectors may not be retrievable)      │
│     - Store in Qdrant documents collection                       │
│     - Track progress per user                                    │
│                                                                  │
│  3. After migration complete:                                    │
│     - All searches use Qdrant                                   │
│     - MariaDB BRAG table can be archived/dropped                │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Pros:**
- Full migration without user action
- Cleaner final state

**Cons:**
- Complex implementation
- Re-embedding cost (API calls, time)
- Risk of errors during migration
- Harder rollback

---

## Recommended: Option A (Fresh Start)

For v1, implement Option A because:
1. Simpler implementation
2. No risk to existing data
3. Users get immediate benefit for new uploads
4. No re-embedding cost
5. Easy to add Option B later if needed

---

## Implementation Steps

### Phase 1: Deploy Infrastructure

```bash
# 1. Deploy updated qdrant-service
cd /wwwroot/synaplan-memories
docker compose pull
docker compose up -d

# 2. Verify service is healthy
curl http://localhost:8090/health
# {"status":"ok","qdrant":"connected"}

# 3. Verify documents collection was created
curl http://localhost:8090/capabilities
# Should show "documents" in capabilities
```

### Phase 2: Deploy Backend Changes

```bash
# 1. Deploy updated backend code
cd /wwwroot/synaplan
docker compose build backend
docker compose up -d backend

# 2. Verify facade is working (still using MariaDB)
curl -H "X-API-Key: xxx" http://localhost:8000/api/v1/admin/vector-storage/status
# {"current_provider":"mariadb","qdrant_configured":true}
```

### Phase 3: Test Qdrant Path

```bash
# 1. Create test user or use dev account
# 2. Temporarily switch that user to Qdrant (if per-user config supported)
# 3. Upload test file
# 4. Verify vector stored in Qdrant
# 5. Verify search works
# 6. Verify deletion works
```

### Phase 4: Enable for All

```bash
# Option 1: Via .env
echo "VECTOR_STORAGE_PROVIDER=qdrant" >> .env
docker compose restart backend

# Option 2: Via Admin UI
# Navigate to System Settings → Vector Storage → Select Qdrant
```

---

## Rollback Procedure

If issues arise after switching to Qdrant:

### Immediate Rollback (< 5 minutes)

```bash
# Option 1: Change .env
sed -i 's/VECTOR_STORAGE_PROVIDER=qdrant/VECTOR_STORAGE_PROVIDER=mariadb/' .env
docker compose restart backend

# Option 2: Via Admin UI
# Navigate to System Settings → Vector Storage → Select MariaDB
```

### Data Considerations

- **Files uploaded before switch:** Still in MariaDB, work immediately after rollback
- **Files uploaded after switch:** In Qdrant only, need re-upload after rollback
- **No data loss:** Both databases retain their data

---

## Future: Background Migration (Option B)

If Option B is needed later, here's the design:

### Migration Command

```php
// backend/src/Command/MigrateVectorsCommand.php

#[AsCommand(
    name: 'app:vectors:migrate',
    description: 'Migrate vectors from MariaDB to Qdrant'
)]
class MigrateVectorsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get all users with vectors
        $users = $this->getUsersWithVectors();

        $io->progressStart(count($users));

        foreach ($users as $userId) {
            $this->migrateUserVectors($userId, $io);
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success('Migration complete');

        return Command::SUCCESS;
    }

    private function migrateUserVectors(int $userId, SymfonyStyle $io): void
    {
        // Get user's embedding model
        $model = $this->modelConfigService->getDefaultModel('VECTORIZE', $userId);

        // Read chunks in batches
        $offset = 0;
        $batchSize = 100;

        while (true) {
            $chunks = $this->ragRepository->findByUser($userId, $offset, $batchSize);

            if (empty($chunks)) {
                break;
            }

            foreach ($chunks as $chunk) {
                // Re-embed the text (vectors in MariaDB may not be retrievable as arrays)
                $vector = $this->aiFacade->embed($chunk->getText(), $model->getProvider());

                // Store in Qdrant
                $this->qdrantStorage->storeChunk(new VectorChunk(
                    userId: $chunk->getUserId(),
                    fileId: $chunk->getMessageId(),
                    groupKey: $chunk->getGroupKey(),
                    fileType: $chunk->getFileType(),
                    chunkIndex: 0, // Would need to track this
                    startLine: $chunk->getStartLine(),
                    endLine: $chunk->getEndLine(),
                    text: $chunk->getText(),
                    vector: $vector,
                ));
            }

            $offset += $batchSize;
        }
    }
}
```

### Migration Status Tracking

```sql
-- Track migration progress
CREATE TABLE vector_migration_status (
    user_id BIGINT PRIMARY KEY,
    total_chunks INT DEFAULT 0,
    migrated_chunks INT DEFAULT 0,
    status ENUM('pending', 'in_progress', 'completed', 'failed') DEFAULT 'pending',
    started_at DATETIME,
    completed_at DATETIME,
    error TEXT
);
```

### Admin UI for Migration

```vue
<!-- Future: Admin migration control panel -->
<template>
  <div class="space-y-4">
    <h3>Vector Migration Status</h3>

    <div class="grid grid-cols-4 gap-4">
      <div class="stat">
        <div class="stat-value">{{ stats.totalUsers }}</div>
        <div class="stat-label">Total Users</div>
      </div>
      <div class="stat">
        <div class="stat-value">{{ stats.migratedUsers }}</div>
        <div class="stat-label">Migrated</div>
      </div>
      <div class="stat">
        <div class="stat-value">{{ stats.pendingUsers }}</div>
        <div class="stat-label">Pending</div>
      </div>
      <div class="stat">
        <div class="stat-value">{{ stats.failedUsers }}</div>
        <div class="stat-label">Failed</div>
      </div>
    </div>

    <button
      @click="startMigration"
      :disabled="migrating"
      class="btn-primary"
    >
      {{ migrating ? 'Migrating...' : 'Start Migration' }}
    </button>
  </div>
</template>
```

---

## Cleanup After Migration

Once confident all data is in Qdrant:

```sql
-- Archive BRAG table (keep for safety)
RENAME TABLE BRAG TO BRAG_archived_20260205;

-- Or drop if space is needed and backup exists
-- DROP TABLE BRAG_archived_20260205;
```

---

## Summary

| Approach | Complexity | Risk | User Impact |
|----------|------------|------|-------------|
| **Option A (Fresh Start)** | Low | None | Must re-upload for Qdrant |
| Option B (Migration) | High | Medium | Seamless, but takes time |

**Recommendation:** Start with Option A. Add Option B only if user demand requires it.
