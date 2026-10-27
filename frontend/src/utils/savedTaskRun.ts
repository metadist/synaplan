import type { SavedTaskRun } from '@/services/api/savedTasksApi'

/**
 * The status code a run row shows. A run paused on a research step reports
 * `waiting_approval` like an approval, but nobody has to decide anything:
 * it is waiting for the report.
 */
export function runStatusCode(
  run: Pick<SavedTaskRun, 'status' | 'waitingNode' | 'planSnapshot'>
): string {
  if (run.status !== 'waiting_approval' || !run.waitingNode) return run.status
  const waitsOnResearch = (run.planSnapshot?.cards ?? []).some(
    (card) =>
      card !== null &&
      typeof card === 'object' &&
      (card as Record<string, unknown>).nodeId === run.waitingNode &&
      (card as Record<string, unknown>).capability === 'research'
  )
  return waitsOnResearch ? 'waiting_research' : run.status
}
