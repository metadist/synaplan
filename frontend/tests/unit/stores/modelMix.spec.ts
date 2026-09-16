import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useModelMixStore } from '@/stores/modelMix'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useAuthStore } from '@/stores/auth'
import { configApi } from '@/services/api/configApi'
import type { AIModel } from '@/types/ai-models'

// Applying a mix must go through the SAME endpoints the /ai/models page
// uses — per-user BCONFIG rows on the server — so a mix survives devices and
// behaves exactly like a manual configuration. These specs pin that contract
// plus the two local concerns: remembering the choice and refusing presets
// this installation cannot serve.

vi.mock('@/services/api/configApi', () => ({
  configApi: {
    getModels: vi.fn().mockResolvedValue({ success: true, models: {}, providers: [] }),
    getDefaultModels: vi.fn().mockResolvedValue({ success: true, defaults: {} }),
    saveDefaultModels: vi.fn().mockResolvedValue({ success: true, message: 'ok' }),
    resetDefaultModels: vi.fn().mockResolvedValue({ success: true, message: 'ok', defaults: {} }),
  },
}))

const model = (
  overrides: Partial<AIModel> & Pick<AIModel, 'id' | 'service' | 'name'>
): AIModel => ({
  tag: 'chat',
  providerId: '',
  quality: 1,
  rating: 1,
  priceIn: 0,
  priceOut: 0,
  description: null,
  isSystemModel: false,
  features: [],
  ...overrides,
})

const signIn = (userId: number) => {
  const authStore = useAuthStore()
  authStore.user = { id: userId } as unknown as NonNullable<typeof authStore.user>
}

const serveAnthropicChat = () => {
  const aiConfig = useAiConfigStore()
  aiConfig.models = {
    CHAT: [
      model({
        id: 240,
        service: 'Anthropic',
        providerId: 'claude-fable-5',
        name: 'Claude Fable 5',
      }),
    ],
  }
}

describe('modelMix store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('applies a provider mix through the defaults endpoint and remembers it per user', async () => {
    signIn(7)
    serveAnthropicChat()
    const store = useModelMixStore()

    const applied = await store.applyMix('anthropic')

    expect(applied).toBe(true)
    expect(configApi.saveDefaultModels).toHaveBeenCalledWith({ defaults: { CHAT: 240 } })
    // The mix only writes some capabilities — the store must re-read the
    // full defaults picture instead of trusting its partial payload.
    expect(configApi.getDefaultModels).toHaveBeenCalled()
    expect(store.activeMixId).toBe('anthropic')
    expect(localStorage.getItem('synaplan_model_mix_7')).toBe('anthropic')
  })

  it('restores the recommended install defaults for the default mix', async () => {
    signIn(7)
    const store = useModelMixStore()

    const applied = await store.applyMix('default')

    expect(applied).toBe(true)
    expect(configApi.resetDefaultModels).toHaveBeenCalled()
    expect(configApi.saveDefaultModels).not.toHaveBeenCalled()
  })

  it('refuses a mix this installation cannot serve', async () => {
    signIn(7)
    // No models loaded at all: every provider mix is unavailable.
    const store = useModelMixStore()

    const applied = await store.applyMix('xai')

    expect(applied).toBe(false)
    expect(configApi.saveDefaultModels).not.toHaveBeenCalled()
    expect(store.activeMixId).toBe('default')
  })

  it('restores the remembered mix for the signed-in user on load', async () => {
    signIn(7)
    serveAnthropicChat()
    localStorage.setItem('synaplan_model_mix_7', 'anthropic')
    const store = useModelMixStore()

    await store.ensureLoaded()

    expect(store.activeMixId).toBe('anthropic')
  })

  it('ignores a stored value that is not a mix id', async () => {
    signIn(7)
    serveAnthropicChat()
    localStorage.setItem('synaplan_model_mix_7', 'totally-bogus')
    const store = useModelMixStore()

    await store.ensureLoaded()

    expect(store.activeMixId).toBe('default')
  })

  it('loads the model catalog on first use when it is still empty', async () => {
    signIn(7)
    const store = useModelMixStore()

    await store.ensureLoaded()

    expect(configApi.getModels).toHaveBeenCalled()
  })
})
