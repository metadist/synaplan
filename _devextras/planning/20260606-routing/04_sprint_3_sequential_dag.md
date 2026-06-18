# Sprint 3: Multi-Task DAG (Sequential)

**Goal:** Deliver the headline feature end-to-end on web chat (e.g., Doc -> Summary -> MP3), executing sequentially.

## Technical Tasks
1. **Remove Single-Node Constraint:**
   - Allow the planner to emit multi-node DAGs.
2. **Dependency Resolution:**
   - Implement logic to inject node outputs (e.g., `$nX.text`, `$nX.file`) into dependent node inputs.
   - Update `ResultAssembler` (`compose_reply`) to assemble text + N file attachments into one OUT message.
3. **Status Callbacks:**
   - Emit per-node progress through the existing SSE status channel.
4. **Failure Handling:**
   - Isolate node failures. If a node fails, `compose_reply` returns best-effort text + error note, or falls back to legacy if the whole plan fails.

## UI/UX Impact
- **Web Chat Progress:** Users will see sequential progress updates (e.g., "Extracting...", "Summarising...", "Generating audio...").
- **Multi-modal Output:** A single chat bubble can now contain text and multiple attachments (e.g., an MP3 file) natively generated in one prompt.

## Release Gate (Success Test)
- [ ] **Acceptance Scenario:** Upload a `.docx` + "summarise into a short mp3" yields one OUT message with summary text AND a playable MP3 attachment.
- [ ] Intermediate status events are successfully observed in the SSE stream.
- [ ] DAG executor tests pass (chains, branches, missing-dependency rejection, cyclic rejection).
- [ ] Sprint 2 single-task equivalence still holds for the Golden Corpus.
- [ ] E2E tests remain green.
