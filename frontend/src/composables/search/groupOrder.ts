import { normalizeTerm } from './localIndex'
import type { SearchKind, SearchResult } from './types'

/** Fixed order of the result groups; an exact match may lead (see below). */
export const GROUP_ORDER: SearchKind[] = [
  'command',
  'page',
  'setting',
  'chat',
  'file',
  'memory',
  'widget',
  'assistant',
  'task',
  'ask',
]

const words = (text: string) =>
  normalizeTerm(text)
    .split(/[^\p{L}\p{N}]+/u)
    .filter(Boolean)

/** The query names this result: its title, or a setting key typed out or as words. */
export function isExactMatch(result: SearchResult, text: string): boolean {
  const typed = words(text).join(' ')
  if (typed === '') return false
  if (words(result.title).join(' ') === typed) return true
  const key = result.setting?.key
  return key !== undefined && words(key.replace(/_/g, ' ')).join(' ') === typed
}

/**
 * Group order for a query. Groups keep the fixed order, except that groups
 * whose top hit is an exact match move to the front, so typing
 * FEATURE_IAM_GROUPS_ENABLED shows that setting above loosely related
 * commands and pages.
 */
export function orderKinds(byKind: Map<SearchKind, SearchResult[]>, text: string): SearchKind[] {
  const present = GROUP_ORDER.filter((kind) => byKind.has(kind))
  const exact = present.filter((kind) => {
    const top = byKind.get(kind)?.[0]
    return kind !== 'ask' && top !== undefined && isExactMatch(top, text)
  })
  return [...exact, ...present.filter((kind) => !exact.includes(kind))]
}
