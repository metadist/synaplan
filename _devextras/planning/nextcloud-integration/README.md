# Synaplan Nextcloud Integration

## Overview

A **Nextcloud App** (`synaplan_integration`) that brings Synaplan's AI capabilities directly into the Nextcloud file manager. Users can summarize documents, translate files, chat about specific documents (RAG), and access a general AI research chat — all without leaving Nextcloud.

## Status

| Item | Status |
|------|--------|
| Planning & Architecture | In Progress |
| Nextcloud Local Instance | Verified (v34.0.0 dev) |
| Synaplan API Endpoints | Verified & Documented |
| App Skeleton | Not Started |
| MVP Implementation | Not Started |

## Environment (Verified 2025-02-11)

| Component | Details |
|-----------|---------|
| **Nextcloud** | v34.0.0 dev, direct install at `/wwwroot/nextcloud/server/` |
| **URL** | `http://localhost/nextcloud/server/` |
| **Login** | admin / admin |
| **PHP** | 8.2+ (composer constraint), 8.4 in DevContainer |
| **Database** | MySQL on 127.0.0.1, database `nextcloud`, prefix `oc_` |
| **Data Dir** | `/wwwroot/nextcloud/server/data` |
| **Apps Dir** | `/wwwroot/nextcloud/server/apps/` (default, bundled apps) |
| **Custom Apps** | Need to create `/wwwroot/nextcloud/server/custom_apps/` and register in config |
| **Synaplan** | Running at `http://localhost:8000` (Docker Compose stack) |
| **Synaplan API Docs** | `http://localhost:8000/api/doc` (Swagger UI) |

## Features

| Feature | Description | Entry Point | Synaplan API |
|---------|-------------|-------------|--------------|
| **Summarize** | Generate summaries of documents | File context menu | `POST /api/v1/summary/generate` |
| **Translate** | Translate documents to other languages | File context menu | `POST /api/v1/summary/generate` (outputLanguage) |
| **Document Chat** | Ask questions about a specific file (RAG) | File sidebar tab | `GET /api/v1/messages/stream` + file upload |
| **Research Chat** | General AI chat (web search, knowledge) | Top navigation / App page | `GET /api/v1/messages/stream` |
| **Open in Synaplan** | Jump to full Synaplan web UI | Settings + Chat panels | Deep link URL |

## User Experience Goals

- **Zero friction**: One click to summarize or translate
- **Context-aware**: Document Chat knows what file you're looking at
- **Escape hatch**: Users can always "Open in Synaplan" for the full experience
- **Non-blocking**: Long operations show progress, don't freeze the UI
- **Streaming**: Chat responses stream in real-time via SSE

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     Nextcloud (NC34)                             │
│                                                                  │
│  ┌──────────────────────┐    ┌───────────────────────────────┐  │
│  │  Vue.js Frontend     │    │  PHP Backend                   │  │
│  │  (NC Component Lib)  │    │  (OCP App Framework)           │  │
│  │                      │    │                                │  │
│  │  • SummaryModal.vue  │◄──►│  • SynaplanController.php     │  │
│  │  • TranslateModal    │    │  • SettingsController.php      │  │
│  │  • ChatSidebar.vue   │    │  • SynaplanApiClient.php      │  │
│  │  • ResearchChat.vue  │    │                                │  │
│  └──────────────────────┘    └───────────────┬────────────────┘  │
│                                              │                   │
└──────────────────────────────────────────────┼───────────────────┘
                                               │ X-API-Key
                                               ▼
                                  ┌──────────────────────┐
                                  │   Synaplan API        │
                                  │   localhost:8000      │
                                  │                       │
                                  │  /api/v1/summary      │
                                  │  /api/v1/messages     │
                                  │  /api/v1/chats        │
                                  │  /api/v1/files        │
                                  │  /api/v1/rag          │
                                  │  /api/health          │
                                  └──────────────────────┘
```

## Synaplan API (Verified Available)

These endpoints have been verified as working in the current Synaplan codebase:

| Endpoint | Method | Purpose | Auth |
|----------|--------|---------|------|
| `/api/health` | GET | Health check / connection test | None |
| `/api/v1/summary/generate` | POST | Summarize text (also translate via `outputLanguage`) | X-API-Key |
| `/api/v1/chats` | POST | Create a new chat session | X-API-Key |
| `/api/v1/messages/stream` | GET | SSE streaming chat (supports `fileIds`, `webSearch`) | X-API-Key |
| `/api/v1/messages/send` | POST | Non-streaming chat | X-API-Key |
| `/api/v1/files/upload` | POST | Upload file (auto-extract text, vectorize) | X-API-Key |
| `/api/v1/files/{id}/content` | GET | Get file metadata + extracted text | X-API-Key |
| `/api/v1/rag/search` | POST | Semantic search over uploaded docs | X-API-Key |

**Authentication**: All API calls use `X-API-Key: sk_...` header. The key belongs to a Synaplan user, and all actions are attributed to that user.

## Nextcloud App Patterns (NC34)

The app will follow modern Nextcloud 34 conventions:

| Pattern | Approach |
|---------|----------|
| **Bootstrapping** | `IBootstrap` interface with `register()` + `boot()` |
| **Route Registration** | PHP 8 Attributes (`#[ApiRoute]`, `#[FrontpageRoute]`) |
| **Controllers** | Extend `OCSController` (for API) and `Controller` (for pages) |
| **Frontend** | Vue 3 + `@nextcloud/vue` component library |
| **Settings** | Admin settings via `ISettings` interface |
| **File Actions** | `OCA.Files.fileActions.registerAction()` or NC34 file action API |
| **Sidebar Tab** | `OCA.Files.Sidebar.registerTab()` |
| **Initial State** | `OCP\InitialState\IInitialStateService` for server → client data |
| **App ID** | `synaplan_integration` |
| **Namespace** | `OCA\SynaplanIntegration` |

## Planning Documents

1. [**Setup Guide**](./01-SETUP.md) — Local dev environment & configuration
2. [**API Specs**](./02-API-SPECS.md) — Verified Synaplan endpoints with request/response schemas
3. [**MVP Plan**](./03-MVP-PLAN.md) — Phase-by-phase implementation plan
4. [**UX Guide**](./04-UX-GUIDE.md) — Wireframes and user flows
5. [**Publishing**](./05-PUBLISHING.md) — App Store submission guide
6. [**Repo Structure**](./06-REPO-STRUCTURE.md) — `synaplan-nextcloud` layout
7. [**Generic Integration**](./07-GENERIC-INTEGRATION.md) — Strategy for "OpenCloud" and other platforms
8. [**App Skeleton**](./08-APP-SKELETON.md) — NC34 app scaffolding blueprint with actual code
9. [**Development Checklist**](./09-DEVELOPMENT-CHECKLIST.md) — Step-by-step workflow

## Repository Strategy

The app will live in a **separate repository**: `synaplan-nextcloud`

- **Source**: `/wwwroot/synaplan-nextcloud/` (new repo)
- **Dev symlink**: `/wwwroot/nextcloud/server/custom_apps/synaplan_integration` → source
- **Build**: Webpack/Vite for Vue frontend
- **Release**: Tarball for Nextcloud App Store

## Vibe Coding Instructions (Claude/Gemini)

When using AI agents to build this, follow this **Iterative Vibe Coding Protocol**:

### 1. Context Loading
Always start a session by loading:
- `@synaplan/AGENTS.md` (Core rules)
- `@synaplan/AGENTS_DEV.md` (Dev conventions)
- `@synaplan/_devextras/planning/nextcloud-integration/` (This plan)

### 2. "One Feature, One Flow" Rule
Build in vertical slices, not horizontal layers:
1. **Skeleton**: App installs, settings page saves credentials
2. **Summarize**: Context menu → API → Modal result
3. **Translate**: Context menu → API → Modal result
4. **Document Chat**: Sidebar → Streaming chat
5. **Research Chat**: Navigation item → Full chat UI

### 3. The "Check-Then-Code" Loop
1. **Check**: "What does the Nextcloud API expect?"
2. **Plan**: "I will create file X with content Y."
3. **Code**: Generate minimal working code
4. **Verify**: Test in browser — does it work?

### 4. Synaplan API First
Before touching Nextcloud code, verify the Synaplan endpoint works via Swagger UI at `http://localhost:8000/api/doc`. If missing, build the Synaplan backend first.

### 5. No Slop
- No huge libraries if fetch works
- No abstractions for the MVP
- Strict typing (PHP 8.3+, TS)
- Comments explain *why*, not *what*
