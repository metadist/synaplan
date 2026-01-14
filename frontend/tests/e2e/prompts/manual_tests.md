# Manual E2E test cases

Tag legend: `[E2E]` browser/UI end-to-end, `[API]` backend/contract, `[Manual]` stays manual/edge.

## Smoke (minimal, release blockers)

- **Auth & session**
  - Accounts (local): BUSINESS `admin@synaplan.com/admin123`, PRO `demo@synaplan.com/demo123`, NEW `test@example.com/test123`.
  - [ ] [E2E] Login per role → BUSINESS/PRO/NEW can sign in.
  - [ ] [E2E] Logout → after logout, protected route returns redirect/401.
  - [ ] [E2E] Logout hard check → browser back/refresh on prior protected page shows login/401 with no stale content.
  - [ ] [E2E] Protected route guard while logged out → deep link redirects to login.

- **Core data & retrieval**
  - [ ] [E2E] Upload small text/PDF fixture → job reaches vectorized status.
  - [ ] [E2E] Semantic search after upload → uploaded file appears with correct source name.

- **Chat basics**
  - [ ] [E2E] Chat without docs → returns assistant response.
  - [ ] [E2E] Chat with uploaded doc context → answer includes citation/source = uploaded filename.

- **Widget entry**
  - [ ] [E2E] Load `widget.js` on different origin → widget button renders via auto-detected API URL.
  - [ ] [E2E] Widget messaging → send and receive one message.
  - [ ] [E2E][SECURITY] Widget ID isolation → wrong widgetId rejected; no data leakage.

- **API sanity**
  - [ ] [API] GET /api/health → returns 200 with expected environment/build info.

## Core regression (deterministic)

- **Auth & session**
  - [ ] [Manual] Deleted user access → login blocked; direct/internal links rejected with redirect/401.
  - [ ] [E2E] Easy vs advanced toggle → visible; switching changes UI/actions; refresh keeps choice.
  - [ ] [E2E] Invalid credentials → generic error (no user leak); lockout/rate-limit message if enabled.
  - [ ] [E2E] Password reset → request succeeds for known email; reset link works once; new password works, old fails.
  - [ ] [E2E] Role boundaries → PRO denied from BUSINESS-only areas with clear message.

- **Workspace defaults**
  - [ ] [E2E] First login (no data) → Chat empty history; Files & RAG empty with upload CTA; Widgets empty/wizard.
  - [ ] [E2E] Language toggle → globe switch updates UI strings (EN/DE/TR) and persists after reload.
  - [ ] [E2E] Mode defaults → easy/advanced toggle state persists after reload; sidebar/nav adjust.
  - [ ] [E2E] Quick-start → use primary CTA to start chat or upload; sidebar/chat list updates immediately.

- **Ingestion & search**
  - [ ] [E2E] Unsupported/bad file → friendly error, no stuck job; retry succeeds.
  - [ ] [E2E] Upload blocked path → too-large/forbidden type returns clear error; UI recovers (no stuck status).
  - [ ] [E2E] Progress visibility (small file) → status goes uploaded → extracted → vectorized; refresh keeps status; spinner on long-running step.
  - [ ] [E2E] Mixed batch upload (small fixtures) → each file shows its own status; one failure does not block others.
  - [ ] [Manual][SECURITY] Vectorized file isolation → only uploader can search/see results for their file; other users get no access/citations.
  - [ ] [E2E] Filters/thresholds → adjustments change results; reset restores defaults.
  - [ ] [E2E] Group filter → add group keyword; search before/after; compare with/without group key filter.
  - [ ] [Manual] Performance → large set returns acceptable latency or shows loading feedback.
  - [ ] [E2E] Exact vs semantic queries → keyword-like vs paraphrased query both return relevant docs.

- **Chat & context**
  - [ ] [E2E] Mode interplay → easy vs advanced keeps chat functional; streaming/typing indicators work.
  - [ ] [E2E] Context scoping → selecting files/RAG context scopes responses; clearing restores default.

- **Task prompts**
  - [ ] [E2E] Create custom task prompt → visible in list; changes persist.
  - [ ] [E2E] Attach files to task prompt → upload/link succeeds; files listed.
  - [ ] [E2E] Remove attached file → deletion unlinks RAG chunks; list updates.
  - [ ] [E2E] Use task prompt in web chat → selecting applies instructions; responses reflect persona/context.
  - [ ] [E2E] Task prompt RAG → attached files are used for retrieval (citations/snippets from prompt files).

- **Widget flows**
  - [ ] [E2E] Lazy load → button click loads chat; network shows chunked/dynamic imports.
  - [ ] [E2E] Widget task prompt → configured prompt applies instructions and RAG context; system prompts unavailable.
  - [ ] [E2E] Widget prompt RAG → ask question answerable only from prompt-attached file; expect cited snippet; after removing file, no stale citation.
  - [ ] [E2E] Widget file upload → upload from widget succeeds; per-file status shown; respects type/size rules; server receives file.
  - [ ] [E2E] Widget file RAG → after widget upload, ask question using uploaded file; response cites that file (or lists it in sources).
  - [ ] [E2E] Widget upload errors → unsupported/too-large file produces clear error; other widget actions still work.

- **File sharing & permissions**
  - [ ] [API] Toggle public/private with optional expiry → link generated when public; expiry enforced.
  - [ ] [API] Access checks → anonymous vs logged-in behave per setting; private prompts auth/deny.
  - [ ] [API] Revoke link → revoked link fails immediately; UI updates.
  - [ ] [API] Download/view → permitted users can view/download; audit/history (if shown) records events.
  - [ ] [API] Link safety → share text uses correct base URL; no extra data leaked in query params.

- **Subscription limits**
  - [ ] [API] Observe quota indicators per tier (BUSINESS/PRO/NEW) in usage stats/config; counters update with actions.

- **Administration/monitoring**
  - [ ] [API] AI Config → Feature/health/status views show backend/provider/Whisper availability; errors surfaced with actionable messaging.

## Integration smoke (provider/model dependent)

- **Ingestion & processing**
  - [ ] [Manual] Image with text → OCR output displayed and searchable; preview accessible.
  - [ ] [Manual] Audio (with speech) → FFmpeg convert + Whisper transcript; vectors created; transcript searchable.
  - [ ] [Manual] Searchability → OCR/transcript content appears in search/chat retrieval.
  - [ ] [Manual] Upload limits → hitting file size quotas shows clear error; app stays responsive.
  - [ ] [Manual] Failure handling → Whisper disabled/unavailable shows clear message; other jobs unaffected; retry after enabling works.

- **Document summaries (LLM-dependent)**
  - [ ] [Manual] Generate summary for a processed doc → expected type/length returned.
  - [ ] [Manual] Output language → selecting different output language reflects in summary; clear error if unavailable.
  - [ ] [Manual] Focus areas → chosen focus changes summary emphasis.
  - [ ] [API] Metadata → shows model/provider, lengths, compression ratio.
  - [ ] [Manual] Generated audio cleanup → generate TTS/audio, delete file, then play in chat → clear handling (no silent failure).

- **Chat & models**
  - [ ] [Manual] Provider switch (two providers) → switching succeeds; failures surface actionable error.
  - [ ] [API] Chat models → switch across configured providers/models and confirm responses stream; fallback/error clear when provider unavailable.
  - [ ] [API] Embeddings/vectorization → vector model runs during ingestion; errors surfaced if embedding model unavailable.
  - [ ] [API] Default model config → selecting defaults per purpose (sort/chat/vectorize/pic2text/text2pic/text2vid/sound2text/text2sound/analyze) persists and drives subsequent tasks.
  - [ ] [API] Model availability → disabled/unavailable models are blocked in selectors; selecting unavailable model shows clear error and reverts.

- **Subscription limits (runtime)**
  - [ ] [Manual] Hit limits via web chat/upload → user-friendly over-limit message; no hidden failures; no partial side effects (no jobs or provider calls when rejected).

- **Widget security/behavior**
  - [ ] [Manual] Widget prompt behavior → greeting matches prompt persona/tone (no default assistant voice).
  - [ ] [Manual] Domain whitelist → allowed domain works; disallowed domain blocked with clear error.

- **Resilience & recovery**
  - [ ] [Manual] AI provider unavailable → clear error, retry option; other features unaffected where possible.
  - [ ] [Manual] Timeouts/loading → spinners or banners appear; no silent failures; retry succeeds when service returns.
  - [ ] [Manual] Session refresh failure handling → set expired/invalid refresh token; next protected call forces login, clears state/cookies (no deadlocks).

- **Recurring regression checks**
  - [ ] [Manual] Models list (soon replaced) → switching filters (All Models/Message Sorting/purpose buttons) does not duplicate entries; list fully refreshes and de-dupes.
  - [ ] [Manual] Model ratings UI → rating column clear (no unexplained raw numbers); header sorting works and stays in sync with sort controls.
  - [ ] [Manual] Image generation “Again” flow → new images show cleanly; no translucent/bleed-through overlays when opening earlier images.
  - [ ] [E2E] File upload UX → per-file progress/percentage (and ETA if available) shows during uploads; long uploads visibly progress.
  - [ ] [Manual] Email channel (live) → sending to smart@ and smart+keyword@ creates/updates thread and returns email response; no silent drops.

## Extended / Edge (periodic)

- **Document ingestion**
  - [ ] [Manual] Audio (music/no speech) → handled gracefully; clear message if no transcript.
  - [ ] [Manual] Video with audio → ingest succeeds; audio track transcribed; text searchable/visible.
  - [ ] [Manual] Video without audio → handled gracefully; message about missing audio; no crash.
  - [ ] [Manual] Post-completion metadata → file type/size/processing level correct; vectorized files marked as such.
  - [ ] [Manual] Large file handling → oversized/long documents rejected with clear message; app remains responsive.

- **RAG search**
  - [ ] [Manual] Multi-language content → searching in doc language returns those docs; cross-language behavior predictable.

- **Chat with knowledge**
  - [ ] [Manual] Deep context scoping edge cases; follow-ups reuse context after long pauses.

- **File sharing & permissions**
  - [ ] [Manual] Audit/history visibility (if available); expiry edge cases.

- **Subscription limits**
  - [ ] [Manual] Cross-channel enforcement → same user via widget/WhatsApp/email hits shared limits; limits decrement consistently.
  - [ ] [Manual] Elevated admin with “highest plan possible” → upload cap exceeds 100MB; Files & RAG accept >100MB and UI reflects limit.
  - [ ] [Manual] Tier boundary/concurrent limit edge cases across channels.

- **Chat widget/embed**
  - [ ] [Manual] Prompt change propagation → edit prompt instructions, reload widget, responses reflect new instructions (no caching of old behavior).
  - [ ] [Manual] Prompt absence/failure → if prompt removed/unpublished, widget shows clear error or disabled state (no silent fallback).
  - [ ] [Manual] Widget prompt caching/staleness edge cases.

- **Model coverage**
  - [ ] [Manual] TTS → request text-to-speech, audio returned/plays; failure handled gracefully.
  - [ ] [Manual] STT → speech-to-text input accepted, transcription returned; errors surfaced if model missing.
  - [ ] [Manual] Image tasks (if enabled) → image generation/vision calls return expected output or clear unsupported message.
  - [ ] [Manual] Video tasks (if enabled) → upload or generation flows work; otherwise show clear unsupported message.
  - [ ] [API] Locked system models → system-locked rows can’t be changed; UI communicates clearly.
  - [ ] [API] Admin adds new model → appears in AI Models list, selectable in default config dropdowns, and is used in chat/RAG when chosen.
  - [ ] [Manual] Unavailable model error paths; on-demand model download UX.

- **Cross-channel continuity**
  - [ ] [Manual] Start chat on web, continue on WhatsApp/email → conversation history stays unified.
  - [ ] [Manual] Rate limits unified across channels; context reuse after channel switch mid-thread.

- **WhatsApp flow**
  - [ ] [Manual] Phone verification → code send/confirm works; verified status reflected; unverified limits enforced before verification.
  - [ ] [Manual] Send/receive text and media → inbound/outbound messages succeed; media handled per limits.
  - [ ] [Manual] Opt-out/unlink → unlinking succeeds; further messages blocked or treated as anonymous per policy.
  - [ ] [Manual] Admin → Created date renders correctly (no “Invalid Date”); WhatsApp-created users show masked identifiers (no opaque hashes).
  - [ ] [Manual] Limits/state → after verifying then removing phone, limit messaging reflects current state (no stale “message limit reached”).
  - [ ] [Manual] Inbound media edge formats handled.

- **Email flow**
  - [ ] [Manual] Send to `smart@` and `smart+keyword@` → thread created/updated; reply returned.
  - [ ] [Manual] Unknown sender → anonymous limits applied; spam/blacklist after 10/hr enforced with clear response.
  - [ ] [Manual] System reply behavior (if configured) → response delivered and formatted correctly.
  - [ ] [Manual] Registered vs unknown → registered email uses user limits/profile; unknown creates anonymous user with ANONYMOUS limits.
  - [ ] [Manual] Invalid destination → email to other addresses ignored/errored gracefully (no crash).
  - [ ] [Manual] Department routing → inbound handler with multiple departments routes correctly; logged routing decision visible.
  - [ ] [Manual] Default department fallback → when AI returns invalid/unmatched department, default department is used; no drop.
  - [ ] [Manual] Attachment handling coverage.

- **API keys & webhooks**
  - [ ] [API] Create API key with scopes → key issued once; scope visible.
  - [ ] [API] Use key to call email/whatsapp/generic webhook → message processed; missing/invalid scopes blocked with clear error.
  - [ ] [API] Revoke/rotate → old key stops working immediately; new key works; audit/logs updated if available.

- **Resilience & recovery**
  - [ ] [Manual] Background model download/on-demand pull → user-facing progress shown; first chat/search waits gracefully.
  - [ ] [Manual] Long-running jobs with retry; partial outages do not cascade to other features.
