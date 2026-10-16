import { beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'
import { FolderIcon } from '@heroicons/vue/24/outline'
import { ApiError } from '@/services/api/httpClient'
import {
  looksLikeQuestion,
  toCandidate,
  useSearchInterpret,
} from '@/composables/search/useSearchInterpret'
import type { RemoteStatus } from '@/composables/search/useRemoteSearch'
import type { SearchResult } from '@/composables/search/types'

const interpretSearch = vi.fn()
const features: { smartSearchAi?: boolean } = {}

vi.mock('@/services/api/searchApi', () => ({
  interpretSearch: (...args: unknown[]) => interpretSearch(...args),
}))
vi.mock('@/services/api/httpClient', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/services/api/httpClient')>()),
  getConfigSync: () => ({ features }),
}))
vi.mock('@/i18n/instance', () => ({ i18n: { global: { locale: { value: 'de' } } } }))

const result = (id: string, kind: SearchResult['kind'], extra: Partial<SearchResult> = {}) =>
  ({ id, kind, title: `Title ${id}`, icon: FolderIcon, run: () => {}, ...extra }) as SearchResult

const okResponse = {
  outcome: 'ok',
  intent: 'change_setting',
  targetIds: ['setting:a'],
  answer: 'Switch it here.',
}

function setup(text = '') {
  const state = {
    text: ref(text),
    candidates: ref<SearchResult[]>([result('setting:a', 'setting'), result('ask:chat', 'ask')]),
    remoteStatus: ref<RemoteStatus>('idle'),
    isOpen: ref(true),
  }
  const scope = effectScope()
  const ai = scope.run(() => useSearchInterpret(state))!
  return { state, ai, scope }
}

const settle = async () => {
  await nextTick()
  await Promise.resolve()
  await nextTick()
}

describe('looksLikeQuestion', () => {
  it('treats a question mark or four words as a question', () => {
    expect(looksLikeQuestion('groups?')).toBe(true)
    expect(looksLikeQuestion('how do I share files')).toBe(true)
    expect(looksLikeQuestion('iam groups')).toBe(false)
    expect(looksLikeQuestion('   ')).toBe(false)
  })
})

describe('toCandidate', () => {
  it('keeps searchable kinds and drops hand-off rows', () => {
    expect(
      toCandidate(
        result('setting:a', 'setting', {
          subtitle: 'Admin',
          setting: { current: 'on' } as SearchResult['setting'],
        })
      )
    ).toEqual({
      id: 'setting:a',
      kind: 'setting',
      title: 'Title setting:a',
      subtitle: 'Admin',
      value: 'on',
    })
    expect(toCandidate(result('ask:chat', 'ask'))).toBeNull()
  })
})

describe('useSearchInterpret', () => {
  beforeEach(() => {
    interpretSearch.mockReset()
    features.smartSearchAi = true
  })

  it('asks once per question after the server results are in', async () => {
    interpretSearch.mockResolvedValue(okResponse)
    const { state, ai, scope } = setup('how do I turn on groups')
    await settle()
    expect(interpretSearch).not.toHaveBeenCalled()

    state.remoteStatus.value = 'ready'
    await settle()
    expect(interpretSearch).toHaveBeenCalledTimes(1)
    const [request] = interpretSearch.mock.calls[0]!
    expect(request).toMatchObject({ q: 'how do I turn on groups', language: 'de' })
    expect(request.candidates).toHaveLength(1)
    expect(ai.status.value).toBe('ready')
    expect(ai.result.value).toEqual(okResponse)

    state.remoteStatus.value = 'loading'
    state.remoteStatus.value = 'ready'
    await settle()
    expect(interpretSearch).toHaveBeenCalledTimes(1)
    scope.stop()
  })

  it('waits for an explicit ask on keyword queries', async () => {
    interpretSearch.mockResolvedValue(okResponse)
    const { state, ai, scope } = setup('groups')
    state.remoteStatus.value = 'ready'
    await settle()
    expect(interpretSearch).not.toHaveBeenCalled()

    await ai.ask()
    expect(interpretSearch).toHaveBeenCalledTimes(1)
    expect(ai.status.value).toBe('ready')
    scope.stop()
  })

  it('stays silent when the feature is off', async () => {
    features.smartSearchAi = false
    const { state, ai, scope } = setup('how do I turn on groups')
    state.remoteStatus.value = 'ready'
    await settle()
    await ai.ask()
    expect(ai.enabled.value).toBe(false)
    expect(interpretSearch).not.toHaveBeenCalled()
    scope.stop()
  })

  it('reports a rate limit and a failure separately', async () => {
    interpretSearch.mockRejectedValueOnce(new ApiError(429, 'slow down'))
    const { ai, scope } = setup('groups')
    await ai.ask()
    expect(ai.status.value).toBe('rateLimited')

    interpretSearch.mockRejectedValueOnce(new Error('boom'))
    await ai.ask()
    expect(ai.status.value).toBe('failed')
    scope.stop()
  })

  it('reports a spent message allowance and a server-side failure', async () => {
    const { ai, scope } = setup('groups')
    interpretSearch.mockResolvedValueOnce({
      outcome: 'limit_reached',
      intent: 'none',
      targetIds: [],
      answer: null,
    })
    await ai.ask()
    expect(ai.status.value).toBe('limitReached')

    interpretSearch.mockResolvedValueOnce({
      outcome: 'failed',
      intent: 'none',
      targetIds: [],
      answer: null,
    })
    await ai.ask()
    expect(ai.status.value).toBe('failed')
    scope.stop()
  })

  it('drops the answer when the text changes or the palette closes', async () => {
    interpretSearch.mockResolvedValue(okResponse)
    const { state, ai, scope } = setup('groups')
    await ai.ask()
    expect(ai.result.value).not.toBeNull()

    state.text.value = 'groups on'
    await nextTick()
    expect(ai.result.value).toBeNull()
    expect(ai.status.value).toBe('idle')

    await ai.ask()
    state.isOpen.value = false
    await nextTick()
    expect(ai.result.value).toBeNull()
    scope.stop()
  })
})
