import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const { api, reload, loadModels, loadDefaults, getConfigSync } = vi.hoisted(() => ({
  api: {
    getFreeApiStatus: vi.fn(),
    activateFreeApi: vi.fn(),
    leaveFreeApi: vi.fn(),
  },
  reload: vi.fn(),
  loadModels: vi.fn(),
  loadDefaults: vi.fn(),
  getConfigSync: vi.fn(),
}))

vi.mock('@/services/api/freeApiApi', () => api)
vi.mock('@/stores/config', () => ({ useConfigStore: () => ({ reload }) }))
vi.mock('@/stores/aiConfig', () => ({ useAiConfigStore: () => ({ loadModels, loadDefaults }) }))
vi.mock('@/services/api/httpClient', () => ({ getConfigSync: () => getConfigSync() }))

import type { FreeApiStatus } from '@/services/api/freeApiApi'
import { useFreeApiStore } from '@/stores/freeApi'

const activeStatus = { active: true, server: 'web.synaplan.com' } as unknown as FreeApiStatus

describe('freeApi store', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    reload.mockResolvedValue(undefined)
    getConfigSync.mockReturnValue({ modules: { freeapi: { configured: true, gated: false } } })
  })

  it('does not ask for a status while the module is off', async () => {
    getConfigSync.mockReturnValue({ modules: { freeapi: { configured: false, gated: false } } })
    const store = useFreeApiStore()

    await store.load()

    expect(store.isAvailable()).toBe(false)
    expect(api.getFreeApiStatus).not.toHaveBeenCalled()
    expect(store.status).toBeNull()
  })

  it('starts with consent and refreshes chat readiness and the model defaults', async () => {
    api.activateFreeApi.mockResolvedValue(activeStatus)
    const store = useFreeApiStore()

    await store.start('de')

    expect(api.activateFreeApi).toHaveBeenCalledWith({ consent: true, locale: 'de', force: false })
    expect(store.status).toEqual(activeStatus)
    expect(reload).toHaveBeenCalled()
    expect(loadModels).toHaveBeenCalled()
    expect(loadDefaults).toHaveBeenCalled()
    expect(store.busy).toBe(false)
  })

  it('leaves nothing refreshed and no busy flag behind when the start fails', async () => {
    api.activateFreeApi.mockRejectedValue(new Error('refused'))
    const store = useFreeApiStore()

    await expect(store.start('en')).rejects.toThrow('refused')

    expect(reload).not.toHaveBeenCalled()
    expect(store.busy).toBe(false)
  })

  // The Demo is on at that point; "could not be started" would be untrue.
  it('still resolves the start when only the refresh afterwards fails', async () => {
    api.activateFreeApi.mockResolvedValue(activeStatus)
    reload.mockRejectedValue(new Error('config unavailable'))
    const store = useFreeApiStore()

    await expect(store.start('en')).resolves.toBeUndefined()

    expect(store.status).toEqual(activeStatus)
    expect(store.busy).toBe(false)
  })

  it('takes the status from the leave result', async () => {
    const inactive = { active: false, server: 'web.synaplan.com' }
    api.leaveFreeApi.mockResolvedValue({ wasActive: true, status: inactive })
    const store = useFreeApiStore()

    const result = await store.leave()

    expect(result.wasActive).toBe(true)
    expect(result.configRefreshed).toBe(true)
    expect(store.status).toEqual(inactive)
    expect(reload).toHaveBeenCalled()
  })

  it('keeps a known status when the status request fails', async () => {
    api.getFreeApiStatus.mockRejectedValueOnce(new Error('unavailable'))
    const store = useFreeApiStore()
    store.status = activeStatus

    await store.load()

    expect(store.status).toEqual(activeStatus)
  })

  it('trusts chat readiness when only the model refresh fails', async () => {
    const inactive = { active: false, server: 'web.synaplan.com' }
    api.leaveFreeApi.mockResolvedValue({ wasActive: true, status: inactive })
    loadModels.mockRejectedValueOnce(new Error('models unavailable'))
    const store = useFreeApiStore()

    const result = await store.leave()

    expect(result.configRefreshed).toBe(true)
    expect(store.busy).toBe(false)
  })

  it('reports a stale config when the refresh after leaving fails', async () => {
    const inactive = { active: false, server: 'web.synaplan.com' }
    api.leaveFreeApi.mockResolvedValue({ wasActive: true, keyRevoked: true, status: inactive })
    reload.mockRejectedValue(new Error('config unavailable'))
    const store = useFreeApiStore()

    const result = await store.leave()

    expect(result.configRefreshed).toBe(false)
    expect(store.status).toEqual(inactive)
    expect(loadModels).not.toHaveBeenCalled()
    expect(store.busy).toBe(false)
  })
})
