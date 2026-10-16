Title: Media: image and audio renders longer than 90 s are timed out while still rendering

**Kind:** Needs planning — do not implement before the open decision below is answered.

## Problem
A synchronous image or audio render that takes longer than the media heartbeat window (90 s by default) is marked `timed_out` by `app:media:reap-jobs` while the worker is still waiting for the provider.

---

## Expected
- A synchronous render is never timed out while its worker is alive and the job is before its deadline (`MediaJobService` image deadline 240 s).
- A job the reaper already moved to a terminal state is not silently flipped to `completed` later; the chat shows one consistent outcome.

## Actual
- `AdvanceMediaJobCommandHandler::generateSync()` calls `MediaJobService::markSubmitting()` once and then blocks in `$this->syncGenerator->generate($job)`. Nothing refreshes the heartbeat during that call.
- The heartbeat is the job's `updated` timestamp, written as the `ACTIVE_ZSET` score in `MediaJobStore::save()`. Only `MediaJobService::updateProgress()` and `MediaJobService::heartbeat()` refresh it.
- `MediaJobReaper::reap()` selects jobs whose score is older than `now - MediaJobConfig::heartbeatStaleSeconds()` (`DEFAULT_HEARTBEAT_STALE_SECONDS = 90`). It cancels the provider operation and calls `markTimedOut()` with "Render worker stopped responding".
- When `generate()` returns afterwards, `MediaJobService::markCompleted()` overwrites the status with `completed`. It has no terminal-state guard.
- The reaper does not consult the worker's advance lock `media-job-advance.{jobKey}`.

Video jobs are not affected: they poll about every 3 s and refresh the heartbeat through `updateProgress()`.

---

## Steps to reproduce
1. Pick an image model whose render takes longer than 90 s, or stub `syncGenerator->generate()` to sleep 120 s.
2. Request an image in chat while the scheduler runs (`app:media:reap-jobs` every minute).
3. After 90–150 s the job turns `timed_out` ("Render worker stopped responding") while the worker still waits.
4. When the render returns, the job turns `completed`.

---

## Open decision
Which liveness signal should the reaper use for synchronous jobs?
- (a) Ignore the heartbeat for synchronous `submitting` jobs and rely on the job deadline only.
- (b) Treat a held `media-job-advance.{jobKey}` lock as alive. The lock TTL is 120 s and is not refreshed during `generate()`, so this needs a refresh or a TTL tied to the deadline.
- (c) Refresh the heartbeat during the render, for example from a provider progress or streaming callback where the client supports it.

## Affected paths
- `backend/src/MessageHandler/AdvanceMediaJobCommandHandler.php` (`generateSync`)
- `backend/src/Service/Media/MediaJobReaper.php` (`reap`)
- `backend/src/Service/Media/MediaJobService.php` (`markCompleted`, `markTimedOut`)
- `backend/src/Service/Media/MediaJobConfig.php` (heartbeat default)

## Out of scope
- Video polling.
- Provider clients' own HTTP timeouts.

## Verification
- Unit test: a synchronous job in `submitting` with a 120 s old heartbeat, before its deadline and with a live worker, is not reaped.
- Unit test: `markCompleted()` on a job that is already terminal keeps the terminal outcome, or resyncs the chat message so one outcome is shown (whichever the decision picks).
- Browser: request a slow image and see one loading state, then the image, with no "stopped responding" error in between.

## Notes
related: #2302 (the dev stack now runs the scheduler role, so local development hits this path too).
