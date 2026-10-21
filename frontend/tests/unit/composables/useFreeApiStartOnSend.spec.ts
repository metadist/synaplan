import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useFreeApiStartOnSend } from '@/composables/useFreeApiStartOnSend'
import { ApiError } from '@/services/api/httpClient'

const { store, auth } = vi.hoisted(() => ({
  store: {
    status: null as { active: boolean } | null,
    busy: false,
    available: true,
    isAvailable: () => store.available,
    start: vi.fn(),
  },
  auth: { isAdmin: true },
}))

vi.mock('@/stores/freeApi', () => ({ useFreeApiStore: () => store }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: ref('de') }) }))

const restoreDraft = vi.fn()

describe('useFreeApiStartOnSend', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    store.status = { active: false }
    store.busy = false
    store.available = true
    auth.isAdmin = true
  })

  it('starts on send only for an admin of an install without a provider', () => {
    const noProvider = ref(true)
    const { startsOnSend } = useFreeApiStartOnSend(noProvider, restoreDraft)
    expect(startsOnSend.value).toBe(true)

    noProvider.value = false
    expect(startsOnSend.value).toBe(false)
  })

  it('does not start for members, a switched-off module, or an active Demo', () => {
    auth.isAdmin = false
    expect(useFreeApiStartOnSend(ref(true), restoreDraft).startsOnSend.value).toBe(false)

    auth.isAdmin = true
    store.available = false
    expect(useFreeApiStartOnSend(ref(true), restoreDraft).startsOnSend.value).toBe(false)

    store.available = true
    store.status = { active: true }
    expect(useFreeApiStartOnSend(ref(true), restoreDraft).startsOnSend.value).toBe(false)
  })

  it('keeps the composer while the start still reloads the config', () => {
    store.status = { active: true }
    store.busy = true

    expect(useFreeApiStartOnSend(ref(true), restoreDraft).startsOnSend.value).toBe(true)
  })

  it('lets the message through without starting when no start is needed', async () => {
    const { readyForSend } = useFreeApiStartOnSend(ref(false), restoreDraft)

    await expect(readyForSend('Hallo')).resolves.toBe(true)
    expect(store.start).not.toHaveBeenCalled()
  })

  it('starts the Demo in the current language', async () => {
    const { readyForSend, startErrorKey } = useFreeApiStartOnSend(ref(true), restoreDraft)

    await expect(readyForSend('Hallo')).resolves.toBe(true)
    expect(store.start).toHaveBeenCalledWith('de')
    expect(startErrorKey.value).toBe('')
    expect(restoreDraft).not.toHaveBeenCalled()
  })

  it('reports why the Demo did not start and gives the message back', async () => {
    store.start.mockRejectedValueOnce(new ApiError(503, 'full', 'freeapi_full_today'))
    const { readyForSend, startErrorKey } = useFreeApiStartOnSend(ref(true), restoreDraft)

    await expect(readyForSend('Hallo')).resolves.toBe(false)
    expect(startErrorKey.value).toBe('freeApi.startError.fullToday')
    expect(restoreDraft).toHaveBeenCalledWith('Hallo')
  })
})
