# Sprint 5: Cross-Channel Parity

**Goal:** Ensure multi-task results (text + multiple files) deliver correctly across all external channels (WhatsApp, Email, API, Widget).

## Technical Tasks
1. **Channel Adapters Update:**
   - Ensure `ResultAssembler` output maps correctly to:
     - **WhatsApp:** `sendMedia` per file.
     - **Email:** `sendAiResponseEmail` with multiple attachments.
     - **API:** JSON includes all file URLs.
     - **Widget:** SSE emits files correctly (though Widget remains single-task by invariant, it must handle the standardized output shape).
2. **Async/Enqueue Path:**
   - Confirm the async path either runs the executor and persists correctly, or is explicitly excluded for v1.

## UI/UX Impact
- **Omnichannel Multi-modal:** Users emailing or WhatsApping the platform can now trigger complex pipelines (e.g., send a voice note -> get a translated document back) and receive the correct attachments natively in their client.

## Release Gate (Success Test)
- [ ] **WhatsApp Test:** Inbound voice/doc -> text + MP3 back works in integration tests.
- [ ] **Email Test:** Doc attachment -> reply email with MP3 works in integration tests.
- [ ] API webhook returns structured multi-file result.
- [ ] Widget end-to-end unchanged (Sprint 2 invariant test passes).
- [ ] E2E tests remain green.
