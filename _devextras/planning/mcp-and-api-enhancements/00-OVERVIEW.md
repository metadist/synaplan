# Synaplan is Better OpenAI API Compatible Than Ever and Offers Now MCP Integration

## Release Scope

This plan covers **5 features + 1 bonus** for the upcoming release:

| # | File | Feature | Effort |
|---|------|---------|--------|
| 1 | `01-OPENAI-COMPATIBLE.md` | User-provided API keys + generic OpenAI-compatible endpoints | Medium |
| 2 | `02-MCP-PROMPT-ENRICHMENT.md` | MCP calls as prompt enrichment (like Internet/File Search) | Large |
| 3 | `03-URL-SCREENSHOT-AUDIT.md` | Audit & fix the URL Screenshot tool | Small |
| 4 | `04-ENRICHMENT-UI-LOGGING.md` | Show enrichment data + enriched prompt in Chat GUI & logs | Medium |
| 5 | `05-API-CUSTOM-PROMPTS.md` | Address custom prompts directly from the API | Small |
| 6 | `06-BONUS-MCP-SERVER.md` | Expose Synaplan API as MCP services (bonus) | Large |
| 7 | `07-TEST-STRATEGY.md` | Test plan per step | Reference |

## Scope Boundaries

- **In scope:** Main app chat (authenticated users)
- **Out of scope:** Widget (no MCP/enrichment changes for widgets in this release)
- **Existing plans:** `mcp-integration-plan.md` covers the 3-step MCP roadmap; this plan is the **concrete implementation breakdown** for Steps 1 & 2 plus the OpenAI compatibility work

## Architecture Context (Current State)

```
User Prompt
    │
    ▼
MessageProcessor (orchestrator)
    ├── MessagePreProcessor     → file downloads, text extraction
    ├── MessageClassifier       → intent detection, language, topic
    ├── BraveSearchService      → Internet Search (tool_internet)
    ├── VectorSearchService     → File Search / RAG (tool_files)
    ├── [NOT IMPLEMENTED]       → URL Screenshot (tool_screenshot)
    ├── [NEW: MCP Client]       → MCP calls (tool_mcp_*)
    │
    ▼
InferenceRouter → ChatHandler / MediaGenerationHandler / ...
    │
    ▼
AiFacade → ProviderRegistry → OpenAI / Ollama / Anthropic / Groq / Google / HuggingFace
```

## Key Files to Know

| Area | Files |
|------|-------|
| **Provider registry** | `backend/src/AI/Service/ProviderRegistry.php` |
| **AI facade** | `backend/src/AI/Service/AiFacade.php` |
| **OpenAI provider** | `backend/src/AI/Provider/OpenAIProvider.php` |
| **Model config** | `backend/src/Service/ModelConfigService.php` |
| **Message pipeline** | `backend/src/Service/Message/MessageProcessor.php` |
| **Chat handler** | `backend/src/Service/Message/Handler/ChatHandler.php` |
| **Web search** | `backend/src/Service/Search/BraveSearchService.php` |
| **RAG / vectors** | `backend/src/Service/RAG/VectorSearchService.php` |
| **Prompt service** | `backend/src/Service/PromptService.php` |
| **Stream controller** | `backend/src/Controller/StreamController.php` |
| **Prompt controller** | `backend/src/Controller/PromptController.php` |
| **Frontend chat** | `frontend/src/views/ChatView.vue` |
| **Chat message** | `frontend/src/components/ChatMessage.vue` |
| **Prompt config UI** | `frontend/src/components/config/TaskPromptsConfiguration.vue` |
| **Screenshot display** | `frontend/src/components/MessageScreenshot.vue` |

## Known Issues Discovered During Planning

1. **Tool naming mismatch:** Frontend uses `tool_internet_search` / `tool_url_screenshot`; backend `PromptService` defaults use `tool_internet` / `tool_screenshot`. Must standardize.
2. **URL Screenshot:** Frontend UI exists (`MessageScreenshot.vue`), backend is **not implemented** (TODO in `MessageProcessor.php` line 243).
3. **OpenAI provider:** Hardcoded to OpenAI's endpoint — no custom base URL support. Uses env var only, no per-user keys.
4. **API prompt selection:** Chat streaming endpoint (`/api/v1/messages/stream`) doesn't accept a `promptTopic` parameter — callers can't pick which custom prompt to use.

## How to Use These Plans

Each file is self-contained and designed for **vibe coding**: read one file, implement it, write the tests, move to the next. Files are numbered in dependency order — implement 01 before 02, etc.

Every step ends with a concrete **test checklist** so you know when you're done.
