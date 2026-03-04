/**
 * Worker-scoped state for dynamic test users.
 *
 * Each Playwright worker gets a unique email and a shared password.
 * Users are created in auth-setup (worker fixture) and deleted in teardown.
 */

export const WORKER_COUNT = 4
export const WORKER_PASSWORD = 'E2eTest1234!'

/** Unique email for a given worker index. Includes run-id to avoid collisions across runs. */
export function workerEmail(workerIndex: number): string {
  if (workerIndex < 0 || workerIndex >= WORKER_COUNT) {
    throw new Error(
      `Worker index ${workerIndex} out of range (max ${WORKER_COUNT - 1}). ` +
        `Increase WORKER_COUNT or reduce playwright workers.`
    )
  }
  const runId = process.env.E2E_RUN_ID ?? Date.now().toString()
  return `e2e-w${workerIndex}-${runId}@test.synaplan.com`
}
