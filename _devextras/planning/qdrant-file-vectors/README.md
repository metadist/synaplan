# Qdrant File Vector Integration Plan

## 🚀 Start Here

**👉 [Read the IMPLEMENTATION GUIDE](./IMPLEMENTATION_GUIDE.md)** for the fast-track coding checklist.

---

## Overview

Make file vectorization configurable to use **either** MariaDB Vector **or** Qdrant (via the memories service), giving admins flexibility to choose based on performance needs.

**Key Design Principles:**
- **Facade Pattern** - Single interface, multiple backends (MariaDB, Qdrant)
- **Per-User Isolation** - Vectors are always filtered by `user_id`
- **Group-Based Organization** - Files organized into groups (`DEFAULT`, `WIDGET:xxx`, etc.)
- **Zero Regression** - Existing behavior unchanged; new system is opt-in

## Documentation Map

| Document | Description |
|----------|-------------|
| [IMPLEMENTATION_GUIDE.md](./IMPLEMENTATION_GUIDE.md) | **Master Checklist** & Coding Rules |
| [01-CONFIGURATION.md](./01-CONFIGURATION.md) | .env setup, provider selection |
| [02-BACKEND-ABSTRACTION.md](./02-BACKEND-ABSTRACTION.md) | PHP Interfaces, DTOs, Facade |
| [03-QDRANT-SERVICE.md](./03-QDRANT-SERVICE.md) | Rust Service extensions |
| [04-INTEGRATION-POINTS.md](./04-INTEGRATION-POINTS.md) | Code changes in existing files |
| [05-GROUPS-AND-ISOLATION.md](./05-GROUPS-AND-ISOLATION.md) | Security & Grouping logic |
| [06-MIGRATION.md](./06-MIGRATION.md) | Migration & Rollback |

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           synaplan backend                                  │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    VectorStorageFacade                               │   │
│  │  (Reads VECTOR_STORAGE_PROVIDER from .env)                          │   │
│  └─────────────────────┬─────────────────────┬─────────────────────────┘   │
│                        │                     │                              │
│           ┌────────────▼────────┐ ┌─────────▼──────────────┐               │
│           │ MariaDBVectorStorage │ │ QdrantVectorStorage    │               │
│           │ (existing BRAG)      │ │ (HTTP to qdrant-svc)   │               │
│           └─────────────────────┘ └───────────┬────────────┘               │
│                                               │                             │
└───────────────────────────────────────────────│─────────────────────────────┘
                                                │
                ┌───────────────────────────────▼─────────────────────────────┐
                │              qdrant-service (Rust)                          │
                │                                                             │
                │   ┌─────────────────────┐  ┌─────────────────────────────┐ │
                │   │   user_memories     │  │     user_documents          │ │
                │   │   (existing)        │  │     (NEW collection)        │ │
                │   └─────────────────────┘  └─────────────────────────────┘ │
                └─────────────────────────────────────────────────────────────┘
```
