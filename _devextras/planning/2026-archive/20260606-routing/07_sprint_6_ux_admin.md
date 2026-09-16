# Sprint 6: UX, Observability & Admin Clarity

**Goal:** Make the new routing model legible to users and administrators, resolving the original "confusing models" complaint.

## Technical Tasks
1. **Frontend Progress UI:**
   - Update the chat UI to show the task plan as discrete progress steps in the chat turn.
   - Ensure it collapses to the normal single-bubble UX for single-task turns (no UX change for simple chats).
   - Add i18n strings to both `en.json` and `de.json`.
2. **Admin Task Plan View:**
   - Build a read-only "task plan" view in the Admin panel for debugging a message (reading from `BMESSAGE_TASKS`).
3. **Admin Models Config UI:**
   - Clarify the models config UI copy so "model purpose" (capability) and "task assignment" are presented as the same concept.
4. **Metrics:**
   - Log per-capability task counts, plan size distribution, planner latency, and failure rates.

## UI/UX Impact
- **Transparency:** Users understand *what* the AI is doing during complex requests.
- **Admin Clarity:** Administrators can easily see how a prompt was routed and which models were used for which steps.

## Release Gate (Success Test)
- [ ] Frontend tests and `vue-tsc` are green.
- [ ] i18n parity between en/de is verified.
- [ ] Simple-chat UX is visually unchanged (verified via snapshot/E2E).
- [ ] Admin plan view successfully renders persisted `BMESSAGE_TASKS`.
- [ ] Metrics are emitted and asserted in a smoke test.
- [ ] E2E tests remain green.
