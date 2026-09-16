# Sprint 4: Parallel Execution

**Goal:** Run independent tasks concurrently to minimize latency, without breaking streaming or ordering.

## Technical Tasks
1. **Concurrency Implementation:**
   - Implement the chosen concurrency mechanism (e.g., Guzzle async/promises for concurrent provider HTTP calls).
2. **Parallel Scheduler:**
   - Update `TaskPlanExecutor` to run all dependency-satisfied nodes concurrently when `MULTITASK_PARALLEL_ENABLED` is on.
   - Ensure deterministic assembly order regardless of completion order.
3. **Concurrency Bounding:**
   - Implement a concurrency cap to protect provider rate limits (especially Groq) and system memory.

## UI/UX Impact
- **Speed:** Complex multi-task prompts (e.g., "Summarize this doc AND generate an image of a cat") will complete significantly faster.
- **Progress UI:** Progress indicators may show multiple tasks happening simultaneously.

## Release Gate (Success Test)
- [ ] Two independent nodes complete concurrently; wall-clock time is < sum of individual latencies.
- [ ] Assembled reply is deterministic across 5 runs.
- [ ] Failure isolation works (one branch fails, the other succeeds, partial result surfaced).
- [ ] Rate-limit guard test passes (no provider 429 storm).
- [ ] Sequential mode (flag off) still passes the Sprint 3 gate.
- [ ] E2E tests remain green.
