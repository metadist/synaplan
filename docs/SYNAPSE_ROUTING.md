# Synapse Routing

Synapse Routing is Synaplan's intelligent message classification system that uses **vector similarity** to determine what a user is asking about — without making an expensive AI call for every single message.

## How It Works

When a user sends a message, Synaplan needs to figure out what kind of request it is (general chat, image generation, coding help, etc.) before routing it to the right AI handler. Previously, this required a full AI sorting call for every message (~500–2000ms, ~2000 tokens).

Synapse Routing replaces this with a two-tier system:

### Tier 1: Embedding Search (~50ms)

1. The user's message is converted to an embedding vector
2. This vector is compared against pre-indexed topic descriptions in Qdrant
3. If the top match has a confidence score ≥ 0.78 (configurable), the message is routed directly

### Tier 2: AI Fallback

When the embedding confidence is too low, Synapse automatically falls back to the traditional AI-based sorting — so accuracy is never compromised.

```
User Message → Embed → Qdrant Search → Confidence ≥ 0.78? → Direct Route (50ms)
                                                    ↓ No
                                        AI Sort Fallback (500-2000ms)
```

## Key Features

- **Multilingual**: Embedding models work across all languages. A French question will still match an English topic description via semantic similarity.
- **Conversation-Sticky**: If the current topic is still relevant (score ≥ 0.65), it won't switch topics mid-conversation unnecessarily.
- **Auto-Indexing**: When topics are created, updated, or deleted via the API, their embeddings are automatically updated in Qdrant.
- **Rule-Based Priority**: Topics with explicit selection rules are always checked first, before any embedding search.
- **Heuristic Detection**: Language, web-search intent (keywords like "today", "weather", "2026"), and media type are detected via lightweight heuristics — no AI call needed.

## Performance

| Metric | Before (AI Sort) | With Synapse |
|--------|------------------|--------------|
| Latency per classification | 500–2000ms | 50–100ms (Tier 1) |
| Cost per classification | ~2000 tokens | ~100 embedding tokens |
| AI calls saved | 0% | 70–90% |

## Configuration

Synapse Routing is **enabled by default**. You can manage it in the Admin panel under **Vector DB → Synapse Routing**:

| Setting | Default | Description |
|---------|---------|-------------|
| `SYNAPSE_ROUTING_ENABLED` | `true` | Enable/disable Synapse Routing entirely |
| `SYNAPSE_CONFIDENCE_THRESHOLD` | `0.78` | Minimum cosine similarity for direct routing (lower = more Tier 1, less accurate; higher = more AI fallback, more accurate) |

Changes take effect immediately — no restart required.

## CLI Commands

```bash
# Index all system topics (run once after deployment)
php bin/console synapse:index

# Index including a specific user's custom topics
php bin/console synapse:index --user=42

# Check Synapse status (collection info, embedding model)
php bin/console synapse:index --status
```

## Observability

Every routing decision is logged with:

- **source**: `synapse_embedding`, `synapse_sticky`, `synapse_rule`, or `synapse_ai_fallback`
- **synapse_score**: The cosine similarity of the top match
- **synapse_fallback_reason**: Why Tier 2 was used (e.g., `low_confidence`, `no_search_results`)
- **synapse_latency_ms**: How long the routing took

Discord notifications (if enabled) include these metrics alongside the regular classification data.

## Architecture

```
backend/src/Service/Message/
├── SynapseRouter.php      # Core routing logic (Tier 1 + fallback)
├── SynapseIndexer.php      # Topic embedding management
├── MessageClassifier.php   # Entry point (calls SynapseRouter)
└── MessageSorter.php       # AI-based sorting (Tier 2 fallback)

backend/src/Command/
└── SynapseIndexCommand.php # CLI for indexing

backend/src/Service/VectorSearch/
└── QdrantClientDirect.php  # synapse_topics collection
```
