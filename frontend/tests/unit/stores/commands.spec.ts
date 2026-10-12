import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'

const { httpClient } = vi.hoisted(() => ({ httpClient: vi.fn() }))
vi.mock('@/services/api/httpClient', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/services/api/httpClient')>()),
  httpClient,
}))

const authed = ref(true)
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAuthenticated() {
      return authed.value
    },
  }),
}))

import { useCommandsStore } from '@/stores/commands'

const row = (id: number, command: string, body = `${command} text`) => ({
  id,
  name: `${command} name`,
  command,
  body,
  tags: [],
})

const savedNames = (store: ReturnType<typeof useCommandsStore>) =>
  store.commands.filter((cmd) => cmd.promptBody !== undefined).map((cmd) => cmd.name)

describe('commands store saved prompts', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    httpClient.mockReset()
    authed.value = true
  })

  it('fetches again on every load and the second payload replaces the first', async () => {
    httpClient
      .mockResolvedValueOnce({ success: true, prompts: [row(1, 'checknote')] })
      .mockResolvedValueOnce({ success: true, prompts: [row(1, 'checknote'), row(2, 'second')] })
    const store = useCommandsStore()

    await store.loadSavedPrompts()
    expect(savedNames(store)).toEqual(['checknote'])

    await store.loadSavedPrompts()
    expect(httpClient).toHaveBeenCalledTimes(2)
    expect(httpClient).toHaveBeenNthCalledWith(2, '/api/v1/saved-prompts', expect.any(Object))
    expect(savedNames(store)).toEqual(['checknote', 'second'])
    expect(store.getCommand('second')?.promptBody).toBe('second text')
  })

  it('drops a prompt that was deleted between two loads', async () => {
    httpClient
      .mockResolvedValueOnce({ success: true, prompts: [row(1, 'notiz'), row(2, 'notiz2')] })
      .mockResolvedValueOnce({ success: true, prompts: [row(2, 'notiz2')] })
    const store = useCommandsStore()

    await store.loadSavedPrompts()
    await store.loadSavedPrompts()

    expect(savedNames(store)).toEqual(['notiz2'])
    expect(store.getCommand('notiz')).toBeUndefined()
  })

  it('ignores an older response that arrives after a newer one', async () => {
    let resolveFirst: (value: unknown) => void = () => {}
    httpClient
      .mockImplementationOnce(() => new Promise((resolve) => (resolveFirst = resolve)))
      .mockResolvedValueOnce({ success: true, prompts: [row(2, 'fresh')] })
    const store = useCommandsStore()

    const first = store.loadSavedPrompts()
    await store.loadSavedPrompts()
    resolveFirst({ success: true, prompts: [row(1, 'stale')] })
    await first

    expect(savedNames(store)).toEqual(['fresh'])
  })

  it('keeps the previous list when a refresh fails', async () => {
    httpClient
      .mockResolvedValueOnce({ success: true, prompts: [row(1, 'notiz')] })
      .mockRejectedValueOnce(new Error('offline'))
    const store = useCommandsStore()

    await store.loadSavedPrompts()
    await store.loadSavedPrompts()

    expect(savedNames(store)).toEqual(['notiz'])
  })

  it('makes no request for a guest and lists no saved prompts', async () => {
    httpClient.mockResolvedValueOnce({ success: true, prompts: [row(1, 'notiz')] })
    const store = useCommandsStore()
    await store.loadSavedPrompts()

    authed.value = false
    await store.loadSavedPrompts()

    expect(httpClient).toHaveBeenCalledTimes(1)
    expect(savedNames(store)).toEqual([])
  })

  it('reset forgets the list and drops a load still in flight', async () => {
    let resolveLoad: (value: unknown) => void = () => {}
    httpClient
      .mockResolvedValueOnce({ success: true, prompts: [row(1, 'previous')] })
      .mockImplementationOnce(() => new Promise((resolve) => (resolveLoad = resolve)))
    const store = useCommandsStore()
    await store.loadSavedPrompts()

    const load = store.loadSavedPrompts()
    store.reset()
    resolveLoad({ success: true, prompts: [row(1, 'previous')] })
    await load

    expect(savedNames(store)).toEqual([])
  })

  it('setSavedPrompts replaces the list and wins over a load still in flight', async () => {
    let resolveLoad: (value: unknown) => void = () => {}
    httpClient.mockImplementationOnce(() => new Promise((resolve) => (resolveLoad = resolve)))
    const store = useCommandsStore()

    const load = store.loadSavedPrompts()
    store.setSavedPrompts([row(3, 'fromprompts')])
    resolveLoad({ success: true, prompts: [row(1, 'older')] })
    await load

    expect(savedNames(store)).toEqual(['fromprompts'])
  })
})
