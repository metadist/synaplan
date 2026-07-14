# E2E Coverage Gaps

Living checklist of user-facing flows without E2E coverage, collected during
the e2e-suite refactoring (July 2026). Not every gap needs an E2E test —
prefer component/unit tests for UI details (see `E2E_TESTING.md` §8). Ordered
roughly by user impact.

## High value (core flows, breakage would hit many users)

- [ ] **Chat rename & delete via UI** — the chat manager row menu offers
  rename/delete; only Share is exercised (`chat-share.spec.ts`). Deleting the
  last chat has dedicated backend logic (auto-creates a new one).
- [ ] **Incognito chat** — "Start incognito chat" button, no persistence after
  ending the session, "New Chat" leaves incognito (`endSession` path in
  `stores/chats.ts`).
- [ ] **File management** — deletion, knowledge folders/groups (create,
  assign, chat scoped to a folder). Upload + RAG search is covered
  (`rag-search.spec.ts`), management is not.
- [ ] **Memories** — memory extraction, the memory list in settings, memory
  badges (`[Memory:ID]`) rendered in answers, feedback categories hidden from
  the list. Deterministic with TestProvider.
- [ ] **Task prompts: create + delete a custom prompt** — the editor is only
  asserted to be *enabled* (`task-prompts.spec.ts`); no save→reload→delete
  roundtrip.
- [ ] **Profile: password change** — full roundtrip (change, logout, login
  with new password) with a disposable provisioned user.

## Medium value

- [ ] **Guest → registered conversion** — guest banner signup navigation is
  covered; the actual conversion (guest chat history surviving registration,
  if supported) is not.
- [ ] **Subscription top-up flow** — checkout PRO and payment-failed banner
  are covered (`subscription.spec.ts`); top-ups (`BUSER_TOPUPS`) are not.
- [ ] **Admin panel** — only impersonation is covered
  (`admin-impersonation-chat.spec.ts`). User search/paging, subscriptions
  panel, registration chart, model toggles are untested.
- [ ] **Widget advanced features** — custom fields, human handoff (Slack),
  website crawl, AI Setup Assistant (`setup-chat`). Some have @noci stubs;
  none run in CI.
- [ ] **Inbound email handler routing** — CRUD and wizard are covered; the
  actual department-classification of an inbound mail is not.
- [ ] **Summarizer tool** — only the link navigation from the Tools dropdown
  is covered.
- [ ] **MCP server config UI** — no coverage.

## Known limitations / deliberate non-goals

- **Voice input / recording** — requires fake media streams; revisit if the
  recorder logic gains complexity.
- **OIDC** — covered by dedicated specs/jobs; do not extend as part of
  general suite work (separate ownership, logic must not change).
- **`oidc-token-exchange.spec.ts` cleanup** — users auto-created by token
  login are not deleted afterwards; harmless on ephemeral CI databases.
  Fix belongs to the OIDC owner.
- **`castingdata-plugin.spec.ts` (@plugin)** — needs the CastApp stack;
  violates several guidelines (waitForTimeout, networkidle, no config
  restore). Scheduled for a separate cleanup pass.
- **Non-deterministic providers** — anything requiring real AI keys stays
  @noci by design (`chat.spec.ts` all-models/vision/image/video suites).
