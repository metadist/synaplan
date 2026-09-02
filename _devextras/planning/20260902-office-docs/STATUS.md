# Status — Office Documents (Synaplan side)

Plan of record: `00_master_plan.md`. Work one step at a time; update this
table when a branch is opened, merged, or a decision is taken. The Collabora
side (editor, Synaplan inside Collabora, partner platforms) is tracked in
`../20260902-collabora-integration/STATUS.md`.

## Phase T — tool calling (first)

| Step | Branch | State | Notes |
| ---- | ------ | ----- | ----- |
| T1 — Provider contract, consistent `tool_use` flags + migration, `ToolCallAccumulator`, `OpenAiToolShapes`, TestProvider | `feat/chat-provider-tool-contract` | planned | Dual gate; catalog matrix test |
| T2 — Chat Completions providers (Groq, OpenAICompatible, Mistral, xAI, TrustedTokens, HuggingFace) | `feat/tools-chat-completions-providers` | planned | Shared trait; `supportsToolCalling` = catalog flag |
| T3 — `/v1/chat/completions` tools pass-through (non-stream + stream), dual-gate 400 | `feat/openai-gateway-tools` | planned | Collabora client tools work from here |
| T4 — Server-side MCP + `web_search` loop on `/v1/chat/completions` | `feat/openai-gateway-server-tools` | planned | Injected on dual-gated models; client tools still relayed |
| T5 — OpenAI Responses API provider | `feat/tools-openai-responses` | planned | |
| T6 — Anthropic + Google (+ Ollama optional) | `feat/tools-anthropic-google-providers` | planned | |
| T7 — Wrap-up docs, admin badge, STATUS cross-record | — | planned | |

## Phase A — engine and UX quick wins

| Step | Branch | State | Notes |
| ---- | ------ | ----- | ----- |
| A0 — Converter client + `collabora/code` sidecar | `feat/office-converter-client` | planned | **Dual-repo:** OSS profile `office` (dev + minimal + `deploy/compose.yaml`); platform PR turns the sidecar **on** (per-node, Centrifugo analog). Shared with the Collabora plan |
| A0-docs — LibreOffice/Collabora required for new office features | `feat/docs-office-libreoffice` in **`synaplan-docs`** | planned | New `docs/office-documents.md` + cross-links (quickstart, FAQ, architecture, using-synaplan, desktop-tools). Ships with A0 so operators see the requirement before the features land |
| A1 — Thumbnails for office docs and PDFs | `feat/office-thumbnails` | planned | Office half of #1499 |
| A2 — Download as PDF | `feat/office-pdf-export` | planned | New endpoint `GET /api/v1/files/{id}/export?format=pdf` |
| A3 — Inline preview modal | `feat/office-inline-preview` | planned | Frontend only, uses A2 |
| A4 — officemaker delivers PDF | `feat/officemaker-pdf` | planned | Includes the `GeneratedDocumentStore` refactor; snapshot re-record |
| A5 — DOCX default styling | `fix/docx-default-styles` | planned | #1396, independent of the engine |
| A6 — Analysable ingestion (legacy formats + structured text for xlsx/pptx) | `feat/office-ingestion-analysis` | planned | A6b needs no engine |
| A7 — Combine documents as one PDF | `feat/office-combine-pdf` | planned | `pdfunite` from poppler-utils; first merge feature |

## Phase B — structured editing and merge

| Slice | Branch | State | Notes |
| ----- | ------ | ----- | ----- |
| B1 — xlsx end to end (model, renderer, revisions, tools, Groq, loop) | — | planned | See `02_phase_b_structured_editing.md`; provenance columns decided before migration |
| B2 — more providers + `tools` model feature | — | planned | Shared with Collabora Epic 1.1 (`ToolCallingChatProviderInterface`) |
| B3 — docx | — | planned | |
| B4 — pptx | — | planned | |
| B5 — importers + fidelity report | — | planned | |
| B6 — version history UI + admin toggle | — | planned | |
| B7 — true merge on the model | — | planned | "Combine as DOCX/XLSX/PPTX" |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-02 | Office engine = Collabora CODE sidecar per web node, reached over HTTP (`OFFICE_CONVERT_URL`). Host apt LibreOffice on web1/2/3 is not used by the containers. See `00_master_plan.md` Decision 1. |
| 2026-09-02 | UX first (Phase A), then structured editing and merge (Phase B, xlsx-first). |
| 2026-09-02 | Everything that runs inside or provisions the Collabora editor moved to `../20260902-collabora-integration/` (its Epic 0 owns the Synaplan WOPI host). |
| 2026-09-02 | Tool calling (Phase T) is built first, before Phase A; it is the shared building block for the Collabora AI Assistant, OpenAI-SDK clients and Phase B. |
| 2026-09-02 | Dual capability gate: `ToolCallingChatProviderInterface` **and** catalog `tool_use`. Flags must be consistent; seeder + Galera-safe migration + `ModelCatalogToolUseConsistencyTest`. |
| 2026-09-02 | `/v1/chat/completions` offers Synaplan MCP + `web_search` on dual-gated models (hybrid loop). Client-owned tools are still relayed. Does not reuse `/v1/messages` MCP-off / "client must declare web_search" defaults. |
| 2026-09-02 | Public docs (`synaplan-docs`) must state that the new office capabilities need a LibreOffice engine — the Collabora CODE sidecar, not host `apt install libreoffice`, and not Desktop's local LibreOffice. Page + cross-links ship with A0. |
| 2026-09-02 | **Two delivery surfaces.** OSS (`synaplan`, including `deploy/compose.yaml` / Umbrel / AWS) keeps the engine optional (profile `office`, empty URL). Our hosted demo (`synaplan-platform` on web1/2/3) turns it **on** (per-node sidecar, Centrifugo analog — not shared like Tika or TTS). Swiss `ch1` is out of this epic. See `00_master_plan.md` "Two delivery surfaces". |

## Investigation baseline (2026-09-02)

- `officemaker` = prompt topic + `DocumentGeneratorService` (PhpOffice); PDF
  explicitly excluded in classifier and prompt; store logic duplicated in
  `ChatHandler` and `StreamController`.
- Thumbnails exist only for videos (`ThumbnailService` → `thumbPath` →
  `/files/{id}/thumb` → `FilePreview.vue`).
- Ingestion is Tika only; workbooks and decks arrive as one flat text blob.
- No repository references LibreOffice/Collabora; dev host and web nodes
  have `libreoffice-*` 24.2.7 (apt) installed but unreachable from the
  containers.
- External services are HTTP + env URL (`TIKA_BASE_URL`, `QDRANT_URL`,
  `SYNAPLAN_TTS_URL`); host services via `docker-host:host-gateway`.
- `poppler-utils` (`pdfunite`, `pdftoppm`), Imagick and ghostscript are in
  the base image.

## Current-state check-in (2026-09-02, before any product code)

Verified on disk. Nothing below has been implemented yet.

### `synaplan` (public OSS)

| Area | State |
| ---- | ----- |
| Converter / `OFFICE_CONVERT_URL` | Absent. `backend/.env.example` has Tika + `SYNAPLAN_TTS_URL=` only. |
| `OfficeConverterClient` / `Service/File/Office/` | Directory does not exist. Closest analog: `TikaClient.php`. |
| Compose `collabora` / profile `office` | Absent in `docker-compose.yml`, `docker-compose-minimal.yml`, `deploy/compose.yaml`, Umbrel. Closest analog: `tts` profile (dev + minimal only; **not** in `deploy/compose.yaml`). |
| `officemaker` | Prompt topic. `DocumentGeneratorService` renders DOCX/XLSX/PPTX (PhpOffice). PDF excluded in `MessageClassifier` (fast-path comment ~line 642) and in the multitask prompt (`Real PDFs are NOT supported`). `officeMakerPrompt()` lists csv/xlsx/docx/pptx/md/txt only. |
| Thumbnails | `ThumbnailService` is **video/ffmpeg only**. `FilePreview.vue`: image / video / audio / text snippet / icon. No office poster branch. |
| Runtime features | `whisperEnabled` etc. in `ConfigController`; no `officeConvertEnabled`. |
| Public docs | `synaplan-docs` has `tts.md` and Tika on the architecture map. No `office-documents.md`. |
| Self-host contract | `deploy/compose.yaml` is the portable production file (in-stack Tika, optional `local-ai`). Official self-host RAM floor is 8 GB — a default-on Collabora would break that. |

### `synaplan-platform` (our demo / prod)

| Area | State |
| ---- | ----- |
| Image | `ghcr.io/metadist/synaplan:latest` on backend / worker / worker-bulk. Schema lands on image roll (`updatewebN.sh`, one node at a time). |
| Per-node compose today | `synaplan-platform`, `synaplan-worker`, `synaplan-worker-bulk`, `synaplan-centrifugo`, plus `whisper-models`. Qdrant is a **separate** compose via `docker-host`. |
| Tika | Shared Coolify, `TIKA_URL=http://tika.synaplan.com` + basic auth. Not a sidecar. |
| TTS | Shared GPU box, `SYNAPLAN_TTS_URL` (empty default in compose, set in `.env`). |
| Collabora / `OFFICE_CONVERT_URL` | Absent. No service, no env, no `CLUSTER-DOC.md` row. |
| Uploads | NFS `./up/` on backend + both workers. Convert artefacts must land here. |
| Sizing | `docs/PHP-RUNTIME-SIZING.md` already treats the box as shared (Galera + Qdrant + workers). Collabora RAM is not in that budget yet. |
| Swiss demo (`ch1` / `synaplan-swiss`) | Separate stack, not this compose. Out of this epic. |
