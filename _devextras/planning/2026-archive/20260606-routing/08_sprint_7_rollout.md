# Sprint 7: Rollout & GA

**Goal:** Safely enable the new routing engine in production with a clear rollback path.

## Technical Tasks
1. **Rollout Strategy (decided 2026-06-07):**
   - **Global default ON.** OSS repo, fresh installs, dev environments, and all **new** signups get the new routing automatically — new developers see the new setup instantly.
   - **Existing users get a switch.** The Sprint-0 grandfather migration already wrote a per-user `MULTITASK_ROUTING_ENABLED = off` row for every pre-existing user. Expose this as a user-facing/admin toggle so they can opt in when ready.
   - Verify on the live platform that the grandfather rows are present BEFORE the global default flips on, so no existing user is silently migrated.
2. **Fallback Maintenance:**
   - Ensure `MessageClassifier` / `MessageSorter` remain as the fallback path indefinitely (do NOT delete legacy path).
3. **Documentation:**
   - Document the rollback procedure (flipping the flag off).
   - Update `docs/` (routing architecture, planner prompt, capability catalog, `MIGRATIONS.md`).

## UI/UX Impact
- **Platform-wide Upgrade:** All users gain access to multi-task prompting.

## Release Gate (Success Test)
- [ ] Canary monitoring window shows no increase in error rates, latency SLO breaches, or provider cost anomalies.
- [ ] Documented one-switch rollback is verified in staging (flip flag -> behaviour reverts -> Golden Corpus matches Sprint 0).
- [ ] All documentation is up to date.
- [ ] E2E tests remain green.
