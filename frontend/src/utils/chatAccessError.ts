/**
 * 404 or 403 from a per-chat request means that selection is finished.
 *
 * Matched by the error's name and status rather than `instanceof`, because
 * chat-store tests replace the HTTP module and a missing constructor must
 * not throw inside the catch.
 */
export function chatGoneStatus(error: unknown): 403 | 404 | null {
  if (typeof error !== 'object' || error === null) return null
  const candidate = error as { name?: unknown; status?: unknown }
  if (candidate.name !== 'ApiError') return null
  if (candidate.status === 403 || candidate.status === 404) return candidate.status
  return null
}

export type ContinueAccess = 'read' | 'use' | 'owner' | null

/**
 * Continue failed with 403 or 404, then the chat was re-checked with Read.
 * A closed chat was already released. A still-open read grant is not a revoke.
 */
export function continueOutcomeAfterRecheck(
  stillOpen: boolean,
  access: ContinueAccess
): 'released' | 'read-only' | 'failed' {
  if (!stillOpen) return 'released'
  if (access === 'read') return 'read-only'
  return 'failed'
}
