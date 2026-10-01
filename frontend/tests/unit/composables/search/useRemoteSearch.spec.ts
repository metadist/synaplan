import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'
import { FolderIcon } from '@heroicons/vue/24/outline'
import { ApiError } from '@/services/api/httpClient'
import type { SmartSearchResponse } from '@/services/api/searchApi'
import {
  REMOTE_DEBOUNCE_MS,
  toSearchResult,
  useRemoteSearch,
} from '@/composables/search/useRemoteSearch'
import { boostRecent, type SearchScope } from '@/composables/search/useSmartSearch'
import type { SearchResult } from '@/composables/search/types'

const searchEverything = vi.fn()
vi.mock('@/services/api/searchApi', () => ({
  searchEverything: (...args: unknown[]) => searchEverything(...args),
}))

const response = (title: string, overrides: Partial<SmartSearchResponse> = {}) => ({
  query: title,
  results: [
    {
      id: 'chat:1',
      kind: 'chat' as const,
      title,
      subtitle: null,
      snippet: 'about the plumber',
      route: '/?chat=1',
      score: 0.03,
      matchedBy: 'semantic' as const,
      action: null,
      sharedBy: null as string | null,
    },
  ],
  semanticAvailable: true,
  degraded: [],
  indexing: false,
  ...overrides,
})

function setup(initial = '') {
  const parsed = ref<{ scope: SearchScope; text: string }>({ scope: 'all', text: initial })
  const isOpen = ref(true)
  const scope = effectScope()
  const remote = scope.run(() => useRemoteSearch(parsed, isOpen))!
  return { parsed, isOpen, remote, scope }
}

const flush = async () => {
  await vi.advanceTimersByTimeAsync(REMOTE_DEBOUNCE_MS)
  await nextTick()
}

describe('useRemoteSearch', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    searchEverything.mockReset()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('asks the server once typing pauses and maps the hits', async () => {
    searchEverything.mockResolvedValue(response('Plumber invoice'))
    const { parsed, remote } = setup()

    parsed.value = { scope: 'all', text: 'plu' }
    await nextTick()
    expect(remote.status.value).toBe('loading')
    parsed.value = { scope: 'all', text: 'plumber' }
    await flush()

    expect(searchEverything).toHaveBeenCalledTimes(1)
    expect(searchEverything.mock.calls[0][0]).toBe('plumber')
    expect(remote.status.value).toBe('ready')
    expect(remote.results.value[0]).toMatchObject({
      id: 'chat:1',
      title: 'Plumber invoice',
      matchedBy: 'semantic',
      route: '/?chat=1',
    })
  })

  it('never lets a slow earlier answer overwrite a newer query', async () => {
    let resolveFirst: (value: unknown) => void = () => {}
    searchEverything
      .mockImplementationOnce(() => new Promise((resolve) => (resolveFirst = resolve)))
      .mockResolvedValueOnce(response('Second'))
    const { parsed, remote } = setup()

    parsed.value = { scope: 'all', text: 'first' }
    await flush()
    parsed.value = { scope: 'all', text: 'second' }
    await flush()
    resolveFirst(response('First'))
    await nextTick()

    const firstSignal = searchEverything.mock.calls[0][1].signal as AbortSignal
    expect(firstSignal.aborted).toBe(true)
    expect(remote.results.value.map((r) => r.title)).toEqual(['Second'])
  })

  it('narrows @ and # prefixes to their kind and skips > commands', async () => {
    searchEverything.mockResolvedValue(response('x'))
    const { parsed } = setup()

    parsed.value = { scope: 'file', text: 'invoice' }
    await flush()
    expect(searchEverything.mock.calls[0][1].kinds).toEqual(['file'])

    parsed.value = { scope: 'setting', text: 'groups' }
    await flush()
    expect(searchEverything.mock.calls[1][1].kinds).toEqual(['setting'])

    parsed.value = { scope: 'command', text: 'theme' }
    await flush()
    expect(searchEverything).toHaveBeenCalledTimes(2)
  })

  it('does not call the server for a single letter or a closed palette', async () => {
    const { parsed, isOpen, remote } = setup()

    parsed.value = { scope: 'all', text: 'a' }
    await flush()
    isOpen.value = false
    parsed.value = { scope: 'all', text: 'another' }
    await flush()

    expect(searchEverything).not.toHaveBeenCalled()
    expect(remote.status.value).toBe('idle')
  })

  it('reports a rate limit separately from an outage', async () => {
    searchEverything.mockRejectedValueOnce(new ApiError(429, 'Too many'))
    searchEverything.mockRejectedValueOnce(new ApiError(500, 'Boom'))
    const { parsed, remote } = setup()

    parsed.value = { scope: 'all', text: 'first' }
    await flush()
    expect(remote.status.value).toBe('rateLimited')

    parsed.value = { scope: 'all', text: 'second' }
    await flush()
    expect(remote.status.value).toBe('error')
    expect(remote.results.value).toEqual([])
  })

  it('passes on keyword-only and indexing states', async () => {
    searchEverything.mockResolvedValue(
      response('x', { semanticAvailable: false, indexing: true, results: [] })
    )
    const { parsed, remote } = setup()

    parsed.value = { scope: 'all', text: 'plumber' }
    await flush()

    expect(remote.semanticAvailable.value).toBe(false)
    expect(remote.indexing.value).toBe(true)
  })

  it('cancels pending work when its scope is disposed', async () => {
    const { parsed, scope } = setup()

    parsed.value = { scope: 'all', text: 'plumber' }
    await nextTick()
    scope.stop()
    await flush()

    expect(searchEverything).not.toHaveBeenCalled()
  })
})

describe('toSearchResult', () => {
  it('turns null fields into absent ones', () => {
    const result = toSearchResult({ ...response('t').results[0], snippet: null })
    expect(result.subtitle).toBeUndefined()
    expect(result.snippet).toBeUndefined()
    expect(result.icon).toBeTruthy()
    expect(result.setting).toBeUndefined()
    expect(result.sharedBy).toBeUndefined()
  })

  it('names the owner of a shared item', () => {
    const result = toSearchResult({ ...response('t').results[0], sharedBy: 'Ada Lovelace' })
    expect(result.sharedBy).toBe('Ada Lovelace')
  })

  it('drops a route that leaves the app', () => {
    for (const route of ['https://evil.example', '//evil.example', 'javascript:alert(1)']) {
      expect(toSearchResult({ ...response('t').results[0], route }).route).toBeUndefined()
    }
    expect(toSearchResult(response('t').results[0]).route).toBe('/?chat=1')
  })

  it('carries an inline setting action as the row control', () => {
    const action = {
      type: 'toggle' as const,
      key: 'FEATURE_IAM_GROUPS_ENABLED',
      current: 'true',
      options: [],
      scope: 'system' as const,
      envPinned: false,
    }
    const result = toSearchResult({ ...response('t').results[0], kind: 'setting', action })
    expect(result.setting).toEqual(action)
  })
})

describe('boostRecent', () => {
  const item = (id: string): SearchResult => ({
    id,
    kind: 'chat',
    title: id,
    icon: FolderIcon,
    matchedBy: 'lexical',
  })

  it('lifts recently opened items in recency order and keeps the rest stable', () => {
    const ordered = boostRecent(
      [item('a'), item('b'), item('c'), item('d')],
      ['chat-x', 'd', 'b']
    ).map((r) => r.id)

    expect(ordered).toEqual(['d', 'b', 'a', 'c'])
  })

  it('keeps an exact match of the query above recent items', () => {
    const ordered = boostRecent([item('a'), item('b'), item('c')], ['b'], 'C').map((r) => r.id)

    expect(ordered).toEqual(['c', 'b', 'a'])
  })
})
