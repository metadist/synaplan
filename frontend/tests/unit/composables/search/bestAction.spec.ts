import { describe, expect, it } from 'vitest'
import { FolderIcon } from '@heroicons/vue/24/outline'
import { buildBestAction } from '@/composables/search/bestAction'
import type { SmartSearchInterpretation } from '@/services/api/searchApi'
import type { SearchResult } from '@/composables/search/types'

const result = (id: string, kind: SearchResult['kind']) =>
  ({ id, kind, title: id, icon: FolderIcon, run: () => {} }) as SearchResult

const candidates = [result('setting:a', 'setting'), result('page:b', 'page')]
const handOff = result('ask:chat', 'ask')

const pick = (overrides: Partial<SmartSearchInterpretation>): SmartSearchInterpretation => ({
  outcome: 'ok',
  intent: 'navigate',
  targetIds: [],
  answer: null,
  ...overrides,
})

describe('buildBestAction', () => {
  it('keeps the picked rows in the order the AI gave', () => {
    const best = buildBestAction(
      pick({ targetIds: ['page:b', 'setting:a'], answer: 'Open it here.' }),
      candidates,
      handOff
    )
    expect(best?.items.map((item) => item.id)).toEqual(['page:b', 'setting:a'])
    expect(best?.note).toBe('Open it here.')
  })

  it('ignores ids the list does not hold', () => {
    expect(buildBestAction(pick({ targetIds: ['file:x'] }), candidates, handOff)).toBeNull()
  })

  it('offers the chat hand-off for an answer without a target', () => {
    const best = buildBestAction(
      pick({ intent: 'answer', answer: 'Ask in chat.' }),
      candidates,
      handOff
    )
    expect(best?.items).toEqual([handOff])
  })

  it('shows nothing for no match, a failure or no call', () => {
    expect(buildBestAction(pick({ outcome: 'no_match' }), candidates, handOff)).toBeNull()
    expect(
      buildBestAction(pick({ outcome: 'failed', targetIds: ['page:b'] }), candidates, handOff)
    ).toBeNull()
    expect(buildBestAction(null, candidates, handOff)).toBeNull()
  })
})
