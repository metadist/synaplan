/** A read that came back empty and was tried again, capped by the backend. */
export interface SearchRecovery {
  attempts: { query: string; hits: number }[]
  matchedQuery?: string
  empty?: boolean
}

/** Wire form of {@see SearchRecovery}. Drops anything that is not a real attempt. */
export function parseSearchRecovery(value: unknown): SearchRecovery | undefined {
  if (!value || typeof value !== 'object') return undefined
  const raw = value as { attempts?: unknown; matched_query?: unknown; empty?: unknown }
  if (!Array.isArray(raw.attempts)) return undefined

  const attempts = raw.attempts.flatMap((item) => {
    if (!item || typeof item !== 'object') return []
    const query = (item as { query?: unknown }).query
    const hits = (item as { hits?: unknown }).hits
    if (typeof query !== 'string' || query.trim() === '') return []
    return [{ query, hits: typeof hits === 'number' && hits >= 0 ? hits : 0 }]
  })
  if (attempts.length === 0) return undefined

  return {
    attempts,
    ...(typeof raw.matched_query === 'string' && raw.matched_query
      ? { matchedQuery: raw.matched_query }
      : {}),
    ...(raw.empty === true ? { empty: true } : {}),
  }
}
