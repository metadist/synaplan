# Phase B — Structured document editing

Status: planned, starts after Phase A (A0–A4 merged)
Detailed design: `office-plan_v2.md` (kept as written; this file only records
what changes in sequencing and scope)

## What stays from v2

Both load-bearing decisions stand:

1. **The document model is the truth, not the binary.** Versioned structured
   model (`Service/Document/Model/*`), deterministic renderers, tools patch
   the model and never the ZIP.
2. **A separate `ChatToolLoop` on `ChatProviderInterface`**, copying bounds
   and error behavior from `AI/Messages/Tools/GatewayToolLoop.php`
   (iteration and wall-clock limits, tool errors returned as `tool_result`,
   never thrown).

Also unchanged: Galera-safe migration for `BDOCUMENT_REVISIONS` (raw
`addSql`, `CREATE TABLE IF NOT EXISTS`, no `Schema $schema`), `BFILETEXT`
stays a text projection (search, digests, MCP resources depend on it),
`DOCUMENT_TOOLS.*` BCONFIG flags default off, admin toggle, mobile-impact
policy, docs.

## What changes

### B-1. Ship one format end to end before widening

v2 Sprints 0–4 build model + persistence + tools + provider tool-calling +
loop for **all three** formats before the first user-visible turn. Instead:

| Slice | Scope | User-visible result |
| ----- | ----- | ------------------- |
| B1 | `SpreadsheetModel` + `XlsxRenderer` (formulas, number formats, 2 sheets, chart) + `DocumentRevision` persistence + xlsx tool set + `ToolCallingChatProviderInterface` for **one** provider (Groq, simplest wire format) + `ChatToolLoop` + minimal SSE `document_step` | "Format column D as currency and add a bar chart" works on a generated xlsx, with a step list in the chat bubble |
| B2 | Second and third provider (OpenAI Responses API, Anthropic), `tools` model feature in `ModelCatalog`, fallback to the classic officemaker path | Works with the user's configured model, not only Groq |
| B3 | `WordModel` + `DocxRenderer` (reuse A5's style sheet) + docx tools | Word documents editable step by step |
| B4 | `DeckModel` adapter on `Presentation/PptxRenderer` + pptx tools | Presentations editable step by step |
| B5 | Importers (`IOFactory::load()` with charts for xlsx; best-effort docx/pptx) + `ImportFidelityReport` + classifier guard for "edit this uploaded file" | Uploaded files editable, with an honest fidelity notice |
| B6 | Version history UI, `GET /files/{id}/revisions`, `POST .../restore`, i18n, admin toggle, docs | Users can see and roll back versions |
| B7 | **True merge** on the model: `merge_documents` tool (concatenate Word blocks with heading level offset, append sheets with name de-duplication, append slides with theme of the first deck) built on B5 importers; UI action "Combine as DOCX/XLSX/PPTX" next to A7's "Combine as PDF"; chat intent via the `DocumentEdit` capability | Several documents become one editable document, not just one PDF |

### B-2. The engine replaces bespoke diff UI

Every revision render is exported to PDF through the Phase A engine and
shown in the A3 preview modal. "What did the AI change" is answered by the
step list (`document_step` labels) plus the rendered preview — v2's
change-summary block stays, the richer per-change UI is dropped.

### B-3. Revision provenance (decide before the migration)

The Collabora integration plan (Epic 0) lets users edit the same file in the
Collabora editor, and Epic 3 lets tasks edit it through MCP — both make the
stored model stale. Add to `BDOCUMENT_REVISIONS` from day one:

- `BSOURCE` enum `model` | `binary` — who authored this revision.
- `BBINARYSHA` — hash of the on-disk file when the revision was written.

Rule: before any tool turn, compare the current binary hash with the latest
revision. If they differ (edited in Collabora, re-uploaded, or changed by a
task), run the B5 importer to resync (or refuse with a clear message until
B5 exists). Without this the tool loop would silently overwrite manual
edits.

### B-4a. Shared building block with the Collabora plan

`ToolCallingChatProviderInterface` (v2 Sprint 3, here B1/B2) is also what
`../20260902-collabora-integration/02_epic_1_ai_assistant_provider.md`
Step 1.1 needs to make `/v1/chat/completions` tool-calling transparent.
Build it once; whichever plan starts first owns the PR and both `STATUS.md`
files record it.

### B-4b. Thumbnails and exports follow revisions

After each revision render, dispatch `GenerateDocumentThumbnailMessage`
(A1) and invalidate the cached PDF export (A2) so previews never lag behind
the file.

## Risks (from v2, still valid)

- Cost and latency of multi-round tool turns — bounds low, `read_*` returns
  ranges not whole sheets.
- Models without tool calling (Ollama, OpenAI-compatible endpoints) fall back
  to the classic path; the classic path is never removed.
- `phpoffice/phppresentation` is pinned to `dev-master as 1.3.0` — render
  through the existing classes, add no new reader paths.
- Two truths while the flag is off; the file UI must handle "no history".
