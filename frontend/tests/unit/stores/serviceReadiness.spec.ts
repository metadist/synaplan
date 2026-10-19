import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { ServiceReadiness } from '@/services/featuresService'
import { SERVICES_READY_TOAST_SEEN_ID, SERVICES_STARTING_TOAST_SEEN_ID } from '@/tours'

const {
  getFeaturesStatus,
  applyProviderDefaults,
  setFeatureStatusCounts,
  push,
  info,
  success,
  error,
  seen,
  ensureLoaded,
  markSeen,
  authStore,
  freeApiStore,
  reload,
  loadModels,
  loadDefaults,
} = vi.hoisted(() => ({
  getFeaturesStatus: vi.fn(),
  applyProviderDefaults: vi.fn(),
  setFeatureStatusCounts: vi.fn(),
  push: vi.fn(),
  info: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
  seen: { value: [] as string[] },
  ensureLoaded: vi.fn(),
  markSeen: vi.fn(),
  authStore: { isAuthenticated: true, isAdmin: true },
  freeApiStore: {
    status: null as { active: boolean } | null,
    isAvailable: vi.fn(() => true),
    load: vi.fn(),
    leave: vi.fn(),
  },
  reload: vi.fn(),
  loadModels: vi.fn(),
  loadDefaults: vi.fn(),
}))

vi.mock('@/services/featuresService', () => ({ getFeaturesStatus }))
vi.mock('@/services/api/providerKeysApi', () => ({ applyProviderDefaults }))
vi.mock('@/composables/useNavItems', () => ({ setFeatureStatusCounts }))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ push, info, success, error }),
}))
vi.mock('@/composables/useTour', () => ({
  useTour: () => ({ seen, ensureLoaded, markSeen }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => authStore }))
vi.mock('@/stores/freeApi', () => ({ useFreeApiStore: () => freeApiStore }))
vi.mock('@/stores/config', () => ({ useConfigStore: () => ({ reload }) }))
vi.mock('@/stores/aiConfig', () => ({ useAiConfigStore: () => ({ loadModels, loadDefaults }) }))

import { i18n } from '@/i18n'
import { useServiceReadinessStore } from '@/stores/serviceReadiness'

const service = (
  id: ServiceReadiness['id'],
  state: ServiceReadiness['state']
): ServiceReadiness => ({
  id,
  name: id,
  state,
  unlocks: '',
  effect: state === 'ready' ? null : 'effect',
  recovery: null,
  since: null,
  percent: null,
  model: null,
  minutes_left: null,
  profile: null,
  docs_anchor: null,
})

describe('serviceReadiness store', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    i18n.global.locale.value = 'en'
    authStore.isAuthenticated = true
    authStore.isAdmin = true
    seen.value = []
    ensureLoaded.mockResolvedValue(undefined)
    markSeen.mockImplementation(async (id: string) => {
      if (!seen.value.includes(id)) seen.value.push(id)
    })
    freeApiStore.status = null
    freeApiStore.isAvailable.mockReturnValue(true)
    freeApiStore.load.mockResolvedValue(undefined)
    freeApiStore.leave.mockResolvedValue({ wasActive: true })
    applyProviderDefaults.mockResolvedValue({ success: true })
    reload.mockResolvedValue(undefined)
    loadModels.mockResolvedValue(undefined)
    loadDefaults.mockResolvedValue(undefined)
    useServiceReadinessStore().resetForTests()
  })

  it('shows the starting toast once on first login while a service is starting', async () => {
    getFeaturesStatus.mockResolvedValue({
      features: {},
      summary: { total: 1, healthy: 0, unhealthy: 1, all_ready: false },
      services: [service('ollama', 'downloading'), service('tika', 'ready')],
    })
    const store = useServiceReadinessStore()

    await store.refresh()
    await store.refresh()

    expect(markSeen).toHaveBeenCalledTimes(1)
    expect(markSeen).toHaveBeenCalledWith(SERVICES_STARTING_TOAST_SEEN_ID)
    expect(info).toHaveBeenCalledTimes(1)
    expect(info).toHaveBeenCalledWith(
      'You can chat already. Local AI, document search and spoken answers are still starting.'
    )
  })

  it('offers switching to local models when Ollama turns ready while the Demo is active', async () => {
    seen.value = [SERVICES_STARTING_TOAST_SEEN_ID]
    freeApiStore.status = { active: true }
    getFeaturesStatus.mockResolvedValue({
      features: {},
      summary: { total: 2, healthy: 2, unhealthy: 0, all_ready: true },
      services: [service('ollama', 'ready'), service('tika', 'ready')],
    })
    const store = useServiceReadinessStore()

    await store.refresh()

    expect(markSeen).toHaveBeenCalledWith(SERVICES_READY_TOAST_SEEN_ID)
    expect(push).toHaveBeenCalledWith(
      expect.objectContaining({
        type: 'success',
        message: 'Everything is ready.',
        action: expect.objectContaining({ label: 'Switch to your local models' }),
      })
    )

    // Clicking the action leaves the Demo and applies Ollama defaults:
    const action = push.mock.calls[0][0].action as { onClick: () => void }
    action.onClick()
    await vi.waitFor(() => {
      expect(freeApiStore.leave).toHaveBeenCalled()
      expect(applyProviderDefaults).toHaveBeenCalledWith('ollama')
      expect(success).toHaveBeenCalledWith(
        'Your local Ollama models answer chat now. The Synaplan Demo was left.'
      )
    })
  })

  it('shows the plain all-ready toast without an action when the Demo is not active', async () => {
    seen.value = [SERVICES_STARTING_TOAST_SEEN_ID]
    freeApiStore.status = { active: false }
    getFeaturesStatus.mockResolvedValue({
      features: {},
      summary: { total: 1, healthy: 1, unhealthy: 0, all_ready: true },
      services: [service('tika', 'ready'), service('collabora', 'off')],
    })
    const store = useServiceReadinessStore()

    await store.refresh()

    expect(push).toHaveBeenCalledWith({
      type: 'success',
      message: 'Everything is ready.',
    })
  })

  it('silently marks readiness without a toast when the stack was already ready on first check', async () => {
    getFeaturesStatus.mockResolvedValue({
      features: {},
      summary: { total: 1, healthy: 1, unhealthy: 0, all_ready: true },
      services: [service('tika', 'ready')],
    })
    const store = useServiceReadinessStore()

    await store.refresh()

    expect(markSeen).toHaveBeenCalledWith(SERVICES_READY_TOAST_SEEN_ID)
    expect(info).not.toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
  })
})
