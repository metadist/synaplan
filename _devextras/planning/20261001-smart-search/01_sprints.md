# Smart Search — sprints

Roadmap: [`00_master_plan.md`](00_master_plan.md) §4.

Every `ota-candidate` step below carries the five exit bullets from
[`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md) §6.

---

## S1 — Palette shell and local index (ota-candidate)

### User-flow

J-SR-1. Ctrl/Cmd+K anywhere in the signed-in app, the search button in
the sidebar rail, or the search entry in the mobile drawer.

### Goal

- Do: `SmartSearchPalette.vue` mounted in `App.vue` (app only), ARIA
  combobox + listbox, MiniSearch over pages (from `useNavItems`), commands
  and recents; titles indexed in all five locales.
- Do not: touch the chat slash-command `CommandPalette.vue`.

### Exit criteria

1. J-SR-1 walked in the browser (U10).
2. The palette is reachable in ten seconds: shortcut hint on the sidebar button (U2).
3. Command copy in all five locales (U3).
4. Empty (recents + suggestions), no-result (ask in chat) and flag-off states (U5, U8, U11).
5. Dark + V2 + 320 px (U9).

---

## S2 — Unified index and lexical API (backend-only)

- `BSEARCHINDEX` (FULLTEXT + VECTOR(1024) + `BEMBEDMODELID`), raw idempotent migration.
- `SearchIndexer`, `SearchIndexMessage` on `async_index`, Doctrine lifecycle
  listener for chats, files, widgets; `app:search:reindex`.
- `POST /api/v1/search` with `SearchProviderInterface` providers and a
  per-user rate limiter.

---

## S3 — Hybrid semantic tier (backend-only + ota-candidate)

### User-flow

J-SR-2, J-SR-5.

### Goal

- Embed the query once, run FULLTEXT and VECTOR on the index, fan out to
  RAG chunks and memories, fuse with RRF (k=60).
- `semanticAvailable: false` when no embedding model answers; the palette
  says "Keyword search only".

### Exit criteria

1. J-SR-2 walked (upload a file, search a phrase from its content).
2. File result shows the folder breadcrumb and opens the preview (U2).
3. "Found by meaning" hint in five locales (U3).
4. Embedding model down ⇒ keyword results + honest hint (U8).
5. Dark + V2 + 320 px (U9).

---

## S4 — Inline settings (ota-candidate)

### User-flow

J-SR-3.

### Exit criteria

1. J-SR-3 walked: toggle, confirm, undo.
2. Setting row names where it lives ("Admin → System config → …") (U2, U7).
3. Consequence sentence for system scope in five locales (U3).
4. Env-pinned: read-only "Set by the server". Save failure: one sentence, value restored (U8).
5. Dark + V2 + 320 px (U9).

---

## S5 — AI intent tier (backend-only + ota-candidate)

### User-flow

J-SR-4, J-SR-5.

### Exit criteria

1. J-SR-4 walked with a cloud chat model.
2. The Best action card sits on top of the list (U2).
3. Copy in five locales (U3).
4. No chat model or `FEATURE_SEARCH_AI_ENABLED=false` ⇒ no "Ask AI" row (U11). Interpret failure ⇒ list stays usable, one sentence (U8).
5. Dark + V2 + 320 px (U9).

---

## S5b — Admin-selectable search models (backend-only + ota-candidate)

### User-flow

J-SR-6.

### Exit criteria

1. J-SR-6 walked on `/admin/setup`.
2. The card is found from the palette ("search model") (U2).
3. Inherit hint and consequence copy in five locales (U3).
4. Unavailable model disabled with a reason; reindex failure keeps the old model and says so (U8).
5. Dark + V2 + 320 px (U9).

---

## S6 — Action pane, preview, evaluation (ota-candidate)

- Tab / → opens secondary actions of the active row (open, open in new
  tab, copy link, ask in chat).
- Preview column ≥ 1024 px.
- `backend/tests/Fixtures/search/golden_queries.json` + `app:search:eval`
  (Recall@5, nDCG@10).

### Exit criteria

1. J-SR-1…J-SR-6 walked end to end.
2. Actions reachable by keyboard and pointer (U2).
3. Copy in five locales (U3).
4. Empty/error states unchanged (U5, U8).
5. Dark + V2 + 320 px (U9).
