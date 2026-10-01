# Smart Search (Ctrl/Cmd+K) — master plan

**Status:** Draft 2026-09-30. Tick §0 before the first product PR.
**Goal:** One place to find and operate everything in Synaplan — pages,
commands, settings, chats, files (including RAG content), memories,
widgets, assistants — by *meaning*, not by exact string.
**Binding UX:** [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md) (U1–U12).
**Class:** palette and admin card are `ota-candidate`; index, providers,
interpret and model slots are `backend-only`.

Files in this track:

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Decisions, journeys, architecture, step table |
| `01_sprints.md` | Per-step user-flow, goal, exit criteria, class |
| `STATUS.md` | Step log |

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | The palette reads destinations from `useNavItems()`. It keeps no route list of its own. | Locked | |
| 2 | Tiered brain: local fuzzy (instant) → hybrid lexical + semantic backend search → LLM intent only for natural-language queries or the "Ask AI" row. | Locked | |
| 3 | The LLM may only pick targets from the candidate list it receives. It never invents ids (same rule as `[Memory:ID]`). | Locked | |
| 4 | Inline settings: personal settings apply at once with an undo toast; system settings show the consequence sentence and need a confirm. Env-pinned, sensitive and `managedBy` keys are never inline. | Locked | |
| 5 | Writes go through the existing endpoints (`PUT /api/v1/admin/config/values`, `PATCH /api/v1/profile`). No generic write endpoint. | Locked | |
| 6 | One MariaDB table `BSEARCHINDEX` holds FULLTEXT + VECTOR so hybrid search works without Qdrant. RAG chunks and memories are fanned out to their existing stores, not copied. | Locked | |
| 7 | New dependency: `minisearch` (frontend, ~7 kB). | Approved | |
| 8 | Search models are admin-selectable: `DEFAULTMODEL.SEARCH` (inherits TOOLS → CHAT) and `DEFAULTMODEL.SEARCH_EMBED` (inherits VECTORIZE). | Approved | |

---

## 1. Journeys

| Id | Who | Step |
| -- | --- | ---- |
| **J-SR-1** | A new user presses Ctrl/Cmd+K (or the search button) and finds a page and a command without help. | S1 |
| **J-SR-2** | A search for something *inside* a file finds the file and the passage; Enter opens the preview. | S3 |
| **J-SR-3** | An admin searches `FEATURE_IAM_GROUPS_ENABLED` or "turn on groups", toggles it inline, confirms the consequence and undoes it. | S4 |
| **J-SR-4** | A natural-language question yields a "Best action" card or a hand-off to a new chat. | S5 |
| **J-SR-5** | Flag or model missing: the surface is absent and the copy is honest ("Keyword search only"). | S3, S5 |
| **J-SR-6** | An admin opens AI infrastructure → Smart Search, switches the search models, sees the index status, and undoes the AI-model change. Search keeps working during a reindex. | S5b |

---

## 2. Architecture

```
PaletteInput ─┬─ tier 0: MiniSearch (pages, commands, recents; all 5 locales) < 50 ms
              ├─ tier 1: POST /api/v1/search  (debounce, AbortController)
              │     SmartSearchService
              │       embed query once (SEARCH_EMBED, cached)
              │       fan-out → SearchProviderInterface (tag app.search.provider)
              │         index (BSEARCHINDEX FULLTEXT + VECTOR), settings,
              │         files (names + RAG chunks), memories
              │       RRF (k=60) → per-kind cap → permission filter
              └─ tier 2: POST /api/v1/search/interpret  (tools:smart_search)
                    grounded on tier-1 candidate ids → Best action card
```

## 3. What we do not rebuild

| Already here | Use it |
| ------------ | ------ |
| Embeddings + 7-day Redis cache | `AiFacade::embed()` |
| RAG chunk search | `VectorSearchService::semanticSearch()` |
| Memory search | `UserMemoryService::searchMemories()` |
| Admin settings schema + values | `SystemConfigService` |
| Model defaults / inheritance | `ModelConfigService`, `DEFAULTMODEL` |
| Destinations + flag gating | `useNavItems()` |
| Dialogs / toasts | `useDialog()`, `useNotification()` |

## 4. Step table

| Step | Commit title | Class | Depends |
| ---- | ------------ | ----- | ------- |
| S0 | `docs(search): smart search master plan` | no-app-impact | — |
| S1 | `feat(search): add global command palette with local index` | ota-candidate | S0 |
| S2 | `feat(search): add unified search index and lexical search API` | backend-only | S0 |
| S3 | `feat(search): hybrid semantic search with RRF` | backend-only + ota-candidate | S1, S2 |
| S4 | `feat(search): operate settings inline from the palette` | ota-candidate | S3 |
| S5 | `feat(search): AI intent tier grounded on search candidates` | backend-only + ota-candidate | S3 |
| S5b | `feat(search): admin-selectable search models` | backend-only + ota-candidate | S5 |
| S6 | `feat(search): action pane, preview and evaluation set` | ota-candidate | S5b |

## 5. Gates

- `make ci-local` and `make test-e2e` before every push.
- `make -C frontend generate-schemas` after OpenAPI changes, then `vue-tsc`.
- `node scripts/mobile-impact.mjs --base main --head HEAD`.

## 6. Non-goals

- The palette is not a substitute for clear navigation; the UI overhaul
  follows separately and the palette adapts because it reads `useNavItems`.
- No full-text index over every chat message in this track (titles +
  first message preview are indexed; message digests stay in Qdrant).
- No palette in the embeddable chat widget.
