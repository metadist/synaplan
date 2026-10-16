<!-- title: Models: three screens disagree on the embedding model — one embedding status card (active model, reachable, indexed count) and five setup rough edges -->
<!-- type: Feature -->
<!-- labels: prio:2, area:models, area:semantic-search -->
<!-- issue-type: Feature -->

## Summary
One embedding status card — the active embedding model, whether it is reachable, how many files and chunks are indexed with it, the reranker state, and a link to fix each — shown on Model Choice and under Admin → AI infrastructure → Knowledge search; plus the small setup defects found while registering an OpenAI-compatible embedding endpoint.

---

## Problem / Motivation
Three screens disagreed on the embedding model: Model Choice showed Embedding / Vectorization unset, Knowledge search said the index uses bge-m3 on Ollama, and Edit Models marked that row "Not pulled". A reranker model exists but Reranking reports none bound (F19). Setup rough edges (F28): after adding the vectorize capability to an endpoint, the Add a model form only offered Chat until a reload; the form collapsed when the capability changed; the "Searchable by AI" pop-up opened partly behind the sidebar; a model at 0.02 per 1M tokens is labelled "Mid Cost"; the first Library search after setup timed out after 30 s with nothing in the log, while the retry returned in 85 ms. Open WebUI sets this up on one page with five fields; Synaplan's switch dialog with cost estimate and dimension warning is the safer design to keep.

---

## Goal
An admin sees in one place which embedding model is live, whether it answers, and what is indexed with it — and the three existing screens derive from that one truth.

---

## Acceptance criteria
- [ ] `GET /api/v1/admin/embedding/status` (or extend an existing endpoint): active model id + name + provider, reachability check result with latency, indexed file and chunk counts for that model, documents indexed with another model (needs re-index), reranker binding. OpenAPI → generated schema.
- [ ] One `EmbeddingStatusCard` rendered on Model Choice and on Knowledge search; Edit Models' row badge for the embedding model derives from the same status ("Active · reachable" instead of "Not pulled" when it is not an Ollama model).
- [ ] After saving an endpoint, capability lists refresh without reload; the Add a model form keeps its state when the capability changes.
- [ ] "Searchable by AI" pop-over positions inside the viewport (shared floating-ui / popover positioning).
- [ ] Cost badge derives from price per 1M tokens with documented thresholds; 0.02 / 1M is "Low cost".
- [ ] First-query timeout: log the embedding call timeout with provider and latency; warm the embedding client on save (one probe request) so the first user search does not pay the cold start.

---

## Notes
- Findings: F19, F28 — community test round on 5.2.0.
- Code: `frontend/src/components/config/EmbeddingSwitchModal.vue`, Model Choice and Knowledge search views, `backend/src/AI/Credential/OpenAiCompatibleEndpointRegistry.php`, the vectorize capability on endpoints, `VectorizationService` (model id per chunk), reranking plug.
- Journey (U10): register an OpenAI-compatible embedding endpoint → Add model (vectorize) without reload → Model Choice card says "Active: qwen3-embedding-4b · reachable 120 ms · 0 files indexed" → upload a file → "1 file, 42 chunks" → Knowledge search shows the same card.

---

## Screenshots/Logs
—
