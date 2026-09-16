import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAiConfigStore } from '@/stores/aiConfig'
import { configApi, type ModelsResponse } from '@/services/api/configApi'

// Booting the chat asks for the model catalog from two places in the same tick
// (ChatView directly, the model-mix store via ensureLoaded). The backend
// serialises identical requests, so the duplicate came back seconds late and
// delayed everything ChatView awaits after it — the chat list, the first chat
// and with it the composer. These specs pin that a race shares one request.

vi.mock('@/services/api/configApi', () => ({
  configApi: {
    getModels: vi.fn(),
    getDefaultModels: vi.fn(),
  },
}))

const getModelsMock = vi.mocked(configApi.getModels)

describe('aiConfig store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  describe('loadModels', () => {
    it('serves callers that race from a single request', async () => {
      const store = useAiConfigStore()
      let respond: (value: ModelsResponse) => void = () => {}
      getModelsMock.mockReturnValueOnce(
        new Promise<ModelsResponse>((resolve) => {
          respond = resolve
        })
      )

      const first = store.loadModels()
      const second = store.loadModels()
      respond({ success: true, models: { CHAT: [] }, providers: [] })
      await Promise.all([first, second])

      expect(getModelsMock).toHaveBeenCalledTimes(1)
      expect(store.models).toEqual({ CHAT: [] })
    })

    it('fetches again once the shared request has settled', async () => {
      const store = useAiConfigStore()
      getModelsMock.mockResolvedValue({ success: true, models: {}, providers: [] })

      await store.loadModels()
      await store.loadModels()

      expect(getModelsMock).toHaveBeenCalledTimes(2)
    })

    it('lets a later caller retry after a failed request', async () => {
      const store = useAiConfigStore()
      vi.spyOn(console, 'error').mockImplementation(() => {})
      getModelsMock.mockRejectedValueOnce(new Error('network'))
      getModelsMock.mockResolvedValueOnce({ success: true, models: { CHAT: [] }, providers: [] })

      await store.loadModels()
      await store.loadModels()

      expect(getModelsMock).toHaveBeenCalledTimes(2)
      expect(store.models).toEqual({ CHAT: [] })
      expect(store.loading).toBe(false)
    })
  })
})
