# Chat Session File Context — Master Plan

Status: planned (see `STATUS.md`)
Date: 2026-08-27

## Goal

A chat must **keep its session files handy** and send them **with** related
requests. Today the conversation "loses" files: a user asks for an image, then
asks to change a color — and gets a completely new, different image. The same
class of problem threatens every generated artifact (Word docs work partially,
images not at all, vision questions about generated media never work).

The fix is one shared concept — a **conversation file catalog** — plus routing
awareness so the classifier/sorter know the thread has files, and handler
plumbing so the right file is actually passed to the model:

1. **Conversation file catalog**: a single service that resolves every file of
   a thread (user uploads AND generated artifacts) with type, origin, and
   recency — generalizing the proven `DocumentImageCatalog` pattern (#1382).
2. **Routing awareness**: the fast-path and the AI sorter must know that the
   thread contains files, so a follow-up edit is routed to `mediamaker` /
   `officemaker` instead of `general`, and `BINPUTMODE` reflects cross-turn
   references, not just current attachments.
3. **Cross-turn image edit**: `MediaGenerationHandler` resolves the referenced
   image from the catalog and runs a real **PIC2PIC** edit instead of a fresh
   TEXT2PIC generation.
4. **Parity**: documents stay green, and (optionally, flag-gated) vision models
   can see generated images in history.

## Acceptance use cases (must pass at the end)

> **A.** "Create an image of a red sports car" → *image generated* → "now make
> the car blue". The result MUST be an edit of the same image (same
> composition, blue car), produced via the PIC2PIC model with the previously
> generated file as reference — on web SSE and on the enqueue/channel path.

> **B.** "Write a Word doc about X" → *docx generated* → "add a summary
> section". The follow-up MUST keep editing the same document (this works
> today via `officemaker` — it must not regress).

> **C.** "Create an image of a cat" → "what breed does the cat in the image
> look like?" — with a vision-capable chat model and the parity flag on, the
> assistant MUST answer from the actual generated pixels (Sprint 4, flag-gated).

## Current state (investigated 2026-08-27)

### The pipeline

`StreamController` → `MessageProcessor::processStream()` (history: last 15
messages / 15k chars, `HISTORY_MAX_*`) → `MessageClassifier::classify()`
(fast-path or `MessageSorter` with `tools:sort`) → `InferenceRouter` /
`TaskPlanExecutor` → handler (`ChatHandler`, `MediaGenerationHandler`, …).

### Gap 1 — image follow-ups never see the previous image (the reported bug)

`backend/src/Service/Message/Handler/MediaGenerationHandler.php`:

- `handleStream()` ~163–169: pic2pic is decided **only** from the current
  message's attachments plus `options['reference_image_paths']` (multitask
  chains, #1144):

  ```php
  $referenceImagePaths = is_array($options['reference_image_paths'] ?? null) ? ... : [];
  $attachedImagePaths = $this->collectAttachedImagePaths($message, $referenceImagePaths);
  $isPic2Pic = !empty($attachedImagePaths);
  ```

- `collectAttachedImagePaths()` ~1308: iterates `$message->getFiles()` and the
  legacy `$message->getFilePath()` of the **current** message only. `$thread`
  is available in the handler but used solely for memory extraction and prompt
  enhancement — never to resolve prior images.

Result: any text-only follow-up → `$isPic2Pic === false` → fresh TEXT2PIC.

### Gap 2 — routing is blind to session files

- **History is text-only.** `MessageSorter` (~192–212) renders history turns as
  strings; a generated image shows up as prose, and OUT turns are clipped to
  200 chars. The sorter cannot reliably know "the assistant just made an
  image".
- **`BINPUTMODE` dead ends.** The `tools:sort` prompt (rule 9,
  `PromptCatalog` ~412) sets `reference_images` only "if the user attached
  image(s)". `MessageSorter::parseResponse()` (~436–443) parses it, but
  `MessageClassifier::classify()` passthrough stops at `resolution`
  (~311–317) — `input_mode` is dropped, and `MediaGenerationHandler` never
  reads it anyway.
- **Fast-path asymmetry.** `canFastPathClassify()` defers when the last
  assistant turn generated a *document* (`lastAssistantGeneratedFile()`,
  keyed on the `__FILE_GENERATED__:` text marker, ~798–810). Generated images
  never carry that marker — a short "make it blue" misses every media trigger
  substring and can be fast-pathed straight to `general` chat.

### Gap 3 — generated files are second-class in storage relations

Two parallel channels exist for message ↔ file:

| Concept | Storage |
| ------- | ------- |
| User uploads on a message | `BFILES` + junction `BMESSAGE_FILE_ATTACHMENTS` (`Message::$files` ManyToMany) |
| Generated media | `BMESSAGES.BFILE/BFILEPATH/BFILETYPE` (legacy channel) + `BFILES` row with `source=generated`, linked only via `BFILES.BMESSAGEID` (`GeneratedFileRegistrar`) |

`FileRepository::findImagesByMessageIds()` exists precisely because "a picture
created earlier in the conversation is invisible to the message relation"
(#1382) — but only `DocumentImageCatalog` (officemaker embedding) uses it.

### What already works (patterns to reuse, not reinvent)

| Mechanism | Where | Reused by this plan |
| --------- | ----- | ------------------- |
| Document follow-up routing (#1042) | `MessageClassifier` fast-path guards + `officemaker` prompt | Sprint 2 mirrors the guard for images |
| Thread image resolution (#1382) | `DocumentImageCatalog` + `FileRepository::findImagesByMessageIds()` | Sprint 1 generalizes it |
| Same-turn reference images (#1144) | `options['reference_image_paths']` → `MediaGenerationHandler` | Sprint 3 feeds the same option from history |
| Document content re-injection | `ChatHandler` `getAllFilesText()` "Current content of the file you previously generated" | Sprint 4 keeps/hardens |

## Target architecture

```
IN message + thread (last 15 msgs)
        │
        ▼
MessageClassifier
  ├─ fast-path: defers when thread has generated media + edit phrasing  [S2]
  └─ MessageSorter: history lines annotated with file inventory          [S2]
       └─ tools:sort rule: cross-turn edit → BINPUTMODE=reference_images [S2]
        │   (input_mode propagated through classification)               [S2]
        ▼
InferenceRouter / TaskPlanExecutor
        │
        ▼
ConversationFileCatalog  [NEW, S1]  ← BMESSAGE_FILE_ATTACHMENTS
  one service, all types            ← BFILES.BMESSAGEID (source=generated)
  origin/type/recency metadata      ← legacy BMESSAGES.BFILEPATH fallback
        │
        ├─ MediaGenerationHandler: no current attachment + edit intent
        │     → newest thread image → reference_image_paths → PIC2PIC    [S3]
        ├─ DocumentImageCatalog: refactored onto the shared catalog      [S4]
        └─ ChatHandler: generated images as vision content (flag-gated)  [S4]

Frontend: "editing <file>" indicator, quote-image-as-reference, i18n     [S5]
```

## Sprints

Each sprint is a separate feature branch + PR and ends with the full unfiltered
pre-commit gate (`make lint && make -C backend phpstan && make test`, plus
frontend checks when frontend files change). No sprint is "done" without its
tests.

| # | Sprint | Doc |
| - | ------ | --- |
| 1 | Conversation file catalog (service + repository, additive) | `01_sprint_1_conversation_file_catalog.md` |
| 2 | Routing awareness: sorter context, fast-path guard, BINPUTMODE propagation | `02_sprint_2_routing_awareness.md` |
| 3 | Cross-turn image edit: pic2pic from history | `03_sprint_3_cross_turn_image_edit.md` |
| 4 | Documents & vision parity | `04_sprint_4_documents_and_vision_parity.md` |
| 5 | Frontend affordances, E2E, docs | `05_sprint_5_frontend_and_hardening.md` |

Order rationale: the catalog (S1) is pure additive infrastructure every later
sprint consumes. Routing (S2) must land before the handler fix (S3) so an edit
request actually *reaches* `MediaGenerationHandler` with the right signal — S3
without S2 would fix pic2pic only for requests that already route correctly.
S4 and S5 are separable polish/parity and must not block the headline fix.

## Key design decisions

1. **Backend-first, additive, default-safe.** Resolution happens server-side;
   no API break; the frontend work is an enhancement, not a prerequisite. The
   fix therefore applies to every channel (web, widget, WhatsApp, email, MCP)
   that flows through `MessageProcessor`.
2. **Reuse the document pattern.** #1042/#1382 solved the same problem for
   docs; images get the mirror image of those guards and catalogs — no second
   mechanism, no new marker format.
3. **Selection rule** (deterministic, explainable): a file attached to the
   current message always wins; otherwise the newest image of the thread is
   the edit target. Model-chosen targets ("the FIRST image") are a follow-up —
   see Sprint 3 "out of scope".
4. **No new tables.** `BFILES`, `BMESSAGE_FILE_ATTACHMENTS`, and the legacy
   message columns already carry everything; the catalog is a read-side
   service. No migration expected in Sprints 1–3 (prompt seed update in S2
   follows the usual seeder + explicit-UPDATE-migration rule).

## Global constraints

- **No hardcoded model names** — PIC2PIC/TEXT2PIC/vision resolve via
  `ModelConfigService` / `ModelRepository` as today. If no PIC2PIC model is
  configured, fall back to today's behavior (fresh generation) with a log line,
  never an error.
- **Internal prompts use the `tools:` prefix**; the `tools:sort` prompt change
  ships through `PromptCatalog` seeding. `BCONFIG`/prompt defaults are
  bootstrap-only — existing installs get the updated sorter prompt via the
  established seeder-update path, and any flag default that must change on
  existing installs ships an explicit UPDATE migration.
- **Characterization snapshots**: Sprints 2 and 3 WILL drift
  `tests/Characterization/RoutingCharacterizationTest.php` — re-record with
  `UPDATE_ROUTING_SNAPSHOTS=1` and review every changed line; never silently
  re-record.
- **Size discipline**: the sorter file-inventory annotation is one clipped
  line per turn; the vision parity feature (S4) is capped (max images, byte
  budget) and OFF by default.
- **Widget parity**: widget sessions flow through the same
  `processStream()` — the fix applies, but widget-specific UI work is out of
  scope for S5.
- **Mobile impact**: backend sprints are `backend-only`; Sprint 5 frontend
  work is `ota-candidate`. Classify new paths in
  `.github/mobile-impact-policy.json` in the same PR when needed.
- **Incognito/transient messages**: the catalog reads the in-memory thread
  where persisted IDs are missing; never assume a `BID` exists.
