import { describe, expect, it } from 'vitest'
import { LocalSearchIndex, normalizeTerm } from '@/composables/search/localIndex'
import { parseScope } from '@/composables/search/useSmartSearch'

const docs = [
  { id: 'page:/groups', title: 'My groups', keywords: 'Meine Gruppen groups teams', subtitle: '' },
  { id: 'page:/files', title: 'Sources', keywords: 'Dateien files documents pdf', subtitle: '' },
  {
    id: 'command:theme-dark',
    title: 'Switch to dark theme',
    keywords: 'dunkel dark mode',
    subtitle: '',
  },
]

describe('normalizeTerm', () => {
  it('lower-cases and strips diacritics', () => {
    expect(normalizeTerm('Grüppen')).toBe('gruppen')
    expect(normalizeTerm('Français')).toBe('francais')
  })
})

describe('LocalSearchIndex', () => {
  const index = new LocalSearchIndex()
  index.rebuild(docs)

  it('finds a page by a keyword from another locale', () => {
    expect(index.search('gruppen')[0]?.id).toBe('page:/groups')
  })

  it('tolerates typos and prefixes', () => {
    expect(index.search('documnts')[0]?.id).toBe('page:/files')
    expect(index.search('dat')[0]?.id).toBe('page:/files')
    expect(index.search('dunk')[0]?.id).toBe('command:theme-dark')
  })

  it('keeps an OR match only when half of the words match', () => {
    expect(index.search('groups documents')[0]?.id).toBeDefined()
    expect(index.search('let colleagues see each other in teams')).toEqual([])
  })

  it('returns nothing for an empty query', () => {
    expect(index.search('   ')).toEqual([])
  })

  it('ignores duplicate ids on rebuild', () => {
    const dupes = new LocalSearchIndex()
    expect(() => dupes.rebuild([...docs, docs[0]])).not.toThrow()
    expect(dupes.search('groups').filter((hit) => hit.id === 'page:/groups')).toHaveLength(1)
  })
})

describe('parseScope', () => {
  it('reads the prefix and strips it from the query', () => {
    expect(parseScope('> dark')).toEqual({ scope: 'command', text: 'dark' })
    expect(parseScope('#FEATURE_IAM')).toEqual({ scope: 'setting', text: 'FEATURE_IAM' })
    expect(parseScope('@ invoice')).toEqual({ scope: 'file', text: 'invoice' })
    expect(parseScope('  groups ')).toEqual({ scope: 'all', text: 'groups' })
  })
})
