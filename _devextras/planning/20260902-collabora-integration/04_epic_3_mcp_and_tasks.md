# Epic 3 — Collabora's MCP endpoint driven by Synaplan tasks and agents

Status: planned, scoped after a short verification spike
Depends on: Synaplan MCP connector layer (`20260821-mcp-oauth-connectors`),
Epic 0 for file access; independent of Epics 1–2

Collabora Online 26.04 exposes a **Model Context Protocol** endpoint so an
external AI client can invoke editor functions **without an open editing
session**. For Synaplan that is the server-side counterpart of Epic 2: saved
tasks, multitask plans and agents can open a document on Collabora, apply
changes with LibreOffice fidelity, and save — no browser involved.

## Step 3.1 — Verification spike (time-boxed)

Record in `STATUS.md`:

- Where the endpoint lives on CODE 26.04 (path, transport — HTTP/SSE or
  streamable HTTP), how it authenticates, and how a document is addressed
  (WOPI `WOPISrc` + access token, or a server-local path).
- The tool list it exposes per document type (text insert/replace, cells,
  slides, export) and whether it can open a WOPI document from **our** host
  (Epic 0 tokens) and save it back.
- Concurrency: does an MCP session share the DocumentBroker with a live
  editing session (i.e. edits appear to a user who has the file open)?

Go/no-go: proceed only if a document can be opened, changed and saved
through MCP with a token our WOPI host mints.

## Step 3.2 — Connector

- Register Collabora as an MCP connector in Synaplan's connector layer
  (system-level, admin-configured; URL is the node-local `collabora:9980`
  MCP path, never public). Auth is whatever the spike found; per-request
  document access uses an Epic 0 short-lived WOPI token for the target file,
  so the connector can never touch a file the user cannot.
- Expose a small, curated capability set to the planner rather than the raw
  tool list: `office.applyInstructions(fileId, instructions)`,
  `office.insertSection(fileId, afterHeading, markdown)`,
  `office.fillRange(fileId, sheet, range, rows)`, `office.addSlides(fileId,
  outline)`, `office.exportPdf(fileId)`. Each maps to one MCP tool sequence.

## Step 3.3 — Tasks and multitask

- `Capability::DocumentEdit` runner in the multitask executor that prefers
  the office-docs Phase B structured tools when the file has a model, and
  the Collabora MCP connector otherwise (uploaded files with formatting we
  must not lose).
- Saved task example to ship as a template: "Every Monday 08:00 update the
  KPI sheet in `<file>` from `<data source>` and export a PDF to the team
  channel" — ties together connectors (data), MCP (edit), engine (PDF) and
  channels (delivery).
- Provenance: a task-driven edit writes a revision with `source=binary`
  (office-docs Phase B rule) and refreshes thumbnail and PDF cache.

## Acceptance

A saved task edits a formatted uploaded `.xlsx` (styles, merged cells,
charts intact) through Collabora MCP, the file's thumbnail updates, and a
user who has the file open in the editor sees the change arrive.
