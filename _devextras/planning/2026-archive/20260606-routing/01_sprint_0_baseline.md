# Sprint 0: Foundations & Behavioural Baseline

**Goal:** Lock down current behaviour so we can mathematically prove "no regression" in later sprints.

## Technical Tasks
1. **Feature Flags:** Add three new configuration flags to `BCONFIG` (group `MULTITASK`), resolved user-scope → global → built-in default (mirroring `ModelConfigService`):
   - `MULTITASK_ROUTING_ENABLED` — built-in/global default **ON** (OSS, fresh installs, dev, and new signups get the new routing). Shadow/Sprint-0 keep it effectively inert because the executor isn't wired yet.
   - `MULTITASK_SHADOW_MODE` — default `off`.
   - `MULTITASK_PARALLEL_ENABLED` — default `off`.
   - **Grandfather migration (live-platform safety):** a one-time, idempotent data migration writes an explicit per-user `MULTITASK_ROUTING_ENABLED = off` row for every **existing** user id, so current users are not flipped and keep a switch they control. New users have no per-user row and inherit the global ON. Fresh/OSS installs have no existing users → everyone on.
   - Confirm `getEffectiveUserIdForMessage` is the user-id used for ALL flag + model resolution (email/WhatsApp remapping parity).
2. **Golden Corpus:** Create a test corpus of ~40 representative inbound messages covering:
   - General chat
   - Slash commands (`/pic`, `/vid`, `/tts`)
   - Document upload & Image upload (analyse vs edit)
   - Web-search questions
   - Officemaker & RAG
   - Various origins (WhatsApp, Email, Widget, Web SSE)
3. **Characterization Test Harness:**
   - Build a test harness that replays the Golden Corpus through `MessageProcessor` using a `TestProvider` (mocked LLM).
   - Snapshot the resulting `{topic, intent, model, response shape, attachments}`.

## UI/UX Impact
- **None.** This sprint is purely backend testing infrastructure and feature flag plumbing.

## Release Gate (Success Test)
- [ ] Full existing test suite is green.
- [ ] Frontend E2E tests are green.
- [ ] New characterization snapshots are committed and stable across 3 consecutive runs.
- [ ] Toggling the new feature flags changes absolutely nothing in the system yet (proven by tests) — the executor is not wired, so ON/OFF is inert in Sprint 0.
- [ ] Grandfather migration is idempotent (re-running it inserts no duplicate rows) and writes one `off` row per existing user.
- [ ] Corpus explicitly covers the four migration-risk areas: a per-user custom `BPROMPTS` topic, a doc/audio attachment (`analyzefile`), a `mediamaker` request with resolution/duration, and an email/WhatsApp message that triggers user-id remapping.
