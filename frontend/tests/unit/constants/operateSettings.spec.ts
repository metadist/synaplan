import { describe, expect, it } from 'vitest'
import {
  AI_TAB_IDS,
  AI_TAB_SECTIONS,
  SYSTEM_CONFIG_GROUPS,
  resolveConfigDeepLink,
  sectionKey,
} from '@/constants/operateSettings'

describe('operateSettings topic map', () => {
  it('shows every backend section on exactly one surface', () => {
    const seen = new Map<string, string>()
    const claim = (key: string, where: string) => {
      expect(seen.get(key), `${key} is claimed by ${seen.get(key)} and ${where}`).toBeUndefined()
      seen.set(key, where)
    }

    for (const tab of AI_TAB_IDS) {
      AI_TAB_SECTIONS[tab].forEach((ref) => claim(sectionKey(ref), `ai:${tab}`))
    }
    for (const group of SYSTEM_CONFIG_GROUPS) {
      for (const tab of group.tabs) {
        ;(tab.sections ?? []).forEach((ref) => claim(sectionKey(ref), `config:${tab.id}`))
      }
    }
  })

  it('keeps AI settings off System configuration', () => {
    const systemBackendTabs = SYSTEM_CONFIG_GROUPS.flatMap((group) =>
      group.tabs.map((tab) => tab.backendTab).filter(Boolean)
    )
    for (const retired of ['ai', 'processing', 'vectordb', 'routing']) {
      expect(systemBackendTabs).not.toContain(retired)
    }
  })

  it('puts embeddings, the vector database and thresholds next to each other', () => {
    expect(AI_TAB_SECTIONS.search.map(sectionKey)).toEqual([
      'ai.embeddings',
      'vectordb.qdrant',
      'vectordb.qdrant_search',
    ])
  })

  it('puts the reading services on the Document reading tab', () => {
    expect(AI_TAB_SECTIONS.documents.map(sectionKey)).toEqual([
      'processing.tika',
      'processing.docling',
      'processing.rasterize',
      'processing.whisper',
    ])
  })
})

describe('resolveConfigDeepLink', () => {
  it.each([
    ['ai', undefined, '/admin/setup', 'providers'],
    ['ai', 'ollama', '/admin/setup', 'providers'],
    ['ai', 'embeddings', '/admin/setup', 'search'],
    ['processing', undefined, '/admin/setup', 'documents'],
    ['processing', 'docling', '/admin/setup', 'documents'],
    ['processing', 'media', '/admin/setup', 'behavior'],
    ['processing', 'brave', '/admin/config', 'web_search'],
    ['processing', 'compute', '/admin/config', 'tools'],
    ['vectordb', 'qdrant_search', '/admin/setup', 'search'],
    ['routing', undefined, '/admin/setup', 'behavior'],
    ['routing', 'conversation_summary', '/admin/setup', 'behavior'],
    ['routing', 'tools', '/admin/config', 'tools'],
    ['routing', 'saved_tasks', '/admin/config', 'tools'],
  ])('%s / %s lands on %s?tab=%s', (tab, section, path, target) => {
    expect(resolveConfigDeepLink(tab, section)).toEqual({ path, tab: target })
  })

  it.each([
    ['channels', 'm365'],
    ['features', undefined],
    ['tools', 'compute'],
    ['web_search', 'brave'],
    [undefined, undefined],
  ])('leaves %s / %s where it is', (tab, section) => {
    expect(resolveConfigDeepLink(tab, section)).toBeNull()
  })
})
