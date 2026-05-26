# Routing Plan — Use Case Detection & Multi-Step

This document describes how `synaplan` (this repo) integrates with the external **synaplan-router** service for intelligent Use Case detection, multi-step orchestration, and self-improving routing via user feedback.

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│  Channels: Chat / WhatsApp / Email / Signal / Widget / API          │
└──────────────────────────────┬──────────────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Synaplan Backend (PHP/Symfony)                                      │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │ SynapseRouter                                                 │   │
│  │                                                               │   │
│  │  Tier 0: Rules  ─────────────────────────────────── (0 ms)   │   │
│  │      ▼ miss                                                   │   │
│  │  Tier 1: synaplan-router (SetFit/ONNX)  ─────────── (2-5 ms) │   │
│  │      ▼ low confidence                                         │   │
│  │  Tier 2: Qdrant similarity  ──────────────────────── (50 ms)  │   │
│  │      ▼ low confidence                                         │   │
│  │  Tier 3: LLM Matcher (Groq/Ollama)  ─────────────── (200 ms) │   │
│  │                                                               │   │
│  └───────────────────────────┬──────────────────────────────────┘   │
│                              ▼                                       │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │ Result: { use_case, is_compound, steps[], confidence }        │   │
│  └───────────────────────────┬──────────────────────────────────┘   │
│                              ▼                                       │
│  ┌───────────────────┐  ┌──────────────────────────────────────┐   │
│  │ Single-Step       │  │ Multi-Step (StepOrchestrator)         │   │
│  │ → Use Case Prompt │  │ → Step 1: Chat (web_search)          │   │
│  │ → Response        │  │ → Step 2: Image Generation           │   │
│  └───────────────────┘  │ → Step 3: Send via Email             │   │
│                          └──────────────────────────────────────┘   │
│                              ▼                                       │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │ Feedback: POST /api/v1/routing/feedback                       │   │
│  │ { message_id, predicted_use_case, correct_use_case }          │   │
│  └───────────────────────────┬──────────────────────────────────┘   │
│                              ▼                                       │
└──────────────────────────────┼──────────────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│  synaplan-router (external Python service)                           │
│  GitHub: metadist/synaplan-router                                    │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────┐     │
│  │ FastAPI Service                                             │     │
│  │                                                             │     │
│  │  POST /classify    → { use_case, confidence, is_compound,  │     │
│  │                        steps[] }                            │     │
│  │  POST /feedback    → store correction                       │     │
│  │  POST /retrain     → trigger re-training                    │     │
│  │  GET  /health      → model version, accuracy, status        │     │
│  │  GET  /use-cases   → list of trained labels                 │     │
│  └────────────────────────────────────────────────────────────┘     │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────┐     │
│  │ Model: SetFit (ONNX-exported)                               │     │
│  │ - Multilingual encoder (paraphrase-multilingual-MiniLM)     │     │
│  │ - Logistic Regression head (multi-label)                    │     │
│  │ - ONNX O4 optimized → ~2ms inference                       │     │
│  │ - Auto-retrain on feedback threshold                        │     │
│  └────────────────────────────────────────────────────────────┘     │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────┐     │
│  │ Training Data                                               │     │
│  │ - Initial: CompoundRoutingCatalog example_queries           │     │
│  │ - Ongoing: User feedback corrections                        │     │
│  │ - Format: JSONL { text, label, source, timestamp }          │     │
│  └────────────────────────────────────────────────────────────┘     │
└─────────────────────────────────────────────────────────────────────┘
```

## Use Case Taxonomy

Based on the product vision, these are the predefined Use Cases:

| Use Case ID | Category | Description |
|-------------|----------|-------------|
| `text_chat` | Text Chat | General conversation, Q&A, knowledge |
| `coding` | Text Chat | Programming, code review, debugging |
| `image_generation` | Media Generation | Create images from text prompts |
| `video_generation` | Media Generation | Create videos from text prompts |
| `audio_generation` | Media Generation | Create audio / music |
| `image_editing` | Media Generation | Edit / transform existing images |
| `file_generation` | File Generation | PowerPoint, Word, PDF, SVG, graphs |
| `file_analysis` | File Analytics | Extract text from documents, analyze content |
| `email_send` | Communication | Compose and send emails |
| `email_receive` | Communication | Process incoming emails (with attachments) |
| `calendar_create` | Communication | Create and send calendar entries |
| `calendar_receive` | Communication | Process incoming calendar invitations |
| `web_search` | Enrichment | Search the web for current information |
| `summarize` | Productivity | Summarize documents or conversations |

### Compound Use Cases (Multi-Step)

| Compound ID | Steps | Example |
|-------------|-------|---------|
| `compound_research_image` | `text_chat(web_search)` → `image_generation` | "Was kostet ein Döner und generiere ein Bild davon" |
| `compound_write_tts` | `text_chat` → `audio_generation(tts)` | "Schreib ein Gedicht und lies es mir vor" |
| `compound_image_email` | `image_generation` → `email_send` | "Generiere ein Logo und maile es an Peter" |
| `compound_research_file` | `text_chat(web_search)` → `file_generation` | "Recherchiere X und erstelle eine PowerPoint" |
| `compound_file_analyze_reply` | `file_analysis` → `text_chat` | "Hole die Datei und fasse sie zusammen" |

## Integration in Synaplan (this repo)

### PHP Side — SynapseRouter Changes

```php
// Current flow (simplified):
// Tier 0: Rules → Tier 1: Qdrant → Tier 2: LLM Fallback

// New flow:
// Tier 0: Rules → Tier 1: synaplan-router → Tier 2: Qdrant → Tier 3: LLM Fallback
```

**New service: `RouterClient.php`**

- HTTP client to `synaplan-router` service
- Timeout: 100ms (fast fail → fallback to Qdrant/LLM)
- Circuit breaker: if router unreachable 3x → skip for 60s
- Endpoint: `POST /classify { text, context?, language? }`
- Response: `{ use_case, confidence, is_compound, steps[], model_version }`

**SynapseRouter modification:**

1. After Tier 0 rules miss → call `RouterClient::classify()`
2. If confidence ≥ threshold (0.80) → use result directly
3. If `is_compound: true` → attach `steps[]` to classification
4. If confidence < threshold or timeout → fall through to Qdrant (Tier 2)
5. Store `classification_source: "setfit"` in message metadata

### Feedback Endpoint (new)

```
POST /api/v1/routing/feedback
{
  "message_id": 5367,
  "predicted_use_case": "text_chat",
  "correct_use_case": "image_generation",
  "user_id": 42
}
```

- Validates that message exists and belongs to user
- Forwards to `synaplan-router` POST /feedback
- Stores correction in `BMESSAGEMETA` for audit

### Frontend — Feedback UI

After a message is delivered, if routing metadata is available:

- Small icon/button: "Wrong result? Tell us what you expected"
- Dropdown with Use Case options (from `synaplan-router` GET /use-cases)
- Submits correction to feedback endpoint
- Non-intrusive — only appears on hover or in message details

### Docker Compose Addition

```yaml
services:
  router:
    image: ghcr.io/metadist/synaplan-router:latest
    environment:
      - MODEL_PATH=/data/model
      - RETRAIN_THRESHOLD=50       # retrain after 50 corrections
      - CONFIDENCE_THRESHOLD=0.80
    volumes:
      - router-data:/data
    ports:
      - "8100:8000"
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/health"]
      interval: 30s
      timeout: 5s
```

## synaplan-router Service (separate repo)

### Tech Stack

| Component | Choice | Why |
|-----------|--------|-----|
| Framework | FastAPI | Async, fast, OpenAPI docs built-in |
| ML | SetFit + sentence-transformers | Few-shot, multilingual, fast |
| Inference | ONNX Runtime (CPU) | ~2ms latency, no GPU needed |
| Storage | SQLite + JSONL | Lightweight, no extra DB |
| Container | Python 3.11 slim | Small image (~500MB with deps) |

### API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/classify` | Classify a message → use_case + steps |
| `POST` | `/feedback` | Store a user correction |
| `POST` | `/retrain` | Trigger model retraining (admin) |
| `GET` | `/health` | Service health + model version |
| `GET` | `/use-cases` | List of all trained labels |
| `GET` | `/metrics` | Accuracy stats, feedback counts |

### `/classify` Request/Response

```json
// Request
{
  "text": "Recherchiere den Bitcoin-Preis und generiere ein Bild davon",
  "language": "de",
  "context": "general"   // optional: previous use_case for sticky detection
}

// Response
{
  "use_case": "compound_research_image",
  "confidence": 0.91,
  "is_compound": true,
  "steps": [
    { "id": "step_1", "capability": "CHAT", "web_search": true },
    { "id": "step_2", "capability": "IMAGE_GENERATION" }
  ],
  "model_version": "v1.3.2",
  "latency_ms": 2.1
}
```

### Auto-Retrain Flow

```
1. Feedback arrives → stored in feedback.jsonl
2. Counter increments
3. When counter ≥ RETRAIN_THRESHOLD (default 50):
   a. Merge feedback into training dataset
   b. Retrain SetFit (few-shot, ~2-5 minutes)
   c. Export to ONNX with O4 optimization
   d. Hot-swap model (zero-downtime)
   e. Reset counter
   f. Log new model version + accuracy delta
```

### Initial Training Data Sources

1. **CompoundRoutingCatalog** (`backend/src/UseCase/CompoundRoutingCatalog.php`)
   - 5 compound scenarios with `example_queries` (DE + EN)
   - ~10-15 examples per compound

2. **System Prompts** (`backend/src/Prompt/PromptCatalog.php`)
   - Each prompt has topic + description → synthetic training data
   - ~5-10 examples per single use case

3. **Historical Messages** (optional, privacy-aware)
   - Messages where `BMESSAGEMETA.ai_sorting_model` confirms the label
   - Only exported with explicit admin action

4. **Synthetic augmentation**
   - LLM generates 20 variations per Use Case from the description
   - Balanced across DE/EN/mixed

### Model Export Pipeline

```bash
# Training (Python, runs on retrain trigger)
python train.py \
  --dataset data/training.jsonl \
  --encoder paraphrase-multilingual-MiniLM-L12-v2 \
  --output models/current/

# ONNX export (automatic after training)
optimum-cli export onnx \
  --model models/current/ \
  --task feature-extraction \
  --optimize O4 \
  models/onnx/

# Serve (automatic hot-swap)
# FastAPI reloads the ONNX model from models/onnx/ on version change
```

### Future: C++ / Native Inference (Phase 2+)

If ~2ms Python+ONNX is not fast enough:

1. **ONNX Runtime C++** — load `model.onnx` in a native binary
2. **Tokenizer** — port HuggingFace tokenizer to C++ (tokenizers-cpp exists)
3. **Embedding + Head** — single forward pass in C++, return result via gRPC/Unix socket
4. **Expected latency** — < 1ms per classification

This is NOT needed initially. Only pursue if:
- Request volume exceeds 1000 req/s on a single node
- Python container memory is a problem (unlikely with ONNX)
- You want to eliminate the Python dependency entirely

## Migration Path (Phases)

### Phase 1: External Router Service (Week 1-2)

- [ ] Create `metadist/synaplan-router` repo
- [ ] FastAPI scaffolding + `/classify`, `/health`, `/use-cases`
- [ ] Initial training on CompoundRoutingCatalog examples
- [ ] ONNX export + benchmark
- [ ] Docker image + docker-compose integration in synaplan
- [ ] `RouterClient.php` in synaplan backend
- [ ] SynapseRouter: insert Tier 1 (router) before Qdrant
- [ ] Fallback chain: Rules → Router → Qdrant → LLM
- [ ] `classification_source: "setfit"` in BMESSAGEMETA

### Phase 2: Feedback Loop (Week 3)

- [ ] `/feedback` endpoint in router
- [ ] `POST /api/v1/routing/feedback` in synaplan backend
- [ ] Frontend: feedback button on messages (non-intrusive)
- [ ] Feedback storage in router (JSONL + SQLite)
- [ ] Admin dashboard: view feedback, accuracy metrics

### Phase 3: Auto-Retrain (Week 4)

- [ ] Retrain trigger (threshold-based)
- [ ] ONNX re-export after training
- [ ] Hot-swap model (zero downtime)
- [ ] Version tracking + accuracy delta logging
- [ ] Admin notification on retrain completion
- [ ] Rollback mechanism (keep last 3 model versions)

### Phase 4: Expand & Optimize (Week 5+)

- [ ] Train single Use Cases (not just compound) → gradually replace Qdrant
- [ ] Historical message export for training (admin opt-in)
- [ ] Multi-label support (message touches multiple Use Cases)
- [ ] A/B testing: SetFit vs. Qdrant accuracy comparison
- [ ] Consider C++/native inference if needed
- [ ] Consider embedding router into FrankenPHP via PHP FFI + ONNX

## Configuration (BCONFIG)

| Setting | Default | Description |
|---------|---------|-------------|
| `ROUTER_SERVICE_URL` | `http://router:8000` | URL of synaplan-router |
| `ROUTER_ENABLED` | `true` | Enable/disable external router |
| `ROUTER_CONFIDENCE_THRESHOLD` | `0.80` | Min confidence to accept router result |
| `ROUTER_TIMEOUT_MS` | `100` | HTTP timeout (fast fail) |
| `ROUTER_CIRCUIT_BREAKER_THRESHOLD` | `3` | Failures before skipping |
| `ROUTER_CIRCUIT_BREAKER_RESET_S` | `60` | Seconds before retry |

## Observability

Every routing decision stores in `BMESSAGEMETA`:

```json
{
  "classification_source": "setfit | qdrant | llm_matcher | rule",
  "classification_confidence": 0.91,
  "classification_model_version": "v1.3.2",
  "classification_latency_ms": 2.1,
  "classification_is_compound": true,
  "classification_steps": ["CHAT+web_search", "IMAGE_GENERATION"],
  "classification_fallback_reason": null
}
```

## Relationship to Existing Code

| File | Role | Changes Needed |
|------|------|----------------|
| `SynapseRouter.php` | Core routing logic | Add Tier 1 (RouterClient) before Qdrant |
| `MessageClassifier.php` | Entry point | None (already calls SynapseRouter) |
| `MessageSorter.php` | LLM fallback | Becomes Tier 3 (unchanged logic) |
| `StepOrchestrator.php` | Multi-step execution | Use steps from router response |
| `ClassificationStepPlanner.php` | Build StepPlan | Accept steps from classification |
| `CompoundRoutingCatalog.php` | Training data source | Export to router training format |
| `AdminSynapseController.php` | Admin API | Add feedback endpoint |
| `SortingPromptConfiguration.vue` | Routing admin page | Add feedback stats, router status |

## Key Design Decisions

1. **Router is optional** — if unreachable, system works identically to today (Qdrant + LLM)
2. **Feedback is per-user** — corrections are tied to user context, not global overrides
3. **Retrain is automatic** — no manual intervention needed after sufficient feedback
4. **ONNX for inference** — Python only for training, inference is native-speed
5. **Separate repo** — independent release cycle, own CI/CD, can be open-sourced separately
6. **Multi-label from day 1** — SetFit supports it; important for compound detection
7. **Hot-swap on retrain** — zero downtime, atomic model switch
8. **Privacy-first** — no message content stored in router, only text → label pairs in feedback
