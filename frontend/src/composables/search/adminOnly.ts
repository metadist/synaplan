import type { SearchResult } from './types'

/** System settings and Operate pages. A normal account cannot open them. */
export function isAdminOnlyResult(result: Pick<SearchResult, 'kind' | 'route'>): boolean {
  if (result.kind === 'setting') return true
  const path = (result.route ?? '').split(/[?#]/)[0]
  return path === '/admin' || path.startsWith('/admin/')
}
