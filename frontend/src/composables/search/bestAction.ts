import type { SmartSearchInterpretation } from '@/services/api/searchApi'
import type { SearchResult } from './types'

export interface BestAction {
  items: SearchResult[]
  note?: string
}

/**
 * Turns the AI pick into the rows of the Best action card. Only results the
 * list already holds can appear; a chat answer without a target falls back
 * to the hand-off row.
 */
export function buildBestAction(
  interpretation: SmartSearchInterpretation | null,
  candidates: SearchResult[],
  chatHandOff: SearchResult | null
): BestAction | null {
  if (!interpretation || interpretation.outcome !== 'ok') return null

  const byId = new Map(candidates.map((candidate) => [candidate.id, candidate]))
  const items = interpretation.targetIds
    .map((id) => byId.get(id))
    .filter((item): item is SearchResult => item !== undefined)
  if (items.length === 0 && interpretation.intent === 'answer' && chatHandOff) {
    items.push(chatHandOff)
  }
  if (items.length === 0) return null

  return { items, note: interpretation.answer ?? undefined }
}
