import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useMessageDigestsStore } from '@/stores/messageDigests'
import * as messageDigestsApi from '@/services/api/messageDigestsApi'
import type { MessageDigestReference } from '@/services/api/messageDigestsApi'

vi.mock('@/services/api/messageDigestsApi')

const rentLetter: MessageDigestReference = {
  messageId: 1234,
  chatId: 42,
  title: 'office rent letter to realtor about the increase of payments',
  channel: 'web',
  sourceDate: 1747216800,
}

describe('Message Digests Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('initializes empty', () => {
    const store = useMessageDigestsStore()
    expect(store.references.size).toBe(0)
    expect(store.unresolvable.size).toBe(0)
    expect(store.loading).toBe(false)
  })

  it('addReferences makes references resolvable by message id', () => {
    const store = useMessageDigestsStore()
    store.addReferences([rentLetter])

    expect(store.getByMessageId(1234)).toEqual(rentLetter)
    expect(store.getByMessageId(999)).toBeUndefined()
  })

  it('a pushed reference clears a previous unresolvable mark', async () => {
    const store = useMessageDigestsStore()
    vi.mocked(messageDigestsApi.resolveMessageDigests).mockResolvedValue([])
    await store.resolveMissing([1234])
    expect(store.isUnresolvable(1234)).toBe(true)

    store.addReferences([rentLetter])
    expect(store.isUnresolvable(1234)).toBe(false)
    expect(store.getByMessageId(1234)).toEqual(rentLetter)
  })

  it('resolveMissing fetches unknown ids and marks unreturned ones unresolvable', async () => {
    const store = useMessageDigestsStore()
    vi.mocked(messageDigestsApi.resolveMessageDigests).mockResolvedValue([rentLetter])

    await store.resolveMissing([1234, 5678])

    expect(messageDigestsApi.resolveMessageDigests).toHaveBeenCalledWith([1234, 5678])
    expect(store.getByMessageId(1234)).toEqual(rentLetter)
    expect(store.isUnresolvable(5678)).toBe(true)
  })

  it('resolveMissing never refetches known or unresolvable ids', async () => {
    const store = useMessageDigestsStore()
    store.addReferences([rentLetter])
    vi.mocked(messageDigestsApi.resolveMessageDigests).mockResolvedValue([])
    await store.resolveMissing([5678])
    expect(store.isUnresolvable(5678)).toBe(true)
    vi.clearAllMocks()

    await store.resolveMissing([1234, 5678])

    expect(messageDigestsApi.resolveMessageDigests).not.toHaveBeenCalled()
  })

  it('coalesces callers of the same tick into one request without dropping ids', async () => {
    const store = useMessageDigestsStore()
    vi.mocked(messageDigestsApi.resolveMessageDigests).mockResolvedValue([rentLetter])

    // A page reload mounts every history message at once: each bubble asks for
    // its own ids in the same tick. None of them may be dropped.
    await Promise.all([store.resolveMissing([1234]), store.resolveMissing([5678])])

    expect(messageDigestsApi.resolveMessageDigests).toHaveBeenCalledTimes(1)
    expect(messageDigestsApi.resolveMessageDigests).toHaveBeenCalledWith([1234, 5678])
    expect(store.getByMessageId(1234)).toEqual(rentLetter)
    expect(store.isUnresolvable(5678)).toBe(true)
  })

  it('does not refetch ids a running request already covers', async () => {
    const store = useMessageDigestsStore()
    vi.mocked(messageDigestsApi.resolveMessageDigests).mockResolvedValue([rentLetter])

    const first = store.resolveMissing([1234])
    await Promise.resolve() // let the batch flush and the request start
    await store.resolveMissing([1234])
    await first

    expect(messageDigestsApi.resolveMessageDigests).toHaveBeenCalledTimes(1)
  })

  it('a network failure marks nothing unresolvable so a later render retries', async () => {
    const store = useMessageDigestsStore()
    vi.mocked(messageDigestsApi.resolveMessageDigests).mockRejectedValue(new Error('offline'))

    await store.resolveMissing([1234])

    expect(store.isUnresolvable(1234)).toBe(false)
    expect(store.getByMessageId(1234)).toBeUndefined()
    expect(store.loading).toBe(false)
  })
})
