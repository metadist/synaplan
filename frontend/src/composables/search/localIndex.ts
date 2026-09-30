import MiniSearch from 'minisearch'
import type { LocalSearchDoc } from './types'

/** Lower-case and strip diacritics so "Gruppen", "grüppen" and "GRUPPEN" meet. */
export function normalizeTerm(term: string): string {
  return term
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
}

const MAX_LOCAL_RESULTS = 30

/**
 * In-browser fuzzy index over pages and commands. Rebuilt whenever the
 * visible destinations or the locale change; the corpus is small (< 200
 * docs), so a rebuild is cheaper than incremental bookkeeping.
 */
export class LocalSearchIndex {
  private index: MiniSearch<LocalSearchDoc>

  constructor() {
    this.index = LocalSearchIndex.create()
  }

  private static create(): MiniSearch<LocalSearchDoc> {
    return new MiniSearch<LocalSearchDoc>({
      fields: ['title', 'keywords', 'subtitle'],
      idField: 'id',
      processTerm: (term) => normalizeTerm(term),
      searchOptions: {
        boost: { title: 3, keywords: 1.5, subtitle: 0.5 },
        prefix: true,
        fuzzy: (term) => (term.length > 3 ? 0.2 : false),
        combineWith: 'AND',
      },
    })
  }

  rebuild(docs: LocalSearchDoc[]): void {
    this.index = LocalSearchIndex.create()
    const seen = new Set<string>()
    const unique = docs.filter((doc) => {
      if (seen.has(doc.id)) return false
      seen.add(doc.id)
      return true
    })
    this.index.addAll(unique)
  }

  /** Returns doc ids ordered by relevance. Falls back to OR when AND finds nothing. */
  search(query: string): Array<{ id: string; score: number }> {
    const trimmed = query.trim()
    if (trimmed === '') return []
    let hits = this.index.search(trimmed)
    if (hits.length === 0) {
      hits = this.index.search(trimmed, { combineWith: 'OR' })
    }
    return hits.slice(0, MAX_LOCAL_RESULTS).map((hit) => ({ id: String(hit.id), score: hit.score }))
  }
}
